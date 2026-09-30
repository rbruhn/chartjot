using System;
using System.Collections.Generic;
using System.Linq;

namespace ChartJot.Core
{
	public enum ApplyStatus
	{
		/// <summary>The fill was applied to the running position.</summary>
		Applied,

		/// <summary>The account already reported this ExecutionId.</summary>
		Duplicate,

		/// <summary>
		/// NT8's signed position after the fill disagrees with the running sum. The fill was NOT applied;
		/// resynchronize with <see cref="TradeTracker.Rebuild"/>.
		/// </summary>
		PositionMismatch,

		/// <summary>An exit fill arrived while flat with no position information. It never opens a trade.</summary>
		OrphanExit
	}

	/// <summary>A trade that is open now.</summary>
	public sealed class OpenTradeInfo
	{
		public string TradeId { get; set; }
		public string Account { get; set; }
		public InstrumentSpec Instrument { get; set; }
		public Direction Direction { get; set; }
		public DateTimeOffset EntryAt { get; set; }
		public int SignedPosition { get; set; }
		public int MaxQuantity { get; set; }
		public bool FeedInterrupted { get; set; }

		/// <summary>True when the fill that opened this trade also closed the previous one (a reversal).</summary>
		public bool OpenedByReversal { get; set; }

		/// <summary>The running high/low observed so far. Save this: after a restart, only this snapshot (not
		/// <c>Account.Executions</c>) can tell you the tick range seen before the restart.</summary>
		public ExcursionSnapshot Excursion { get; set; }

		/// <summary>The fills recorded so far.</summary>
		public IList<TradeFill> Fills { get; set; }
	}

	public sealed class ApplyResult
	{
		public ApplyStatus Status { get; set; }

		/// <summary>Trades this fill completed. A reversal fill completes one and opens another.</summary>
		public IList<CompletedTrade> Closed { get; private set; }

		/// <summary>Set when this fill started a trade.</summary>
		public OpenTradeInfo Opened { get; set; }

		/// <summary>For <see cref="ApplyStatus.PositionMismatch"/>: the running sum after this fill vs NT8's figure.</summary>
		public int ComputedPosition { get; set; }
		public int ReportedPosition { get; set; }

		public ApplyResult(ApplyStatus status)
		{
			Status = status;
			Closed = new List<CompletedTrade>();
		}
	}

	public sealed class RebuildResult
	{
		/// <summary>False when the replayed fills do not add up to the expected position. State is unchanged then.</summary>
		public bool Consistent { get; set; }

		public int ComputedPosition { get; set; }

		/// <summary>Trades completed while replaying. The caller decides which of these are new.</summary>
		public IList<CompletedTrade> Closed { get; set; }

		/// <summary>The trade left open by the replay, if any.</summary>
		public OpenTradeInfo Open { get; set; }
	}

	/// <summary>
	/// Rebuilds round-turn trades from fills, per account and instrument. Flat versus open comes from a running
	/// signed sum of fills, checked against NT8's own position after each fill.
	/// </summary>
	public sealed class TradeTracker
	{
		private sealed class OpenTrade
		{
			public Direction Direction;
			public readonly List<TradeFill> Fills = new List<TradeFill>();
			public ExcursionTracker Excursion = new ExcursionTracker();
			public int Signed;
			public int MaxQuantity;
			public string TradeId;
			public bool OpenedByReversal;
		}

		private sealed class PositionState
		{
			public InstrumentSpec Instrument;
			public string Account;
			public int Signed;
			public OpenTrade Trade;
		}

		private readonly Dictionary<string, PositionState> states = new Dictionary<string, PositionState>();
		private readonly HashSet<string> seen = new HashSet<string>();

		private static string Key(string account, string instrumentFullName)
		{
			return account + "|" + instrumentFullName;
		}

		private static string SeenKey(string account, string executionId)
		{
			return account + "|" + executionId;
		}

		public int Position(string account, string instrumentFullName)
		{
			PositionState state;
			return states.TryGetValue(Key(account, instrumentFullName), out state) ? state.Signed : 0;
		}

		public IList<OpenTradeInfo> OpenTrades
		{
			get
			{
				return states.Values.Where(s => s.Trade != null).Select(s => Describe(s)).ToList();
			}
		}

