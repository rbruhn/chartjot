// Chart Jot AddOn - phase 2a: settings, staging, and submit/delivery wiring (#64).
//
// Wires NT8's real account/execution/connection events into addon/core's
// TradeTracker/AddonState (ChartJot.Core.dll). Closed trades are staged after
// the commission settle period; ChartJotMonitor.Submit() freezes a staged trade
// into the delivery queue, and a background pump sends it. There is no note
// panel yet, so nothing in the UI calls Submit() -- that is #64's second PR. No
// screenshot either (#37/#52), and no live tick subscription for MAE/MFE, so
// excursion stays incomplete.
//
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
using ChartJot.Core;
using NinjaTrader.Cbi;
using NinjaTrader.Gui;
using NinjaTrader.Gui.Tools;
#endregion

namespace NinjaTrader.NinjaScript.AddOns
{
	public class ChartJot : AddOnBase
	{
		private NTMenuItem newMenu;
		private NTMenuItem settingsMenuItem;

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

			// Same Control Center menu technique spike/ChartJotSpike.cs verified.
			ControlCenter cc = window as ControlCenter;
			if (cc == null)
				return;

			newMenu = cc.FindFirst("ControlCenterMenuItemNew") as NTMenuItem;
			if (newMenu == null)
			{
				ChartJotLog.Write("UI", "Control Center 'New' menu not found; settings cannot be opened from the menu");
				return;
			}

			settingsMenuItem = new NTMenuItem { Header = "Chart Jot Settings", Style = Application.Current.TryFindResource("MainMenuItem") as Style };
			newMenu.Items.Add(settingsMenuItem);
			settingsMenuItem.Click += OnSettingsClick;
		}

		protected override void OnWindowDestroyed(Window window)
		{
			if (settingsMenuItem != null && window is ControlCenter)
			{
				if (newMenu != null && newMenu.Items.Contains(settingsMenuItem))
					newMenu.Items.Remove(settingsMenuItem);
				settingsMenuItem.Click -= OnSettingsClick;
				settingsMenuItem = null;
			}
		}

		private void OnSettingsClick(object sender, RoutedEventArgs e)
		{
			NinjaTrader.Core.Globals.RandomDispatcher.BeginInvoke(new Action(() => new ChartJotSettingsWindow().Show()));
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
						state.Stage(tradeId);
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

		public const string AddonVersion = "0.2.0";

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
				}

				foreach (CompletedTrade closed in result.Closed)
				{
					lock (sync)
						state.RecordClosed(closed);
					ChartJotLog.Write("TRADE", "closed trade_id=" + closed.TradeId + " net_pnl=" + closed.NetPnl.ToString(CultureInfo.InvariantCulture));
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
						// No screenshot capture yet (#37/#52); a trade always posts without one.
						attempt = await delivery.SendAsync(due, null, cancellationToken).ConfigureAwait(false);
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
		private readonly TextBlock errors;
		private bool clearToken;

		public ChartJotSettingsWindow()
		{
			Caption	= "Chart Jot Settings";
			Width	= 640;
			Height	= 380;

			AddonSettings current = ChartJotMonitor.Settings;

			endpoint		= new TextBox { Text = current.EndpointUrl ?? "", Margin = new Thickness(4) };
			token			= new PasswordBox { Margin = new Thickness(4) };
			tokenStatus		= new TextBlock { Margin = new Thickness(4), TextWrapping = TextWrapping.Wrap };
			dataFolder		= new TextBox { Text = current.DataFolder ?? "", Margin = new Thickness(4) };
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
			AddRow(grid, 5, "", errors, null);

			StackPanel buttons = new StackPanel { Orientation = Orientation.Horizontal, HorizontalAlignment = HorizontalAlignment.Right };
			buttons.Children.Add(save);
			buttons.Children.Add(close);
			grid.RowDefinitions.Add(new RowDefinition { Height = GridLength.Auto });
			Grid.SetRow(buttons, 6);
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
}
