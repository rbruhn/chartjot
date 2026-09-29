using ChartJot.Core;
using static ChartJot.Core.Tests.TestData;

namespace ChartJot.Core.Tests
{
	public class RestoreExcursionTests
	{
		private static readonly Fill Entry = Buy("a", "o1", "Entry", 1, 7700m, 0, positionAfter: 1, isEntry: true);

		[Fact]
		public void RestoreExcursion_AfterRebuild_MergesPersistedRangeAndFlagsInterrupted()
		{
			// The trade_id as it was before a restart (deterministic from the first entry's ExecutionId).
			TradeTracker before = new TradeTracker();
			OpenTradeInfo opened = before.Apply(Entry).Opened;

			// A fresh process, as after an AddOn restart: nothing in memory, rebuilt from Account.Executions alone.
			TradeTracker after = new TradeTracker();
			RebuildResult rebuild = after.Rebuild(Entry.Account, Entry.Instrument.FullName, new[] { Entry }, expectedPosition: 1);
			Assert.True(rebuild.Consistent);
			Assert.False(rebuild.Open.Excursion.HadTicks);

			after.RestoreExcursion(Entry.Account, Entry.Instrument.FullName, opened.TradeId,
				new ExcursionSnapshot { High = 7705m, Low = 7698m, HadTicks = true });

			OpenTradeInfo restored = after.OpenTrades[0];
			Assert.True(restored.Excursion.HadTicks);
			Assert.Equal(7705m, restored.Excursion.High);
			Assert.Equal(7698m, restored.Excursion.Low);
			Assert.True(restored.FeedInterrupted);
		}

		[Fact]
		public void RestoreExcursion_WhenTradeIdDoesNotMatch_IsNoOp()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Entry);

			tracker.RestoreExcursion(Entry.Account, Entry.Instrument.FullName, "a-different-trade-id",
				new ExcursionSnapshot { High = 9999m, Low = 1m, HadTicks = true });

			OpenTradeInfo unchanged = tracker.OpenTrades[0];
			Assert.False(unchanged.Excursion.HadTicks);
		}

		[Fact]
		public void RestoreExcursion_WhenNoOpenTrade_DoesNotThrow()
		{
			TradeTracker tracker = new TradeTracker();

			tracker.RestoreExcursion("TEST-ACCT-001", "ES 12-26", "anything",
				new ExcursionSnapshot { High = 1m, Low = 1m, HadTicks = true });
		}
	}
}
