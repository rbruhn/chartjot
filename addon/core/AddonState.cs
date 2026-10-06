using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;

namespace ChartJot.Core
{
	/// <summary>What <see cref="AddonState.Reconcile"/> found for one account/instrument.</summary>
	public sealed class ReconcileResult
	{
		/// <summary>False when the fills do not add up to the position NT8 reports. Nothing was changed then; the
		/// previously open trade's saved state is kept for the next attempt.</summary>
		public bool Consistent { get; set; }

		public int ComputedPosition { get; set; }
		public int ReportedPosition { get; set; }

		/// <summary>The trade open after reconciling, or null when flat (or not consistent).</summary>
		public OpenTradeInfo Open { get; set; }

		/// <summary>True when <see cref="Open"/> is the trade that was already open before (same trade_id), with its
		/// observed excursion restored. False with <see cref="Open"/> set means NT8 reports a trade that was not
		/// being tracked.</summary>
		public bool Resumed { get; set; }

		/// <summary>Previously open trades that closed while their fills and ticks were not being observed, with the
		/// excursion observed before that restored. They are already in <see cref="AddonState.AwaitingStage"/>.</summary>
		public IList<CompletedTrade> ClosedWhileAway { get; set; }

		/// <summary>Previously open trades found neither open nor closed in the fills given (for example, NT8 no
		/// longer lists them). Their saved state and notes are kept, not dropped.</summary>
		public IList<string> UnresolvedTradeIds { get; set; }
	}

	/// <summary>
	/// Everything the AddOn must not lose across an NT8 or AddOn restart, in one state file (NT8.md, "Persistence of
	/// open state"): open trades' observed price range, notes for trades not yet staged, pending pre-trade notes,
	/// closed trades waiting to be staged, staged trades, and the delivery queue.
	/// <para>
	/// Open trades' fills are not saved: <see cref="Reconcile"/> rebuilds them from <c>Account.Executions</c>, which
	/// also yields the same trade_id. Only the tick range seen before the restart has to come from this file.
	/// </para>
	/// Has no NinjaTrader types and is not thread-safe; the caller serializes access.
	/// </summary>
	public sealed class AddonState
	{
		private sealed class OpenTradeRecord
		{
			public string TradeId;
			public string Account;
			public string InstrumentFullName;
			public ExcursionSnapshot Excursion;
			public Dictionary<string, ExcursionSnapshot> FillExcursions = new Dictionary<string, ExcursionSnapshot>();
		}

		private const int Version = 1;

		// Open trades loaded from the file (or captured from the tracker) that Reconcile has not settled yet.
		private readonly Dictionary<string, OpenTradeRecord> records = new Dictionary<string, OpenTradeRecord>();
		private readonly Dictionary<string, List<NoteRecord>> tradeNotes = new Dictionary<string, List<NoteRecord>>();
		private readonly Dictionary<string, CompletedTrade> awaiting = new Dictionary<string, CompletedTrade>();
		private readonly List<string> awaitingOrder = new List<string>();
		private readonly Dictionary<string, TradeForm> forms = new Dictionary<string, TradeForm>();
		// Chart images (#52) of trades not staged yet (open, or closed and waiting to be staged), by trade_id.
		private readonly Dictionary<string, TradeImages> captures = new Dictionary<string, TradeImages>();

		// #53: the last at-risk stop price of trades not staged yet, by trade_id.
		private readonly Dictionary<string, decimal> stops = new Dictionary<string, decimal>();

		public AddonState(TimeSpan? initialBackoff = null, TimeSpan? maxBackoff = null)
			: this(new PendingNotes(), new StagedTrades(), new DeliveryQueue(initialBackoff, maxBackoff))
		{
		}

		private AddonState(PendingNotes pending, StagedTrades staged, DeliveryQueue deliveries)
		{
			Tracker = new TradeTracker();
			PendingNotes = pending;
			Staged = staged;
			Deliveries = deliveries;
		}

		public TradeTracker Tracker { get; private set; }
		public PendingNotes PendingNotes { get; private set; }
		public StagedTrades Staged { get; private set; }
		public DeliveryQueue Deliveries { get; private set; }

		/// <summary>Closed trades not yet staged (waiting on commission settle or the screenshot).</summary>
		public IList<CompletedTrade> AwaitingStage
		{
			get { return awaitingOrder.Select(id => awaiting[id]).ToList(); }
		}

		// ---- notes

		/// <summary>Moves the account/instrument's pending pre_trade notes onto a trade that just opened there.</summary>
		public void AttachPendingNotes(OpenTradeInfo opened)
		{
			if (opened == null)
				throw new ArgumentNullException("opened");
			foreach (NoteRecord note in PendingNotes.Take(opened.Account, opened.Instrument.FullName))
				AddTradeNote(opened.TradeId, note);
		}