		public ApplyResult Apply(Fill fill)
		{
			if (fill == null)
				throw new ArgumentNullException("fill");
			if (fill.Quantity <= 0)
				throw new ArgumentException("Fill quantity must be positive.", "fill");

			string seenKey = SeenKey(fill.Account, fill.ExecutionId);
			if (seen.Contains(seenKey))
				return new ApplyResult(ApplyStatus.Duplicate);

			string key = Key(fill.Account, fill.Instrument.FullName);
			PositionState state;
			if (!states.TryGetValue(key, out state))
			{
				state = new PositionState { Account = fill.Account, Instrument = fill.Instrument };
				states[key] = state;
			}

			int before = state.Signed;
			int delta = fill.SignedQuantity;
			int after = before + delta;

			// NT8 reports the signed position after the fill. If it disagrees, our sum is out of step.
			if (fill.PositionAfter.HasValue && fill.PositionAfter.Value != after)
			{
				return new ApplyResult(ApplyStatus.PositionMismatch)
				{
					ComputedPosition = after,
					ReportedPosition = fill.PositionAfter.Value
				};
			}

			// Flat, no position figure, and flagged as an exit: we missed the entry. Never open a trade from it.
			if (before == 0 && !fill.PositionAfter.HasValue && fill.IsExit && !fill.IsEntry)
			{
				seen.Add(seenKey);
				return new ApplyResult(ApplyStatus.OrphanExit);
			}

			seen.Add(seenKey);
			ApplyResult result = new ApplyResult(ApplyStatus.Applied);

			if (before == 0 || Math.Sign(before) == Math.Sign(delta))
			{
				bool opening = state.Trade == null;
				if (opening)
					state.Trade = new OpenTrade { Direction = delta > 0 ? Direction.Long : Direction.Short };
				AddFill(state.Trade, fill, FillRole.Entry, fill.Quantity, state.Trade.Signed + delta);
				state.Signed = after;
				if (opening)
					result.Opened = Describe(state);
				return result;
			}

			// Opposite direction: reduces, flattens, or flips the position.
			int closing = Math.Min(fill.Quantity, Math.Abs(before));
			int remainder = fill.Quantity - closing;
			int sign = Math.Sign(delta);

			AddFill(state.Trade, fill, FillRole.Exit, closing, state.Trade.Signed + sign * closing);

			if (after == 0 || remainder > 0)
			{
				OpenTrade finished = state.Trade;
				result.Closed.Add(TradeCalculator.Complete(state.Account, state.Instrument, finished.Direction,
					finished.MaxQuantity, finished.Fills, finished.Excursion.Interrupted, finished.OpenedByReversal));
				state.Trade = null;
			}

			if (remainder > 0)
			{
				// The flipping fill also opens the new trade with the quantity left over.
				state.Trade = new OpenTrade { Direction = delta > 0 ? Direction.Long : Direction.Short, OpenedByReversal = true };
				AddFill(state.Trade, fill, FillRole.Entry, remainder, sign * remainder);
				result.Opened = Describe(state);
			}

			state.Signed = after;
			return result;
		}

		/// <summary>Feeds a live last-trade price to every open trade in that instrument.</summary>
		public void OnPrice(string instrumentFullName, decimal price)
		{
			foreach (PositionState state in states.Values)
			{
				if (state.Trade != null && state.Instrument.FullName == instrumentFullName)
					state.Trade.Excursion.AddTick(price);
			}
		}

		/// <summary>
		/// Flags open trades whose price feed was interrupted (disconnect, or restart while open).
		/// Null instrument means every open trade.
		/// </summary>
		public void MarkFeedInterrupted(string instrumentFullName)
		{
			foreach (PositionState state in states.Values)
			{
				if (state.Trade != null && (instrumentFullName == null || state.Instrument.FullName == instrumentFullName))
					state.Trade.Excursion.Interrupted = true;
			}
		}

		/// <summary>
		/// Rebuilds one account/instrument from its fills (for example <c>Account.Executions</c>, in order) and
		/// checks the result against the position NT8 reports now. If the fills do not add up to
		/// <paramref name="expectedPosition"/>, nothing is changed and the result says so.
		/// </summary>
		public RebuildResult Rebuild(string account, string instrumentFullName, IEnumerable<Fill> fills, int expectedPosition)
		{
			TradeTracker replay = new TradeTracker();
			List<CompletedTrade> closed = new List<CompletedTrade>();
			foreach (Fill fill in fills)
			{
				if (fill.Account != account || fill.Instrument.FullName != instrumentFullName)
					continue;
				closed.AddRange(replay.Apply(fill).Closed);
			}

			int computed = replay.Position(account, instrumentFullName);
			RebuildResult result = new RebuildResult
			{
				Consistent = computed == expectedPosition,
				ComputedPosition = computed,
				Closed = closed
			};
			if (!result.Consistent)
				return result;

			string key = Key(account, instrumentFullName);
			PositionState rebuilt;
			replay.states.TryGetValue(key, out rebuilt);

			// Keep the price range already observed for the same open trade.
			PositionState previous;
			if (rebuilt != null && rebuilt.Trade != null && states.TryGetValue(key, out previous) && previous.Trade != null)
			{
				if (previous.Trade.TradeId == rebuilt.Trade.TradeId)
					rebuilt.Trade.Excursion = previous.Trade.Excursion;
				else
					rebuilt.Trade.Excursion.Interrupted = true;
			}
			else if (rebuilt != null && rebuilt.Trade != null)
			{
				// Rebuilt with no live prices seen for it.
				rebuilt.Trade.Excursion.Interrupted = true;
			}

			if (rebuilt == null)
				states.Remove(key);
			else
				states[key] = rebuilt;

			foreach (string id in replay.seen)
				seen.Add(id);

			result.Open = rebuilt != null && rebuilt.Trade != null ? Describe(rebuilt) : null;
			return result;
		}

