using ChartJot.Core;
using static ChartJot.Core.Tests.TestData;

namespace ChartJot.Core.Tests
{
	public class ExcursionTests
	{
		private static CompletedTrade LongTradeWith(Action<TradeTracker> midTrade)
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("a", "o1", "Entry", 1, 100m, 0, positionAfter: 1, isEntry: true));
			midTrade(tracker);
			return tracker.Apply(Sell("b", "o2", "Close", 1, 101m, 10, positionAfter: 0, isExit: true)).Closed[0];
		}

		[Fact]
		public void NoLiveTicks_ExcursionIsNullAndIncomplete()
		{
			CompletedTrade t = LongTradeWith(tracker => { });

			Assert.Null(t.Excursion);
			Assert.False(t.ExcursionComplete);
			Assert.All(t.Legs, l =>
			{
				Assert.Null(l.MaePoints);
				Assert.Null(l.MfePoints);
			});
		}

		[Fact]
		public void FeedInterrupted_KeepsObservedValuesButIsIncomplete()
		{
			CompletedTrade t = LongTradeWith(tracker =>
			{
				tracker.OnPrice("ES 12-26", 98m);
				tracker.MarkFeedInterrupted("ES 12-26");
			});

			Assert.NotNull(t.Excursion);
			Assert.Equal(2m, t.Excursion.MaePoints);
			Assert.False(t.ExcursionComplete);
		}

		[Fact]
		public void MarkFeedInterrupted_WithNoInstrument_FlagsEveryOpenTrade()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("a", "o1", "Entry", 1, 100m, 0, positionAfter: 1, isEntry: true));
			tracker.Apply(Buy("b", "o2", "Entry", 1, 100m, 0, positionAfter: 1, instrument: Mes, isEntry: true));

			tracker.MarkFeedInterrupted(null);

			Assert.All(tracker.OpenTrades, t => Assert.True(t.FeedInterrupted));
		}

		[Fact]
		public void UninterruptedFeed_IsComplete()
		{
			CompletedTrade t = LongTradeWith(tracker => tracker.OnPrice("ES 12-26", 99.5m));

			Assert.True(t.ExcursionComplete);
		}

		[Fact]
		public void ExcursionIsNeverNegative()
		{
			// Price only ever moves in the trade's favor after entry, and the exit is at the high.
			CompletedTrade t = LongTradeWith(tracker => tracker.OnPrice("ES 12-26", 100.5m));

			Assert.Equal(0m, t.Excursion.MaePoints);
			Assert.Equal(1m, t.Excursion.MfePoints);
		}

		[Fact]
		public void ZeroOrNegativeTicks_AreIgnored()
		{
			CompletedTrade t = LongTradeWith(tracker => tracker.OnPrice("ES 12-26", 0m));

			Assert.Null(t.Excursion);
		}

		[Fact]
		public void ReversalStartsAFreshRangeForTheNewTrade()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("a", "o1", "Entry", 1, 100m, 0, positionAfter: 1, isEntry: true));
			tracker.OnPrice("ES 12-26", 95m);
			ApplyResult flip = tracker.Apply(Sell("b", "o2", "", 2, 101m, 10, positionAfter: -1, isEntry: true, isExit: true));
			tracker.OnPrice("ES 12-26", 100.5m);
			CompletedTrade second = tracker.Apply(Buy("c", "o3", "Close", 1, 100m, 20, positionAfter: 0, isExit: true)).Closed[0];

			Assert.Equal(5m, flip.Closed[0].Excursion.MaePoints);
			// The short's range starts at the flipping fill (101), so 95 is not part of it.
			Assert.Equal(1m, second.Excursion.MfePoints);   // low 100 (exit fill)
			Assert.Equal(0m, second.Excursion.MaePoints);   // high 101 is the entry price
		}
	}
}
