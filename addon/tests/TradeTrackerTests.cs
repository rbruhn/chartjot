using ChartJot.Core;
using static ChartJot.Core.Tests.TestData;

namespace ChartJot.Core.Tests
{
	public class TradeTrackerTests
	{
		// The worked example in NT8.md: buy 3 at 7700, sell 2 at 7701 (Target1), sell 1 at 7702.50 (Stop1).
		private static CompletedTrade WorkedExample()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("e1", "ord-1001", "Entry", 3, 7700.00m, 0, 3.87m, 3, isEntry: true));
			tracker.OnPrice("ES 12-26", 7699.25m);
			tracker.OnPrice("ES 12-26", 7700.50m);
			tracker.Apply(Sell("e2", "ord-1002", "Target1", 2, 7701.00m, 70, 2.58m, 1, isExit: true));
			tracker.OnPrice("ES 12-26", 7703.25m);
			tracker.OnPrice("ES 12-26", 7702.00m);
			ApplyResult last = tracker.Apply(Sell("e3", "ord-1003", "Stop1", 1, 7702.50m, 380, 1.29m, 0, isExit: true));
			Assert.Single(last.Closed);
			return last.Closed[0];
		}

		[Fact]
		public void WorkedExample_MatchesTheSpecNumbers()
		{
			CompletedTrade t = WorkedExample();

			Assert.Equal(Direction.Long, t.Direction);
			Assert.Equal(3, t.Quantity);
			Assert.Equal(3, t.TotalEntryQuantity);
			Assert.Equal(7700.00m, t.AverageEntryPrice);
			Assert.Equal(7701.50m, t.AverageExitPrice);
			Assert.Equal(1.50m, t.Points);
			Assert.Equal(6, t.Ticks);
			Assert.Equal(225.00m, t.GrossPnl);
			Assert.Equal(7.74m, t.Commission);
			Assert.Null(t.Fees);
			Assert.Equal(217.26m, t.NetPnl);
			Assert.Equal("Entry", t.EntryOrderName);
			Assert.Equal("Stop1", t.ExitOrderName);
			Assert.Equal("stop", t.ExitReason);
			Assert.Equal(At(0), t.EntryAt);
			Assert.Equal(At(380), t.ExitAt);
		}

		[Fact]
		public void WorkedExample_Excursion()
		{
			CompletedTrade t = WorkedExample();

			Assert.NotNull(t.Excursion);
			Assert.Equal(0.75m, t.Excursion.MaePoints);
			Assert.Equal(3.25m, t.Excursion.MfePoints);
			Assert.Equal(7699.25m, t.Excursion.MaxAdversePrice);
			Assert.Equal(7703.25m, t.Excursion.MaxFavorablePrice);
			Assert.True(t.ExcursionComplete);
		}

		[Fact]
		public void WorkedExample_LegsAndRunner()
		{
			CompletedTrade t = WorkedExample();

			Assert.Equal(2, t.Legs.Count);

			Leg first = t.Legs[0];
			Assert.Equal(1, first.Sequence);
			Assert.False(first.Runner);
			Assert.Equal("ord-1002", first.ExitOrderId);
			Assert.Equal("Target1", first.OrderName);
			Assert.Equal("profit_target", first.Reason);
			Assert.Equal(2, first.Quantity);
			Assert.Equal(7701.00m, first.AverageExitPrice);
			Assert.Equal(1.00m, first.Points);
			Assert.Equal(100.00m, first.GrossPnl);
			Assert.Equal(0.75m, first.MaePoints);
			Assert.Equal(1.00m, first.MfePoints);

			Leg runner = t.Legs[1];
			Assert.Equal(2, runner.Sequence);
			Assert.True(runner.Runner);
			Assert.Equal("stop", runner.Reason);
			Assert.Equal(1, runner.Quantity);
			Assert.Equal(2.50m, runner.Points);
			Assert.Equal(125.00m, runner.GrossPnl);
			Assert.Equal(0.75m, runner.MaePoints);
			Assert.Equal(3.25m, runner.MfePoints);

			Assert.Equal(t.GrossPnl, t.Legs.Sum(l => l.GrossPnl));
		}

		[Fact]
		public void WorkedExample_ExecutionsCarryPositionAfterAndCommission()
		{
			CompletedTrade t = WorkedExample();

			Assert.Equal(new[] { 3, 1, 0 }, t.Fills.Select(f => f.PositionAfter).ToArray());
			Assert.Equal(new[] { FillRole.Entry, FillRole.Exit, FillRole.Exit }, t.Fills.Select(f => f.Role).ToArray());
			Assert.Equal(new[] { 3.87m, 2.58m, 1.29m }, t.Fills.Select(f => f.Commission).ToArray());
		}

		[Fact]
		public void Short_TradeGrossAndExcursionAreSignedByDirection()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Sell("s1", "o1", "Entry", 1, 7794.25m, 0, 1.99m, -1, isEntry: true));
			tracker.OnPrice("ES 12-26", 7793.00m);
			tracker.OnPrice("ES 12-26", 7796.00m);
			ApplyResult r = tracker.Apply(Buy("s2", "o2", "Stop1", 1, 7796.75m, 200, 1.99m, 0, isExit: true));

			CompletedTrade t = Assert.Single(r.Closed);
			Assert.Equal(Direction.Short, t.Direction);
			Assert.Equal(-2.50m, t.Points);
			Assert.Equal(-10, t.Ticks);
			Assert.Equal(-125.00m, t.GrossPnl);
			Assert.Equal(-128.98m, t.NetPnl);
			Assert.Equal(1.25m, t.Excursion.MfePoints);   // low 7793.00
			Assert.Equal(2.50m, t.Excursion.MaePoints);   // high is the 7796.75 exit fill
			Assert.Equal(7793.00m, t.Excursion.MaxFavorablePrice);
			Assert.Equal(7796.75m, t.Excursion.MaxAdversePrice);
		}

		[Fact]
		public void TwoExitOrdersFillingTogether_AreTwoLegs_LaterOneIsRunner()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Sell("a", "o1", "Entry", 3, 7798.25m, 0, positionAfter: -3, isEntry: true));
			tracker.Apply(Buy("b", "o2", "Stop1", 2, 7803.25m, 60, positionAfter: -1, isExit: true));
			ApplyResult r = tracker.Apply(Buy("c", "o3", "Stop2", 1, 7803.25m, 60, positionAfter: 0, isExit: true));

			CompletedTrade t = Assert.Single(r.Closed);
			Assert.Equal(2, t.Legs.Count);
			Assert.False(t.Legs[0].Runner);
			Assert.True(t.Legs[1].Runner);
			Assert.Equal(new[] { 2, 1 }, t.Legs.Select(l => l.Quantity).ToArray());
		}

		[Fact]
		public void OneExitOrderFillingInPieces_IsOneLeg()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Sell("a", "o1", "Entry", 3, 7764.00m, 0, positionAfter: -3, isEntry: true));
			tracker.Apply(Buy("b", "o2", "Target1", 1, 7763.00m, 5, positionAfter: -2, isExit: true));
			tracker.Apply(Buy("c", "o2", "Target1", 1, 7763.00m, 5, positionAfter: -1, isExit: true));
			ApplyResult r = tracker.Apply(Buy("d", "o3", "Stop2", 1, 7763.75m, 20, positionAfter: 0, isExit: true));

			CompletedTrade t = Assert.Single(r.Closed);
			Assert.Equal(2, t.Legs.Count);
			Assert.Equal(2, t.Legs[0].Quantity);
			Assert.Equal("Target1", t.Legs[0].OrderName);
			Assert.Equal(new[] { "o2", "o3" }, t.Legs.Select(l => l.ExitOrderId).ToArray());
		}

		[Fact]
		public void ScalingInAfterAPartialExit_QuantityIsMaxOpen_TotalEntryIsAll()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("a", "o1", "Entry", 2, 100m, 0, positionAfter: 2, isEntry: true));
			tracker.Apply(Sell("b", "o2", "Target1", 1, 101m, 10, positionAfter: 1, isExit: true));
			tracker.Apply(Buy("c", "o3", "Add", 1, 100.50m, 20, positionAfter: 2, isEntry: true));
			ApplyResult r = tracker.Apply(Sell("d", "o4", "Target2", 2, 102m, 30, positionAfter: 0, isExit: true));

			CompletedTrade t = Assert.Single(r.Closed);
			Assert.Equal(2, t.Quantity);
			Assert.Equal(3, t.TotalEntryQuantity);
			Assert.Equal((2 * 100m + 1 * 100.50m) / 3m, t.AverageEntryPrice);
			Assert.Equal(new[] { 2, 1, 2, 0 }, t.Fills.Select(f => f.PositionAfter).ToArray());
		}

		[Fact]
		public void Reversal_SplitsTheFlippingFillAcrossTwoTrades()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("a", "o1", "", 1, 7804.75m, 0, 1.99m, 1, isEntry: true));

			// Sell 2 while long 1: closes the long, opens a short of 1.
			ApplyResult flip = tracker.Apply(Sell("b", "o2", "", 2, 7805.25m, 30, 3.98m, -1, isEntry: true, isExit: true));

			CompletedTrade closed = Assert.Single(flip.Closed);
			Assert.Equal(Direction.Long, closed.Direction);
			Assert.Equal(0.50m, closed.Points);
			Assert.Equal(25.00m, closed.GrossPnl);
			Assert.Equal(1.99m + 1.99m, closed.Commission); // half of 3.98 belongs to the closed trade
			TradeFill closingPart = closed.Fills[1];
			Assert.Equal(2, closingPart.Fill.Quantity);
			Assert.Equal(1, closingPart.AllocatedQuantity);
			Assert.Equal(0, closingPart.PositionAfter);

			Assert.NotNull(flip.Opened);
			Assert.Equal(Direction.Short, flip.Opened.Direction);
			Assert.Equal(-1, flip.Opened.SignedPosition);
			Assert.NotEqual(closed.TradeId, flip.Opened.TradeId);
			Assert.Equal(-1, tracker.Position("APEX-24570-135", "ES 12-26"));

			// The next fill covers the new short. The flipping fill is its first entry.
			ApplyResult cover = tracker.Apply(Buy("c", "o3", "", 1, 7805.00m, 90, 1.99m, 0, isExit: true));
			CompletedTrade second = Assert.Single(cover.Closed);
			Assert.Equal(Direction.Short, second.Direction);
			Assert.Equal(1, second.TotalEntryQuantity);
			Assert.Equal(7805.25m, second.AverageEntryPrice);
			Assert.Equal(1.99m + 1.99m, second.Commission);
			Assert.Equal(new[] { -1, 0 }, second.Fills.Select(f => f.PositionAfter).ToArray());
			Assert.Equal(TradeIds.Create("APEX-24570-135", "ES 12-26", "b"), second.TradeId);
		}

		[Fact]
		public void DuplicateExecutionId_IsIgnored()
		{
			TradeTracker tracker = new TradeTracker();
			Fill entry = Buy("a", "o1", "Entry", 3, 100m, 0, positionAfter: 3, isEntry: true);
			Assert.Equal(ApplyStatus.Applied, tracker.Apply(entry).Status);

			Assert.Equal(ApplyStatus.Duplicate, tracker.Apply(entry).Status);
			Assert.Equal(3, tracker.Position("APEX-24570-135", "ES 12-26"));
		}

		[Fact]
		public void SameExecutionIdInADifferentAccount_IsNotADuplicate()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("a", "o1", "Entry", 1, 100m, 0, positionAfter: 1, account: "A", isEntry: true));

			ApplyResult other = tracker.Apply(Buy("a", "o1", "Entry", 1, 100m, 0, positionAfter: 1, account: "B", isEntry: true));

			Assert.Equal(ApplyStatus.Applied, other.Status);
		}

		[Fact]
		public void FillWhoseReportedPositionDisagrees_IsNotApplied()
		{
			// The bug the spike had after an NT8 restart: a cover of 6 while short 9 arrived with the tracker
			// flat. NT8 said the position after the fill was -3. The sum said +6.
			TradeTracker tracker = new TradeTracker();

			ApplyResult r = tracker.Apply(Buy("x", "o1", "Target1", 6, 7738.75m, 0, positionAfter: -3, account: "Sim110", instrument: Mes, isExit: true));

			Assert.Equal(ApplyStatus.PositionMismatch, r.Status);
			Assert.Equal(6, r.ComputedPosition);
			Assert.Equal(-3, r.ReportedPosition);
			Assert.Equal(0, tracker.Position("Sim110", "MES 12-26"));
			Assert.Empty(tracker.OpenTrades);
		}

		[Fact]
		public void MismatchedFill_CanBeAppliedAgainAfterARebuild()
		{
			TradeTracker tracker = new TradeTracker();
			Fill cover = Buy("x", "o2", "Target1", 6, 7738.75m, 100, positionAfter: -3, account: "Sim110", instrument: Mes, isExit: true);
			Assert.Equal(ApplyStatus.PositionMismatch, tracker.Apply(cover).Status);

			Fill entry = Sell("e", "o1", "Entry", 9, 7746m, 0, positionAfter: -9, account: "Sim110", instrument: Mes, isEntry: true);
			RebuildResult rebuilt = tracker.Rebuild("Sim110", "MES 12-26", new[] { entry, cover }, -3);

			Assert.True(rebuilt.Consistent);
			Assert.NotNull(rebuilt.Open);
			Assert.Equal(Direction.Short, rebuilt.Open.Direction);
			Assert.Equal(-3, rebuilt.Open.SignedPosition);
			Assert.Equal(9, rebuilt.Open.MaxQuantity);
			Assert.Equal(-3, tracker.Position("Sim110", "MES 12-26"));
			Assert.Equal(ApplyStatus.Duplicate, tracker.Apply(cover).Status);
		}

		[Fact]
		public void ExitFlaggedFillWhileFlatWithNoPositionFigure_NeverOpensATrade()
		{
			TradeTracker tracker = new TradeTracker();

			ApplyResult r = tracker.Apply(Buy("x", "o1", "Target1", 2, 100m, 0, isExit: true));

			Assert.Equal(ApplyStatus.OrphanExit, r.Status);
			Assert.Equal(0, tracker.Position("APEX-24570-135", "ES 12-26"));
			Assert.Empty(tracker.OpenTrades);
		}

		[Fact]
		public void FillsWithNeitherEntryNorExitFlag_StillOpenWhenPositionAgrees()
		{
			// Rithmic replays arrive with IsEntry and IsExit both false. The position figure decides.
			TradeTracker tracker = new TradeTracker();

			ApplyResult r = tracker.Apply(Buy("1096239|2907664565|2907664565", "2907664565", "", 3, 7768.75m, 0, positionAfter: 3));

			Assert.Equal(ApplyStatus.Applied, r.Status);
			Assert.NotNull(r.Opened);
			Assert.Equal(3, tracker.Position("APEX-24570-135", "ES 12-26"));
		}

		[Fact]
		public void AccountsAndInstrumentsAreTrackedSeparately()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("a", "o1", "Entry", 3, 100m, 0, positionAfter: 3, account: "A", isEntry: true));
			tracker.Apply(Buy("b", "o2", "Entry", 2, 100m, 0, positionAfter: 2, account: "B", isEntry: true));
			tracker.Apply(Buy("c", "o3", "Entry", 1, 100m, 0, positionAfter: 1, account: "A", instrument: Mes, isEntry: true));

			ApplyResult closeA = tracker.Apply(Sell("d", "o4", "Close", 3, 101m, 10, positionAfter: 0, account: "A", isExit: true));

			Assert.Single(closeA.Closed);
			Assert.Equal(3, closeA.Closed[0].TotalEntryQuantity);
			Assert.Equal(2, tracker.Position("B", "ES 12-26"));
			Assert.Equal(1, tracker.Position("A", "MES 12-26"));
			Assert.Equal(2, tracker.OpenTrades.Count);
		}

		[Fact]
		public void OnPrice_OnlyFeedsTradesInThatInstrument()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("a", "o1", "Entry", 1, 100m, 0, positionAfter: 1, isEntry: true));
			tracker.Apply(Buy("b", "o2", "Entry", 1, 100m, 0, positionAfter: 1, instrument: Mes, isEntry: true));

			tracker.OnPrice("MES 12-26", 90m);

			CompletedTrade es = tracker.Apply(Sell("c", "o3", "Close", 1, 101m, 5, positionAfter: 0, isExit: true)).Closed[0];
			CompletedTrade mes = tracker.Apply(Sell("d", "o4", "Close", 1, 101m, 5, positionAfter: 0, instrument: Mes, isExit: true)).Closed[0];

			Assert.Null(es.Excursion);
			Assert.Equal(10m, mes.Excursion.MaePoints);
		}

		[Fact]
		public void Reversal_FlagsOnlyTheTradeItOpened()
		{
			TradeTracker tracker = new TradeTracker();
			OpenTradeInfo fromFlat = tracker.Apply(Buy("a", "o1", "", 1, 7804.75m, 0, 0, 1, isEntry: true)).Opened;
			ApplyResult flip = tracker.Apply(Sell("b", "o2", "", 2, 7805.25m, 30, 0, -1, isEntry: true, isExit: true));
			CompletedTrade second = tracker.Apply(Buy("c", "o3", "", 1, 7805.00m, 90, 0, 0, isExit: true)).Closed[0];

			Assert.False(fromFlat.OpenedByReversal);
			Assert.False(flip.Closed[0].OpenedByReversal);
			Assert.True(flip.Opened.OpenedByReversal);
			Assert.True(second.OpenedByReversal);
		}

		[Fact]
		public void Rebuild_KeepsTheReversalFlag()
		{
			Fill a = Buy("a", "o1", "", 1, 7804.75m, 0, 0, 1, isEntry: true);
			Fill b = Sell("b", "o2", "", 2, 7805.25m, 30, 0, -1, isEntry: true, isExit: true);
			TradeTracker tracker = new TradeTracker();

			RebuildResult result = tracker.Rebuild(a.Account, a.Instrument.FullName, new[] { a, b }, -1);

			Assert.True(result.Open.OpenedByReversal);
			Assert.False(result.Closed[0].OpenedByReversal);
		}
	}
}
