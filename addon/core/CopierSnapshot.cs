using System;
using System.Collections.Generic;
using System.Globalization;

namespace ChartJot.Core
{
	public enum ContractSize
	{
		Mini,
		Micro,
		Nano
	}

	/// <summary>One account's row from the copier's AllAccountData.</summary>
	public sealed class FollowerSetup
	{
		public string Account { get; set; }

		/// <summary>True when the row's role is "Slave" (a follower of this master).</summary>
		public bool IsFollower { get; set; }

		public decimal Multiplier { get; set; }
		public ContractSize Size { get; set; }

		/// <summary>Follower takes the opposite side of the master.</summary>
		public bool Fade { get; set; }

		/// <summary>Account is marked blown: not expected to copy.</summary>
		public bool Blown { get; set; }

		/// <summary>Dashboard Mode column is "Executions". Anything else is Orders mode.</summary>
		public bool ExecutionsMode { get; set; }

		public string Connection { get; set; }
	}

	/// <summary>The copier setup for one master, read when the master enters a trade.</summary>
	public sealed class CopierSnapshot
	{
		public string MasterAccount { get; set; }

		/// <summary>copier_live or copier_workspace.</summary>
		public string Source { get; set; }

		public bool Enabled { get; set; }
		public bool AllInstruments { get; set; }
		public string SingleInstrument { get; set; }
		public string RoundingMode { get; set; }
		public string MultiplierMode { get; set; }
		public IList<FollowerSetup> Followers { get; set; }

		/// <summary>Things the parser tolerated (short rows, unknown values). For the diagnostic log.</summary>
		public IList<string> Diagnostics { get; set; }

		public IEnumerable<FollowerSetup> FollowerRows
		{
			get
			{
				foreach (FollowerSetup f in Followers)
					if (f.IsFollower)
						yield return f;
			}
		}
	}

	public static class CopierSnapshotParser
	{
		public const string SourceLive = "copier_live";
		public const string SourceWorkspace = "copier_workspace";

		/// <summary>
		/// Builds a snapshot from the copier's property values. Returns null (the caller falls back to the next
		/// source) when the master is unknown or no row can be parsed. Short rows default to Mini, no Fade, not
		/// Blown, Default mode; extra columns are ignored.
		/// </summary>
		public static CopierSnapshot Parse(string masterAccount, bool enabled, string instrumentMode, string singleInstrument,
			string roundingMode, string multiplierMode, IEnumerable<string> rows, string source)
		{
			if (string.IsNullOrWhiteSpace(masterAccount) || rows == null)
				return null;

			List<string> diagnostics = new List<string>();
			List<FollowerSetup> followers = new List<FollowerSetup>();
			int rowCount = 0;

			foreach (string row in rows)
			{
				if (string.IsNullOrWhiteSpace(row))
					continue;
				rowCount++;

				string[] c = row.Split('|');
				if (c.Length < 3 || string.IsNullOrWhiteSpace(c[0]))
				{
					diagnostics.Add("skipped a row with " + c.Length + " column(s): " + row);
					continue;
				}

				string account = c[0].Trim();
				if (string.Equals(account, masterAccount, StringComparison.OrdinalIgnoreCase))
					continue;

				// A blank size means the Size column is off: the follower trades the master's exact quantity.
				decimal multiplier = 1m;
				if (c[2].Trim().Length > 0 && !TryMultiplier(c[2], out multiplier))
				{
					diagnostics.Add("account " + account + ": unreadable multiplier '" + c[2] + "', using 1");
					multiplier = 1m;
				}

				followers.Add(new FollowerSetup
				{
					Account = account,
					IsFollower = string.Equals(c[1].Trim(), "Slave", StringComparison.OrdinalIgnoreCase),
					Multiplier = multiplier,
					Size = Size(Column(c, 3), account, diagnostics),
					Fade = Yes(Column(c, 4)),
					ExecutionsMode = string.Equals(Column(c, 8), "Executions", StringComparison.OrdinalIgnoreCase),
					Blown = Yes(Column(c, 9)),
					Connection = Column(c, 10)
				});
			}

			if (rowCount > 0 && followers.Count == 0 && diagnostics.Count > 0)
				return null; // nothing parsed

			return new CopierSnapshot
			{
				MasterAccount = masterAccount.Trim(),
				Source = source,
				Enabled = enabled,
				AllInstruments = !string.Equals((instrumentMode ?? "").Trim(), "Single", StringComparison.OrdinalIgnoreCase),
				SingleInstrument = singleInstrument,
				RoundingMode = roundingMode,
				MultiplierMode = multiplierMode,
				Followers = followers,
				Diagnostics = diagnostics
			};
		}

		private static string Column(string[] columns, int index)
		{
			return index < columns.Length ? columns[index].Trim() : "";
		}

		private static bool Yes(string value)
		{
			return string.Equals(value, "Yes", StringComparison.OrdinalIgnoreCase);
		}

		private static ContractSize Size(string value, string account, IList<string> diagnostics)
		{
			if (value.Length == 0 || Yes(value))
				return ContractSize.Mini;
			if (string.Equals(value, "No", StringComparison.OrdinalIgnoreCase))
				return ContractSize.Micro;
			if (string.Equals(value, "Nano", StringComparison.OrdinalIgnoreCase))
				return ContractSize.Nano;

			diagnostics.Add("account " + account + ": unknown contract size '" + value + "', using Mini");
			return ContractSize.Mini;
		}

		/// <summary>The dashboard shows the size as "3x", "3", a decimal like "1.25", or a fraction like "1/2".</summary>
		private static bool TryMultiplier(string text, out decimal value)
		{
			string t = (text ?? "").Trim();
			if (t.EndsWith("x", StringComparison.OrdinalIgnoreCase))
				t = t.Substring(0, t.Length - 1).Trim();

			int slash = t.IndexOf('/');
			if (slash > 0)
			{
				decimal numerator, denominator;
				if (decimal.TryParse(t.Substring(0, slash), NumberStyles.Number, CultureInfo.InvariantCulture, out numerator)
					&& decimal.TryParse(t.Substring(slash + 1), NumberStyles.Number, CultureInfo.InvariantCulture, out denominator)
					&& denominator > 0m && numerator > 0m)
				{
					value = numerator / denominator;
					return true;
				}
				value = 0m;
				return false;
			}
			return decimal.TryParse(t, NumberStyles.Number, CultureInfo.InvariantCulture, out value) && value > 0m;
		}
	}
}