		/// <summary>A note for a trade that is open, or closed but not yet staged. Staged trades hold their own notes.</summary>
		public void AddTradeNote(string tradeId, NoteRecord note)
		{
			if (string.IsNullOrEmpty(tradeId))
				throw new ArgumentException("A trade_id is required.", "tradeId");
			if (note == null)
				throw new ArgumentNullException("note");

			List<NoteRecord> notes;
			if (!tradeNotes.TryGetValue(tradeId, out notes))
			{
				notes = new List<NoteRecord>();
				tradeNotes[tradeId] = notes;
			}
			notes.Add(note);
		}

		/// <summary>Notes for a trade not yet staged, oldest first. Empty, never null.</summary>
		public IList<NoteRecord> NotesFor(string tradeId)
		{
			List<NoteRecord> notes;
			return tradeNotes.TryGetValue(tradeId, out notes)
				? notes.OrderBy(n => n.OccurredAt).ToList()
				: new List<NoteRecord>();
		}

		// ---- closed trades

		/// <summary>Holds a trade that just closed until it is staged, so a restart in between does not lose it.</summary>
		public void RecordClosed(CompletedTrade trade)
		{
			if (trade == null)
				throw new ArgumentNullException("trade");
			if (Staged.Find(trade.TradeId) != null || Deliveries.Find(trade.TradeId) != null)
				return;

			if (!awaiting.ContainsKey(trade.TradeId))
				awaitingOrder.Add(trade.TradeId);
			awaiting[trade.TradeId] = trade;
		}

		/// <summary>Moves a closed trade and its notes into the review list.</summary>
		public StagedTrade Stage(string tradeId)
		{
			return Stage(tradeId, null);
		}

		/// <summary>Moves a closed trade and its notes into the review list, stamped with <paramref name="stagedAt"/>
		/// (wall clock) for <see cref="PruneUnsubmitted"/>.</summary>
		public StagedTrade Stage(string tradeId, DateTimeOffset? stagedAt)
		{
			CompletedTrade trade;
			if (!awaiting.TryGetValue(tradeId, out trade))
				throw new InvalidOperationException("Trade " + tradeId + " is not waiting to be staged.");

			TradeImages images = ImagesFor(tradeId);
			StagedTrade staged = new StagedTrade { Trade = trade, Notes = NotesFor(tradeId), StagedAt = stagedAt, Screenshot = images.Exit, EntryScreenshot = images.Entry,
				StopPrice = StopFor(tradeId) };
			Staged.Add(staged);
			Forget(tradeId);
			return staged;
		}

		/// <summary>Drops a closed trade that will not be journaled, and anything held for it.</summary>
		public void Discard(string tradeId)
		{
			Forget(tradeId);
		}

		private void Forget(string tradeId)
		{
			if (awaiting.Remove(tradeId))
				awaitingOrder.Remove(tradeId);
			tradeNotes.Remove(tradeId);
			records.Remove(tradeId);
			captures.Remove(tradeId);
			stops.Remove(tradeId);
		}

		/// <summary>
		/// Re-reads each fill's commission and fee (NT8.md: commission may arrive after the fill, so re-read it after
		/// the settle period; staged values may still be refreshed until the trader submits) and recomputes the trade
		/// with the same trade_id. Works on a trade awaiting staging or a staged one that <see cref="CanEdit"/>.
		/// <paramref name="currentCharges"/> returns null for a fill it has nothing for. Returns true when anything
		/// changed.
		/// </summary>
		public bool RefreshCharges(string tradeId, Func<string, FillCharges> currentCharges)
		{
			if (currentCharges == null)
				throw new ArgumentNullException("currentCharges");

			CompletedTrade trade;
			StagedTrade staged = Staged.Find(tradeId);
			if (staged != null)
			{
				if (!CanEdit(tradeId))
					return false;
				trade = staged.Trade;
			}
			else if (!awaiting.TryGetValue(tradeId, out trade))
			{
				return false;
			}

			bool changed = false;
			List<TradeFill> fills = new List<TradeFill>();
			foreach (TradeFill f in trade.Fills)
			{
				FillCharges charges = currentCharges(f.Fill.ExecutionId);
				if (charges == null || (charges.Commission == f.Fill.Commission && charges.Fee == f.Fill.Fee))
				{
					fills.Add(f);
					continue;
				}

				changed = true;
				Fill fill = CopyWithCharges(f.Fill, charges);
				fills.Add(new TradeFill
				{
					Fill = fill,
					Role = f.Role,
					AllocatedQuantity = f.AllocatedQuantity,
					// Same pro-rating as TradeTracker: a reversal fill's charges are split by allocated quantity.
					Commission = f.AllocatedQuantity == fill.Quantity ? fill.Commission : fill.Commission * f.AllocatedQuantity / fill.Quantity,
					Fee = f.AllocatedQuantity == fill.Quantity ? fill.Fee : fill.Fee * f.AllocatedQuantity / fill.Quantity,
					PositionAfter = f.PositionAfter,
					Excursion = f.Excursion
				});
			}
			if (!changed)
				return false;

			CompletedTrade refreshed = TradeCalculator.Complete(trade.Account, trade.Instrument, trade.Direction, trade.Quantity, fills,
				feedInterrupted: !trade.ExcursionComplete, openedByReversal: trade.OpenedByReversal);
			if (staged != null)
				staged.Trade = refreshed;
			else
				awaiting[tradeId] = refreshed;
			return true;
		}

