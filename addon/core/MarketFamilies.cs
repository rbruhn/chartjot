using System;
using System.Collections.Generic;
using System.Linq;

namespace ChartJot.Core
{
	/// <summary>
	/// Which instruments count as the same market for copy matching. A group lists the Mini symbol first and the
	/// Micro symbol second, e.g. ES then MES. An instrument in no group matches only itself.
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

		/// <summary>Same market means the same family (or the same symbol) and the same contract month.</summary>
		public bool SameMarket(InstrumentSpec a, InstrumentSpec b)
		{
			if (ContractMonth(a) != ContractMonth(b))
				return false;
			return SameFamily(a.Symbol, b.Symbol);
		}

		public bool SameFamily(string symbolA, string symbolB)
		{
			string a = (symbolA ?? "").Trim().ToUpperInvariant();
			string b = (symbolB ?? "").Trim().ToUpperInvariant();
			if (a == b)
				return true;
			int ga = GroupOf(a);
			return ga >= 0 && ga == GroupOf(b);
		}

		public bool IsMapped(string symbol)
		{
			return GroupOf((symbol ?? "").Trim().ToUpperInvariant()) >= 0;
		}

		/// <summary>
		/// The symbol a follower of the given size is expected to trade in the master's market, or null when
		/// there is none (Nano, or an unmapped instrument).
		/// </summary>
		public string ExpectedSymbol(InstrumentSpec master, ContractSize size)
		{
			int g = GroupOf((master.Symbol ?? "").Trim().ToUpperInvariant());
			if (g < 0)
				return size == ContractSize.Nano ? null : master.Symbol;

			string[] group = groups[g];
			if (size == ContractSize.Mini)
				return group[0];
			if (size == ContractSize.Micro && group.Length > 1)
				return group[1];
			return null;
		}

		/// <summary>The part of the full name after the symbol, e.g. "12-26" for "ES 12-26".</summary>
		public static string ContractMonth(InstrumentSpec instrument)
		{
			string name = instrument.FullName ?? "";
			int space = name.IndexOf(' ');
			return space < 0 ? "" : name.Substring(space + 1).Trim();
		}

		private int GroupOf(string symbol)
		{
			for (int i = 0; i < groups.Count; i++)
				if (Array.IndexOf(groups[i], symbol) >= 0)
					return i;
			return -1;
		}
	}
}
