// Chart Jot AddOn - phase 3: settings, staging, the chart form, Submit, and
// chart images (#64, #37/#52).
//
// Wires NT8's real account/execution/connection events into addon/core's
// TradeTracker/AddonState (ChartJot.Core.dll). Closed trades are staged after
// the commission settle period. Each chart gets a Chart Jot form that follows its
// Chart Trader account/instrument: one note and a trade type, Submit and Reset.
// Submit sends the master trade plus each copier follower's own trade, all with
// the master's note, type and chart images; a background pump delivers them.
// Chart images: the exit image about 1s after flat (Recapture replaces it) and,
// when enabled in Settings, the entry image about 1s after the fill, each from
// the chart whose visible tab shows the trade. No live tick subscription for
// MAE/MFE yet, so excursion stays incomplete.
//
// Form:     the "Chart Jot" button on each chart's toolbar
// Settings: Control Center -> New -> Chart Jot Settings
//           (stored in %USERPROFILE%\ChartJot\settings.json, token DPAPI-encrypted)
// State:    <Data folder>\state.json (default %USERPROFILE%\ChartJot\)
// Log:      %LOCALAPPDATA%\ChartJot\nt8\chartjot-YYYYMMDD.log
// Remove:   delete this file (and ChartJot.Core.dll from bin\Custom\) and recompile.

#region Using declarations
using System;
using System.Collections.Generic;
using System.ComponentModel;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Reflection;
using System.Runtime.InteropServices;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media.Imaging;
using ChartJot.Core;
using NinjaTrader.Cbi;
using NinjaTrader.Gui;
using NinjaTrader.Gui.Tools;
#endregion

namespace NinjaTrader.NinjaScript.AddOns
{
	public class ChartJot : AddOnBase
	{
		private const string ChartButtonName = "ChartJotFormButton";

		protected override void OnStateChange()
		{
			if (State == State.SetDefaults)
			{
				Description	= "Chart Jot journal AddOn";
				Name		= "ChartJot";
			}
			else if (State == State.Terminated)
			{
				ChartJotMonitor.Stop();
			}
		}

		protected override void OnWindowCreated(Window window)
		{
			// Starting here matches the timing the spike already verified works (NT8's account/window
			// plumbing is up by the time a window is created).
			ChartJotMonitor.Start();

			// Settings are opened from the form's Settings button, so nothing is added to Control Center.
			NinjaTrader.Gui.Chart.Chart chart = window as NinjaTrader.Gui.Chart.Chart;
			if (chart != null)
				AddChartButton(chart);
		}

		protected override void OnWindowDestroyed(Window window)
		{
			NinjaTrader.Gui.Chart.Chart chart = window as NinjaTrader.Gui.Chart.Chart;
			if (chart != null)
			{
				ChartJotCopier.Untrack(chart);
				ChartJotFormWindow.CloseFor(chart);
			}
		}

		/// <summary>
		/// A "Chart Jot" button opens the chart's form: placed at the bottom of the Chart Trader panel, or, when that
		/// panel's layout is not available, at the very end of the chart toolbar.
		/// </summary>
		private static void AddChartButton(NinjaTrader.Gui.Chart.Chart chart)
		{
			ChartJotCopier.Track(chart);
			PlaceChartButton(chart, 0);
		}