		private static Fill CopyWithCharges(Fill f, FillCharges charges)
		{
			return new Fill
			{
				ExecutionId = f.ExecutionId,
				OrderId = f.OrderId,
				OrderName = f.OrderName,
				Account = f.Account,
				Instrument = f.Instrument,
				Time = f.Time,
				Side = f.Side,
				Quantity = f.Quantity,
				Price = f.Price,
				Commission = charges.Commission,
				Fee = charges.Fee,
				PositionAfter = f.PositionAfter,
				IsEntry = f.IsEntry,
				IsExit = f.IsExit
			};
		}

		// ---- trade form (one note form per Chart Trader account + instrument)

		private static string FormKey(string account, string instrumentFullName)
		{
			return account + "|" + instrumentFullName;
		}

		/// <summary>The form for an account/instrument. An empty, unsaved one when nothing was written there yet.</summary>
		public TradeForm FormFor(string account, string instrumentFullName)
		{
			TradeForm form;
			return forms.TryGetValue(FormKey(account, instrumentFullName), out form)
				? form
				: new TradeForm { Account = account, Instrument = instrumentFullName, LastSubmitted = new List<string>() };
		}

		/// <summary>
		/// Saves what the form shows. The body is kept as typed (null when empty). The first time it gets text,
		/// <paramref name="now"/> is remembered as when the note was started; clearing the text forgets it.
		/// <c>trade_type_other</c> is kept only for <c>Other</c>.
		/// </summary>
		public void UpdateForm(string account, string instrumentFullName, string body, string tradeType, string tradeTypeOther, DateTimeOffset now)
		{
			if (tradeType != null && !TradeTypes.IsValid(tradeType))
				throw new ArgumentException("Unknown trade type '" + tradeType + "'.", "tradeType");

			string key = FormKey(account, instrumentFullName);
			TradeForm form;
			if (!forms.TryGetValue(key, out form))
			{
				form = FormFor(account, instrumentFullName);
				forms[key] = form;
			}

			bool hasText = !string.IsNullOrWhiteSpace(body);
			if (!hasText)
				form.StartedAt = null;
			else if (!form.StartedAt.HasValue)
				form.StartedAt = now;

			form.Body = string.IsNullOrEmpty(body) ? null : body;
			form.TradeType = tradeType;
			form.TradeTypeOther = tradeType == "Other" && !string.IsNullOrWhiteSpace(tradeTypeOther) ? tradeTypeOther.Trim() : null;
		}

		/// <summary>The account/instrument's closed trades not submitted yet, oldest first: what Submit sends.</summary>
		public IList<CompletedTrade> PendingForForm(string account, string instrumentFullName)
		{
			DateTimeOffset? since = FormFor(account, instrumentFullName).CycleStartedAt;
			IEnumerable<CompletedTrade> waiting = awaitingOrder.Select(id => awaiting[id]);
			IEnumerable<CompletedTrade> staged = Staged.All
				.Where(s => !since.HasValue || (s.StagedAt.HasValue && s.StagedAt.Value >= since.Value))
				.Select(s => s.Trade);
			return waiting.Concat(staged)
				.Where(t => t.Account == account && t.Instrument.FullName == instrumentFullName)
				.Where(t => Deliveries.Find(t.TradeId) == null)
				.OrderBy(t => t.ExitAt)
				.ToList();
		}

		/// <summary>
		/// The form starts caring about trades from <paramref name="now"/>: called when it is first opened in an NT
		/// session, so trades closed before (never meant for it) are not offered, warned about, or sent.
		/// </summary>
		public void StartFormCycle(string account, string instrumentFullName, DateTimeOffset now)
		{
			string key = FormKey(account, instrumentFullName);
			TradeForm form;
			if (!forms.TryGetValue(key, out form))
			{
				form = FormFor(account, instrumentFullName);
				forms[key] = form;
			}
			form.CycleStartedAt = now;
		}

		/// <summary>Submit is enabled once a trade has closed there and a trade type is chosen.</summary>
		public bool CanSubmitForm(string account, string instrumentFullName)
		{
			return TradeTypes.IsValid(FormFor(account, instrumentFullName).TradeType) && PendingForForm(account, instrumentFullName).Count > 0;
		}

		/// <summary>Closed, unsubmitted trades on every other account: where a master's followers are looked for.</summary>
		public IList<CompletedTrade> FollowerCandidates(string masterAccount)
		{
			return Unsubmitted().Where(t => t.Account != masterAccount).ToList();
		}

