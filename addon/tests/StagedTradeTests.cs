using ChartJot.Core;
using static ChartJot.Core.Tests.TestData;

namespace ChartJot.Core.Tests
{
	public class StagedTradeTests
	{
		// The worked example from TradeTrackerTests: buy 3 at 7700, sell 2 at 7701 (Target1), sell 1 at 7702.50 (Stop1).
		private static CompletedTrade WorkedExample()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("e1", "ord-1001", "Entry", 3, 7700.00m, 0, 3.87m, 3, isEntry: true));
			tracker.OnPrice("ES 12-26", 7699.25m);
			tracker.OnPrice("ES 12-26", 7700.50m);
			tracker.Apply(Sell("e2", "ord-1002", "Target1", 2, 7701.00m, 70, 2.58m, 1, isExit: true));
			tracker.OnPrice("ES 12-26", 7703.25m);
			tracker.OnPrice("ES 12-26", 7702.00m);
			return tracker.Apply(Sell("e3", "ord-1003", "Stop1", 1, 7702.50m, 380, 1.29m, 0, isExit: true)).Closed[0];
		}

		private static CopyEvaluation SampleCopies()
		{
			CopierSnapshot setup = CopierSnapshotParser.Parse("APEX-24570-135", true, "All", "ES", "Round Up At 0.5", "Multiplier",
				new[] { "PA-02|Slave|1|No|No|No|No|No|Default|No|Rithmic" }, CopierSnapshotParser.SourceLive);

			TradeTracker followerTracker = new TradeTracker();
			followerTracker.Apply(Buy("f1", "fo1", "Entry", 3, 7700.00m, 1, 0.5m, 3, "PA-02", isEntry: true));
			CompletedTrade follower = followerTracker.Apply(Sell("f2", "fo2", "Close", 3, 7701.50m, 200, 0.5m, 0, "PA-02", isExit: true)).Closed[0];

			return CopyMatcher.Evaluate(WorkedExample(), setup, new MatchOptions(), new[] { CopyCandidate.FromClosed(follower) });
		}

		private static StagedTrade FullExample()
		{
			return new StagedTrade
			{
				Trade = WorkedExample(),
				Notes = new List<NoteRecord>
				{
					new NoteRecord { Body = "watching for a breakout", Phase = NotePhases.PreTrade, OccurredAt = At(-30) },
					new NoteRecord { Body = "in it now", Phase = NotePhases.InTrade, OccurredAt = At(10) },
					new NoteRecord { Body = "took the stop", Phase = NotePhases.PostTrade, OccurredAt = At(381) }
				},
				TradeType = null,
				TradeTypeOther = null,
				Screenshot = new ScreenshotMeta { CapturedAt = At(381), Caption = "ES 1m", Format = "png" },
				Copies = SampleCopies()
			};
		}

		[Fact]
		public void SerializeDeserialize_RoundTripsTheCompletedTrade()
		{
			StagedTrade original = FullExample();
			StagedTrades trades = new StagedTrades();
			trades.Add(original);

			StagedTrades reloaded = StagedTrades.Deserialize(trades.Serialize());
			CompletedTrade t = reloaded.Find(original.Trade.TradeId).Trade;

			Assert.Equal(original.Trade.TradeId, t.TradeId);
			Assert.Equal(original.Trade.Account, t.Account);
			Assert.Equal(original.Trade.Instrument.FullName, t.Instrument.FullName);
			Assert.Equal(original.Trade.Instrument.TickSize, t.Instrument.TickSize);
			Assert.Equal(original.Trade.Instrument.PointValue, t.Instrument.PointValue);
			Assert.Equal(Direction.Long, t.Direction);
			Assert.Equal(3, t.Quantity);
			Assert.Equal(7700.00m, t.AverageEntryPrice);
			Assert.Equal(7701.50m, t.AverageExitPrice);
			Assert.Equal(1.50m, t.Points);
			Assert.Equal(225.00m, t.GrossPnl);
			Assert.Equal(7.74m, t.Commission);
			Assert.Null(t.Fees);
			Assert.Equal(217.26m, t.NetPnl);
			Assert.Equal("Entry", t.EntryOrderName);
			Assert.Equal("Stop1", t.ExitOrderName);
			Assert.Equal("stop", t.ExitReason);
			Assert.Equal(At(0), t.EntryAt);
			Assert.Equal(At(380), t.ExitAt);
			Assert.True(t.ExcursionComplete);
			Assert.NotNull(t.Excursion);
			Assert.Equal(original.Trade.Excursion.MaePoints, t.Excursion.MaePoints);
			Assert.Equal(original.Trade.Excursion.MfePoints, t.Excursion.MfePoints);
		}

		[Fact]
		public void SerializeDeserialize_RoundTripsLegsAndFills()
		{
			StagedTrade original = FullExample();
			StagedTrades trades = new StagedTrades();
			trades.Add(original);

			CompletedTrade t = StagedTrades.Deserialize(trades.Serialize()).Find(original.Trade.TradeId).Trade;

			Assert.Equal(2, t.Legs.Count);
			Assert.Equal("Target1", t.Legs[0].OrderName);
			Assert.False(t.Legs[0].Runner);
			Assert.Equal(original.Trade.Legs[0].Points, t.Legs[0].Points);
			Assert.Equal(original.Trade.Legs[0].MaePoints, t.Legs[0].MaePoints);
			Assert.Equal("Stop1", t.Legs[1].OrderName);
			Assert.True(t.Legs[1].Runner);

			Assert.Equal(3, t.Fills.Count);
			TradeFill firstFill = t.Fills[0];
			Assert.Equal("e1", firstFill.Fill.ExecutionId);
			Assert.Equal("ord-1001", firstFill.Fill.OrderId);
			Assert.Equal(Side.Buy, firstFill.Fill.Side);
			Assert.Equal(7700.00m, firstFill.Fill.Price);
			Assert.Equal(3, firstFill.Fill.Quantity);
			Assert.True(firstFill.Fill.IsEntry);
			Assert.Equal(FillRole.Entry, firstFill.Role);
			Assert.Equal(original.Trade.Fills[0].Excursion.High, firstFill.Excursion.High);
			Assert.Equal(original.Trade.Fills[0].Excursion.Low, firstFill.Excursion.Low);
			Assert.Equal(original.Trade.Fills[0].Excursion.HadTicks, firstFill.Excursion.HadTicks);
		}

		[Fact]
		public void SerializeDeserialize_RoundTripsNotesInOrder()
		{
			StagedTrade original = FullExample();
			StagedTrades trades = new StagedTrades();
			trades.Add(original);

			StagedTrade reloaded = StagedTrades.Deserialize(trades.Serialize()).Find(original.Trade.TradeId);

			Assert.Equal(3, reloaded.Notes.Count);
			Assert.Equal("watching for a breakout", reloaded.Notes[0].Body);
			Assert.Equal(NotePhases.PreTrade, reloaded.Notes[0].Phase);
			Assert.Equal(At(-30), reloaded.Notes[0].OccurredAt);
			Assert.Equal("in it now", reloaded.Notes[1].Body);
			Assert.Equal("took the stop", reloaded.Notes[2].Body);
		}

		[Fact]
		public void SerializeDeserialize_RoundTripsScreenshotMeta()
		{
			StagedTrade original = FullExample();
			StagedTrades trades = new StagedTrades();
			trades.Add(original);

			StagedTrade reloaded = StagedTrades.Deserialize(trades.Serialize()).Find(original.Trade.TradeId);

			Assert.NotNull(reloaded.Screenshot);
			Assert.Equal(At(381), reloaded.Screenshot.CapturedAt);
			Assert.Equal("ES 1m", reloaded.Screenshot.Caption);
			Assert.Equal("png", reloaded.Screenshot.Format);
		}

		[Fact]
		public void SerializeDeserialize_RoundTripsCopyEvaluation()
		{
			StagedTrade original = FullExample();
			StagedTrades trades = new StagedTrades();
			trades.Add(original);

			CopyEvaluation reloaded = StagedTrades.Deserialize(trades.Serialize()).Find(original.Trade.TradeId).Copies;

			Assert.Equal(original.Copies.Source, reloaded.Source);
			Assert.Equal(original.Copies.Summary.Matched, reloaded.Summary.Matched);
			Assert.Equal(original.Copies.Summary.NetPnl, reloaded.Summary.NetPnl);
			CopyResult copy = Assert.Single(reloaded.Copies);
			CopyResult expectedCopy = Assert.Single(original.Copies.Copies);
			Assert.Equal(expectedCopy.Account, copy.Account);
			Assert.Equal(expectedCopy.Outcome, copy.Outcome);
			Assert.Equal(expectedCopy.Quantity, copy.Quantity);
			Assert.Equal(expectedCopy.Performance.NetPnl, copy.Performance.NetPnl);
			Assert.Equal(expectedCopy.Expected.Multiplier, copy.Expected.Multiplier);
			Assert.Equal(expectedCopy.Fills.Count, copy.Fills.Count);
		}

		[Fact]
		public void SerializeDeserialize_NullScreenshotAndCopies_RoundTripAsNull()
		{
			StagedTrade minimal = new StagedTrade
			{
				Trade = WorkedExample(),
				Notes = new List<NoteRecord>(),
				TradeType = "2ES",
				TradeTypeOther = null,
				Screenshot = null,
				Copies = null
			};
			StagedTrades trades = new StagedTrades();
			trades.Add(minimal);

			StagedTrade reloaded = StagedTrades.Deserialize(trades.Serialize()).Find(minimal.Trade.TradeId);

			Assert.Null(reloaded.Screenshot);
			Assert.Null(reloaded.Copies);
			Assert.Equal("2ES", reloaded.TradeType);
			Assert.Empty(reloaded.Notes);
		}

		[Fact]
		public void SerializeDeserialize_TradeWithNoLiveTicks_ExcursionRoundTripsAsNull()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("a", "o1", "Entry", 1, 100m, 0, positionAfter: 1, isEntry: true));
			CompletedTrade flat = tracker.Apply(Sell("b", "o2", "Close", 1, 101m, 10, positionAfter: 0, isExit: true)).Closed[0];
			Assert.Null(flat.Excursion);

			StagedTrades trades = new StagedTrades();
			trades.Add(new StagedTrade { Trade = flat, Notes = new List<NoteRecord>() });

			CompletedTrade reloaded = StagedTrades.Deserialize(trades.Serialize()).Find(flat.TradeId).Trade;

			Assert.Null(reloaded.Excursion);
			Assert.False(reloaded.ExcursionComplete);
		}

		[Fact]
		public void Add_PreservesInsertionOrderAndAllowsReplace()
		{
			StagedTrades trades = new StagedTrades();
			CompletedTrade t1 = WorkedExample();
			trades.Add(new StagedTrade { Trade = t1, Notes = new List<NoteRecord>() });

			Assert.Single(trades.All);

			// Adding again under the same trade_id replaces rather than duplicates.
			trades.Add(new StagedTrade { Trade = t1, Notes = new List<NoteRecord>(), TradeType = "RL" });

			Assert.Single(trades.All);
			Assert.Equal("RL", trades.Find(t1.TradeId).TradeType);
		}

		[Fact]
		public void Add_WithoutTradeId_Throws()
		{
			StagedTrades trades = new StagedTrades();
			Assert.Throws<ArgumentException>(() => trades.Add(new StagedTrade { Trade = new CompletedTrade() }));
		}

		[Fact]
		public void Add_NullStagedTrade_Throws()
		{
			StagedTrades trades = new StagedTrades();
			Assert.Throws<ArgumentNullException>(() => trades.Add(null));
		}

		[Fact]
		public void Remove_DropsTheTrade()
		{
			StagedTrades trades = new StagedTrades();
			CompletedTrade t = WorkedExample();
			trades.Add(new StagedTrade { Trade = t, Notes = new List<NoteRecord>() });

			trades.Remove(t.TradeId);

			Assert.Empty(trades.All);
			Assert.Null(trades.Find(t.TradeId));
		}

		[Fact]
		public void SerializeDeserialize_EmptyCollection_RoundTrips()
		{
			StagedTrades trades = new StagedTrades();

			Assert.Empty(StagedTrades.Deserialize(trades.Serialize()).All);
		}

		[Fact]
		public void SerializeDeserialize_RoundTripsTheReversalFlag()
		{
			StagedTrades staged = new StagedTrades();
			CompletedTrade trade = WorkedExample();
			trade.OpenedByReversal = true;
			staged.Add(new StagedTrade { Trade = trade, Notes = new List<NoteRecord>() });

			StagedTrades reloaded = StagedTrades.Deserialize(staged.Serialize());

			Assert.True(reloaded.All[0].Trade.OpenedByReversal);
		}

		[Fact]
		public void Deserialize_AStateFileWithoutTheReversalFlag_ReadsItAsFalse()
		{
			StagedTrades staged = new StagedTrades();
			staged.Add(new StagedTrade { Trade = WorkedExample(), Notes = new List<NoteRecord>() });
			string older = staged.Serialize().Replace(",\"opened_by_reversal\":false", "");

			StagedTrades reloaded = StagedTrades.Deserialize(older);

			Assert.DoesNotContain("opened_by_reversal", older);
			Assert.False(reloaded.All[0].Trade.OpenedByReversal);
		}

		[Fact]
		public void Deserialize_AStateFileWithoutTheReversalFlag_WorksItOutFromTheSplitFirstEntry()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("a", "o1", "", 1, 7804.75m, 0, 0, 1, isEntry: true));
			tracker.Apply(Sell("b", "o2", "", 2, 7805.25m, 30, 0, -1, isEntry: true, isExit: true));
			CompletedTrade reversed = tracker.Apply(Buy("c", "o3", "", 1, 7805.00m, 90, 0, 0, isExit: true)).Closed[0];
			StagedTrades staged = new StagedTrades();
			staged.Add(new StagedTrade { Trade = reversed, Notes = new List<NoteRecord>() });
			string older = staged.Serialize().Replace(",\"opened_by_reversal\":true", "");

			StagedTrades reloaded = StagedTrades.Deserialize(older);

			Assert.DoesNotContain("opened_by_reversal", older);
			Assert.True(reloaded.All[0].Trade.OpenedByReversal);
		}
	}
}