		// Chart Trader is built after the chart window, so try a few times before falling back to the toolbar.
		private static void PlaceChartButton(NinjaTrader.Gui.Chart.Chart chart, int attempt)
		{
			chart.Dispatcher.InvokeAsync(() =>
			{
				try
				{
					Grid panel = chart.ChartTrader == null ? null : chart.ChartTrader.Content as Grid;
					if (panel != null && panel.Children.OfType<FrameworkElement>().Any(e => e.Name == ChartButtonName))
						return;
					if (chart.MainMenu.OfType<FrameworkElement>().Any(e => e.Name == ChartButtonName))
						return;

					// Only a grid laid out in rows can take one more row without overlapping what is there.
					if (panel != null && panel.RowDefinitions.Count > 0)
					{
						Button button = NewChartButton(chart);
						button.Margin = new Thickness(4, 6, 4, 4);
						button.Padding = new Thickness(6, 3, 6, 3);
						panel.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });
						Grid.SetRow(button, panel.RowDefinitions.Count - 1);
						Grid.SetColumn(button, 0);
						Grid.SetColumnSpan(button, Math.Max(1, panel.ColumnDefinitions.Count));
						panel.Children.Add(button);
						ChartJotLog.Write("UI", "Chart Jot button added to the Chart Trader panel");
						return;
					}

					if (attempt < 5)
					{
						Task.Delay(TimeSpan.FromSeconds(1)).ContinueWith(t => PlaceChartButton(chart, attempt + 1));
						return;
					}

					ChartJotLog.Write("UI", "Chart Trader panel layout not available (content: "
						+ (chart.ChartTrader == null || chart.ChartTrader.Content == null ? "none" : chart.ChartTrader.Content.GetType().Name)
						+ "); Chart Jot button added to the end of the toolbar");
					Button toolbarButton = NewChartButton(chart);
					toolbarButton.Margin = new Thickness(2, 0, 2, 0);
					toolbarButton.Padding = new Thickness(6, 0, 6, 0);
					chart.MainMenu.Add(toolbarButton);
					KeepLast(chart, toolbarButton);
				}
				catch (Exception ex)
				{
					ChartJotLog.Write("UI", "Could not add the Chart Jot button to a chart: " + ex.Message);
				}
			});
		}

		private static Button NewChartButton(NinjaTrader.Gui.Chart.Chart chart)
		{
			Button button = new Button
			{
				Name	= ChartButtonName,
				Content	= "Chart Jot",
				ToolTip	= "Open the Chart Jot note form for this chart's Chart Trader account"
			};
			button.Click += (s, e) => ChartJotFormWindow.ShowFor(chart);
			return button;
		}

		/// <summary>Other add-ons and indicators add toolbar items later; move the button back to the end each time.</summary>
		private static void KeepLast(NinjaTrader.Gui.Chart.Chart chart, Button button)
		{
			chart.MainMenu.CollectionChanged += (s, e) =>
			{
				int index = chart.MainMenu.IndexOf(button);
				if (index >= 0 && index != chart.MainMenu.Count - 1)
				{
					// The collection cannot change inside its own change event.
					chart.Dispatcher.InvokeAsync(() =>
					{
						int now = chart.MainMenu.IndexOf(button);
						if (now >= 0 && now != chart.MainMenu.Count - 1)
							chart.MainMenu.Move(now, chart.MainMenu.Count - 1);
					});
				}
			};
		}
	}

	// ---------------------------------------------------------------- logging

	public static class ChartJotLog
	{
		private static readonly object sync = new object();
		private static long sequence;
		private static readonly DateTime startedAt = DateTime.Now;
		private static readonly System.Diagnostics.Stopwatch clock = System.Diagnostics.Stopwatch.StartNew();

		public static string Folder
		{
			get
			{
				string dir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "ChartJot", "nt8");
				Directory.CreateDirectory(dir);
				return dir;
			}
		}

		public static void Write(string category, string message)
		{
			lock (sync)
			{
				sequence++;
				DateTime now = startedAt.AddTicks(clock.Elapsed.Ticks);
				string line = string.Format(CultureInfo.InvariantCulture, "{0:yyyy-MM-dd HH:mm:ss.fff} #{1:D6} [{2}] {3}",
					now, sequence, category, message);
				try
				{
					File.AppendAllText(Path.Combine(Folder, "chartjot-" + DateTime.Now.ToString("yyyyMMdd") + ".log"), line + Environment.NewLine);
				}
				catch
				{
					// A failed log write must never take NT8 down with it.
				}
			}
		}
	}

	// ------------------------------------------------------- account monitor

	/// <summary>
	/// Subscribes every account's execution/position events, applies fills to addon/core's TradeTracker,
	/// and reconciles against Account.Executions on startup and every reconnect (NT8.md, "Persistence of
	/// open state"). Locking discipline follows spike/ChartJotSpike.cs exactly: one lock guards shared
	/// state, a second serializes the startup scan against the connect scan, and nothing that can block
	/// (event subscribe/unsubscribe, Account.Executions, AddonState.Save) runs while holding either.
	/// </summary>
	public static class ChartJotMonitor
	{
		private static readonly object sync = new object();
		private static readonly object scanGate = new object();
		private static readonly HashSet<string> subscribedAccounts = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
		private static readonly object saveGate = new object();
		private static bool started;
		private static AddonState state;
		private static Delegate connectionHandler;

		/// <summary>Replaced wholesale (never mutated in place) by <see cref="ApplySettings"/>.</summary>
		private static volatile AddonSettings settings;

		public static string UserProfile
		{
			get { return Environment.GetFolderPath(Environment.SpecialFolder.UserProfile); }
		}

		private static string StatePath
		{
			get { return settings.StatePath; }
		}

		public static AddonSettings Settings
		{
			get { return settings ?? LoadSettings(); }
		}

		public static void Start()
		{
			lock (sync)
			{
				if (started)
					return;
				started = true;
			}

			settings = LoadSettings();
			state = LoadState();
			PruneUnsubmitted();
			SubscribeConnectionStatus();
			SubscribeAccounts("startup");
			ChartJotDelivery.Start();
		}

		public static void Stop()
		{
			ChartJotDelivery.Stop();
			UnsubscribeConnectionStatus();

			List<Account> toUnsubscribe;
			lock (sync)
			{
				toUnsubscribe = Account.All.Where(a => subscribedAccounts.Contains(a.Name)).ToList();
				subscribedAccounts.Clear();
			}

			// Outside the lock: NT8 event unsubscribe can block.
			foreach (Account account in toUnsubscribe)
			{
				account.ExecutionUpdate -= OnExecutionUpdate;
				account.PositionUpdate -= OnPositionUpdate;
			}
		}

		private static AddonState LoadState()
		{
			try
			{
				return AddonState.Load(StatePath);
			}
			catch (Exception ex)
			{
				ChartJotLog.Write("STATE", "Failed to load state file, starting fresh: " + ex.Message);
				return new AddonState();
			}
		}

		private static AddonSettings LoadSettings()
		{
			try
			{
				return AddonSettings.Load(UserProfile);
			}
			catch (Exception ex)
			{
				ChartJotLog.Write("SETTINGS", "Failed to load settings, using defaults: " + ex.Message);
				return AddonSettings.Defaults(UserProfile);
			}
		}

		/// <summary>
		/// Saves new settings and switches to them. A changed data folder takes effect immediately: the current
		/// state is written into the new folder, and the old state file is left where it was as a backup.
		/// </summary>
		public static void ApplySettings(AddonSettings updated)
		{
			if (updated == null)
				throw new ArgumentNullException("updated");

			updated.Save(UserProfile);
			string oldPath = settings == null ? null : settings.StatePath;
			settings = updated;
			ChartJotLog.Write("SETTINGS", "Saved. endpoint=" + (updated.EndpointUrl ?? "(none)") + " token=" + (updated.HasToken ? "set" : "missing")
				+ " dataFolder=" + updated.DataFolder);

			if (state != null && !string.Equals(oldPath, updated.StatePath, StringComparison.OrdinalIgnoreCase))
			{
				SaveState();
				ChartJotLog.Write("SETTINGS", "Data folder changed; state now saved to " + updated.StatePath + " (previous file kept at " + oldPath + ")");
			}
			ChartJotDelivery.Kick();
		}

		/// <summary>Serializes under the state lock (so no event handler mutates it mid-write) and writes outside it.</summary>
		private static void SaveState()
		{
			try
			{
				string json;
				lock (sync)
					json = state.Serialize();
				lock (saveGate)
					StateFile.WriteAtomic(StatePath, json);
			}
			catch (Exception ex)
			{
				ChartJotLog.Write("STATE", "Failed to save state file: " + ex.Message);
			}
		}

		// ---- staging (NT8.md: wait a short settle period after flat, re-read commissions, then stage)

		private static readonly TimeSpan SettlePeriod = TimeSpan.FromSeconds(2);

		private static void StageAfterSettle(string accountName, string tradeId)
		{
			Task.Delay(SettlePeriod).ContinueWith(t => StageNow(accountName, tradeId));
		}

		private static void StageNow(string accountName, string tradeId)
		{
			try
			{
				// Account.Executions can block, so it is read before taking the lock.
				Account account = Account.All.FirstOrDefault(a => a.Name == accountName);
				Dictionary<string, FillCharges> charges = new Dictionary<string, FillCharges>();
				if (account != null)
				{
					foreach (Execution execution in account.Executions)
						charges[execution.ExecutionId] = new FillCharges { Commission = (decimal)execution.Commission, Fee = (decimal)execution.Fee };
				}

				bool staged = false;
				bool refreshed = false;
				lock (sync)
				{
					if (state.AwaitingStage.Any(t => t.TradeId == tradeId))
					{
						FillCharges found;
						refreshed = state.RefreshCharges(tradeId, id => charges.TryGetValue(id, out found) ? found : null);
						state.Stage(tradeId, DateTimeOffset.Now);
						staged = true;
					}
				}

				if (staged)
				{
					SaveState();
					ChartJotLog.Write("TRADE", "staged for review trade_id=" + tradeId + (refreshed ? " (commissions refreshed)" : ""));
				}
			}
			catch (Exception ex)
			{
				ChartJotLog.Write("ERROR", "StageNow " + tradeId + ": " + ex);
			}
		}

		/// <summary>Stages everything still waiting from before a restart, or closed while NT8 was not watching.</summary>
		private static void StageAllAwaiting()
		{
			List<CompletedTrade> waiting;
			lock (sync)
				waiting = state.AwaitingStage.ToList();
			foreach (CompletedTrade trade in waiting)
				StageNow(trade.Account, trade.TradeId);
		}

		// ---- submission (called by the note panel, #64's second PR)

		public const string AddonVersion = "0.4.0";

		/// <summary>
		/// The trader clicked Submit trade. Freezes the staged trade into the delivery queue and wakes the delivery
		/// pump. Throws (with a message fit for the panel) when the trade is not staged, already submitted, or has no
		/// valid trade type.
		/// </summary>
		public static QueuedDelivery Submit(string tradeId)
		{
			string accountName;
			lock (sync)
			{
				StagedTrade staged = state.Staged.Find(tradeId);
				accountName = staged == null ? null : staged.Trade.Account;
			}

			Account account = accountName == null ? null : Account.All.FirstOrDefault(a => a.Name == accountName);
			string connection = account != null && account.Connection != null && account.Connection.Options != null
				? account.Connection.Options.Name
				: null;

			QueuedDelivery queued;
			lock (sync)
				queued = state.Submit(tradeId, AddonVersion, connection);
			SaveState();
			ChartJotLog.Write("SUBMIT", "queued trade_id=" + tradeId);
			ChartJotDelivery.Kick();
			return queued;
		}

		/// <summary>The trader asked to retry a failed delivery unchanged (token fixed, or the server issue resolved).</summary>
		public static void RetryDelivery(string tradeId)
		{
			lock (sync)
				state.Deliveries.RetryManually(tradeId, DateTimeOffset.Now);
			SaveState();
			ChartJotDelivery.Kick();
		}

		// ---- delivery pump access (ChartJotDelivery). DeliveryQueue is not thread-safe, so every touch is under sync.

		internal static QueuedDelivery TakeNextDue()
		{
			QueuedDelivery due;
			lock (sync)
			{
				if (state == null)
					return null;
				due = state.Deliveries.Due(DateTimeOffset.Now).FirstOrDefault();
				if (due != null)
					state.Deliveries.MarkSending(due.TradeId);
			}
			if (due != null)
				SaveState();
			return due;
		}

		internal static void RecordDelivery(string tradeId, DeliveryAttempt attempt)
		{
			IList<string> removed;
			lock (sync)
			{
				state.Deliveries.RecordResult(tradeId, attempt.Outcome, DateTimeOffset.Now, attempt.StatusCode, attempt.ServerBody, attempt.ErrorMessage);
				removed = state.RemoveSent();
			}
			SaveState();
			if (removed.Count > 0)
				ChartJotLog.Write("DELIVERY", "removed from review list (sent): " + string.Join(", ", removed));
		}

		// ---- trade form access (ChartJotFormWindow). Every read and write of the state happens under sync;
		// the window only ever gets copies.

		/// <summary>Staged trades nobody submitted are dropped after this long (wall clock since staging).</summary>
		public static readonly TimeSpan UnsubmittedMaxAge = TimeSpan.FromHours(24);

		public static FormView Form(string accountName, string instrumentFullName)
		{
			FormView view = new FormView();
			lock (sync)
			{
				if (state == null)
					return view;

				TradeForm form = state.FormFor(accountName, instrumentFullName);
				view.Body = form.Body;
				view.TradeType = form.TradeType;
				view.TradeTypeOther = form.TradeTypeOther;

				OpenTradeInfo open = state.Tracker.OpenTrades.FirstOrDefault(t => t.Account == accountName && t.Instrument.FullName == instrumentFullName);
				if (open != null)
				{
					view.IsOpen = true;
					view.OpenDirection = open.Direction;
					view.OpenQuantity = Math.Abs(open.SignedPosition);
					view.OpenEntryAt = open.EntryAt;
				}

				IList<CompletedTrade> pending = state.PendingForForm(accountName, instrumentFullName);
				view.ClosedCount = pending.Count;
				if (pending.Count > 0)
				{
					view.ClosedDirection = pending[pending.Count - 1].Direction;
					view.ClosedNetPnl = pending.Sum(t => t.NetPnl);
					view.LatestClosedTradeId = pending[pending.Count - 1].TradeId;
					TradeImages images = state.ImagesFor(view.LatestClosedTradeId);
					view.HasExitImage = images.Exit != null;
					view.HasEntryImage = images.Entry != null;
				}
				view.CanSubmit = state.CanSubmitForm(accountName, instrumentFullName);

				foreach (string tradeId in form.LastSubmitted ?? new List<string>())
				{
					QueuedDelivery delivery = state.Deliveries.Find(tradeId);
					if (delivery != null)
						view.LastSubmitted.Add(new SubmittedView { TradeId = tradeId, State = delivery.State, Attempts = delivery.Attempts,
							StatusCode = delivery.LastStatusCode, IsConfigurationError = delivery.IsConfigurationError, Detail = DeliveryDetail(delivery) });
				}
			}
			return view;
		}

		/// <summary>Records a captured chart image (#52); false when the trade can no longer take one.</summary>
		public static bool RecordCapture(string tradeId, bool entry, ScreenshotMeta meta)
		{
			bool recorded;
			lock (sync)
				recorded = state != null && state.RecordCapture(tradeId, entry, meta);
			if (recorded)
				SaveState();
			return recorded;
		}

		// Forms already opened in this NT session (account|instrument).
		private static readonly HashSet<string> formCyclesStarted = new HashSet<string>(StringComparer.Ordinal);

		/// <summary>
		/// The first time a form opens for an account/instrument in this NT session, it starts caring about trades
		/// from now: trades closed before it was opened are never offered or sent.
		/// </summary>
		public static void StartFormCycleOnce(string accountName, string instrumentFullName)
		{
			lock (sync)
			{
				if (state == null || !formCyclesStarted.Add(accountName + "|" + instrumentFullName))
					return;
				state.StartFormCycle(accountName, instrumentFullName, DateTimeOffset.Now);
			}
			SaveState();
		}

		public static void UpdateForm(string accountName, string instrumentFullName, string body, string tradeType, string tradeTypeOther)
		{
			lock (sync)
				state.UpdateForm(accountName, instrumentFullName, body, tradeType, tradeTypeOther, DateTimeOffset.Now);
			SaveState();
		}

		/// <summary>
		/// Submit from a chart's form. Re-reads commissions, finds each copier follower's own trade for the master
		/// trade(s) (<paramref name="followers"/> is the copier's follower list for this master; empty when no
		/// copier is in use), and sends them all with the form's note and trade type. Returns a short summary for
		/// the status line. Throws with a message fit for the form when Submit is not possible.
		/// </summary>
		public static string SubmitForm(string accountName, string instrumentFullName, IList<FollowerSetup> followers)
		{
			Dictionary<string, FillCharges> charges = CurrentCharges();
			Dictionary<string, string> connections = ConnectionNames();

			IList<QueuedDelivery> queued;
			Dictionary<string, string> followerMaster = new Dictionary<string, string>();
			int followerCount;
			lock (sync)
			{
				FillCharges found;
				Func<string, FillCharges> lookup = id => charges.TryGetValue(id, out found) ? found : null;

				foreach (CompletedTrade pending in state.PendingForForm(accountName, instrumentFullName))
					state.RefreshCharges(pending.TradeId, lookup);
				IList<CompletedTrade> masters = state.PendingForForm(accountName, instrumentFullName);

				List<string> followerIds = new List<string>();
				if (followers != null && followers.Count > 0)
				{
					IList<CompletedTrade> candidates = state.FollowerCandidates(accountName);
					foreach (CompletedTrade master in masters)
					{
						foreach (CompletedTrade match in FollowerMatcher.Match(master, followers, candidates, FollowerMatcher.DefaultWindow))
						{
							if (followerIds.Contains(match.TradeId))
								continue;
							state.RefreshCharges(match.TradeId, lookup);
							followerIds.Add(match.TradeId);
							followerMaster[match.TradeId] = master.TradeId;
						}
					}
				}

				queued = state.SubmitForm(accountName, instrumentFullName, AddonVersion,
					name => { string connection; return connections.TryGetValue(name, out connection) ? connection : null; },
					followerIds, DateTimeOffset.Now, id => { string master; return followerMaster.TryGetValue(id, out master) ? master : null; });
				followerCount = followerIds.Count;
			}
			// Each follower sends the master's image files under its own trade_id.
			foreach (KeyValuePair<string, string> pair in followerMaster)
				ChartJotCapture.CopyImages(pair.Value, pair.Key);
			SaveState();
			ChartJotLog.Write("SUBMIT", string.Format(CultureInfo.InvariantCulture, "form account={0} instrument={1} trades={2} followers={3} trade_ids={4}",
				accountName, instrumentFullName, queued.Count - followerCount, followerCount, string.Join(",", queued.Select(q => q.TradeId))));
			ChartJotDelivery.Kick();

			int masterCount = queued.Count - followerCount;
			return "Submitted " + masterCount.ToString(CultureInfo.InvariantCulture) + (masterCount == 1 ? " trade" : " trades")
				+ (followerCount > 0 ? " + " + followerCount.ToString(CultureInfo.InvariantCulture) + (followerCount == 1 ? " follower" : " followers") : "");
		}

		/// <summary>Reset from a chart's form: clears it and drops that account/instrument's unsubmitted closed trades.</summary>
		public static int ResetForm(string accountName, string instrumentFullName)
		{
			IList<string> dropped;
			lock (sync)
				dropped = state.ResetForm(accountName, instrumentFullName, DateTimeOffset.Now);
			SaveState();
			ChartJotLog.Write("FORM", "reset account=" + accountName + " instrument=" + instrumentFullName
				+ (dropped.Count > 0 ? " dropped unsubmitted trade_ids=" + string.Join(",", dropped) : ""));
			return dropped.Count;
		}

		/// <summary>Retries every failed delivery among the form's last submission.</summary>
		public static void RetryLastSubmission(string accountName, string instrumentFullName)
		{
			List<string> failed;
			lock (sync)
			{
				failed = (state.FormFor(accountName, instrumentFullName).LastSubmitted ?? new List<string>())
					.Where(id => { QueuedDelivery d = state.Deliveries.Find(id); return d != null && d.State == DeliveryState.Failed; })
					.ToList();
				foreach (string tradeId in failed)
					state.Deliveries.RetryManually(tradeId, DateTimeOffset.Now);
			}
			SaveState();
			ChartJotDelivery.Kick();
		}

		private static void PruneUnsubmitted()
		{
			IList<string> dropped;
			lock (sync)
				dropped = state.PruneUnsubmitted(DateTimeOffset.Now, UnsubmittedMaxAge);
			if (dropped.Count == 0)
				return;
			SaveState();
			ChartJotLog.Write("STATE", "dropped " + dropped.Count.ToString(CultureInfo.InvariantCulture)
				+ " closed trade(s) nobody submitted within " + UnsubmittedMaxAge.TotalHours.ToString(CultureInfo.InvariantCulture) + "h");
		}

		// Every account's current per-fill charges. Account.Executions can block, so never call this under sync.
		private static Dictionary<string, FillCharges> CurrentCharges()
		{
			Dictionary<string, FillCharges> charges = new Dictionary<string, FillCharges>();
			foreach (Account account in Account.All)
			{
				foreach (Execution execution in account.Executions)
					charges[execution.ExecutionId] = new FillCharges { Commission = (decimal)execution.Commission, Fee = (decimal)execution.Fee };
			}
			return charges;
		}

		// Account name -> NT8 connection name, read before taking sync.
		private static Dictionary<string, string> ConnectionNames()
		{
			Dictionary<string, string> names = new Dictionary<string, string>();
			foreach (Account account in Account.All)
				names[account.Name] = account.Connection != null && account.Connection.Options != null ? account.Connection.Options.Name : null;
			return names;
		}

		private static string DeliveryDetail(QueuedDelivery delivery)
		{
			string detail = delivery.LastErrorMessage ?? delivery.LastServerBody;
			if (string.IsNullOrWhiteSpace(detail))
				return null;
			detail = detail.Trim();
			return detail.Length > 300 ? detail.Substring(0, 300) + "..." : detail;
		}
		// ---- account/instrument scan (startup and every reconnect)

		private static void SubscribeAccounts(string reason)
		{
			// Serializes the startup scan against a reconnect scan (NT8.md: "serialize the startup and
			// connect scans"), not against the per-fill/per-position event handlers, which use sync instead.
			lock (scanGate)
				SubscribeAccountsCore(reason);
		}

		private static void SubscribeAccountsCore(string reason)
		{
			List<Account> toSubscribe = new List<Account>();
			lock (sync)
			{
				foreach (Account account in Account.All)
				{
					if (subscribedAccounts.Add(account.Name))
						toSubscribe.Add(account);
				}
			}

			// Outside the lock: NT8 event subscribe can block.
			foreach (Account account in toSubscribe)
			{
				account.ExecutionUpdate	+= OnExecutionUpdate;
				account.PositionUpdate		+= OnPositionUpdate;
			}

			foreach (Account account in Account.All)
				ReconcileAccount(account, reason);

			// Trades closed while away (and any left from before a restart) have long passed the settle period.
			StageAllAwaiting();
		}

		private static void ReconcileAccount(Account account, string reason)
		{
			foreach (IGrouping<string, Execution> group in account.Executions.GroupBy(e => e.Instrument.FullName))
			{
				string instrumentFullName = group.Key;
				List<Fill> fills = group.OrderBy(e => e.Time).Select(ToFill).ToList();
				int reportedPosition = SignedPosition(account, instrumentFullName);

				ReconcileResult result;
				lock (sync)
					result = state.Reconcile(account.Name, instrumentFullName, fills, reportedPosition);
				SaveState();

				ChartJotLog.Write("RECONCILE", string.Format(CultureInfo.InvariantCulture,
					"reason={0} account={1} instrument={2} consistent={3} resumed={4} closedWhileAway={5} unresolved={6}",
					reason, account.Name, instrumentFullName, result.Consistent, result.Resumed,
					result.ClosedWhileAway.Count, result.UnresolvedTradeIds.Count));

				if (!result.Consistent)
					ChartJotLog.Write("RECONCILE", string.Format(CultureInfo.InvariantCulture,
						"MISMATCH account={0} instrument={1} computed={2} reported={3}",
						account.Name, instrumentFullName, result.ComputedPosition, result.ReportedPosition));

				foreach (CompletedTrade closed in result.ClosedWhileAway)
					ChartJotLog.Write("TRADE", "closed while away trade_id=" + closed.TradeId + " net_pnl=" + closed.NetPnl.ToString(CultureInfo.InvariantCulture));
			}
		}

		private static int SignedPosition(Account account, string instrumentFullName)
		{
			Position position = account.Positions.FirstOrDefault(p => p.Instrument.FullName == instrumentFullName);
			if (position == null || position.MarketPosition == MarketPosition.Flat)
				return 0;
			return position.MarketPosition == MarketPosition.Long ? position.Quantity : -position.Quantity;
		}

		// ---- live events

		private static void OnExecutionUpdate(object sender, ExecutionEventArgs e)
		{
			try
			{
				Execution execution = e.Execution;
				if (execution == null)
					return;

				Fill fill = ToFill(execution);
				ApplyResult result;
				lock (sync)
					result = state.Tracker.Apply(fill);

				ChartJotLog.Write("FILL", string.Format(CultureInfo.InvariantCulture,
					"account={0} instrument={1} id={2} status={3}",
					fill.Account, fill.Instrument.FullName, fill.ExecutionId, result.Status));

				if (result.Status == ApplyStatus.PositionMismatch)
				{
					ChartJotLog.Write("FILL", string.Format(CultureInfo.InvariantCulture,
						"position mismatch, reconciling: computed={0} reported={1}", result.ComputedPosition, result.ReportedPosition));
					ReconcileAccount(execution.Account, "mismatch");
					return;
				}

				if (result.Opened != null)
				{
					lock (sync)
						state.AttachPendingNotes(result.Opened);
					if (Settings.CaptureEntryImage)
						ChartJotCapture.CaptureLater(result.Opened.Account, result.Opened.Instrument.FullName, result.Opened.TradeId, true);
				}

				foreach (CompletedTrade closed in result.Closed)
				{
					lock (sync)
						state.RecordClosed(closed);
					ChartJotLog.Write("TRADE", "closed trade_id=" + closed.TradeId + " net_pnl=" + closed.NetPnl.ToString(CultureInfo.InvariantCulture));
					ChartJotCapture.CaptureLater(closed.Account, closed.Instrument.FullName, closed.TradeId, false);
					StageAfterSettle(closed.Account, closed.TradeId);
				}

				SaveState();
			}
			catch (Exception ex)
			{
				// An AddOn bug must never escape into NT8's event pipeline.
				ChartJotLog.Write("ERROR", "OnExecutionUpdate: " + ex);
			}
		}

		private static void OnPositionUpdate(object sender, PositionEventArgs e)
		{
			try
			{
				// Logged for visibility only in this phase: ExecutionUpdate already drives the tracker,
				// and NT8.md's reconcile-on-mismatch path (above) is what actually corrects drift.
				ChartJotLog.Write("POSITION", string.Format(CultureInfo.InvariantCulture,
					"account={0} instrument={1} marketPosition={2} qty={3}",
					e.Position != null && e.Position.Account != null ? e.Position.Account.Name : "?",
					e.Position != null && e.Position.Instrument != null ? e.Position.Instrument.FullName : "?",
					e.MarketPosition, e.Quantity));
			}
			catch (Exception ex)
			{
				ChartJotLog.Write("ERROR", "OnPositionUpdate: " + ex);
			}
		}

		private static Fill ToFill(Execution execution)
		{
			TimeZoneInfo tz = NinjaTrader.Core.Globals.GeneralOptions.TimeZoneInfo;
			DateTime local = DateTime.SpecifyKind(execution.Time, DateTimeKind.Unspecified);
			DateTimeOffset time = new DateTimeOffset(local, tz.GetUtcOffset(local));

			return new Fill
			{
				ExecutionId		= execution.ExecutionId,
				OrderId			= execution.OrderId,
				OrderName		= execution.Order != null ? execution.Order.Name : null,
				Account			= execution.Account.Name,
				Instrument		= new InstrumentSpec
				{
					FullName	= execution.Instrument.FullName,
					Symbol		= execution.Instrument.MasterInstrument.Name,
					TickSize	= (decimal)execution.Instrument.MasterInstrument.TickSize,
					PointValue	= (decimal)execution.Instrument.MasterInstrument.PointValue
				},
				Time			= time,
				// NT8.md/spike-verified: a fill's own MarketPosition (not Order.OrderAction) gives the
				// signed direction it contributed -- Long means this fill pushed the position toward long.
				Side			= execution.MarketPosition == MarketPosition.Long ? Side.Buy : Side.Sell,
				Quantity		= execution.Quantity,
				Price			= (decimal)execution.Price,
				Commission		= (decimal)execution.Commission,
				Fee			= (decimal)execution.Fee,
				PositionAfter		= execution.Position,
				IsEntry			= execution.IsEntry,
				IsExit			= execution.IsExit
			};
		}

		// ---- connection status (reflection: a static event with no directly-typed accessor NinjaScript
		// exposes to AddOns in this SDK surface, same technique spike/ChartJotSpike.cs already proved works)

		private static void SubscribeConnectionStatus()
		{
			try
			{
				EventInfo ev = typeof(Connection).GetEvent("ConnectionStatusUpdate", BindingFlags.Public | BindingFlags.Static);
				if (ev == null)
				{
					ChartJotLog.Write("CONN", "ConnectionStatusUpdate event not found");
					return;
				}
				MethodInfo handlerMethod = typeof(ChartJotMonitor).GetMethod("OnConnectionStatusUpdate", BindingFlags.NonPublic | BindingFlags.Static);
				connectionHandler = Delegate.CreateDelegate(ev.EventHandlerType, handlerMethod);
				ev.AddEventHandler(null, connectionHandler);
				ChartJotLog.Write("CONN", "Subscribed to Connection.ConnectionStatusUpdate");
			}
			catch (Exception ex)
			{
				ChartJotLog.Write("CONN", "Subscribe failed: " + ex.Message);
			}
		}

		private static void UnsubscribeConnectionStatus()
		{
			if (connectionHandler == null)
				return;
			try
			{
				EventInfo ev = typeof(Connection).GetEvent("ConnectionStatusUpdate", BindingFlags.Public | BindingFlags.Static);
				if (ev != null)
					ev.RemoveEventHandler(null, connectionHandler);
			}
			catch (Exception ex)
			{
				ChartJotLog.Write("CONN", "Unsubscribe failed: " + ex.Message);
			}
			connectionHandler = null;
		}

		private static void OnConnectionStatusUpdate(object sender, ConnectionStatusEventArgs e)
		{
			try
			{
				ChartJotLog.Write("CONN", string.Format(CultureInfo.InvariantCulture,
					"status={0} connection={1}", e.Status, e.Connection != null && e.Connection.Options != null ? e.Connection.Options.Name : "?"));

				if (e.Status == ConnectionStatus.Connected)
					Task.Run(() => SubscribeAccounts("reconnect"));
			}
			catch (Exception ex)
			{
				ChartJotLog.Write("ERROR", "OnConnectionStatusUpdate: " + ex);
			}
		}
	}

	// ------------------------------------------------------- delivery pump

	/// <summary>
	/// Sends queued trades in the background (NT8.md, "Delivery Requirements"): a timer wakes every few seconds,
	/// or at once after Submit/Retry/settings changes, and sends each due delivery through addon/core's
	/// TradeDelivery. Never runs on an NT8 event thread or the UI dispatcher, and never holds the monitor's lock
	/// across an HTTP call. Deliveries wait as Pending while the settings are incomplete.
	/// </summary>
	public static class ChartJotDelivery
	{
		private static readonly TimeSpan PollInterval = TimeSpan.FromSeconds(5);
		private static readonly TimeSpan RequestTimeout = TimeSpan.FromSeconds(30);
		private static readonly object gate = new object();

		// NT8.md: one shared HttpClient instance.
		private static HttpClient client;
		private static Timer timer;
		private static CancellationTokenSource stopping;
		private static int running;
		private static string lastSkipReason;

		public static void Start()
		{
			lock (gate)
			{
				if (timer != null)
					return;
				// NT8 runs on .NET Framework 4.8: TLS 1.2 must be enabled explicitly.
				ServicePointManager.SecurityProtocol |= SecurityProtocolType.Tls12;
				if (client == null)
					client = new HttpClient();
				stopping = new CancellationTokenSource();
				timer = new Timer(OnTick, null, PollInterval, PollInterval);
			}
		}

		public static void Stop()
		{
			lock (gate)
			{
				if (timer != null)
				{
					timer.Dispose();
					timer = null;
				}
				if (stopping != null)
					stopping.Cancel();
			}
		}

		/// <summary>Runs the pump now instead of waiting for the next tick.</summary>
		public static void Kick()
		{
			lock (gate)
			{
				if (timer != null)
					timer.Change(TimeSpan.Zero, PollInterval);
			}
		}

		private static void OnTick(object unused)
		{
			// One pump at a time; a tick that lands while one is running is simply skipped.
			if (Interlocked.CompareExchange(ref running, 1, 0) != 0)
				return;

			CancellationToken cancellationToken;
			lock (gate)
				cancellationToken = stopping == null ? new CancellationToken(true) : stopping.Token;

			Task.Run(() => PumpAsync(cancellationToken)).ContinueWith(t => Interlocked.Exchange(ref running, 0));
		}

		private static async Task PumpAsync(CancellationToken cancellationToken)
		{
			try
			{
				AddonSettings current = ChartJotMonitor.Settings;
				IList<string> errors = current.Validate();
				if (errors.Count > 0)
				{
					LogSkip("Delivery paused, settings incomplete: " + string.Join(" ", errors));
					return;
				}

				string intakeToken;
				try
				{
					intakeToken = current.GetToken(new DpapiTokenProtector());
				}
				catch (Exception ex)
				{
					// Never include the token or its protected bytes in the log.
					LogSkip("Delivery paused, the saved token could not be decrypted (re-enter it in Chart Jot Settings): " + ex.GetType().Name);
					return;
				}

				TradeDelivery delivery = new TradeDelivery(client, current.EndpointUrl.Trim(), intakeToken, ChartJotMonitor.AddonVersion, RequestTimeout);
				lastSkipReason = null;

				while (!cancellationToken.IsCancellationRequested)
				{
					QueuedDelivery due = ChartJotMonitor.TakeNextDue();
					if (due == null)
						break;

					DeliveryAttempt attempt;
					try
					{
						DeliveryScreenshot exit, entry;
						ChartJotCapture.ImagesForDelivery(due, out exit, out entry);
						attempt = await delivery.SendAsync(due, exit, entry, cancellationToken).ConfigureAwait(false);
					}
					catch (Exception ex)
					{
						// Anything unforeseen must still settle the delivery, or it would sit in Sending.
						attempt = new DeliveryAttempt { Outcome = DeliveryOutcome.Retryable, ErrorMessage = ex.GetType().Name + ": " + ex.Message };
					}

					ChartJotMonitor.RecordDelivery(due.TradeId, attempt);
					ChartJotLog.Write("DELIVERY", string.Format(CultureInfo.InvariantCulture,
						"trade_id={0} outcome={1} status={2} error={3}",
						due.TradeId, attempt.Outcome,
						attempt.StatusCode.HasValue ? attempt.StatusCode.Value.ToString(CultureInfo.InvariantCulture) : "none",
						attempt.ErrorMessage ?? ""));
				}
			}
			catch (Exception ex)
			{
				ChartJotLog.Write("ERROR", "Delivery pump: " + ex);
			}
		}

		// Logged once per distinct reason, not every five seconds.
		private static void LogSkip(string reason)
		{
			if (reason == lastSkipReason)
				return;
			lastSkipReason = reason;
			ChartJotLog.Write("DELIVERY", reason);
		}
	}

	// ------------------------------------------------------- token protection

	/// <summary>
	/// Windows DPAPI, CurrentUser scope (NT8.md, "Local storage and secrets"). Called through crypt32 directly
	/// because System.Security.dll (ProtectedData) is not among NinjaScript's references on this install.
	/// </summary>
	public sealed class DpapiTokenProtector : ITokenProtector
	{
		private const int UiForbidden = 0x1;

		// Ties the protected bytes to this purpose: another app's DPAPI blob for the same user will not decrypt as ours.
		private static readonly byte[] Entropy = System.Text.Encoding.UTF8.GetBytes("ChartJot.IntakeToken.v1");

		[StructLayout(LayoutKind.Sequential)]
		private struct DataBlob
		{
			public int Size;
			public IntPtr Data;
		}

		[DllImport("crypt32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
		private static extern bool CryptProtectData(ref DataBlob dataIn, string description, ref DataBlob entropy,
			IntPtr reserved, IntPtr prompt, int flags, ref DataBlob dataOut);

		[DllImport("crypt32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
		private static extern bool CryptUnprotectData(ref DataBlob dataIn, IntPtr description, ref DataBlob entropy,
			IntPtr reserved, IntPtr prompt, int flags, ref DataBlob dataOut);

		[DllImport("kernel32.dll")]
		private static extern IntPtr LocalFree(IntPtr memory);

		public byte[] Protect(byte[] plain)
		{
			return Transform(plain, true);
		}

		public byte[] Unprotect(byte[] protectedBytes)
		{
			return Transform(protectedBytes, false);
		}

		private static byte[] Transform(byte[] input, bool protect)
		{
			if (input == null)
				throw new ArgumentNullException("input");

			GCHandle inputHandle = GCHandle.Alloc(input, GCHandleType.Pinned);
			GCHandle entropyHandle = GCHandle.Alloc(Entropy, GCHandleType.Pinned);
			DataBlob output = new DataBlob();
			try
			{
				DataBlob dataIn = new DataBlob { Size = input.Length, Data = inputHandle.AddrOfPinnedObject() };
				DataBlob entropy = new DataBlob { Size = Entropy.Length, Data = entropyHandle.AddrOfPinnedObject() };

				bool ok = protect
					? CryptProtectData(ref dataIn, "Chart Jot intake token", ref entropy, IntPtr.Zero, IntPtr.Zero, UiForbidden, ref output)
					: CryptUnprotectData(ref dataIn, IntPtr.Zero, ref entropy, IntPtr.Zero, IntPtr.Zero, UiForbidden, ref output);
				if (!ok)
					throw new Win32Exception(Marshal.GetLastWin32Error());

				byte[] result = new byte[output.Size];
				Marshal.Copy(output.Data, result, 0, output.Size);
				return result;
			}
			finally
			{
				if (output.Data != IntPtr.Zero)
					LocalFree(output.Data);
				inputHandle.Free();
				entropyHandle.Free();
			}
		}
	}

	// ------------------------------------------------------- settings window

	/// <summary>
	/// Control Center -> New -> Chart Jot Settings. The endpoint, intake token (masked; never shown back) and data
	/// folder, with an Open folder button and a non-blocking warning for cloud-sync folders (NT8.md, "Configuration").
	/// </summary>
	public class ChartJotSettingsWindow : NTWindow
	{
		private readonly TextBox endpoint;
		private readonly PasswordBox token;
		private readonly TextBlock tokenStatus;
		private readonly TextBox dataFolder;
		private readonly TextBlock folderWarning;
		private readonly CheckBox captureEntry;
		private readonly TextBlock errors;
		private bool clearToken;

		public ChartJotSettingsWindow()
		{
			Caption	= "Chart Jot Settings";
			Width	= 640;
			Height	= 420;

			AddonSettings current = ChartJotMonitor.Settings;

			endpoint		= new TextBox { Text = current.EndpointUrl ?? "", Margin = new Thickness(4) };
			token			= new PasswordBox { Margin = new Thickness(4) };
			tokenStatus		= new TextBlock { Margin = new Thickness(4), TextWrapping = TextWrapping.Wrap };
			dataFolder		= new TextBox { Text = current.DataFolder ?? "", Margin = new Thickness(4) };
			captureEntry	= new CheckBox { Content = "Capture an entry image shortly after each entry (the exit image is always captured)", IsChecked = current.CaptureEntryImage, Margin = new Thickness(4) };
			folderWarning	= new TextBlock { Margin = new Thickness(4), TextWrapping = TextWrapping.Wrap, Foreground = System.Windows.Media.Brushes.Orange };
			errors			= new TextBlock { Margin = new Thickness(4), TextWrapping = TextWrapping.Wrap, Foreground = System.Windows.Media.Brushes.IndianRed };

			Button removeToken = new Button { Content = "Remove saved token", Margin = new Thickness(4), Padding = new Thickness(8, 2, 8, 2) };
			removeToken.Click += (s, e) => { clearToken = true; token.Clear(); UpdateTokenStatus(false); };
			UpdateTokenStatus(current.HasToken);

			Button openFolder = new Button { Content = "Open folder", Margin = new Thickness(4), Padding = new Thickness(8, 2, 8, 2) };
			openFolder.Click += (s, e) => OpenFolder();
			dataFolder.TextChanged += (s, e) => { folderWarning.Text = AddonSettings.CloudSyncWarning(dataFolder.Text) ?? ""; };
			folderWarning.Text = AddonSettings.CloudSyncWarning(dataFolder.Text) ?? "";

			Button save = new Button { Content = "Save", Margin = new Thickness(4), Padding = new Thickness(16, 2, 16, 2), IsDefault = true };
			save.Click += (s, e) => Save();
			Button close = new Button { Content = "Close", Margin = new Thickness(4), Padding = new Thickness(16, 2, 16, 2), IsCancel = true };
			close.Click += (s, e) => Close();

			Grid grid = new Grid { Margin = new Thickness(8) };
			grid.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });
			grid.ColumnDefinitions.Add(new ColumnDefinition { Width = new GridLength(1, GridUnitType.Star) });
			grid.ColumnDefinitions.Add(new ColumnDefinition { Width = GridLength.Auto });

			AddRow(grid, 0, "Journal endpoint URL", endpoint, null);
			AddRow(grid, 1, "Journal intake token", token, removeToken);
			AddRow(grid, 2, "", tokenStatus, null);
			AddRow(grid, 3, "Data folder", dataFolder, openFolder);
			AddRow(grid, 4, "", folderWarning, null);
			AddRow(grid, 5, "Entry image", captureEntry, null);
			AddRow(grid, 6, "", errors, null);

			StackPanel buttons = new StackPanel { Orientation = Orientation.Horizontal, HorizontalAlignment = HorizontalAlignment.Right };
			buttons.Children.Add(save);
			buttons.Children.Add(close);
			grid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });
			Grid.SetRow(buttons, 7);
			Grid.SetColumnSpan(buttons, 3);
			grid.Children.Add(buttons);

			Content = grid;
		}

		private static void AddRow(Grid grid, int row, string label, UIElement field, UIElement action)
		{
			grid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });

			TextBlock caption = new TextBlock { Text = label, Margin = new Thickness(4), VerticalAlignment = VerticalAlignment.Center };
			Grid.SetRow(caption, row);
			grid.Children.Add(caption);

			Grid.SetRow(field, row);
			Grid.SetColumn(field, 1);
			grid.Children.Add(field);

			if (action != null)
			{
				Grid.SetRow(action, row);
				Grid.SetColumn(action, 2);
				grid.Children.Add(action);
			}
		}

		private void UpdateTokenStatus(bool saved)
		{
			tokenStatus.Text = saved
				? "A token is saved (encrypted). Leave the box empty to keep it."
				: "No token saved. Paste the intake token from Chart Jot's settings page.";
		}

		private void OpenFolder()
		{
			try
			{
				Directory.CreateDirectory(dataFolder.Text.Trim());
				System.Diagnostics.Process.Start("explorer.exe", dataFolder.Text.Trim());
			}
			catch (Exception ex)
			{
				errors.Text = "Could not open the folder: " + ex.Message;
			}
		}

		private void Save()
		{
			try
			{
				DpapiTokenProtector protector = new DpapiTokenProtector();
				AddonSettings updated = AddonSettings.Deserialize(ChartJotMonitor.Settings.Serialize());
				updated.EndpointUrl = endpoint.Text.Trim();
				updated.DataFolder = dataFolder.Text.Trim();
				updated.CaptureEntryImage = captureEntry.IsChecked == true;
				if (token.Password.Trim().Length > 0)
					updated.SetToken(token.Password, protector);
				else if (clearToken)
					updated.SetToken(null, protector);

				IList<string> problems = updated.Validate();
				if (problems.Count > 0)
				{
					errors.Text = string.Join(Environment.NewLine, problems);
					return;
				}

				Directory.CreateDirectory(updated.DataFolder);
				ChartJotMonitor.ApplySettings(updated);
				token.Clear();
				clearToken = false;
				UpdateTokenStatus(updated.HasToken);
				errors.Text = "Saved.";
			}
			catch (Exception ex)
			{
				errors.Text = "Could not save the settings: " + ex.Message;
				ChartJotLog.Write("ERROR", "Settings save: " + ex.GetType().Name + ": " + ex.Message);
			}
		}
	}



	// ------------------------------------------------------- chart images (#37/#52)

	/// <summary>
	/// Captures a trade's chart image from the chart that shows it (NT8.md, "Screenshot Capture"):
	/// <list type="bullet">
	/// <item>Only a chart whose <b>visible</b> tab shows the trade's instrument qualifies: a background tab cannot be
	/// captured without switching to it, which the AddOn never does. Charts hosting the copier dashboard are skipped.</item>
	/// <item>Among those, the form's own chart (Recapture), then a chart whose Chart Trader account is the trade's,
	/// then the most recently activated one.</item>
	/// <item>The image is taken on the chart's dispatcher and encoded as PNG off it, into
	/// <c>{Data folder}\images\{trade_id}.png</c> (exit) or <c>{trade_id}-entry.png</c> (entry).</item>
	/// </list>
	/// A capture that fails or finds no chart is logged and skipped; it never blocks submitting the trade.
	/// </summary>
	public static class ChartJotCapture
	{
		public static readonly TimeSpan RenderDelay = TimeSpan.FromSeconds(1);

		public static string ImagesFolder
		{
			get { return Path.Combine(ChartJotMonitor.Settings.DataFolder, "images"); }
		}

		public static string PathFor(string tradeId, bool entry)
		{
			string name = new string((tradeId ?? "").Select(c => Path.GetInvalidFileNameChars().Contains(c) ? '_' : c).ToArray());
			return Path.Combine(ImagesFolder, name + (entry ? "-entry" : "") + ".png");
		}

		/// <summary>Captures after the render delay, so the fill and its markers are drawn. Never blocks the caller.</summary>
		public static void CaptureLater(string account, string instrumentFullName, string tradeId, bool entry)
		{
			Task.Delay(RenderDelay).ContinueWith(t => Capture(account, instrumentFullName, tradeId, entry, null));
		}

		/// <summary>
		/// Captures now and records it on the trade. Returns null on success, otherwise why nothing was captured.
		/// Call off any chart's UI thread: it visits charts on their own dispatchers.
		/// </summary>
		public static string Capture(string account, string instrumentFullName, string tradeId, bool entry, NinjaTrader.Gui.Chart.Chart preferred)
		{
			string kind = entry ? "entry" : "exit";
			try
			{
				NinjaTrader.Gui.Chart.Chart chart = FindChart(account, instrumentFullName, preferred);
				if (chart == null)
				{
					string reason = "no open chart is showing " + instrumentFullName;
					ChartJotLog.Write("CAPTURE", kind + " image skipped trade_id=" + tradeId + ": " + reason);
					return reason;
				}

				BitmapSource image = null;
				chart.Dispatcher.Invoke(() =>
				{
					image = chart.GetScreenshot(ShareScreenshotType.Chart) as BitmapSource;
					if (image != null && image.CanFreeze)
						image.Freeze();	// required before encoding on another thread
				});
				if (image == null)
				{
					ChartJotLog.Write("CAPTURE", kind + " image skipped trade_id=" + tradeId + ": the chart returned no image");
					return "the chart returned no image";
				}

				string path = PathFor(tradeId, entry);
				Directory.CreateDirectory(Path.GetDirectoryName(path));
				string temp = path + "." + Guid.NewGuid().ToString("N") + ".tmp";
				PngBitmapEncoder encoder = new PngBitmapEncoder();
				encoder.Frames.Add(BitmapFrame.Create(image));
				using (FileStream stream = File.Create(temp))
					encoder.Save(stream);
				if (File.Exists(path))
					File.Delete(path);
				File.Move(temp, path);

				if (!ChartJotMonitor.RecordCapture(tradeId, entry, new ScreenshotMeta { CapturedAt = DateTimeOffset.Now, Format = "png" }))
				{
					ChartJotLog.Write("CAPTURE", kind + " image not recorded trade_id=" + tradeId + ": the trade is unknown or already submitted");
					return "the trade was already submitted";
				}
				ChartJotLog.Write("CAPTURE", string.Format(CultureInfo.InvariantCulture, "{0} image trade_id={1} {2}x{3}",
					kind, tradeId, image.PixelWidth, image.PixelHeight));
				return null;
			}
			catch (Exception ex)
			{
				ChartJotLog.Write("CAPTURE", kind + " image failed trade_id=" + tradeId + ": " + ex.GetType().Name + ": " + ex.Message);
				return "capture failed (" + ex.Message + ")";
			}
		}

		/// <summary>A follower sends its master's image files under its own trade_id.</summary>
		public static void CopyImages(string masterTradeId, string followerTradeId)
		{
			foreach (bool entry in new[] { false, true })
			{
				string from = PathFor(masterTradeId, entry);
				if (!File.Exists(from))
					continue;
				try
				{
					File.Copy(from, PathFor(followerTradeId, entry), true);
				}
				catch (Exception ex)
				{
					ChartJotLog.Write("CAPTURE", "copying the master's image to trade_id=" + followerTradeId + " failed: " + ex.Message);
				}
			}
		}

		/// <summary>The image files a delivery's frozen payload says were captured; null for any that is absent or unreadable.</summary>
		public static void ImagesForDelivery(QueuedDelivery delivery, out DeliveryScreenshot exit, out DeliveryScreenshot entry)
		{
			exit = null;
			entry = null;
			JsonValue payload;
			try
			{
				payload = JsonValue.Parse(delivery.PayloadJson);
			}
			catch (FormatException)
			{
				return;
			}
			if (payload.Has("screenshot") && !payload["screenshot"].IsNull)
				exit = Load(delivery.TradeId, false);
			if (payload.Has("entry_screenshot") && !payload["entry_screenshot"].IsNull)
				entry = Load(delivery.TradeId, true);
		}

		private static DeliveryScreenshot Load(string tradeId, bool entry)
		{
			string path = PathFor(tradeId, entry);
			try
			{
				return File.Exists(path) ? new DeliveryScreenshot { Bytes = File.ReadAllBytes(path), Format = "png" } : null;
			}
			catch (Exception ex)
			{
				ChartJotLog.Write("CAPTURE", "reading " + path + " failed; sending without it: " + ex.Message);
				return null;
			}
		}

		private static NinjaTrader.Gui.Chart.Chart FindChart(string account, string instrumentFullName, NinjaTrader.Gui.Chart.Chart preferred)
		{
			NinjaTrader.Gui.Chart.Chart best = null;
			int bestScore = 0;
			foreach (NinjaTrader.Gui.Chart.Chart chart in ChartJotCopier.Charts())
			{
				int score = 0;
				try
				{
					chart.Dispatcher.Invoke(() =>
					{
						NinjaTrader.Gui.Chart.ChartControl visible = chart.ActiveChartControl;
						if (visible == null || visible.Instrument == null || visible.Instrument.FullName != instrumentFullName)
							return;
						if (ChartJotCopier.HostsCopier(chart))
							return;
						bool sameAccount = chart.ChartTrader != null && chart.ChartTrader.Account != null && chart.ChartTrader.Account.Name == account;
						score = chart == preferred ? 3 : sameAccount ? 2 : 1;
					});
				}
				catch (Exception ex)
				{
					ChartJotLog.Write("CAPTURE", "checking a chart failed: " + ex.Message);
				}
				// Charts() is most recently activated first, so the first chart with the best score wins ties.
				if (score > bestScore)
				{
					best = chart;
					bestScore = score;
				}
			}
			return best;
		}
	}
	// ------------------------------------------------------- trade copier (read-only)

	/// <summary>
	/// Reads the Affordable Indicators copier's follower setup for a master account from the copier indicator
	/// (<c>aiDuplicateAccountActions</c>) on any open chart, the way spike/ChartJotSpike.cs verified. Read-only:
	/// it never changes the copier. Returns an empty list when no copier instance names this master.
	/// </summary>
	public static class ChartJotCopier
	{
		private const string CopierType = "NinjaTrader.NinjaScript.Indicators.aiDuplicateAccountActions";

		private static readonly object sync = new object();
		private static readonly List<NinjaTrader.Gui.Chart.Chart> charts = new List<NinjaTrader.Gui.Chart.Chart>();

		private static readonly Dictionary<NinjaTrader.Gui.Chart.Chart, DateTime> activated = new Dictionary<NinjaTrader.Gui.Chart.Chart, DateTime>();

		public static void Track(NinjaTrader.Gui.Chart.Chart chart)
		{
			lock (sync)
			{
				if (charts.Contains(chart))
					return;
				charts.Add(chart);
				activated[chart] = DateTime.UtcNow;
			}
			chart.Dispatcher.InvokeAsync(() => chart.Activated += OnActivated);
		}

		private static void OnActivated(object sender, EventArgs e)
		{
			NinjaTrader.Gui.Chart.Chart chart = sender as NinjaTrader.Gui.Chart.Chart;
			if (chart != null)
				lock (sync)
					activated[chart] = DateTime.UtcNow;
		}

		/// <summary>Tracked chart windows, most recently activated first.</summary>
		public static IList<NinjaTrader.Gui.Chart.Chart> Charts()
		{
			lock (sync)
				return charts.OrderByDescending(c => activated.ContainsKey(c) ? activated[c] : DateTime.MinValue).ToList();
		}

		/// <summary>True when the window hosts the copier dashboard (not a trading chart). Call on its dispatcher.</summary>
		public static bool HostsCopier(NinjaTrader.Gui.Chart.Chart chart)
		{
			foreach (object chartControl in ChartControls(chart))
			{
				System.Collections.IEnumerable indicators = GetProp(chartControl, "Indicators") as System.Collections.IEnumerable;
				if (indicators == null)
					continue;
				foreach (object indicator in indicators)
					if (indicator != null && indicator.GetType().FullName == CopierType)
						return true;
			}
			return false;
		}

		public static void Untrack(NinjaTrader.Gui.Chart.Chart chart)
		{
			lock (sync)
			{
				charts.Remove(chart);
				activated.Remove(chart);
			}
		}

		/// <summary>Call off any chart's UI thread: it visits each chart on that chart's dispatcher.</summary>
		public static IList<FollowerSetup> FollowersOf(string masterAccount)
		{
			List<NinjaTrader.Gui.Chart.Chart> windows;
			lock (sync)
				windows = charts.ToList();

			List<FollowerSetup> followers = new List<FollowerSetup>();
			int copiers = 0;
			foreach (NinjaTrader.Gui.Chart.Chart chart in windows)
			{
				try
				{
					chart.Dispatcher.Invoke(() =>
					{
						foreach (object chartControl in ChartControls(chart))
						{
							System.Collections.IEnumerable indicators = GetProp(chartControl, "Indicators") as System.Collections.IEnumerable;
							if (indicators == null)
								continue;
							foreach (object indicator in indicators)
							{
								if (indicator == null || indicator.GetType().FullName != CopierType)
									continue;
								copiers++;
								string master = Convert.ToString(GetProp(indicator, "ThisMasterAccount"), CultureInfo.InvariantCulture);
								if (!string.Equals(master, masterAccount, StringComparison.OrdinalIgnoreCase))
									continue;
								System.Collections.IEnumerable rows = GetProp(indicator, "AllAccountData") as System.Collections.IEnumerable;
								if (rows == null || rows is string)
									continue;
								List<string> lines = new List<string>();
								foreach (object row in rows)
									lines.Add(Convert.ToString(row, CultureInfo.InvariantCulture));
								foreach (FollowerSetup follower in CopierSetup.Followers(masterAccount, lines))
									if (!followers.Any(f => string.Equals(f.Account, follower.Account, StringComparison.OrdinalIgnoreCase)))
										followers.Add(follower);
							}
						}
					});
				}
				catch (Exception ex)
				{
					ChartJotLog.Write("COPIER", "reading a chart failed: " + ex.Message);
				}
			}

			ChartJotLog.Write("COPIER", string.Format(CultureInfo.InvariantCulture, "master={0} copier instances={1} followers={2}",
				masterAccount, copiers, string.Join(",", followers.Select(f => f.Account + (f.Fade ? "(fade)" : "")))));
			return followers;
		}

		private static IEnumerable<object> ChartControls(NinjaTrader.Gui.Chart.Chart chart)
		{
			List<object> result = new List<object>();
			TabControl tabs = chart.MainTabControl;
			if (tabs != null)
			{
				foreach (object item in tabs.Items)
				{
					TabItem tabItem = item as TabItem;
					object content = tabItem != null ? tabItem.Content : item;
					object chartControl = GetProp(content, "ChartControl");
					if (chartControl != null)
						result.Add(chartControl);
				}
			}
			if (result.Count == 0 && chart.ActiveChartControl != null)
				result.Add(chart.ActiveChartControl);
			return result;
		}

		private static object GetProp(object target, string name)
		{
			if (target == null)
				return null;
			try
			{
				PropertyInfo p = target.GetType().GetProperty(name, BindingFlags.Public | BindingFlags.Instance);
				return p != null && p.GetIndexParameters().Length == 0 ? p.GetValue(target, null) : null;
			}
			catch
			{
				return null;
			}
		}
	}

	// ------------------------------------------------------- trade form (one per chart)

	public sealed class SubmittedView
	{
		public string TradeId;
		public DeliveryState State;
		public int Attempts;
		public int? StatusCode;
		public bool IsConfigurationError;
		public string Detail;
	}

	/// <summary>A copy of the form and its trade state for one account/instrument, taken under the monitor's lock.</summary>
	public sealed class FormView
	{
		public string Body;
		public string TradeType;
		public string TradeTypeOther;
		public bool IsOpen;
		public Direction OpenDirection;
		public int OpenQuantity;
		public DateTimeOffset OpenEntryAt;
		public int ClosedCount;
		public Direction ClosedDirection;
		public decimal ClosedNetPnl;
		public bool CanSubmit;
		public string LatestClosedTradeId;
		public bool HasExitImage;
		public bool HasEntryImage;
		public List<SubmittedView> LastSubmitted = new List<SubmittedView>();
	}

	/// <summary>
	/// The Chart Jot form for one chart, opened from the chart's toolbar button. It follows the chart's Chart Trader
	/// account and instrument: one note box (write before, during and after the trade), the trade type, Submit and
	/// Reset, and a status line. Lives on the chart's own dispatcher so it can read Chart Trader directly.
	/// </summary>
	public class ChartJotFormWindow : NTWindow
	{
		private static readonly Dictionary<NinjaTrader.Gui.Chart.Chart, ChartJotFormWindow> open = new Dictionary<NinjaTrader.Gui.Chart.Chart, ChartJotFormWindow>();

		private readonly NinjaTrader.Gui.Chart.Chart chart;
		private readonly TextBlock scope;
		private readonly TextBlock status;
		private readonly TextBox note;
		private readonly ComboBox tradeType;
		private readonly TextBox tradeTypeOther;
		private readonly TextBlock warning;
		private readonly Button submit;
		private readonly Button reset;
		private readonly Button retry;
		private readonly Button recapture;
		private readonly Button settings;
		private readonly System.Windows.Threading.DispatcherTimer refresh;
		private readonly System.Windows.Threading.DispatcherTimer saveDelay;

		private string account;
		private string instrument;
		private bool loading;
		private bool busy;
		private string message;
		private DateTime messageUntil;
		private bool awaitingDelivery;

		/// <summary>Opens the chart's form, or brings the open one to the front. Call on the chart's dispatcher.</summary>
		public static void ShowFor(NinjaTrader.Gui.Chart.Chart chart)
		{
			ChartJotFormWindow window;
			if (open.TryGetValue(chart, out window))
			{
				window.Activate();
				return;
			}
			window = new ChartJotFormWindow(chart);
			open[chart] = window;
			window.Closed += (s, e) => open.Remove(chart);
			window.Show();
		}

		/// <summary>Closes the chart's form when the chart itself closes.</summary>
		public static void CloseFor(NinjaTrader.Gui.Chart.Chart chart)
		{
			ChartJotFormWindow window;
			if (open.TryGetValue(chart, out window))
				window.Close();
		}

		private ChartJotFormWindow(NinjaTrader.Gui.Chart.Chart chart)
		{
			this.chart = chart;
			Caption	= "Chart Jot";
			Width	= 460;
			Height	= 520;
			Owner	= chart;

			// NinjaTrader's own text colour, so the form follows the skin (WPF's default is black).
			System.Windows.Media.Brush text = Application.Current.TryFindResource("FontControlBrush") as System.Windows.Media.Brush
				?? Application.Current.TryFindResource("FontLabelBrush") as System.Windows.Media.Brush;
			if (text != null)
				Foreground = text;

			scope		= new TextBlock { Margin = new Thickness(6, 6, 6, 2), FontWeight = FontWeights.SemiBold, TextWrapping = TextWrapping.Wrap };
			status		= new TextBlock { Margin = new Thickness(6, 2, 6, 6), TextWrapping = TextWrapping.Wrap };
			note		= new TextBox { Margin = new Thickness(6), AcceptsReturn = true, AcceptsTab = true, TextWrapping = TextWrapping.Wrap,
							VerticalScrollBarVisibility = ScrollBarVisibility.Auto, VerticalContentAlignment = VerticalAlignment.Top, FontSize = 14 };
			tradeType	= new ComboBox { Margin = new Thickness(6, 2, 6, 2) };
			tradeTypeOther = new TextBox { Margin = new Thickness(6, 2, 6, 2), Visibility = Visibility.Collapsed };
			warning		= new TextBlock { Margin = new Thickness(6, 2, 6, 2), TextWrapping = TextWrapping.Wrap, Foreground = System.Windows.Media.Brushes.Orange };
			submit		= new Button { Content = "Submit", Margin = new Thickness(6), Padding = new Thickness(18, 4, 18, 4), FontWeight = FontWeights.SemiBold };
			reset		= new Button { Content = "Reset", Margin = new Thickness(6), Padding = new Thickness(18, 4, 18, 4) };
			retry		= new Button { Content = "Retry", Margin = new Thickness(6), Padding = new Thickness(12, 4, 12, 4), Visibility = Visibility.Collapsed };
			recapture	= new Button { Content = "Recapture", ToolTip = "Replace the exit image with what this chart shows now (e.g. after marking it up)",
							Margin = new Thickness(6), Padding = new Thickness(12, 4, 12, 4), Visibility = Visibility.Collapsed };

			settings	= new Button { Content = "Settings", ToolTip = "Chart Jot Settings", Margin = new Thickness(6), Padding = new Thickness(10, 4, 10, 4) };
			settings.Click += (s, e) => NinjaTrader.Core.Globals.RandomDispatcher.BeginInvoke(new Action(() => new ChartJotSettingsWindow().Show()));

			tradeType.Items.Add(new ComboBoxItem { Content = "Trade type...", Tag = null });
			foreach (string value in TradeTypes.All)
				tradeType.Items.Add(new ComboBoxItem { Content = value == "Other" ? "Other" : value + "  -  " + TradeTypes.Label(value), Tag = value });
			tradeType.SelectedIndex = 0;

			note.TextChanged				+= (s, e) => { if (!loading) { saveDelay.Stop(); saveDelay.Start(); } };
			note.LostFocus					+= (s, e) => SaveNow();
			tradeType.SelectionChanged		+= (s, e) => { if (!loading) { SaveNow(); Render(); } };
			tradeTypeOther.LostFocus		+= (s, e) => SaveNow();
			submit.Click					+= (s, e) => OnSubmit();
			reset.Click						+= (s, e) => OnReset();
			retry.Click						+= (s, e) => OnRetry();
			recapture.Click					+= (s, e) => OnRecapture();

			StackPanel buttons = new StackPanel { Orientation = Orientation.Horizontal, HorizontalAlignment = HorizontalAlignment.Right };
			buttons.Children.Add(recapture);
			buttons.Children.Add(retry);
			buttons.Children.Add(reset);
			buttons.Children.Add(submit);

			DockPanel actions = new DockPanel { LastChildFill = false };
			DockPanel.SetDock(settings, Dock.Left);
			DockPanel.SetDock(buttons, Dock.Right);
			actions.Children.Add(settings);
			actions.Children.Add(buttons);

			StackPanel bottom = new StackPanel();
			bottom.Children.Add(tradeType);
			bottom.Children.Add(tradeTypeOther);
			bottom.Children.Add(warning);
			bottom.Children.Add(actions);

			DockPanel root = new DockPanel { Margin = new Thickness(4) };
			DockPanel.SetDock(scope, Dock.Top);
			DockPanel.SetDock(status, Dock.Top);
			DockPanel.SetDock(bottom, Dock.Bottom);
			root.Children.Add(scope);
			root.Children.Add(status);
			root.Children.Add(bottom);
			root.Children.Add(note);	// fills the rest, and grows with the window
			Content = root;

			saveDelay = new System.Windows.Threading.DispatcherTimer(System.Windows.Threading.DispatcherPriority.Background, Dispatcher) { Interval = TimeSpan.FromMilliseconds(600) };
			saveDelay.Tick += (s, e) => { saveDelay.Stop(); SaveNow(); };
			refresh = new System.Windows.Threading.DispatcherTimer(System.Windows.Threading.DispatcherPriority.Background, Dispatcher) { Interval = TimeSpan.FromSeconds(1) };
			refresh.Tick += (s, e) => Tick();
			Closed += (s, e) => { SaveNow(); refresh.Stop(); saveDelay.Stop(); };

			Tick();
			refresh.Start();
			note.Focus();
		}

		// ---- scope: follow the chart's Chart Trader account and instrument

		private void Tick()
		{
			try
			{
				string newAccount = null;
				string newInstrument = null;
				if (chart.ChartTrader != null)
				{
					if (chart.ChartTrader.Account != null)
						newAccount = chart.ChartTrader.Account.Name;
					if (chart.ChartTrader.Instrument != null)
						newInstrument = chart.ChartTrader.Instrument.FullName;
				}
				if (newInstrument == null && chart.ActiveChartControl != null && chart.ActiveChartControl.Instrument != null)
					newInstrument = chart.ActiveChartControl.Instrument.FullName;

				if (newAccount != account || newInstrument != instrument)
				{
					SaveNow();
					account = newAccount;
					instrument = newInstrument;
					message = null;
					Load();
				}
				Render();
			}
			catch (Exception ex)
			{
				ChartJotLog.Write("ERROR", "Chart Jot form: " + ex);
			}
		}

		private bool HasScope
		{
			get { return account != null && instrument != null; }
		}

		private void Load()
		{
			loading = true;
			try
			{
				if (HasScope)
					ChartJotMonitor.StartFormCycleOnce(account, instrument);
				FormView view = HasScope ? ChartJotMonitor.Form(account, instrument) : new FormView();
				note.Text = view.Body ?? "";
				SelectType(view.TradeType);
				tradeTypeOther.Text = view.TradeTypeOther ?? "";
			}
			finally
			{
				loading = false;
			}
		}

		private void SaveNow()
		{
			saveDelay.Stop();
			if (loading || !HasScope)
				return;
			try
			{
				string type = SelectedType();
				ChartJotMonitor.UpdateForm(account, instrument, note.Text, type, type == "Other" ? tradeTypeOther.Text : null);
			}
			catch (Exception ex)
			{
				ShowMessage(ex.Message);
			}
		}

		// ---- rendering

		private void Render()
		{
			scope.Text = HasScope
				? account + "  ·  " + instrument
				: "Choose an account in Chart Trader to start a note.";

			bool enabled = HasScope && !busy;
			note.IsEnabled = HasScope;
			tradeType.IsEnabled = HasScope;
			tradeTypeOther.Visibility = SelectedType() == "Other" ? Visibility.Visible : Visibility.Collapsed;

			if (!HasScope)
			{
				status.Text = "";
				warning.Text = "";
				submit.IsEnabled = reset.IsEnabled = false;
				recapture.Visibility = Visibility.Collapsed;
				retry.Visibility = Visibility.Collapsed;
				return;
			}

			FormView view = ChartJotMonitor.Form(account, instrument);
			Direction? direction = view.IsOpen ? view.OpenDirection : (view.ClosedCount > 0 ? view.ClosedDirection : (Direction?)null);
			string mismatch = direction.HasValue ? TradeTypes.DirectionWarning(SelectedType(), direction.Value) : null;
			warning.Text = mismatch == null ? "" : mismatch + " You can still submit.";

			submit.IsEnabled = enabled && view.ClosedCount > 0 && TradeTypes.IsValid(SelectedType());
			reset.IsEnabled = enabled;
			bool failed = view.LastSubmitted.Any(d => d.State == DeliveryState.Failed);
			retry.Visibility = failed && view.ClosedCount == 0 && !view.IsOpen ? Visibility.Visible : Visibility.Collapsed;
			recapture.Visibility = view.ClosedCount > 0 ? Visibility.Visible : Visibility.Collapsed;
			recapture.IsEnabled = enabled;
			if (message != null && DateTime.UtcNow >= messageUntil)
				message = null;
			status.Text = message ?? StatusText(view);
		}

		/// <summary>Shows <paramref name="text"/> on the status line for a few seconds.</summary>
		private void ShowMessage(string text)
		{
			message = text;
			messageUntil = DateTime.UtcNow.AddSeconds(5);
		}

		/// <summary>Only the trade the form is working on now, plus a failed submission (it needs Retry).</summary>
		private string StatusText(FormView view)
		{
			if (busy)
				return "Working...";
			if (view.IsOpen)
			{
				TimeSpan elapsed = DateTimeOffset.Now - view.OpenEntryAt;
				string time = elapsed < TimeSpan.Zero ? "" : ", " + ((int)elapsed.TotalMinutes).ToString(CultureInfo.InvariantCulture) + "m " + elapsed.Seconds.ToString("00", CultureInfo.InvariantCulture) + "s";
				return "In trade: " + view.OpenDirection + " " + view.OpenQuantity.ToString(CultureInfo.InvariantCulture) + time;
			}
			if (view.ClosedCount > 0)
			{
				string pnl = view.ClosedNetPnl.ToString("+0.00;-0.00;0.00", CultureInfo.InvariantCulture);
				string what = (view.ClosedCount == 1 ? "Trade closed (" : "Trades closed (") + pnl + ")";
				string images = view.HasExitImage ? "" : " No exit image yet: show " + instrument + " on this chart and click Recapture.";
				return (TradeTypes.IsValid(SelectedType()) ? what + ": ready to submit." : what + ": choose a trade type, then Submit.") + images;
			}
			if (view.LastSubmitted.Any(d => d.State == DeliveryState.Failed && d.IsConfigurationError))
			{
				awaitingDelivery = false;
				return "Last submission failed: check the intake token in Chart Jot Settings, then Retry.";
			}
			SubmittedView failed = view.LastSubmitted.FirstOrDefault(d => d.State == DeliveryState.Failed);
			if (failed != null)
			{
				awaitingDelivery = false;
				if (!failed.StatusCode.HasValue || failed.StatusCode.Value >= 500)
					return "Not sent: the journal couldn't be reached after " + failed.Attempts.ToString(CultureInfo.InvariantCulture)
						+ " tries" + (failed.Detail != null ? " (" + failed.Detail + ")" : "") + ". Click Retry to send it again.";
				return "Not sent: the journal refused it (HTTP " + failed.StatusCode.Value.ToString(CultureInfo.InvariantCulture) + ")"
					+ (failed.Detail != null ? ": " + failed.Detail : ".") + " Click Retry to send it again.";
			}
			// A submission still on its way is the current trade too: say so until the journal has it.
			if (view.LastSubmitted.Any(d => d.State == DeliveryState.Pending || d.State == DeliveryState.Sending || d.State == DeliveryState.QueuedForRetry))
			{
				awaitingDelivery = true;
				return view.LastSubmitted.Any(d => d.Attempts > 0)
					? "Not sent yet: the journal can't be reached. Trying again (up to 3 tries)."
					: "Sending...";
			}
			if (awaitingDelivery)
			{
				awaitingDelivery = false;
				ShowMessage("Sent.");
				return "Sent.";
			}
			return "Waiting for entry.";
		}

		private string SelectedType()
		{
			ComboBoxItem item = tradeType.SelectedItem as ComboBoxItem;
			return item == null ? null : item.Tag as string;
		}

		private void SelectType(string value)
		{
			foreach (ComboBoxItem item in tradeType.Items)
			{
				if ((item.Tag as string) == value)
				{
					tradeType.SelectedItem = item;
					return;
				}
			}
			tradeType.SelectedIndex = 0;
		}

		// ---- actions

		private void OnSubmit()
		{
			if (!HasScope || busy)
				return;
			SaveNow();
			busy = true;
			message = null;
			Render();

			string master = account;
			string market = instrument;
			Task.Run(() =>
			{
				string result;
				try
				{
					// Reading the copier visits other charts' dispatchers, so it runs off this one.
					ChartJotMonitor.SubmitForm(master, market, ChartJotCopier.FollowersOf(master));
					result = null;
					awaitingDelivery = true;
				}
				catch (Exception ex)
				{
					result = "Not submitted: " + ex.Message;
				}
				Dispatcher.InvokeAsync(() =>
				{
					busy = false;
					Load();
					if (result != null)
						ShowMessage(result);
					Render();
				});
			});
		}

		private void OnReset()
		{
			if (!HasScope)
				return;
			FormView view = ChartJotMonitor.Form(account, instrument);
			if (view.ClosedCount > 0 && MessageBox.Show(
				"Reset clears the note and trade type, and the closed trade will not be journaled. Continue?",
				"Chart Jot", MessageBoxButton.YesNo, MessageBoxImage.Question) != MessageBoxResult.Yes)
				return;

			ChartJotMonitor.ResetForm(account, instrument);
			Load();
			message = null;
			Render();
			note.Focus();
		}

		private void OnRecapture()
		{
			if (!HasScope || busy)
				return;
			FormView view = ChartJotMonitor.Form(account, instrument);
			if (view.LatestClosedTradeId == null)
				return;

			busy = true;
			Render();
			string master = account;
			string market = instrument;
			string tradeId = view.LatestClosedTradeId;
			Task.Run(() =>
			{
				// Capturing visits charts on their own dispatchers, so it runs off this one.
				string failure = ChartJotCapture.Capture(master, market, tradeId, false, chart);
				string result = failure == null ? "Exit image replaced." : "Not recaptured: " + failure + ".";
				Dispatcher.InvokeAsync(() =>
				{
					busy = false;
					ShowMessage(result);
					Render();
				});
			});
		}

		private void OnRetry()
		{
			if (!HasScope)
				return;
			ChartJotMonitor.RetryLastSubmission(account, instrument);
			Render();
		}
	}
}
