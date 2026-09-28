using System;
using System.Globalization;

namespace ChartJot.Core
{
	/// <summary>
	/// Decimal strings for the payload. Values are never sent as binary floating point.
	/// At most 8 decimal places, never fewer than the caller's minimum.
	/// </summary>
	public static class DecimalFormat
	{
		public const int MaxDecimals = 8;

		public static string Format(decimal value, int minDecimals)
		{
			decimal rounded = Math.Round(value, MaxDecimals, MidpointRounding.AwayFromZero);
			if (rounded == 0m)
				rounded = 0m; // drop any negative-zero sign

			string text = rounded.ToString("F" + MaxDecimals, CultureInfo.InvariantCulture);
			int dot = text.IndexOf('.');
			int keep = text.Length;
			int floor = dot + 1 + Math.Max(0, minDecimals);
			while (keep > floor && text[keep - 1] == '0')
				keep--;
			text = text.Substring(0, keep);
			return text.EndsWith(".", StringComparison.Ordinal) ? text.Substring(0, text.Length - 1) : text;
		}

		/// <summary>Price: at least as many decimals as the tick size (0.25 gives 7740.00).</summary>
		public static string Price(decimal value, decimal tickSize)
		{
			return Format(value, Scale(tickSize));
		}

		/// <summary>Currency: at least two decimals.</summary>
		public static string Money(decimal value)
		{
			return Format(value, 2);
		}

		/// <summary>Number of decimal places, ignoring trailing zeros (0.250 is 2).</summary>
		public static int Scale(decimal value)
		{
			decimal normalized = value / 1.000000000000000000000000000000000m;
			return (decimal.GetBits(normalized)[3] >> 16) & 0xFF;
		}
	}
}