		/// <summary>
		/// Submit: every closed, unsubmitted trade of the account/instrument (the master), then each follower trade
		/// in <paramref name="followerTradeIds"/>, is frozen into the delivery queue. Each sends its own trade data;
		/// all carry the form's note (one <c>general</c> note, timed when the trader started it; none when the form
		/// has no text) and trade type. The form is then cleared. Throws, changing nothing, when nothing has closed,
		/// no valid trade type is chosen, or a follower trade is not a closed, unsubmitted trade of another account.
		/// </summary>
		public IList<QueuedDelivery> SubmitForm(string account, string instrumentFullName, string addonVersion,
			Func<string, string> connectionFor, IEnumerable<string> followerTradeIds, DateTimeOffset now)
		{
			return SubmitForm(account, instrumentFullName, addonVersion, connectionFor, followerTradeIds, now, null);
		}

		/// <summary>
		/// As above. Each follower trade also carries the chart images (#52) of its master trade:
		/// <paramref name="masterOf"/> maps a follower trade_id to the master trade_id it copied; when null (or it
		/// returns an id that is not one of the masters), the first master's images are used.
		/// </summary>
		public IList<QueuedDelivery> SubmitForm(string account, string instrumentFullName, string addonVersion,
			Func<string, string> connectionFor, IEnumerable<string> followerTradeIds, DateTimeOffset now, Func<string, string> masterOf)
		{
			TradeForm form = FormFor(account, instrumentFullName);
			List<CompletedTrade> masters = PendingForForm(account, instrumentFullName).ToList();
			if (masters.Count == 0)
				throw new InvalidOperationException("No closed trade to submit yet.");
			if (!TradeTypes.IsValid(form.TradeType))
				throw new InvalidOperationException("Choose a trade type first.");

			Dictionary<string, CompletedTrade> candidates = FollowerCandidates(account).ToDictionary(t => t.TradeId);
			List<CompletedTrade> followers = new List<CompletedTrade>();
			foreach (string id in followerTradeIds ?? Enumerable.Empty<string>())
			{
				CompletedTrade follower;
				if (!candidates.TryGetValue(id, out follower))
					throw new InvalidOperationException("Follower trade " + id + " is not a closed, unsubmitted trade of another account.");
				if (!followers.Contains(follower))
					followers.Add(follower);
			}

			Dictionary<string, TradeImages> masterImages = masters.ToDictionary(t => t.TradeId, t => ImagesFor(t.TradeId));
			List<QueuedDelivery> queued = new List<QueuedDelivery>();
			foreach (CompletedTrade trade in masters.Concat(followers))
			{
				StagedTrade staged = Staged.Find(trade.TradeId) ?? Stage(trade.TradeId, now);
				if (followers.Contains(trade))
				{
					string masterId = masterOf == null ? null : masterOf(trade.TradeId);
					TradeImages images;
					if (masterId == null || !masterImages.TryGetValue(masterId, out images))
						images = masterImages[masters[0].TradeId];
					staged.Screenshot = Copy(images.Exit);
					staged.EntryScreenshot = Copy(images.Entry);
				}
				staged.Notes = string.IsNullOrWhiteSpace(form.Body)
					? new List<NoteRecord>()
					: new List<NoteRecord> { new NoteRecord { Body = form.Body.Trim(), Phase = NotePhases.General, OccurredAt = form.StartedAt ?? now } };
				staged.TradeType = form.TradeType;
				staged.TradeTypeOther = form.TradeTypeOther;
				queued.Add(Submit(trade.TradeId, addonVersion, connectionFor == null ? null : connectionFor(trade.Account)));
			}

			forms[FormKey(account, instrumentFullName)] = new TradeForm
			{
				Account = account,
				Instrument = instrumentFullName,
				LastSubmitted = queued.Select(d => d.TradeId).ToList(),
				CycleStartedAt = now
			};
			return queued;
		}

		/// <summary>Reset: clears the form and drops the account/instrument's closed, unsubmitted trades (the trader
		/// chose not to journal them). Submitted trades are untouched. Returns the dropped trade_ids.</summary>
		public IList<string> ResetForm(string account, string instrumentFullName)
		{
			return ResetForm(account, instrumentFullName, null);
		}

		/// <summary>As above, and the form's next cycle starts at <paramref name="now"/> (null keeps the current start).</summary>
		public IList<string> ResetForm(string account, string instrumentFullName, DateTimeOffset? now)
		{
			List<string> dropped = PendingForForm(account, instrumentFullName).Select(t => t.TradeId).ToList();
			foreach (string tradeId in dropped)
			{
				Staged.Remove(tradeId);
				Forget(tradeId);
			}

			TradeForm old = FormFor(account, instrumentFullName);
			forms[FormKey(account, instrumentFullName)] = new TradeForm
			{
				Account = account,
				Instrument = instrumentFullName,
				LastSubmitted = old.LastSubmitted ?? new List<string>(),
				CycleStartedAt = now ?? old.CycleStartedAt
			};
			return dropped;
		}

