// Chart Jot AddOn - phase 1: account adapters + reconciliation.
//
// Wires NT8's real account/execution/connection events into addon/core's
// TradeTracker/AddonState (ChartJot.Core.dll). No note panel, no screenshot,
// no settings, no HTTP submit yet -- correctness is observed through the
// diagnostic log, the same way spike/ChartJotSpike.cs was verified. Live tick
// subscription for MAE/MFE is deliberately out of scope this phase too (see
// NT.md); trades are tracked and reconciled, but excursion stays incomplete
// until that lands.
//
// Log: %LOCALAPPDATA%\ChartJot\nt8\chartjot-YYYYMMDD.log
// State: %USERPROFILE%\ChartJot\state.json (hardcoded until the settings UI exists)
// Remove: delete this file (and ChartJot.Core.dll from bin\Custom\) and recompile.

#region Using declarations
using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Reflection;
using System.Threading.Tasks;
using System.Windows;
using ChartJot.Core;
using NinjaTrader.Cbi;
#endregion

namespace NinjaTrader.NinjaScript.AddOns
{
	public class ChartJot : AddOnBase
	{
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
			// No menu item or window in this phase; starting here matches the timing the spike already
			// verified works (NT8's account/window plumbing is up by the time a window is created).
			ChartJotMonitor.Start();
		}

		protected override void OnWindowDestroyed(Window window)
		{
			// Nothing per-window to clean up yet: no menu item, no note panel this phase.
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
		private static bool started;
		private static AddonState state;
		private static Delegate connectionHandler;

		private static string StatePath
		{
			get { return Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.UserProfile), "ChartJot", "state.json"); }
		}

		public static void Start()
		{
			lock (sync)
			{
				if (started)
					return;
				started = true;
			}

			state = LoadState();
			SubscribeConnectionStatus();
			SubscribeAccounts("startup");
		}

		public static void Stop()
		{
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

		private static void SaveState()
		{
			try
			{
				state.Save(StatePath);
			}
			catch (Exception ex)
			{
				ChartJotLog.Write("STATE", "Failed to save state file: " + ex.Message);
			}
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
}
