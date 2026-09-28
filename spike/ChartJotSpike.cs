// Chart Jot - test AddOn ("spike").
//
// Read-only: it never places, changes or cancels orders and never writes NT8 or
// copier settings. It logs how NinjaTrader behaves during a few test trades so
// the real AddOn can be built on verified facts (see NT8.md "Spike to Verify").
//
// Log: %LOCALAPPDATA%\ChartJot\spike\spike-YYYYMMDD.log
// Open: Control Center -> New -> Chart Jot Test
// Remove: delete this file and recompile (NinjaScript Editor -> F5).

#region Using declarations
using System;
using System.Collections;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Reflection;
using System.Text;
using System.Threading;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media.Imaging;
using NinjaTrader.Cbi;
using NinjaTrader.Core;
using NinjaTrader.Data;
using NinjaTrader.Gui;
using NinjaTrader.Gui.Tools;
#endregion

namespace NinjaTrader.NinjaScript.AddOns
{
	public class ChartJotSpike : AddOnBase
	{
		private NTMenuItem	newMenu;
		private NTMenuItem	spikeMenuItem;

		protected override void OnStateChange()
		{
			if (State == State.SetDefaults)
			{
				Description	= "Chart Jot test AddOn (read-only diagnostics)";
				Name		= "ChartJotSpike";
			}
			else if (State == State.Terminated)
			{
				SpikeMonitor.Stop();
			}
		}

		protected override void OnWindowCreated(Window window)
		{
			SpikeMonitor.Start();
			SpikeMonitor.OnWindowCreated(window);

			ControlCenter cc = window as ControlCenter;
			if (cc == null)
				return;

			newMenu = cc.FindFirst("ControlCenterMenuItemNew") as NTMenuItem;
			if (newMenu == null)
			{
				SpikeLog.Write("UI", "Control Center 'New' menu not found; test window cannot be opened from the menu");
				return;
			}

			spikeMenuItem = new NTMenuItem { Header = "Chart Jot Test", Style = Application.Current.TryFindResource("MainMenuItem") as Style };
			newMenu.Items.Add(spikeMenuItem);
			spikeMenuItem.Click += OnMenuItemClick;
		}

		protected override void OnWindowDestroyed(Window window)
		{
			SpikeMonitor.OnWindowDestroyed(window);

			if (spikeMenuItem != null && window is ControlCenter)
			{
				if (newMenu != null && newMenu.Items.Contains(spikeMenuItem))
					newMenu.Items.Remove(spikeMenuItem);
				spikeMenuItem.Click -= OnMenuItemClick;
				spikeMenuItem = null;
			}
		}

		private void OnMenuItemClick(object sender, RoutedEventArgs e)
		{
			Core.Globals.RandomDispatcher.BeginInvoke(new Action(() => new ChartJotSpikeWindow().Show()));
		}
	}

	// ---------------------------------------------------------------- logging

	public static class SpikeLog
	{
		private static readonly object	sync		= new object();
		private static long				sequence;
		private static readonly DateTime startedAt	= DateTime.Now;
		private static readonly System.Diagnostics.Stopwatch clock = System.Diagnostics.Stopwatch.StartNew();

		public static event Action<string> LineWritten;

		public static string Folder
		{
			get
			{
				string dir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "ChartJot", "spike");
				Directory.CreateDirectory(dir);
				return dir;
			}
		}

		public static void Write(string check, string message)
		{
			string line;
			lock (sync)
			{
				sequence++;
				DateTime now = startedAt.AddTicks(clock.Elapsed.Ticks);
				line = string.Format(CultureInfo.InvariantCulture, "{0:yyyy-MM-dd HH:mm:ss.fff} #{1:D6} [{2}] {3}", now, sequence, check, message);
				try
				{
					File.AppendAllText(Path.Combine(Folder, "spike-" + DateTime.Now.ToString("yyyyMMdd") + ".log"), line + Environment.NewLine);
				}
				catch (Exception ex)
				{
					line += "  (log write failed: " + ex.Message + ")";
				}
			}

			Action<string> handler = LineWritten;
			if (handler != null)
				handler(line);
		}