		/// <summary>
		/// Drops staged trades nobody submitted within <paramref name="maxAge"/> of staging: closed trades on
		/// accounts that are neither a form's master nor a submitted master's follower, or trades the trader never
		/// got to. A staged trade with no staged time (an older state file) counts as old. Returns the dropped ids.
		/// </summary>
		public IList<string> PruneUnsubmitted(DateTimeOffset now, TimeSpan maxAge)
		{
			List<string> dropped = Staged.All
				.Where(s => Deliveries.Find(s.Trade.TradeId) == null)
				.Where(s => !s.StagedAt.HasValue || now - s.StagedAt.Value > maxAge)
				.Select(s => s.Trade.TradeId)
				.ToList();
			foreach (string tradeId in dropped)
				Staged.Remove(tradeId);
			return dropped;
		}

		// Closed trades not yet frozen into the delivery queue: waiting to be staged, or staged and not submitted.
		private IEnumerable<CompletedTrade> Unsubmitted()
		{
			return awaitingOrder.Select(id => awaiting[id])
				.Concat(Staged.All.Select(s => s.Trade))
				.Where(t => Deliveries.Find(t.TradeId) == null);
		}

		// ---- chart images (#37/#52)

		/// <summary>
		/// Records a chart image captured for a trade: the entry image (<paramref name="entry"/>) or the exit image,
		/// replacing any earlier one of that kind (Recapture). Accepted for an open trade, a closed trade waiting to
		/// be staged, and a staged trade that can still be edited. False otherwise (unknown, or already submitted):
		/// a submitted trade's images are frozen with its payload.
		/// </summary>
		public bool RecordCapture(string tradeId, bool entry, ScreenshotMeta meta)
		{
			if (meta == null)
				throw new ArgumentNullException("meta");

			StagedTrade staged = Staged.Find(tradeId);
			if (staged != null)
			{
				if (!CanEdit(tradeId))
					return false;
				if (entry)
					staged.EntryScreenshot = meta;
				else
					staged.Screenshot = meta;
				return true;
			}

			bool tracked = awaiting.ContainsKey(tradeId) || records.ContainsKey(tradeId)
				|| Tracker.OpenTrades.Any(t => t.TradeId == tradeId);
			if (!tracked)
				return false;

			TradeImages images;
			if (!captures.TryGetValue(tradeId, out images))
			{
				images = new TradeImages();
				captures[tradeId] = images;
			}
			if (entry)
				images.Entry = meta;
			else
				images.Exit = meta;
			return true;
		}

		/// <summary>A trade's images, wherever it is (open, waiting, staged). Never null; either image may be.</summary>
		public TradeImages ImagesFor(string tradeId)
		{
			StagedTrade staged = Staged.Find(tradeId);
			if (staged != null)
				return new TradeImages { Exit = staged.Screenshot, Entry = staged.EntryScreenshot };
			TradeImages images;
			return captures.TryGetValue(tradeId, out images)
				? new TradeImages { Exit = images.Exit, Entry = images.Entry }
				: new TradeImages();
		}

		private static ScreenshotMeta Copy(ScreenshotMeta meta)
		{
			return meta == null ? null : new ScreenshotMeta { CapturedAt = meta.CapturedAt, Caption = meta.Caption, Format = meta.Format };
		}

		// ---- stop price (#53)

		/// <summary>
		/// The open position's protective stop is now at <paramref name="stopPrice"/>. It is recorded only while it is
		/// on the losing side of the trade's average entry (below it for a long, above it for a short): the ATM's
		/// stop, then any tightening or loosening by hand. A stop at breakeven or in profit (moved after Target 1, a
		/// trail, or by hand) is ignored, so the trade keeps the last stop it was actually risking. False when there
		/// is no open trade on that account/instrument or the stop is not at risk.
		/// </summary>
		public bool RecordStop(string account, string instrumentFullName, decimal stopPrice)
		{
			OpenTradeInfo open = FindOpen(account, instrumentFullName);
			if (open == null)
				return false;

			List<TradeFill> entries = open.Fills.Where(f => f.Role == FillRole.Entry && f.AllocatedQuantity > 0).ToList();
			if (entries.Count == 0)
				return false;
			decimal averageEntry = entries.Sum(f => f.Fill.Price * f.AllocatedQuantity) / entries.Sum(f => (decimal)f.AllocatedQuantity);

			bool atRisk = open.Direction == Direction.Long ? stopPrice < averageEntry : stopPrice > averageEntry;
			if (!atRisk)
				return false;

			stops[open.TradeId] = stopPrice;
			return true;
		}

		/// <summary>The trade's recorded stop price (open, waiting, or staged), or null when it has none.</summary>
		public decimal? StopFor(string tradeId)
		{
			StagedTrade staged = Staged.Find(tradeId);
			if (staged != null)
				return staged.StopPrice;
			decimal stop;
			return stops.TryGetValue(tradeId, out stop) ? stop : (decimal?)null;
		}

		// ---- submission

		/// <summary>
		/// True while the trader may still change a staged trade (notes, trade type, stop price): before Submit, and
		/// again after a delivery failed (a 422 or a bad token), when resubmitting freezes a new payload. False while a
		/// submission is pending, in flight, or sent, and for a trade that is not staged.
		/// </summary>
		public bool CanEdit(string tradeId)
		{
			if (Staged.Find(tradeId) == null)
				return false;
			QueuedDelivery delivery = Deliveries.Find(tradeId);
			return delivery == null || delivery.State == DeliveryState.Failed;
		}

