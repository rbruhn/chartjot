using ChartJot.Core;

namespace ChartJot.Core.Tests
{
	public class TradeTypesTests
	{
		[Theory]
		[InlineData("2ES", "Second Entry Short")]
		[InlineData("2EL", "Second Entry Long")]
		[InlineData("RS", "Range Short")]
		[InlineData("RL", "Range Long")]
		[InlineData("F2ES", "Failed Second Entry Short")]
		[InlineData("F2EL", "Failed Second Entry Long")]
		[InlineData("Other", "Other type of entry")]
		public void Label_MatchesTheNt8MdTable(string value, string label)
		{
			Assert.Equal(label, TradeTypes.Label(value));
		}

		[Fact]
		public void Label_IsNullForAnUnknownValue()
		{
			Assert.Null(TradeTypes.Label("XYZ"));
		}

		// A failed second entry is traded the opposite way (confirmed by the trader 2026-10-04).
		[Theory]
		[InlineData("2ES", Direction.Short)]
		[InlineData("2EL", Direction.Long)]
		[InlineData("RS", Direction.Short)]
		[InlineData("RL", Direction.Long)]
		[InlineData("F2ES", Direction.Long)]
		[InlineData("F2EL", Direction.Short)]
		public void ImpliedDirection_MatchesTheNt8MdTable(string value, Direction expected)
		{
			Assert.Equal(expected, TradeTypes.ImpliedDirection(value));
		}

		[Theory]
		[InlineData("Other")]
		[InlineData(null)]
		[InlineData("XYZ")]
		public void ImpliedDirection_IsNullWhenTheTypeImpliesNone(string value)
		{
			Assert.Null(TradeTypes.ImpliedDirection(value));
		}

		[Theory]
		[InlineData("2ES", Direction.Long)]
		[InlineData("F2ES", Direction.Short)]
		[InlineData("RL", Direction.Short)]
		public void DirectionWarning_WhenTheTypeImpliesTheOtherDirection(string value, Direction actual)
		{
			string warning = TradeTypes.DirectionWarning(value, actual);

			Assert.NotNull(warning);
			Assert.Contains(TradeTypes.Label(value), warning);
		}

		[Theory]
		[InlineData("2ES", Direction.Short)]
		[InlineData("F2ES", Direction.Long)]
		[InlineData("Other", Direction.Long)]
		[InlineData(null, Direction.Short)]
		public void DirectionWarning_IsNullWhenTheyAgreeOrNothingIsImplied(string value, Direction actual)
		{
			Assert.Null(TradeTypes.DirectionWarning(value, actual));
		}
	}
}
