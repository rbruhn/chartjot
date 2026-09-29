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
		public void RestoreExcursion_WithFillExcursions_RestoresEachFillsOwnRange()
		{
			Fill entry = Buy("a", "o1", "Entry", 2, 7700m, 0, 0, positionAfter: 2, isEntry: true);
			Fill target = Sell("b", "o2", "Target1", 1, 7702m, 30, 0, positionAfter: 1, isExit: true);
			TradeTracker before = new TradeTracker();
			string tradeId = before.Apply(entry).Opened.TradeId;
			before.OnPrice(Es.FullName, 7699m);
			before.Apply(target);
			before.OnPrice(Es.FullName, 7708m);
			OpenTradeInfo saved = before.OpenTrades[0];

			TradeTracker after = new TradeTracker();
			after.Rebuild(entry.Account, Es.FullName, new[] { entry, target }, expectedPosition: 1);
			after.RestoreExcursion(entry.Account, Es.FullName, tradeId, saved.Excursion,
				saved.Fills.ToDictionary(f => f.Fill.ExecutionId, f => f.Excursion));
			CompletedTrade closed = after.Apply(Sell("c", "o3", "Stop1", 1, 7701m, 90, 0, positionAfter: 0, isExit: true)).Closed[0];

			Assert.Equal(2m, closed.Legs[0].MfePoints);
			Assert.Equal(1m, closed.Legs[0].MaePoints);
			Assert.Equal(8m, closed.Legs[1].MfePoints);
		}

		[Fact]
		public void RestoreExcursion_WithoutFillExcursions_LeavesEarlierFillsAlone()
		{
			Fill entry = Buy("a", "o1", "Entry", 2, 7700m, 0, 0, positionAfter: 2, isEntry: true);
			Fill target = Sell("b", "o2", "Target1", 1, 7702m, 30, 0, positionAfter: 1, isExit: true);
			TradeTracker tracker = new TradeTracker();
			tracker.Rebuild(entry.Account, Es.FullName, new[] { entry, target }, expectedPosition: 1);

			tracker.RestoreExcursion(entry.Account, Es.FullName, tracker.OpenTrades[0].TradeId,
				new ExcursionSnapshot { High = 7708m, Low = 7699m, HadTicks = true });

			Assert.All(tracker.OpenTrades[0].Fills, f => Assert.False(f.Excursion.HadTicks));
		}

		[Fact]
		public void RestoreExcursion_WhenNoOpenTrade_DoesNotThrow()
		{
			TradeTracker tracker = new TradeTracker();

			tracker.RestoreExcursion("APEX-24570-135", "ES 12-26", "anything",
				new ExcursionSnapshot { High = 1m, Low = 1m, HadTicks = true });
		}
	}
}