		/// <summary>
		/// The trader clicked Submit trade: freezes the staged trade into a payload and queues it (NT8.md, "Once the
		/// trader clicks Submit trade, the JSON payload is frozen and saved"). The trade stays in <see cref="Staged"/>
		/// so the panel can show its delivery state, until <see cref="RemoveSent"/>. Throws when the trade is not
		/// staged or not editable (already submitted), or has no valid trade type; nothing is queued then.
		/// </summary>
		public QueuedDelivery Submit(string tradeId, string addonVersion, string connection)
		{
			StagedTrade staged = Staged.Find(tradeId);
			if (staged == null)
				throw new InvalidOperationException("Trade " + tradeId + " is not staged for review.");
			if (!CanEdit(tradeId))
				throw new InvalidOperationException("Trade " + tradeId + " has already been submitted.");

			string payload = PayloadBuilder.Build(staged.Trade, new SubmissionInfo
			{
				AddonVersion = addonVersion,
				Connection = connection,
				TradeType = staged.TradeType,
				TradeTypeOther = staged.TradeTypeOther,
				Notes = staged.Notes ?? new List<NoteRecord>(),
				Screenshot = staged.Screenshot,
				EntryScreenshot = staged.EntryScreenshot,
				StopPrice = staged.StopPrice
			});
			return Deliveries.Enqueue(tradeId, payload);
		}

		/// <summary>Drops staged trades the server has accepted. Their deliveries stay in the queue as the record
		/// that they were sent, which is also what stops <see cref="RecordClosed"/> journaling them again.</summary>
		public IList<string> RemoveSent()
		{
			List<string> sent = Staged.All
				.Select(s => s.Trade.TradeId)
				.Where(id => { QueuedDelivery d = Deliveries.Find(id); return d != null && d.State == DeliveryState.Sent; })
				.ToList();
			foreach (string tradeId in sent)
				Staged.Remove(tradeId);
			return sent;
		}

		// ---- reconcile

		/// <summary>
		/// Brings one account/instrument in line with NT8's own records after startup or any transition to
		/// Connected. <paramref name="fills"/> is its execution history (<c>Account.Executions</c>, in order) and
		/// <paramref name="reportedPosition"/> the signed position NT8 reports now.
		/// <list type="bullet">
		/// <item>The trade that was open before (from the state file, or the tracker's live one) is rebuilt with
		/// the same trade_id, and the price range observed before is restored; its feed is flagged interrupted.</item>
		/// <item>If that trade closed meanwhile, it is recorded as closed with the range observed before restored,
		/// and waits in <see cref="AwaitingStage"/>.</item>
		/// <item>Trades that opened and closed entirely while unobserved are not journaled.</item>
		/// </list>
		/// Safe to call again with the same input: the result and the state are the same.
		/// </summary>
		public ReconcileResult Reconcile(string account, string instrumentFullName, IEnumerable<Fill> fills, int reportedPosition)
		{
			if (fills == null)
				throw new ArgumentNullException("fills");

			// The live trade is the newest observation of this position, so it replaces anything loaded from disk.
			Dictionary<string, OpenTradeRecord> previous = records.Values
				.Where(r => r.Account == account && r.InstrumentFullName == instrumentFullName)
				.ToDictionary(r => r.TradeId);
			OpenTradeInfo live = FindOpen(account, instrumentFullName);
			if (live != null)
				previous[live.TradeId] = Capture(live);

			RebuildResult rebuild = Tracker.Rebuild(account, instrumentFullName, fills.ToList(), reportedPosition);
			ReconcileResult result = new ReconcileResult
			{
				Consistent = rebuild.Consistent,
				ComputedPosition = rebuild.ComputedPosition,
				ReportedPosition = reportedPosition,
				ClosedWhileAway = new List<CompletedTrade>(),
				UnresolvedTradeIds = new List<string>()
			};
			if (!rebuild.Consistent)
				return result;

			foreach (OpenTradeRecord record in previous.Values)
			{
				if (awaiting.ContainsKey(record.TradeId) || Staged.Find(record.TradeId) != null || Deliveries.Find(record.TradeId) != null)
				{
					records.Remove(record.TradeId);
					continue;
				}

				if (rebuild.Open != null && rebuild.Open.TradeId == record.TradeId)
				{
					Tracker.RestoreExcursion(account, instrumentFullName, record.TradeId, record.Excursion, record.FillExcursions);
					records.Remove(record.TradeId);
					result.Resumed = true;
					continue;
				}

				CompletedTrade closed = rebuild.Closed.FirstOrDefault(t => t.TradeId == record.TradeId);
				if (closed != null)
				{
					CompletedTrade restored = RestoreExcursion(closed, record);
					RecordClosed(restored);
					records.Remove(record.TradeId);
					result.ClosedWhileAway.Add(restored);
					continue;
				}

				// Rebuild dropped it from the tracker, so this record is all that is left of it.
				records[record.TradeId] = record;
				result.UnresolvedTradeIds.Add(record.TradeId);
			}

			result.Open = FindOpen(account, instrumentFullName);
			return result;
		}