		/// <summary>
		/// After <see cref="Rebuild"/> reconstructs an open trade from <c>Account.Executions</c>, folds in the
		/// live tick range that was observed before an AddOn restart (only the persisted state file has that; it
		/// is not in NT8's own records). Also flags the trade's feed as interrupted, since the gap itself was not
		/// observed either way. A no-op if there is no open trade for the account/instrument, or a different
		/// trade opened while the state file was stale.
		/// <para>
		/// <paramref name="fillExcursions"/>, when given, holds each persisted fill's own running high/low by
		/// ExecutionId. Leg MAE/MFE is measured up to that leg's final fill, so a leg that exited before the restart
		/// gets its own persisted range back; a fill not in the map happened after the save and gets the whole
		/// persisted range, which was all observed before it.
		/// </para>
		/// </summary>
		public void RestoreExcursion(string account, string instrumentFullName, string tradeId, ExcursionSnapshot snapshot,
			IDictionary<string, ExcursionSnapshot> fillExcursions = null)
		{
			PositionState state;
			if (!states.TryGetValue(Key(account, instrumentFullName), out state) || state.Trade == null)
				return;
			if (state.Trade.TradeId != tradeId)
				return;

			state.Trade.Excursion.Merge(snapshot);
			state.Trade.Excursion.Interrupted = true;

			if (fillExcursions != null)
				RestoreFillExcursions(state.Trade.Fills, snapshot, fillExcursions);
		}

		/// <summary>The per-fill half of <see cref="RestoreExcursion"/>, also used for a trade that closed while its
		/// ticks were not being observed.</summary>
		internal static void RestoreFillExcursions(IList<TradeFill> fills, ExcursionSnapshot snapshot,
			IDictionary<string, ExcursionSnapshot> fillExcursions)
		{
			foreach (TradeFill fill in fills)
			{
				ExcursionSnapshot persisted;
				if (!fillExcursions.TryGetValue(fill.Fill.ExecutionId, out persisted))
					persisted = snapshot;
				fill.Excursion = ExcursionSnapshot.Combine(fill.Excursion, persisted);
			}
		}

		private static void AddFill(OpenTrade trade, Fill fill, FillRole role, int allocated, int signedAfter)
		{
			decimal share = (decimal)allocated / fill.Quantity;
			trade.Excursion.AddFill(fill.Price);
			trade.Fills.Add(new TradeFill
			{
				Fill = fill,
				Role = role,
				AllocatedQuantity = allocated,
				Commission = allocated == fill.Quantity ? fill.Commission : fill.Commission * allocated / fill.Quantity,
				Fee = allocated == fill.Quantity ? fill.Fee : fill.Fee * allocated / fill.Quantity,
				PositionAfter = signedAfter,
				Excursion = trade.Excursion.Snapshot()
			});
			trade.Signed = signedAfter;
			if (Math.Abs(signedAfter) > trade.MaxQuantity)
				trade.MaxQuantity = Math.Abs(signedAfter);
			if (trade.TradeId == null && role == FillRole.Entry)
				trade.TradeId = TradeIds.Create(fill.Account, fill.Instrument.FullName, fill.ExecutionId);
		}

		private static OpenTradeInfo Describe(PositionState state)
		{
			OpenTrade trade = state.Trade;
			return new OpenTradeInfo
			{
				TradeId = trade.TradeId,
				Account = state.Account,
				Instrument = state.Instrument,
				Direction = trade.Direction,
				EntryAt = trade.Fills[0].Fill.Time,
				SignedPosition = trade.Signed,
				MaxQuantity = trade.MaxQuantity,
				FeedInterrupted = trade.Excursion.Interrupted,
				OpenedByReversal = trade.OpenedByReversal,
				Excursion = trade.Excursion.Snapshot(),
				Fills = new List<TradeFill>(trade.Fills)
			};
		}
	}
}
