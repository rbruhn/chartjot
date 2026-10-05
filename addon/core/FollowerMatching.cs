using System;
using System.Collections.Generic;
using System.Linq;

namespace ChartJot.Core
{
	/// <summary>
	/// Which instruments count as the same market for follower matching: a Mini and its Micro (ES and MES, ...),
	/// in the same contract month. An instrument in no group matches only itself. Restored from before #28.
	/// </summary>
	public sealed class MarketFamilies
	{
		private readonly List<string[]> groups;

		public MarketFamilies(IEnumerable<string[]> groups)
		{
			this.groups = groups.Select(g => g.Select(s => s.Trim().ToUpperInvariant()).ToArray()).ToList();
		}

		public static MarketFamilies Default
		{
			get
			{
				return new MarketFamilies(new[]
				{
					new[] { "ES", "MES" },
					new[] { "NQ", "MNQ" },
					new[] { "YM", "MYM" },
					new[] { "RTY", "M2K" },
					new[] { "CL", "MCL" },
					new[] { "GC", "MGC" }
				});
			}
		}

		/// <summary>Same family (or the same symbol) and the same contract month.</summary>
		public bool SameMarket(InstrumentSpec a, InstrumentSpec b)
		{
			if (ContractMonth(a) != ContractMonth(b))
				return false;

			string symbolA = (a.Symbol ?? "").Trim().ToUpperInvariant();
			string symbolB = (b.Symbol ?? "").Trim().ToUpperInvariant();
			if (symbolA == symbolB)
				return true;
			int group = GroupOf(symbolA);
			return group >= 0 && group == GroupOf(symbolB);
		}

		/// <summary>The part of the full name after the symbol, e.g. "12-26" for "ES 12-26".</summary>
		public static string ContractMonth(InstrumentSpec instrument)
		{
			string name = (instrument.FullName ?? "").Trim();
			int space = name.LastIndexOf(' ');
			return space < 0 ? "" : name.Substring(space + 1);
		}

		private int GroupOf(string symbol)
		{
			for (int i = 0; i < groups.Count; i++)
			{
				if (Array.IndexOf(groups[i], symbol) >= 0)
					return i;
			}
			return -1;
		}
	}

	/// <summary>One follower row of the copier's setup for a master.</summary>
	public sealed class FollowerSetup
	{
		public string Account { get; set; }

		/// <summary>The follower takes the opposite side of the master.</summary>
		public bool Fade { get; set; }

		/// <summary>Dashboard Mode column is "Executions" (copies fills); anything else is Orders mode.</summary>
		public bool ExecutionsMode { get; set; }

		/// <summary>Marked blown in the copier. Informational: a trade it did take is still matched.</summary>
		public bool Blown { get; set; }
	}

	/// <summary>
	/// Reads the Affordable Indicators copier's <c>AllAccountData</c> rows. Vendor-confirmed column map:
	/// <c>name|role|size|type|fade|hide|goalLossAutoExit|fundedAutoClose|mode|blown|connection</c>.
	/// </summary>
	public static class CopierSetup
	{
		/// <summary>The rows whose role is "Slave", other than the master itself. Malformed rows are skipped;
		/// missing trailing columns default to no fade, Orders mode, not blown.</summary>
		public static IList<FollowerSetup> Followers(string masterAccount, IEnumerable<string> rows)
		{
			List<FollowerSetup> followers = new List<FollowerSetup>();
			if (rows == null)
				return followers;

			foreach (string row in rows)
			{
				if (string.IsNullOrWhiteSpace(row))
					continue;
				string[] c = row.Split('|');
				if (c.Length < 2 || string.IsNullOrWhiteSpace(c[0]))
					continue;

				string account = c[0].Trim();
				if (string.Equals(account, masterAccount, StringComparison.OrdinalIgnoreCase))
					continue;
				if (!string.Equals(c[1].Trim(), "Slave", StringComparison.OrdinalIgnoreCase))
					continue;

				followers.Add(new FollowerSetup
				{
					Account = account,
					Fade = Yes(Column(c, 4)),
					ExecutionsMode = string.Equals(Column(c, 8), "Executions", StringComparison.OrdinalIgnoreCase),
					Blown = Yes(Column(c, 9))
				});
			}
			return followers;
		}