		private OpenTradeInfo FindOpen(string account, string instrumentFullName)
		{
			return Tracker.OpenTrades.FirstOrDefault(t => t.Account == account && t.Instrument.FullName == instrumentFullName);
		}

		private static OpenTradeRecord Capture(OpenTradeInfo open)
		{
			OpenTradeRecord record = new OpenTradeRecord
			{
				TradeId = open.TradeId,
				Account = open.Account,
				InstrumentFullName = open.Instrument.FullName,
				Excursion = open.Excursion
			};
			foreach (TradeFill fill in open.Fills)
				record.FillExcursions[fill.Fill.ExecutionId] = fill.Excursion;
			return record;
		}

		// Recomputes a trade that closed unobserved, so its excursion and legs include the range seen before.
		private static CompletedTrade RestoreExcursion(CompletedTrade closed, OpenTradeRecord record)
		{
			List<TradeFill> fills = closed.Fills.Select(f => new TradeFill
			{
				Fill = f.Fill,
				Role = f.Role,
				AllocatedQuantity = f.AllocatedQuantity,
				Commission = f.Commission,
				Fee = f.Fee,
				PositionAfter = f.PositionAfter,
				Excursion = f.Excursion
			}).ToList();
			TradeTracker.RestoreFillExcursions(fills, record.Excursion, record.FillExcursions);

			return TradeCalculator.Complete(closed.Account, closed.Instrument, closed.Direction, closed.Quantity, fills,
				feedInterrupted: true, openedByReversal: closed.OpenedByReversal);
		}

		// ---- persistence

		public string Serialize()
		{
			List<OpenTradeRecord> open = Tracker.OpenTrades.Select(Capture).ToList();
			HashSet<string> openIds = new HashSet<string>(open.Select(r => r.TradeId));
			open.AddRange(records.Values.Where(r => !openIds.Contains(r.TradeId)));

			JsonWriter w = new JsonWriter();
			w.BeginObject();
			w.Property("version", Version);

			w.Name("open_trades").BeginArray();
			foreach (OpenTradeRecord record in open)
			{
				w.BeginObject();
				w.Property("trade_id", record.TradeId);
				w.Property("account", record.Account);
				w.Property("instrument_full_name", record.InstrumentFullName);
				w.Name("excursion");
				WriteSnapshot(w, record.Excursion);
				w.Name("fill_excursions").BeginArray();
				foreach (KeyValuePair<string, ExcursionSnapshot> fill in record.FillExcursions)
				{
					w.BeginObject();
					w.Property("execution_id", fill.Key);
					w.Name("excursion");
					WriteSnapshot(w, fill.Value);
					w.EndObject();
				}
				w.EndArray();
				w.EndObject();
			}
			w.EndArray();

			w.Name("trade_notes").BeginArray();
			foreach (KeyValuePair<string, List<NoteRecord>> entry in tradeNotes)
			{
				w.BeginObject();
				w.Property("trade_id", entry.Key);
				w.Name("notes").BeginArray();
				foreach (NoteRecord note in entry.Value)
					StagedTrades.WriteNote(w, note);
				w.EndArray();
				w.EndObject();
			}
			w.EndArray();

			w.Name("awaiting_stage").BeginArray();
			foreach (string tradeId in awaitingOrder)
				StagedTrades.WriteCompletedTrade(w, awaiting[tradeId]);
			w.EndArray();

			w.Name("pending_notes").Raw(PendingNotes.Serialize());
			w.Name("staged_trades").Raw(Staged.Serialize());
			w.Name("deliveries").Raw(Deliveries.Serialize());

			w.Name("captures").BeginArray();
			foreach (KeyValuePair<string, TradeImages> entry in captures)
			{
				w.BeginObject();
				w.Property("trade_id", entry.Key);
				StagedTrades.WriteScreenshot(w, "exit", entry.Value.Exit);
				StagedTrades.WriteScreenshot(w, "entry", entry.Value.Entry);
				w.EndObject();
			}
			w.EndArray();

			w.Name("stops").BeginArray();
			foreach (KeyValuePair<string, decimal> entry in stops)
			{
				w.BeginObject();
				w.Property("trade_id", entry.Key);
				w.Property("stop_price", entry.Value);
				w.EndObject();
			}
			w.EndArray();

			w.Name("forms").BeginArray();
			foreach (TradeForm form in forms.Values)
			{
				w.BeginObject();
				w.Property("account", form.Account);
				w.Property("instrument_full_name", form.Instrument);
				w.Property("body", form.Body);
				w.Property("trade_type", form.TradeType);
				w.Property("trade_type_other", form.TradeTypeOther);
				w.Property("started_at", form.StartedAt.HasValue ? PayloadBuilder.Timestamp(form.StartedAt.Value) : null);
				w.Property("cycle_started_at", form.CycleStartedAt.HasValue ? PayloadBuilder.Timestamp(form.CycleStartedAt.Value) : null);
				w.Name("last_submitted").BeginArray();
				foreach (string tradeId in form.LastSubmitted ?? new List<string>())
					w.String(tradeId);
				w.EndArray();
				w.EndObject();
			}
			w.EndArray();
			w.EndObject();
			return w.ToString();
		}