		// Every readable public instance property as "Name=Value; ...". Used to discover
		// which Execution/Order properties exist in this NT8 version.
		public static string DumpProperties(object target)
		{
			if (target == null)
				return "(null)";

			StringBuilder sb = new StringBuilder();
			foreach (PropertyInfo p in target.GetType().GetProperties(BindingFlags.Public | BindingFlags.Instance).OrderBy(x => x.Name))
			{
				if (p.GetIndexParameters().Length > 0)
					continue;
				string value;
				try
				{
					object v = p.GetValue(target, null);
					value = v == null ? "null" : Convert.ToString(v, CultureInfo.InvariantCulture);
				}
				catch (Exception ex)
				{
					value = "<" + (ex.InnerException ?? ex).GetType().Name + ">";
				}
				if (value.Length > 200)
					value = value.Substring(0, 200) + "...";
				sb.Append(p.Name).Append('=').Append(value).Append("; ");
			}
			return sb.ToString();
		}
	}

	// ------------------------------------------------------- account monitor

	public static class SpikeMonitor
	{
		private class OpenPosition
		{
			public string			Account;
			public Instrument		Instrument;
			public int				Signed;
			public DateTime			OpenedAt;
			public double			High	= double.MinValue;
			public double			Low		= double.MaxValue;
			public long				Ticks;
			public bool				Interrupted;
			public List<Execution>	Fills	= new List<Execution>();
		}

		private class RecentEntry
		{
			public string	Account;
			public string	Market;
			public string	Instrument;
			public DateTime	At;
			public long		ClockMs;
		}

		private static readonly object							sync			= new object();
		private static bool										started;
		private static readonly List<Account>					subscribed		= new List<Account>();
		private static readonly HashSet<string>					seenExecutionIds = new HashSet<string>();
		private static readonly Dictionary<string, OpenPosition> positions		= new Dictionary<string, OpenPosition>();
		private static readonly Dictionary<string, MarketData>	marketData		= new Dictionary<string, MarketData>();
		private static readonly List<RecentEntry>				recentEntries	= new List<RecentEntry>();
		private static readonly List<Window>					chartWindows	= new List<Window>();
		private static readonly System.Diagnostics.Stopwatch	clock			= System.Diagnostics.Stopwatch.StartNew();
		private static bool										timeZoneLogged;
		private static Delegate									connectionHandler;

		public static volatile bool CopierInUse = true;

		public static void Start()
		{
			lock (sync)
			{
				if (started)
					return;
				started = true;
			}

			SpikeLog.Write("START", "Spike started. NT8 version=" + SafeNtVersion() + " .NET=" + Environment.Version + " copierInUse=" + CopierInUse);
			LogTimeZoneSettings();
			LogJsonCheck();
			SubscribeConnectionStatus();
			SubscribeAccounts("startup");
		}

		public static void Stop()
		{
			List<MarketData> stale;
			lock (sync)
			{
				if (!started)
					return;
				started = false;

				foreach (Account a in subscribed)
				{
					a.ExecutionUpdate	-= OnExecutionUpdate;
					a.OrderUpdate		-= OnOrderUpdate;
					a.PositionUpdate	-= OnPositionUpdate;
				}
				subscribed.Clear();

				stale = marketData.Values.ToList();
				marketData.Clear();
			}

			// Outside the lock: NT8 calls can block.
			foreach (MarketData md in stale)
			{
				try
				{
					md.Update -= OnMarketData;
				}
				catch { }
			}

			UnsubscribeConnectionStatus();
			SpikeLog.Write("STOP", "Spike stopped");
		}

		// ---- accounts (checks 1, 2)

		// The startup scan and the connect-triggered scan can race; run one at a time.
		private static readonly object scanGate = new object();

		private static void SubscribeAccounts(string reason)
		{
			lock (scanGate)
				SubscribeAccountsCore(reason);
		}

		private static void SubscribeAccountsCore(string reason)
		{
			List<Account> accounts;
			lock (Account.All)
				accounts = Account.All.ToList();

			foreach (Account account in accounts)
			{
				bool isNew;
				lock (sync)
				{
					isNew = !subscribed.Contains(account);
					if (isNew)
						subscribed.Add(account);
				}

				int existing = 0;
				List<string> ids = new List<string>();
				lock (account.Executions)
				{
					foreach (Execution ex in account.Executions)
					{
						existing++;
						if (ids.Count < 50)
							ids.Add(ex.ExecutionId);
					}
				}
				SpikeLog.Write("CHECK2", string.Format("Account {0} ({1}): {2} executions already in Account.Executions [{3}] ids: {4}",
					account.Name, reason, existing, isNew ? "subscribing" : "already subscribed", string.Join(",", ids)));

				// Known accounts are re-read too: at startup the account may not be connected
				// yet, and a position can change while the AddOn is not receiving events.
				// Copy under the lock, act after releasing it: Subscribe() calls into NT8.
				List<Position> open = new List<Position>();
				lock (account.Positions)
				{
					foreach (Position p in account.Positions)
						if (p.MarketPosition != MarketPosition.Flat)
							open.Add(p);
				}
				foreach (Position p in open)
				{
					int signed = p.MarketPosition == MarketPosition.Long ? p.Quantity : -p.Quantity;
					string key = Key(account.Name, p.Instrument);
					OpenPosition tracked;
					bool seeded;
					lock (sync)
					{
						positions.TryGetValue(key, out tracked);
						seeded = tracked == null || tracked.Signed != signed;
						if (seeded)
							positions[key] = new OpenPosition { Account = account.Name, Instrument = p.Instrument, Signed = signed, OpenedAt = DateTime.Now };
					}
					if (seeded)
						SpikeLog.Write("CHECK2", string.Format("Account {0} already open ({1}): {2} {3} {4}{5}", account.Name, reason,
							p.Instrument.FullName, p.MarketPosition, p.Quantity, tracked != null ? " [tracked " + tracked.Signed + ", resynced]" : ""));
					Subscribe(p.Instrument);
				}

				if (!isNew)
					continue;

				account.ExecutionUpdate	+= OnExecutionUpdate;
				account.OrderUpdate		+= OnOrderUpdate;
				account.PositionUpdate	+= OnPositionUpdate;
			}
		}

		private static void OnExecutionUpdate(object sender, ExecutionEventArgs e)
		{
			Execution ex = e.Execution;
			if (ex == null)
			{
				SpikeLog.Write("CHECK1", "ExecutionUpdate with null Execution");
				return;
			}

			Account account = sender as Account;
			string accountName = account != null ? account.Name : Convert.ToString(ex.Account);

			bool duplicate;
			lock (sync)
				duplicate = !seenExecutionIds.Add(accountName + "|" + ex.ExecutionId);

			SpikeLog.Write("CHECK2", string.Format("ExecutionUpdate account={0} id={1} {2}", accountName, ex.ExecutionId, duplicate ? "DUPLICATE (seen before)" : "new"));
			SpikeLog.Write("CHECK1", "Execution: " + SpikeLog.DumpProperties(ex));
			SpikeLog.Write("CHECK1", "Execution.Order: " + SpikeLog.DumpProperties(ex.Order));
			if (CopierInUse && ex.Order != null)
				SpikeLog.Write("CHECK7", string.Format("Order link: account={0} order={1} name='{2}' oco='{3}'", accountName, ex.Order.OrderId, ex.Order.Name, ex.Order.Oco));

			if (!timeZoneLogged)
			{
				timeZoneLogged = true;
				SpikeLog.Write("CHECK4", string.Format("Execution.Time={0:o} Kind={1} DateTime.Now={2:o} UtcNow={3:o}", ex.Time, ex.Time.Kind, DateTime.Now, DateTime.UtcNow));
			}

			if (duplicate)
				return;

			int delta = ex.MarketPosition == MarketPosition.Long ? ex.Quantity : ex.MarketPosition == MarketPosition.Short ? -ex.Quantity : 0;
			if (delta == 0)
			{
				SpikeLog.Write("CHECK1", "Execution MarketPosition not Long/Short: " + ex.MarketPosition);
				return;
			}

			string key = Key(accountName, ex.Instrument);
			OpenPosition pos;
			int before, after;
			bool opened = false, closed = false;
			lock (sync)
			{
				if (!positions.TryGetValue(key, out pos))
				{
					pos = new OpenPosition { Account = accountName, Instrument = ex.Instrument, OpenedAt = ex.Time };
					positions[key] = pos;
				}
				before		= pos.Signed;
				pos.Signed += delta;
				after		= pos.Signed;
				pos.Fills.Add(ex);
				Track(pos, ex.Price);

				if (before == 0 && after != 0)
					opened = true;
				if (before != 0 && (after == 0 || Math.Sign(after) != Math.Sign(before)))
					closed = true;
			}

			SpikeLog.Write("CHECK2", string.Format("Running position {0} {1}: {2} -> {3}{4}{5}", accountName, ex.Instrument.FullName, before, after,
				opened ? " OPENED" : "", closed ? (after == 0 ? " FLAT" : " REVERSED") : ""));

			if (opened || (closed && after != 0))
			{
				Subscribe(ex.Instrument);
				NoteEntry(accountName, ex);
			}

			if (closed)
				OnClosed(pos, key, after);
		}

		private static void OnOrderUpdate(object sender, OrderEventArgs e)
		{
			Account account = sender as Account;
			Order o = e.Order;
			SpikeLog.Write("CHECK2", string.Format("OrderUpdate account={0} order={1} name={2} state={3} filled={4} instrument={5}",
				account != null ? account.Name : "?", o != null ? o.OrderId : "?", o != null ? o.Name : "?", e.OrderState, e.Filled,
				o != null && o.Instrument != null ? o.Instrument.FullName : "?"));
		}

		private static void OnPositionUpdate(object sender, PositionEventArgs e)
		{
			Account account = sender as Account;
			SpikeLog.Write("CHECK2", string.Format("PositionUpdate account={0} instrument={1} marketPosition={2} qty={3} avg={4}",
				account != null ? account.Name : "?", e.Position != null && e.Position.Instrument != null ? e.Position.Instrument.FullName : "?",
				e.MarketPosition, e.Quantity, e.AveragePrice.ToString(CultureInfo.InvariantCulture)));
		}

		// ---- close handling (checks 3, 5)

		private static void OnClosed(OpenPosition pos, string key, int after)
		{
			List<Execution> fills;
			double high, low;
			long ticks;
			bool interrupted;
			lock (sync)
			{
				fills		= pos.Fills.ToList();
				high		= pos.High;
				low			= pos.Low;
				ticks		= pos.Ticks;
				interrupted	= pos.Interrupted;

				// Start fresh for the remainder (reversal) or the next trade.
				positions[key] = new OpenPosition { Account = pos.Account, Instrument = pos.Instrument, Signed = after, OpenedAt = DateTime.Now };
				if (after != 0 && fills.Count > 0)
					positions[key].Fills.Add(fills[fills.Count - 1]);
			}

			SpikeLog.Write("CHECK5", string.Format(CultureInfo.InvariantCulture, "Round turn closed {0} {1}: fills={2} liveTicks={3} high={4} low={5} feedInterrupted={6}",
				pos.Account, pos.Instrument.FullName, fills.Count, ticks, high == double.MinValue ? "n/a" : high.ToString(CultureInfo.InvariantCulture),
				low == double.MaxValue ? "n/a" : low.ToString(CultureInfo.InvariantCulture), interrupted));

			Dictionary<string, double> initial = new Dictionary<string, double>();
			foreach (Execution f in fills)
				initial[f.ExecutionId ?? ""] = f.Commission;
			SpikeLog.Write("CHECK3", "Commission at flat: " + string.Join(", ", initial.Select(kv => kv.Key + "=" + kv.Value.ToString(CultureInfo.InvariantCulture))));

			Task.Run(async () =>
			{
				int elapsed = 0;
				foreach (int at in new[] { 500, 1000, 2000, 5000 })
				{
					await Task.Delay(at - elapsed);
					elapsed = at;
					foreach (Execution f in fills)
					{
						string id = f.ExecutionId ?? "";
						double now = f.Commission;
						if (now != initial[id])
						{
							SpikeLog.Write("CHECK3", string.Format(CultureInfo.InvariantCulture, "Commission CHANGED after {0} ms: id={1} {2} -> {3}", at, id, initial[id], now));
							initial[id] = now;
						}
					}
				}
				SpikeLog.Write("CHECK3", "Commission re-check done for " + pos.Account + " " + pos.Instrument.FullName);
			});
		}

		private static void Track(OpenPosition pos, double price)
		{
			if (price > pos.High) pos.High = price;
			if (price < pos.Low) pos.Low = price;
		}

		private static void Subscribe(Instrument instrument)
		{
			lock (sync)
				if (marketData.ContainsKey(instrument.FullName))
					return;

			// Create outside the lock: the constructor calls into NT8 and can block.
			MarketData md;
			try
			{
				md = new MarketData(instrument);
				md.Update += OnMarketData;
			}
			catch (Exception ex)
			{
				SpikeLog.Write("CHECK5", "MarketData subscribe FAILED for " + instrument.FullName + ": " + ex.Message);
				return;
			}

			bool duplicate;
			lock (sync)
			{
				duplicate = marketData.ContainsKey(instrument.FullName);
				if (!duplicate)
					marketData[instrument.FullName] = md;
			}
			if (duplicate)
			{
				md.Update -= OnMarketData;
				return;
			}
			SpikeLog.Write("CHECK5", "MarketData subscribed for " + instrument.FullName);
		}

		private static void OnMarketData(object sender, MarketDataEventArgs e)
		{
			if (e.MarketDataType != MarketDataType.Last)
				return;
			Instrument instrument = (GetProp(e, "Instrument") ?? GetProp(sender, "Instrument")) as Instrument;
			if (instrument == null)
				return;

			lock (sync)
			{
				foreach (OpenPosition pos in positions.Values)
				{
					if (pos.Signed == 0 || pos.Instrument.FullName != instrument.FullName)
						continue;
					pos.Ticks++;
					Track(pos, e.Price);
				}
			}
		}

		// ---- connection (checks 2, 5)

		private static void SubscribeConnectionStatus()
		{
			try
			{
				EventInfo ev = typeof(Connection).GetEvent("ConnectionStatusUpdate", BindingFlags.Public | BindingFlags.Static);
				if (ev == null)
				{
					SpikeLog.Write("CHECK5", "Connection.ConnectionStatusUpdate static event NOT found");
					return;
				}
				MethodInfo handler = typeof(SpikeMonitor).GetMethod("OnConnectionStatus", BindingFlags.NonPublic | BindingFlags.Static);
				connectionHandler = Delegate.CreateDelegate(ev.EventHandlerType, handler);
				ev.AddEventHandler(null, connectionHandler);
				SpikeLog.Write("CHECK5", "Subscribed to Connection.ConnectionStatusUpdate");
			}
			catch (Exception ex)
			{
				SpikeLog.Write("CHECK5", "ConnectionStatusUpdate subscribe FAILED: " + ex.Message);
			}
		}

		private static void UnsubscribeConnectionStatus()
		{
			if (connectionHandler == null)
				return;
			try
			{
				typeof(Connection).GetEvent("ConnectionStatusUpdate", BindingFlags.Public | BindingFlags.Static).RemoveEventHandler(null, connectionHandler);
			}
			catch { }
			connectionHandler = null;
		}

		private static void OnConnectionStatus(object sender, EventArgs e)
		{
			object status = GetProp(e, "Status");
			object previous = GetProp(e, "PreviousStatus");
			object connection = GetProp(e, "Connection");
			object options = GetProp(connection, "Options");
			string name = Convert.ToString(GetProp(options, "Name") ?? connection);
			SpikeLog.Write("CHECK5", string.Format("Connection {0}: {1} -> {2}", name, previous, status));

			string s = Convert.ToString(status);
			if (s != "Connected")
			{
				lock (sync)
					foreach (OpenPosition pos in positions.Values)
						if (pos.Signed != 0)
							pos.Interrupted = true;
			}
			else
			{
				SubscribeAccounts("reconnect");
			}
		}

		// ---- copier (check 7)

		private static void NoteEntry(string accountName, Execution ex)
		{
			if (!CopierInUse)
			{
				SpikeLog.Write("CHECK7", "skipped (trade copier not in use): entry timing for " + accountName);
				return;
			}

			string market = MarketFamily(ex.Instrument);
			RecentEntry mine = new RecentEntry { Account = accountName, Market = market, Instrument = ex.Instrument.FullName, At = ex.Time, ClockMs = clock.ElapsedMilliseconds };
			List<RecentEntry> earlier;
			lock (sync)
			{
				recentEntries.RemoveAll(r => mine.ClockMs - r.ClockMs > 15000);
				earlier = recentEntries.Where(r => r.Market == market && r.Account != accountName).ToList();
				recentEntries.Add(mine);
			}

			foreach (RecentEntry r in earlier)
				SpikeLog.Write("CHECK7", string.Format("Possible copy: {0} {1} entered {2} ms (clock) / {3} ms (fill time) after {4} {5}; order name={6}",
					accountName, mine.Instrument, mine.ClockMs - r.ClockMs, (long)(mine.At - r.At).TotalMilliseconds, r.Account, r.Instrument,
					ex.Order != null ? ex.Order.Name : "?"));

			// Off the account event thread: reading the copier needs each chart's dispatcher.
			Task.Run(() => ReadCopierSetup("entry by " + accountName));
		}

		public static void RescanAccounts()
		{
			SubscribeAccounts("rescan");
		}

		public static void ReadCopierSetup(string reason)
		{
			if (!CopierInUse)
			{
				SpikeLog.Write("CHECK7", "skipped (trade copier not in use): read copier setup (" + reason + ")");
				return;
			}

			List<Window> charts;
			lock (sync)
				charts = chartWindows.ToList();

			int found = 0;
			foreach (Window w in charts)
			{
				try
				{
					w.Dispatcher.Invoke(() =>
					{
						foreach (object chartControl in ChartControls(w))
						{
							IEnumerable indicators = GetProp(chartControl, "Indicators") as IEnumerable;
							if (indicators == null)
							{
								SpikeLog.Write("CHECK7", "ChartControl.Indicators not readable on " + w.Title);
								continue;
							}
							foreach (object ind in indicators)
							{
								if (ind == null || ind.GetType().FullName != "NinjaTrader.NinjaScript.Indicators.aiDuplicateAccountActions")
									continue;
								found++;
								LogCopier(ind, w.Title, reason);
							}
						}
					});
				}
				catch (Exception ex)
				{
					SpikeLog.Write("CHECK7", "Reading chart '" + w.Title + "' FAILED: " + ex.Message);
				}
			}

			SpikeLog.Write("CHECK7", string.Format("Copier read ({0}): {1} copier instance(s) found on {2} tracked chart window(s)", reason, found, charts.Count));
		}

		private static string ContractSize(string value)
		{
			return value == "Yes" ? "Mini" : value == "No" ? "Micro" : value;
		}

		private static void LogCopier(object copier, string chartTitle, string reason)
		{
			StringBuilder sb = new StringBuilder();
			foreach (string name in new[] { "ThisMasterAccount", "CopierIsEnabled", "CopierMode", "InstrumentMode", "SingleInstrument", "ExecutionsRoundingMode", "MultiplierMode", "IsFadeEnabled" })
			{
				object v = GetProp(copier, name);
				sb.Append(name).Append('=').Append(v == null ? "<not readable>" : Convert.ToString(v, CultureInfo.InvariantCulture)).Append("; ");
			}
			SpikeLog.Write("CHECK7", "Copier on chart '" + chartTitle + "' (" + reason + "): " + sb);

			object data = GetProp(copier, "AllAccountData");
			IEnumerable rows = data as IEnumerable;
			if (data == null || rows == null || data is string)
			{
				SpikeLog.Write("CHECK7", "AllAccountData not readable as a list (type=" + (data == null ? "null" : data.GetType().FullName) + ")");
				return;
			}
			SpikeLog.Write("CHECK7", "AllAccountData type=" + data.GetType().FullName);
			foreach (object row in rows)
			{
				string text = Convert.ToString(row);
				if (string.IsNullOrEmpty(text))
					continue;
				// Column map confirmed by the vendor:
				// name|role|size|type(Yes=Mini,No=Micro,Nano)|fade|hide|goalLossAutoExit|fundedAutoClose|mode|blown|connection
				string[] c = text.Split('|');
				SpikeLog.Write("CHECK7", c.Length >= 11
					? string.Format("  row: account={0} role={1} size={2} type={3} fade={4} autoExit={5} fundedClose={6} mode={7} blown={8} connection={9} (columns={10})",
						c[0], c[1], c[2], ContractSize(c[3]), c[4], c[6], c[7], c[8], c[9], c[10], c.Length)
					: "  row with unexpected column count " + c.Length + ": " + text);
			}
		}

		// ---- charts and screenshots (check 8)

		public static void OnWindowCreated(Window window)
		{
			if (!(window is NinjaTrader.Gui.Chart.Chart))
				return;
			lock (sync)
				if (!chartWindows.Contains(window))
					chartWindows.Add(window);

			NinjaTrader.Gui.Chart.Chart chart = (NinjaTrader.Gui.Chart.Chart)window;
			object instrument = chart.ActiveChartControl != null ? GetProp(chart.ActiveChartControl, "Instrument") : null;
			SpikeLog.Write("CHECK8", "Chart window created: '" + window.Title + "' active instrument=" + Convert.ToString(instrument ?? "(not ready)"));
		}

		public static void OnWindowDestroyed(Window window)
		{
			if (!(window is NinjaTrader.Gui.Chart.Chart))
				return;
			lock (sync)
				chartWindows.Remove(window);
			SpikeLog.Write("CHECK8", "Chart window closed: '" + window.Title + "'");
			if (CopierInUse)
				Task.Run(() => ReadCopierSetup("after a chart closed"));
		}

		public static void TestScreenshots()
		{
			List<Window> charts;
			lock (sync)
				charts = chartWindows.ToList();

			if (charts.Count == 0)
			{
				SpikeLog.Write("CHECK8", "No chart windows tracked. Open a chart (after this AddOn loaded) and try again.");
				return;
			}

			int n = 0;
			foreach (Window w in charts)
			{
				NinjaTrader.Gui.Chart.Chart chart = w as NinjaTrader.Gui.Chart.Chart;
				if (chart == null)
					continue;

				BitmapSource bmp = null;
				string label = "unknown";
				bool copierHost = false;
				try
				{
					// Verified in NinjaTrader.Gui.dll: Chart.GetScreenshot(ShareScreenshotType) on the chart window.
					// It captures the tab currently shown in that window.
					w.Dispatcher.Invoke(() =>
					{
						List<object> controls = ChartControls(w).ToList();
						copierHost = controls.Any(HostsCopier);
						if (copierHost)
						{
							SpikeLog.Write("CHECK8", string.Format("Skipped copier host window '{0}' (instrument {1}); not a trading chart",
								w.Title, Convert.ToString(chart.ActiveChartControl != null ? GetProp(chart.ActiveChartControl, "Instrument") : null)));
							return;
						}
						label = "visible-tab-" + Convert.ToString(chart.ActiveChartControl != null ? GetProp(chart.ActiveChartControl, "Instrument") : null);
						if (controls.Count > 1)
							SpikeLog.Write("CHECK8", string.Format("'{0}' has {1} tabs; only the visible tab is captured (background tabs are not switched to)", w.Title, controls.Count));

						bmp = chart.GetScreenshot(ShareScreenshotType.Chart) as BitmapSource;
						if (bmp != null && bmp.CanFreeze)
							bmp.Freeze();
					});
				}
				catch (Exception ex)
				{
					SpikeLog.Write("CHECK8", "Chart.GetScreenshot on '" + w.Title + "' FAILED on dispatcher: " + (ex.InnerException ?? ex).Message);
					continue;
				}

				if (copierHost)
					continue;
				if (bmp == null)
				{
					SpikeLog.Write("CHECK8", "Chart.GetScreenshot returned nothing for '" + w.Title + "'");
					continue;
				}

				// Encode off the chart dispatcher, as the real AddOn will.
				n++;
				string file = Path.Combine(SpikeLog.Folder, string.Format("screenshot-{0:HHmmss}-{1}-{2}.png", DateTime.Now, n, Safe(label)));
				try
				{
					PngBitmapEncoder encoder = new PngBitmapEncoder();
					encoder.Frames.Add(BitmapFrame.Create(bmp));
					using (FileStream fs = File.Create(file))
						encoder.Save(fs);
					SpikeLog.Write("CHECK8", string.Format("Saved {0} ({1}x{2}) from '{3}', encoded on thread {4}", Path.GetFileName(file), bmp.PixelWidth, bmp.PixelHeight, w.Title, Thread.CurrentThread.ManagedThreadId));
				}
				catch (Exception ex)
				{
					SpikeLog.Write("CHECK8", "Encoding screenshot of '" + w.Title + "' FAILED: " + ex.Message);
				}
			}
		}

		// True when the chart hosts the Affordable Indicators copier (its dashboard lives in a chart window).
		private static bool HostsCopier(object chartControl)
		{
			IEnumerable indicators = GetProp(chartControl, "Indicators") as IEnumerable;
			if (indicators == null)
				return false;
			foreach (object ind in indicators)
				if (ind != null && ind.GetType().FullName == "NinjaTrader.NinjaScript.Indicators.aiDuplicateAccountActions")
					return true;
			return false;
		}

		// All ChartControls of a chart window (one per tab); must run on the window's dispatcher.
		private static IEnumerable<object> ChartControls(Window w)
		{
			List<object> result = new List<object>();
			TabControl tabs = GetProp(w, "MainTabControl") as TabControl;
			if (tabs != null)
			{
				foreach (object item in tabs.Items)
				{
					TabItem tabItem = item as TabItem;
					object content = tabItem != null ? tabItem.Content : item;
					object cc = GetProp(content, "ChartControl");
					if (cc != null)
						result.Add(cc);
				}
			}
			if (result.Count == 0)
			{
				object active = ((NinjaTrader.Gui.Chart.Chart)w).ActiveChartControl;
				if (active != null)
					result.Add(active);
			}
			return result;
		}

		// ---- time zone and JSON (checks 4, 6)

		private static void LogTimeZoneSettings()
		{
			SpikeLog.Write("CHECK4", "Windows TimeZoneInfo.Local=" + TimeZoneInfo.Local.Id + " offset now=" + TimeZoneInfo.Local.GetUtcOffset(DateTime.Now));
			try
			{
				object options = GetStatic(typeof(NinjaTrader.Core.Globals), "GeneralOptions");
				object tz = GetProp(options, "TimeZoneInfo");
				TimeZoneInfo tzInfo = tz as TimeZoneInfo;
				SpikeLog.Write("CHECK4", options == null
					? "Globals.GeneralOptions NOT found"
					: "Globals.GeneralOptions.TimeZoneInfo=" + (tzInfo != null ? tzInfo.Id : Convert.ToString(tz ?? "<not found>")));
			}
			catch (Exception ex)
			{
				SpikeLog.Write("CHECK4", "Reading NT8 time zone FAILED: " + ex.Message);
			}
		}

		private static void LogJsonCheck()
		{
			try
			{
				var sample = new { schema_version = 1, price = 7741.25m.ToString(CultureInfo.InvariantCulture), occurred_at = DateTimeOffset.Now.ToString("yyyy-MM-ddTHH:mm:sszzz") };
				SpikeLog.Write("CHECK6", "JavaScriptSerializer OK: " + new System.Web.Script.Serialization.JavaScriptSerializer().Serialize(sample));
			}
			catch (Exception ex)
			{
				SpikeLog.Write("CHECK6", "JavaScriptSerializer FAILED: " + ex.Message);
			}

			// For information only: NinjaScript cannot reference Newtonsoft on this install.
			Type newtonsoft = Type.GetType("Newtonsoft.Json.JsonConvert, Newtonsoft.Json");
			SpikeLog.Write("CHECK6", "Newtonsoft.Json loadable at runtime by reflection: " + (newtonsoft != null ? newtonsoft.Assembly.GetName().Version.ToString() : "no"));
		}

		// ---- helpers

		private static string Key(string account, Instrument instrument)
		{
			return account + "|" + instrument.FullName;
		}

		private static string MarketFamily(Instrument instrument)
		{
			string root = instrument.MasterInstrument.Name;
			switch (root)
			{
				case "MES": return "ES";
				case "MNQ": return "NQ";
				case "MYM": return "YM";
				case "M2K": return "RTY";
				case "MCL": return "CL";
				case "MGC": return "GC";
				default: return root;
			}
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

		private static object GetStatic(Type type, string name)
		{
			PropertyInfo p = type.GetProperty(name, BindingFlags.Public | BindingFlags.Static);
			if (p != null)
				return p.GetValue(null, null);
			FieldInfo f = type.GetField(name, BindingFlags.Public | BindingFlags.Static);
			return f != null ? f.GetValue(null) : null;
		}

		private static string SafeNtVersion()
		{
			try { return typeof(NinjaTrader.Core.Globals).Assembly.GetName().Version.ToString(); }
			catch { return "?"; }
		}

		private static string Safe(string s)
		{
			foreach (char c in Path.GetInvalidFileNameChars())
				s = s.Replace(c, '_');
			return s.Replace(' ', '_');
		}
	}

	// ------------------------------------------------------------ test window

	public class ChartJotSpikeWindow : NTWindow
	{
		private readonly TextBox logView;

		public ChartJotSpikeWindow()
		{
			Caption	= "Chart Jot Test";
			Width	= 900;
			Height	= 500;

			CheckBox copier = new CheckBox { Content = "Trade copier in use", IsChecked = SpikeMonitor.CopierInUse, Margin = new Thickness(4), VerticalAlignment = VerticalAlignment.Center };
			copier.Checked		+= (s, e) => { SpikeMonitor.CopierInUse = true; SpikeLog.Write("UI", "Trade copier in use: ON"); };
			copier.Unchecked	+= (s, e) => { SpikeMonitor.CopierInUse = false; SpikeLog.Write("UI", "Trade copier in use: OFF"); };

			Button read = new Button { Content = "Read copier setup", Margin = new Thickness(4), Padding = new Thickness(8, 2, 8, 2) };
			// Also re-scans accounts, so accounts added after connecting (e.g. copier playback accounts) are watched.
			read.Click += (s, e) => Task.Run(() => { SpikeMonitor.RescanAccounts(); SpikeMonitor.ReadCopierSetup("button"); });

			Button shot = new Button { Content = "Test screenshot", Margin = new Thickness(4), Padding = new Thickness(8, 2, 8, 2) };
			shot.Click += (s, e) => Task.Run(() => SpikeMonitor.TestScreenshots());

			Button folder = new Button { Content = "Open log folder", Margin = new Thickness(4), Padding = new Thickness(8, 2, 8, 2) };
			folder.Click += (s, e) => System.Diagnostics.Process.Start("explorer.exe", SpikeLog.Folder);

			StackPanel bar = new StackPanel { Orientation = Orientation.Horizontal };
			bar.Children.Add(copier);
			bar.Children.Add(read);
			bar.Children.Add(shot);
			bar.Children.Add(folder);

			logView = new TextBox
			{
				IsReadOnly					= true,
				TextWrapping				= TextWrapping.NoWrap,
				FontFamily					= new System.Windows.Media.FontFamily("Consolas"),
				VerticalScrollBarVisibility	= ScrollBarVisibility.Auto,
				HorizontalScrollBarVisibility = ScrollBarVisibility.Auto
			};

			DockPanel root = new DockPanel();
			DockPanel.SetDock(bar, Dock.Top);
			root.Children.Add(bar);
			root.Children.Add(logView);
			Content = root;

			SpikeLog.LineWritten += OnLine;
			Closed += (s, e) => SpikeLog.LineWritten -= OnLine;

			SpikeLog.Write("UI", "Test window opened. Log folder: " + SpikeLog.Folder);
		}

		private void OnLine(string line)
		{
			Dispatcher.InvokeAsync(() =>
			{
				logView.AppendText(line + Environment.NewLine);
				if (logView.LineCount > 2000)
					logView.Text = logView.Text.Substring(logView.Text.Length / 2);
				logView.ScrollToEnd();
			});
		}
	}
}
