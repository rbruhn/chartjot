using System;

namespace ChartJot.Core
{
	public static class ExitReason
	{
		public const string Stop = "stop";
		public const string ProfitTarget = "profit_target";
		public const string Exit = "exit";
		public const string Other = "other";

		/// <summary>Classified from the exit order name, case-insensitive.</summary>
		public static string Classify(string orderName)
		{
			string name = (orderName ?? "").Trim();
			if (name.Length == 0)
				return Other;

			if (StartsWith(name, "Stop"))
				return Stop;
			if (StartsWith(name, "Target") || StartsWith(name, "Profit"))
				return ProfitTarget;
			if (Equals(name, "Exit") || Equals(name, "Close") || Equals(name, "Close position") || Equals(name, "Flatten"))
				return Exit;
			return Other;
		}

		private static bool StartsWith(string name, string prefix)
		{
			return name.StartsWith(prefix, StringComparison.OrdinalIgnoreCase);
		}

		private static bool Equals(string name, string expected)
		{
			return string.Equals(name, expected, StringComparison.OrdinalIgnoreCase);
		}
	}
}