		/// <summary>
		/// Rebuilds the state from <see cref="Serialize"/>'s output. Open trades come back as saved records only; the
		/// tracker starts empty until <see cref="Reconcile"/> is run for each account/instrument.
		/// </summary>
		public static AddonState Deserialize(string json, TimeSpan? initialBackoff = null, TimeSpan? maxBackoff = null)
		{
			JsonValue root = JsonValue.Parse(json);
			if (!root.Has("version") || root["version"].AsInt32() != Version)
				throw new FormatException("Unsupported state file version.");

			AddonState state = new AddonState(
				PendingNotes.FromJson(root["pending_notes"]),
				StagedTrades.FromJson(root["staged_trades"]),
				DeliveryQueue.FromJson(root["deliveries"], initialBackoff, maxBackoff));

			foreach (JsonValue item in root["open_trades"].Items)
			{
				OpenTradeRecord record = new OpenTradeRecord
				{
					TradeId = item["trade_id"].AsString(),
					Account = item["account"].AsString(),
					InstrumentFullName = item["instrument_full_name"].AsString(),
					Excursion = ReadSnapshot(item["excursion"])
				};
				foreach (JsonValue fill in item["fill_excursions"].Items)
					record.FillExcursions[fill["execution_id"].AsString()] = ReadSnapshot(fill["excursion"]);
				state.records[record.TradeId] = record;
			}

			foreach (JsonValue item in root["trade_notes"].Items)
			{
				string tradeId = item["trade_id"].AsString();
				foreach (JsonValue note in item["notes"].Items)
					state.AddTradeNote(tradeId, StagedTrades.ReadNote(note));
			}

			foreach (JsonValue item in root["awaiting_stage"].Items)
				state.RecordClosed(StagedTrades.ReadCompletedTrade(item));

			// Missing in state files written before the stop price was tracked.
			if (root.Has("stops"))
			{
				foreach (JsonValue item in root["stops"].Items)
					state.stops[item["trade_id"].AsString()] = item["stop_price"].AsDecimal();
			}

			// Missing in state files written before chart images existed.
			if (root.Has("captures"))
			{
				foreach (JsonValue item in root["captures"].Items)
				{
					state.captures[item["trade_id"].AsString()] = new TradeImages
					{
						Exit = StagedTrades.ReadScreenshot(item["exit"]),
						Entry = StagedTrades.ReadScreenshot(item["entry"])
					};
				}
			}

			// Missing in state files written before the form existed.
			if (root.Has("forms"))
			{
				foreach (JsonValue item in root["forms"].Items)
				{
					TradeForm form = new TradeForm
					{
						Account = item["account"].AsString(),
						Instrument = item["instrument_full_name"].AsString(),
						Body = item["body"].AsString(),
						TradeType = item["trade_type"].AsString(),
						TradeTypeOther = item["trade_type_other"].AsString(),
						StartedAt = item["started_at"].IsNull ? (DateTimeOffset?)null : DateTimeOffset.Parse(item["started_at"].AsString(), CultureInfo.InvariantCulture, DateTimeStyles.None),
						CycleStartedAt = item["cycle_started_at"].IsNull ? (DateTimeOffset?)null : DateTimeOffset.Parse(item["cycle_started_at"].AsString(), CultureInfo.InvariantCulture, DateTimeStyles.None),
						LastSubmitted = item["last_submitted"].IsNull ? new List<string>() : item["last_submitted"].Items.Select(v => v.AsString()).ToList()
					};
					state.forms[FormKey(form.Account, form.Instrument)] = form;
				}
			}

			return state;
		}

		/// <summary>Writes the whole state atomically (see <see cref="StateFile"/>).</summary>
		public void Save(string path)
		{
			StateFile.WriteAtomic(path, Serialize());
		}

		/// <summary>Loads the state file, or returns an empty state when there is none yet.</summary>
		public static AddonState Load(string path, TimeSpan? initialBackoff = null, TimeSpan? maxBackoff = null)
		{
			string json = StateFile.Read(path);
			return json == null ? new AddonState(initialBackoff, maxBackoff) : Deserialize(json, initialBackoff, maxBackoff);
		}

		private static void WriteSnapshot(JsonWriter w, ExcursionSnapshot snapshot)
		{
			w.BeginObject();
			w.Property("high", snapshot.High);
			w.Property("low", snapshot.Low);
			w.Property("had_ticks", snapshot.HadTicks);
			w.EndObject();
		}

		private static ExcursionSnapshot ReadSnapshot(JsonValue v)
		{
			return new ExcursionSnapshot { High = v["high"].AsDecimal(), Low = v["low"].AsDecimal(), HadTicks = v["had_ticks"].AsBool() };
		}
	}
}