		private static string Column(string[] columns, int index)
		{
			return index < columns.Length ? columns[index].Trim() : "";
		}

		private static bool Yes(string value)
		{
			return string.Equals(value, "Yes", StringComparison.OrdinalIgnoreCase);
		}
	}

	/// <summary>
	/// Finds each follower's own trade for a master trade, so it can carry the master's note, trade type and
	/// screenshot while sending its own data. The vendor-confirmed rules from before #28: same market
	/// (<see cref="MarketFamilies"/>), the master's direction or the opposite for a fading follower, and an entry
	/// from <paramref name="window"/> before the master's entry up to <paramref name="window"/> after it
	/// (Executions mode copies fills) or up to the master's exit (Orders mode copies working orders). The earliest
	/// qualifying trade per follower wins.
	/// </summary>
	public static class FollowerMatcher
	{
		public static readonly TimeSpan DefaultWindow = TimeSpan.FromSeconds(5);

		public static IList<CompletedTrade> Match(CompletedTrade master, IEnumerable<FollowerSetup> followers,
			IEnumerable<CompletedTrade> candidates, TimeSpan window)
		{
			if (master == null)
				throw new ArgumentNullException("master");

			List<CompletedTrade> pool = (candidates ?? Enumerable.Empty<CompletedTrade>()).ToList();
			MarketFamilies families = MarketFamilies.Default;
			List<CompletedTrade> matched = new List<CompletedTrade>();

			foreach (FollowerSetup follower in followers ?? Enumerable.Empty<FollowerSetup>())
			{
				if (string.Equals(follower.Account, master.Account, StringComparison.OrdinalIgnoreCase))
					continue;

				Direction wanted = follower.Fade
					? (master.Direction == Direction.Long ? Direction.Short : Direction.Long)
					: master.Direction;
				DateTimeOffset lower = master.EntryAt - window;
				DateTimeOffset upper = follower.ExecutionsMode ? master.EntryAt + window : master.ExitAt;

				CompletedTrade match = pool
					.Where(t => string.Equals(t.Account, follower.Account, StringComparison.OrdinalIgnoreCase))
					.Where(t => t.TradeId != master.TradeId && t.Direction == wanted)
					.Where(t => t.EntryAt >= lower && t.EntryAt <= upper)
					.Where(t => families.SameMarket(master.Instrument, t.Instrument))
					.OrderBy(t => t.EntryAt)
					.FirstOrDefault();
				if (match != null && !matched.Contains(match))
					matched.Add(match);
			}
			return matched;
		}
	}

	/// <summary>
	/// The one note form for a chart's Chart Trader account and instrument: a note written before, during or after
	/// the trade, and its trade type. Submit sends it with the closed trade(s); Reset clears it for the next idea.
	/// </summary>
	public sealed class TradeForm
	{
		public string Account { get; set; }
		public string Instrument { get; set; }

		/// <summary>Null when empty.</summary>
		public string Body { get; set; }
		public string TradeType { get; set; }
		public string TradeTypeOther { get; set; }

		/// <summary>When the trader started writing this note; becomes the note's occurred_at.</summary>
		public DateTimeOffset? StartedAt { get; set; }

		/// <summary>The trade_ids the last Submit sent (master first, then followers), for the status line.</summary>
		public IList<string> LastSubmitted { get; set; }

		/// <summary>
		/// When the form started caring about trades (wall clock): when it was first opened in this NT session, or
		/// the last Submit/Reset. Only trades staged at or after it are the form's; null means no limit (older
		/// state files).
		/// </summary>
		public DateTimeOffset? CycleStartedAt { get; set; }
	}
}
