using System.Text.RegularExpressions;
using ChartJot.Core;

namespace ChartJot.Core.Tests
{
	public class HelpersTests
	{
		[Theory]
		[InlineData("Stop1", "stop")]
		[InlineData("Stop loss", "stop")]
		[InlineData("stop2", "stop")]
		[InlineData("Target1", "profit_target")]
		[InlineData("Profit target", "profit_target")]
		[InlineData("Exit", "exit")]
		[InlineData("Close", "exit")]
		[InlineData("Close position", "exit")]
		[InlineData("Flatten", "exit")]
		[InlineData("AI", "other")]
		[InlineData("Entry", "other")]
		[InlineData("", "other")]
		[InlineData(null, "other")]
		public void ExitReason_IsClassifiedFromTheOrderName(string name, string expected)
		{
			Assert.Equal(expected, ExitReason.Classify(name));
		}

		[Fact]
		public void TradeId_HasTheDocumentedShape()
		{
			string id = TradeIds.Create("APEX-24570-135", "ES 12-26", "1096239|2907664565|2907664565");

			Assert.Matches(new Regex("^NT8-APEX_24570_135-ES_12_26-[0-9a-f]{16}$"), id);
		}

		[Fact]
		public void TradeId_IsStableAndDependsOnTheFirstEntryFill()
		{
			string a = TradeIds.Create("Sim101", "ES 12-26", "abc");

			Assert.Equal(a, TradeIds.Create("Sim101", "ES 12-26", "abc"));
			Assert.NotEqual(a, TradeIds.Create("Sim101", "ES 12-26", "abd"));
			Assert.NotEqual(a, TradeIds.Create("Sim110", "ES 12-26", "abc"));
			Assert.NotEqual(a, TradeIds.Create("Sim101", "MES 12-26", "abc"));
		}

		[Fact]
		public void TradeId_HashUsesTheRawNamesNotTheSanitizedOnes()
		{
			// "A-1" and "A_1" sanitize the same but must not collide.
			Assert.NotEqual(TradeIds.Create("A-1", "ES 12-26", "x"), TradeIds.Create("A_1", "ES 12-26", "x"));
		}

		[Fact]
		public void TradeId_KnownHash()
		{
			// SHA-256("Sim101|ES 12-26|abc"), first 8 bytes. Pins the algorithm and the separator.
			Assert.EndsWith("-" + Sha256Prefix("Sim101|ES 12-26|abc"), TradeIds.Create("Sim101", "ES 12-26", "abc"));
		}

		private static string Sha256Prefix(string text)
		{
			byte[] hash = System.Security.Cryptography.SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(text));
			return Convert.ToHexString(hash).ToLowerInvariant().Substring(0, 16);
		}

		[Theory]
		[InlineData("7740", "0.25", "7740.00")]
		[InlineData("7701.5", "0.25", "7701.50")]
		[InlineData("7701.75", "0.25", "7701.75")]
		[InlineData("7700", "1", "7700")]
		[InlineData("7700", "0.03125", "7700.00000")]
		[InlineData("7737.83333333333", "0.25", "7737.83333333")]
		[InlineData("0.123456789", "0.25", "0.12345679")]
		[InlineData("-1.5", "0.25", "-1.50")]
		[InlineData("-0.000000001", "0.25", "0.00")]
		public void Price_KeepsAtLeastTheTickSizeDecimalsAndAtMostEight(string value, string tick, string expected)
		{
			Assert.Equal(expected, DecimalFormat.Price(decimal.Parse(value, System.Globalization.CultureInfo.InvariantCulture),
				decimal.Parse(tick, System.Globalization.CultureInfo.InvariantCulture)));
		}

		[Theory]
		[InlineData("225", "225.00")]
		[InlineData("217.26", "217.26")]
		[InlineData("0", "0.00")]
		[InlineData("-128.98", "-128.98")]
		[InlineData("1.9925", "1.9925")]
		public void Money_HasAtLeastTwoDecimals(string value, string expected)
		{
			Assert.Equal(expected, DecimalFormat.Money(decimal.Parse(value, System.Globalization.CultureInfo.InvariantCulture)));
		}

		[Theory]
		[InlineData("0.25", 2)]
		[InlineData("0.250", 2)]
		[InlineData("1", 0)]
		[InlineData("0.03125", 5)]
		public void Scale_IgnoresTrailingZeros(string value, int expected)
		{
			Assert.Equal(expected, DecimalFormat.Scale(decimal.Parse(value, System.Globalization.CultureInfo.InvariantCulture)));
		}
	}
}
