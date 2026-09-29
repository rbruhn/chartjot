using ChartJot.Core;
using static ChartJot.Core.Tests.TestData;

namespace ChartJot.Core.Tests
{
	public class CopyMatcherTests
	{
		private const string MasterAccount = "TEST-ACCT-001";
		private static int idCounter;

		// A closed round turn in any account, built through the tracker like real fills.
		private static CompletedTrade Closed(string account, InstrumentSpec instrument, Direction direction, int qty,
			int entrySeconds, int exitSeconds, decimal entryPrice = 100m, decimal exitPrice = 101m, decimal commission = 0m)
		{
			TradeTracker tracker = new TradeTracker();
			int n = ++idCounter;
			int sign = direction == Direction.Long ? 1 : -1;
			Fill entry = direction == Direction.Long
				? Buy("e" + n, "oe" + n, "Entry", qty, entryPrice, entrySeconds, commission, sign * qty, account, instrument, isEntry: true)
				: Sell("e" + n, "oe" + n, "Entry", qty, entryPrice, entrySeconds, commission, sign * qty, account, instrument, isEntry: true);
			tracker.Apply(entry);
			Fill exit = direction == Direction.Long
				? Sell("x" + n, "ox" + n, "Close", qty, exitPrice, exitSeconds, commission, 0, account, instrument, isExit: true)
				: Buy("x" + n, "ox" + n, "Close", qty, exitPrice, exitSeconds, commission, 0, account, instrument, isExit: true);
			return tracker.Apply(exit).Closed[0];
		}

		private static OpenTradeInfo Open(string account, InstrumentSpec instrument, Direction direction, int qty, int entrySeconds, decimal price = 100m)
		{
			TradeTracker tracker = new TradeTracker();
			int n = ++idCounter;
			Fill entry = direction == Direction.Long
				? Buy("e" + n, "oe" + n, "Entry", qty, price, entrySeconds, 0m, qty, account, instrument, isEntry: true)
				: Sell("e" + n, "oe" + n, "Entry", qty, price, entrySeconds, 0m, -qty, account, instrument, isEntry: true);
			return tracker.Apply(entry).Opened;
		}

		private static CompletedTrade Master(Direction direction = Direction.Long, int qty = 3, int exitSeconds = 380)
		{
			return Closed(MasterAccount, Es, direction, qty, 0, exitSeconds);
		}

		private static CopierSnapshot Setup(params string[] rows)
		{
			return CopierSnapshotParser.Parse(MasterAccount, true, "All", "ES", "Round Up At 0.5", "Multiplier", rows, CopierSnapshotParser.SourceLive);
		}

		private static CopyResult Only(CopyEvaluation e)
		{
			return Assert.Single(e.Copies);
		}

		private const string MicroOrders = "PA-02|Slave|1|No|No|No|No|No|Default|No|Rithmic";
		private const string MicroExecutions = "PA-02|Slave|1|No|No|No|No|No|Executions|No|Rithmic";

		private static CopyEvaluation Run(CompletedTrade master, CopierSnapshot setup, params CopyCandidate[] candidates)
		{
			return CopyMatcher.Evaluate(master, setup, new MatchOptions(), candidates);
		}

		private static CopyCandidate Mes(string account, Direction d, int qty, int entrySeconds, int exitSeconds)
		{
			return CopyCandidate.FromClosed(Closed(account, TestData.Mes, d, qty, entrySeconds, exitSeconds));
		}

		// ---- the worked example

		[Fact]
		public void WorkedExample_MatchedMicroCopyAndAMissedFollower()
		{
			CompletedTrade master = Master();
			TradeTracker t = new TradeTracker();
			t.Apply(Buy("b1", "co1", "Entry", 3, 7700.25m, 1, 1.17m, 3, "PA-02", TestData.Mes, isEntry: true));
			t.Apply(Sell("b2", "co2", "Target1", 2, 7701.00m, 70, 0.78m, 1, "PA-02", TestData.Mes, isExit: true));
			CompletedTrade copy = t.Apply(Sell("b3", "co3", "Stop1", 1, 7702.50m, 380, 0.39m, 0, "PA-02", TestData.Mes, isExit: true)).Closed[0];

			CopyEvaluation e = Run(master,
				Setup(MicroOrders, "PA-03|Slave|1|No|No|No|No|No|Default|No|Rithmic"),
				CopyCandidate.FromClosed(copy));

			Assert.Equal("copier_live", e.Source);
			Assert.Equal(2, e.Copies.Count);

			CopyResult matched = e.Copies[0];
			Assert.Equal("PA-02", matched.Account);
			Assert.Equal(CopyOutcome.Matched, matched.Outcome);
			Assert.Equal("MES 12-26", matched.Instrument.FullName);
			Assert.Equal(3, matched.Quantity);
			Assert.Equal(1.25m, matched.Performance.Points);
			Assert.Equal(5, matched.Performance.Ticks);
			Assert.Equal(18.75m, matched.Performance.GrossPnl);
			Assert.Equal(2.34m, matched.Performance.Commission);
			Assert.Equal(16.41m, matched.Performance.NetPnl);
			Assert.Empty(matched.Warnings);
			Assert.Equal(3, matched.Expected.Quantity);
			Assert.Equal("micro", matched.Expected.ContractSize);

			CopyResult missed = e.Copies[1];
			Assert.Equal("PA-03", missed.Account);
			Assert.Equal(CopyOutcome.Missed, missed.Outcome);
			Assert.Null(missed.Instrument);
			Assert.Null(missed.Performance);
			Assert.Equal(0, missed.Quantity);
			Assert.Equal(3, missed.Expected.Quantity);

			Assert.Equal(2, e.Summary.Accounts);
			Assert.Equal(1, e.Summary.Matched);
			Assert.Equal(1, e.Summary.Missed);
			Assert.Equal(0, e.Summary.StillOpen);
			Assert.Equal(16.41m, e.Summary.NetPnl);
		}

		// ---- matching window

		[Theory]
		[InlineData(-5, true)]    // 5 s before the master: still a copy
		[InlineData(-6, false)]   // 6 s before: too early
		[InlineData(0, true)]
		[InlineData(1, true)]
		public void WindowBeforeTheMastersEntry(int seconds, bool matched)
		{
			CopyEvaluation e = Run(Master(), Setup(MicroOrders), Mes("PA-02", Direction.Long, 3, seconds, 380));

			Assert.Equal(matched ? CopyOutcome.Matched : CopyOutcome.Missed, Only(e).Outcome);
		}

		[Fact]
		public void OrdersMode_AFollowerWhoseLimitEntryFillsLaterWhileTheMasterIsOpenIsMatched()
		{
			CopyEvaluation e = Run(Master(), Setup(MicroOrders), Mes("PA-02", Direction.Long, 3, 120, 380));

			Assert.Equal(CopyOutcome.Matched, Only(e).Outcome);
		}

		[Fact]
		public void OrdersMode_AnEntryAfterTheMasterIsFlatIsNotMatched()
		{
			CopyEvaluation e = Run(Master(exitSeconds: 380), Setup(MicroOrders), Mes("PA-02", Direction.Long, 3, 381, 400));

			CopyResult r = Only(e);
			Assert.Equal(CopyOutcome.Missed, r.Outcome);
			Assert.Equal("expected 3 MES, no entry before the master trade closed", r.Warnings[0]);
		}

		[Theory]
		[InlineData(3, true)]
		[InlineData(5, true)]
		[InlineData(6, false)]
		[InlineData(120, false)]
		public void ExecutionsMode_OnlyTheWindowAfterTheMastersEntryCounts(int seconds, bool matched)
		{
			CopyEvaluation e = Run(Master(), Setup(MicroExecutions), Mes("PA-02", Direction.Long, 3, seconds, 380));

			CopyResult r = Only(e);
			Assert.Equal(matched ? CopyOutcome.Matched : CopyOutcome.Missed, r.Outcome);
			if (!matched)
				Assert.Equal("expected 3 MES, no entry within 5 seconds", r.Warnings[0]);
		}

		[Fact]
		public void TheWindowIsConfigurable()
		{
			MatchOptions options = new MatchOptions { Window = TimeSpan.FromSeconds(10) };

			CopyEvaluation e = CopyMatcher.Evaluate(Master(), Setup(MicroOrders), options, new[] { Mes("PA-02", Direction.Long, 3, -8, 380) });

			Assert.Equal(CopyOutcome.Matched, Only(e).Outcome);
		}

		[Fact]
		public void TheEarliestEntryInTheWindowIsTheCopy()
		{
			CopyCandidate late = Mes("PA-02", Direction.Long, 1, 4, 30);
			CopyCandidate early = Mes("PA-02", Direction.Long, 3, 1, 380);

			CopyEvaluation e = Run(Master(), Setup(MicroOrders), late, early);

			Assert.Equal(3, Only(e).Quantity);
		}

		// ---- direction, fade

		[Fact]
		public void OppositeDirectionIsNeverMatched_AndSaysSo()
		{
			CopyEvaluation e = Run(Master(Direction.Long), Setup(MicroOrders), Mes("PA-02", Direction.Short, 3, 1, 380));

			CopyResult r = Only(e);
			Assert.Equal(CopyOutcome.Missed, r.Outcome);
			Assert.Equal("entered short but expected long; not matched", r.Warnings[0]);
		}

		[Fact]
		public void AFadedFollowerIsMatchedOppositeAndFlagged()
		{
			CopyEvaluation e = Run(Master(Direction.Long),
				Setup("PA-02|Slave|1|No|Yes|No|No|No|Executions|No|Rithmic"),
				Mes("PA-02", Direction.Short, 3, 1, 380));

			CopyResult r = Only(e);
			Assert.Equal(CopyOutcome.Matched, r.Outcome);
			Assert.True(r.Expected.Faded);
			Assert.Contains("faded copy", r.Warnings);
		}

		[Fact]
		public void AFadedFollowerThatWentTheMastersWayIsNotMatched()
		{
			CopyEvaluation e = Run(Master(Direction.Long),
				Setup("PA-02|Slave|1|No|Yes|No|No|No|Executions|No|Rithmic"),
				Mes("PA-02", Direction.Long, 3, 1, 380));

			Assert.Equal(CopyOutcome.Missed, Only(e).Outcome);
		}

		// ---- who is expected

		[Fact]
		public void ABlownFollowerIsNeverReportedMissed()
		{
			CopyEvaluation e = Run(Master(), Setup("PA-02|Slave|1|No|No|No|No|No|Default|Yes|Rithmic"));

			Assert.Empty(e.Copies);
			Assert.Null(e.Summary);
		}

		[Fact]
		public void ABlownFollowerThatCopiedIsStillMatched()
		{
			CopyEvaluation e = Run(Master(), Setup("PA-02|Slave|1|No|No|No|No|No|Default|Yes|Rithmic"), Mes("PA-02", Direction.Long, 3, 1, 380));

			CopyResult r = Only(e);
			Assert.Equal(CopyOutcome.Matched, r.Outcome);
			Assert.True(r.Expected.Blown);
		}

		[Fact]
		public void ADisabledCopier_ReportsNoMisses_ButStillMatchesWhatWasCopied()
		{
			CopierSnapshot setup = CopierSnapshotParser.Parse(MasterAccount, false, "All", "ES", "Round Up At 0.5", "Multiplier",
				new[] { MicroOrders, "PA-03|Slave|1|No|No|No|No|No|Default|No|Rithmic" }, "copier_live");

			CopyEvaluation e = Run(Master(), setup, Mes("PA-02", Direction.Long, 3, 1, 380));

			CopyResult r = Only(e);
			Assert.Equal("PA-02", r.Account);
			Assert.Equal(CopyOutcome.Matched, r.Outcome);
		}

		[Fact]
		public void SingleInstrumentMode_OtherInstrumentsAreNotExpected()
		{
			CopierSnapshot nqOnly = CopierSnapshotParser.Parse(MasterAccount, true, "Single", "NQ", "Round Up At 0.5", "Multiplier", new[] { MicroOrders }, "copier_live");
			CopierSnapshot esOnly = CopierSnapshotParser.Parse(MasterAccount, true, "Single", "ES", "Round Up At 0.5", "Multiplier", new[] { MicroOrders }, "copier_live");

			Assert.Empty(Run(Master(), nqOnly).Copies);
			Assert.Equal(CopyOutcome.Missed, Only(Run(Master(), esOnly)).Outcome);
		}

		[Fact]
		public void OnlyFollowersOfThisMasterCount_OtherGroupsNeverShareCopies()
		{
			// PA-09 traded in the same second but is not a follower of this master.
			CopyEvaluation e = Run(Master(), Setup(MicroOrders), Mes("PA-09", Direction.Long, 3, 1, 380), Mes("PA-02", Direction.Long, 3, 1, 380));

			Assert.Equal(new[] { "PA-02" }, e.Copies.Select(c => c.Account).ToArray());
		}

		[Fact]
		public void AFollowerWithRoleNone_IsNotACopy()
		{
			CopyEvaluation e = Run(Master(), Setup("PA-02|None|1|No|No|No|No|No|Default|No|Rithmic"), Mes("PA-02", Direction.Long, 3, 1, 380));

			Assert.Empty(e.Copies);
		}

		[Fact]
		public void TheMastersOwnAccountIsNeverACopy()
		{
			CopyCandidate self = CopyCandidate.FromClosed(Closed(MasterAccount, Es, Direction.Long, 3, 1, 380));

			CopyEvaluation e = Run(Master(), Setup("TEST-ACCT-001|Slave|1|Yes|No|No|No|No|Default|No|Rithmic"), self);

			Assert.Empty(e.Copies);
		}

		// ---- markets and sizes

		[Fact]
		public void AMiniFollowerOfAnEsMasterTradesEsAndIsMatched()
		{
			CopyCandidate mini = CopyCandidate.FromClosed(Closed("PA-02", Es, Direction.Long, 6, 1, 380));

			CopyEvaluation e = Run(Master(qty: 3), Setup("PA-02|Slave|2|Yes|No|No|No|No|Default|No|Rithmic"), mini);

			CopyResult r = Only(e);
			Assert.Equal(CopyOutcome.Matched, r.Outcome);
			Assert.Equal(6, r.Expected.Quantity);
			Assert.Empty(r.Warnings);
		}

		[Fact]
		public void AMicroFollowerOfAnEsMaster_UsesItsOwnPointValue()
		{
			CopyEvaluation e = Run(Master(), Setup(MicroOrders), Mes("PA-02", Direction.Long, 3, 1, 380));

			// 3 MES from 100 to 101 at $5 a point.
			Assert.Equal(15.00m, Only(e).Performance.GrossPnl);
		}

		[Fact]
		public void ADifferentContractMonthIsNotTheSameMarket()
		{
			InstrumentSpec march = new InstrumentSpec { FullName = "MES 03-27", Symbol = "MES", TickSize = 0.25m, PointValue = 5m };
			CopyCandidate wrongMonth = CopyCandidate.FromClosed(Closed("PA-02", march, Direction.Long, 3, 1, 380));

			CopyEvaluation e = Run(Master(), Setup(MicroOrders), wrongMonth);

			Assert.Equal(CopyOutcome.Missed, Only(e).Outcome);
		}

		[Fact]
		public void AnMesMasterWithAMiniFollower_IsMatched()
		{
			CompletedTrade micro = Closed(MasterAccount, TestData.Mes, Direction.Long, 3, 0, 380);
			CopyCandidate mini = CopyCandidate.FromClosed(Closed("PA-02", Es, Direction.Long, 1, 1, 380));

			CopyEvaluation e = Run(micro, Setup("PA-02|Slave|1|Yes|No|No|No|No|Default|No|Rithmic"), mini);

			Assert.Equal(CopyOutcome.Matched, Only(e).Outcome);
		}

		// ---- warnings

		[Fact]
		public void QuantityMismatchWarns_ButStillMatches()
		{
			CopyEvaluation e = Run(Master(), Setup(MicroOrders), Mes("PA-02", Direction.Long, 1, 1, 380));

			CopyResult r = Only(e);
			Assert.Equal(CopyOutcome.Matched, r.Outcome);
			Assert.Equal(new[] { "expected 3 MES, filled 1 MES" }, r.Warnings.ToArray());
		}

		[Fact]
		public void TheWrongContractSizeWarns()
		{
			// A Micro follower that traded the Mini.
			CopyCandidate wrong = CopyCandidate.FromClosed(Closed("PA-02", Es, Direction.Long, 3, 1, 380));

			CopyEvaluation e = Run(Master(), Setup(MicroOrders), wrong);

			CopyResult r = Only(e);
			Assert.Equal(CopyOutcome.Matched, r.Outcome);
			Assert.Contains("expected MES, traded ES", r.Warnings);
		}

		private static int? Expected(int[] orders, double size, string rounding = "Round Up At 0.5", string mode = "Multiplier", bool executions = false, List<string> diag = null, bool sizeColumnEnabled = true)
		{
			return CopyMatcher.ExpectedQuantity(orders, (decimal)size, rounding, mode, executions, diag ?? new List<string>(), sizeColumnEnabled);
		}

		[Theory]
		[InlineData(3, 1.0, 3)]
		[InlineData(3, 3.0, 9)]
		[InlineData(4, 2.0, 8)]
		public void MultiplierMode_WholeAndAboveOneSizes(int master, double size, int expected)
		{
			Assert.Equal(expected, Expected(new[] { master }, size));
			Assert.Equal(expected, Expected(new[] { master }, size, executions: true));
		}

		[Theory]
		[InlineData(3, 1.25, 4)]   // 3.75 rounds up
		[InlineData(3, 0.5, 2)]    // 1.5 rounds up at 0.5
		[InlineData(1, 0.5, 1)]    // 0.5 rounds up
		[InlineData(1, 0.4, 1)]    // never below one contract
		[InlineData(2, 0.25, 1)]   // 0.5 rounds up
		[InlineData(1, 0.05, 1)]
		public void ExecutionsMode_RoundsTheTotalAndNeverGoesBelowOne(int master, double size, int expected)
		{
			Assert.Equal(expected, Expected(new[] { master }, size, executions: true));
		}

		[Fact]
		public void ExecutionsMode_UsesTheMastersTotalAcrossItsEntryOrders()
		{
			// 1+1+1 scale-in at 0.5x: one rounding of 1.5, not three separate ones.
			Assert.Equal(2, Expected(new[] { 1, 1, 1 }, 0.5, executions: true));
		}

		[Fact]
		public void OrdersMode_SumsAcrossMasterOrders()
		{
			Assert.Equal(6, Expected(new[] { 1, 1, 1 }, 2.0));
			Assert.Equal(9, Expected(new[] { 3 }, 3.0));
			Assert.Equal(3, Expected(new[] { 1, 2 }, 1.0));
		}

		[Fact]
		public void OrdersMode_AFractionIsRoundedPerOrderByTheSizeRoundingSetting()
		{
			// Vendor-confirmed: Orders mode uses the same Size Rounding setting as Executions mode, per order.
			List<string> diag = new List<string>();

			Assert.Equal(2, Expected(new[] { 4 }, 0.5, diag: diag));                              // 4 x 0.5 = 2 exactly
			Assert.Empty(diag);
			Assert.Equal(2, Expected(new[] { 3 }, 0.5, rounding: "Round Up At 0.5"));              // 1.5 rounds up
			Assert.Equal(1, Expected(new[] { 3 }, 0.5, rounding: "Round Down"));                   // 1.5 rounds down
			Assert.Equal(1, Expected(new[] { 1 }, 0.5, rounding: "Round Down"));                   // 0.5 rounds down to 0, floored to 1
		}

		[Fact]
		public void QuantityMode_EveryMasterOrderIsReplacedByTheFixedCount()
		{
			Assert.Equal(2, Expected(new[] { 3 }, 2.0, mode: "Quantity"));
			// The vendor's own example: a 1+1+1 scale-in with Quantity = 2 puts 6 contracts on the follower.
			Assert.Equal(6, Expected(new[] { 1, 1, 1 }, 2.0, mode: "Quantity"));
			Assert.Equal(1, Expected(new[] { 5 }, 0.2, mode: "Quantity"));   // snaps to a whole count of 1 or more
		}

		[Fact]
		public void UnknownRoundingMatters_OnlyWhenAFractionAppears()
		{
			// "Round Down" is a known mode now; use a made-up one to exercise the unknown-mode fallback.
			Assert.Equal(9, Expected(new[] { 3 }, 3.0, rounding: "Nearest Ten Cents", executions: true));
			Assert.Null(Expected(new[] { 3 }, 0.5, rounding: "Nearest Ten Cents", executions: true));
		}

		[Theory]
		[InlineData(3, 3.0, false, 9)]    // no fraction: rounding mode is irrelevant
		[InlineData(3, 0.5, true, 1)]     // 1.5, Executions mode: rounds down to 1
		[InlineData(1, 0.5, true, 1)]     // 0.5 rounds down to 0, floored to 1
		[InlineData(3, 0.5, false, 1)]    // 1.5, Orders mode: rounds down to 1 (same setting)
		public void RoundDown_TruncatesInsteadOfRoundingHalfUp(int master, double size, bool executions, int expected)
		{
			Assert.Equal(expected, Expected(new[] { master }, size, rounding: "Round Down", executions: executions));
		}

		[Fact]
		public void SizeColumnDisabled_EveryFollowerMirrorsTheMastersQuantityExactly()
		{
			// IsXEnabled = false: the Size column (and its rounding) is bypassed entirely.
			Assert.Equal(3, Expected(new[] { 3 }, 2.0, sizeColumnEnabled: false));
			Assert.Equal(3, Expected(new[] { 3 }, 2.0, mode: "Quantity", sizeColumnEnabled: false));
			Assert.Equal(4, Expected(new[] { 1, 3 }, 0.5, executions: true, sizeColumnEnabled: false));
		}

		[Fact]
		public void ANoEntriesMasterHasNoExpectation()
		{
			Assert.Null(Expected(new int[0], 1.0));
		}

		[Fact]
		public void EntryOrderQuantities_CountsAnOrderFillingInPiecesOnce()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("a", "o1", "Entry", 1, 100m, 0, 0m, 1, isEntry: true));
			tracker.Apply(Buy("b", "o1", "Entry", 2, 100m, 0, 0m, 3, isEntry: true));
			tracker.Apply(Buy("c", "o2", "Add", 1, 100m, 5, 0m, 4, isEntry: true));
			CompletedTrade t = tracker.Apply(Sell("d", "o3", "Close", 4, 101m, 10, 0m, 0, isExit: true)).Closed[0];

			Assert.Equal(new[] { 3, 1 }, CopyMatcher.EntryOrderQuantities(t).ToArray());
		}

		[Fact]
		public void ANonExecutionsFollowerWithAFractionalSize_IsCheckedAfterRounding()
		{
			// Master 3 ES at 1/2x in Orders mode: 1.5 rounds up to 2 under the setup's Round Up At 0.5.
			CopyEvaluation e = Run(Master(), Setup("PA-02|Slave|1/2|No|No|No|No|No|Default|No|Rithmic"), Mes("PA-02", Direction.Long, 1, 1, 380));

			CopyResult r = Only(e);
			Assert.Equal(2, r.Expected.Quantity);
			Assert.Equal(new[] { "expected 2 MES, filled 1 MES" }, r.Warnings.ToArray());
			Assert.Equal(0.5m, r.Expected.Multiplier);
		}

		[Fact]
		public void AnExecutionsFollowerWithAFractionalSize_IsCheckedAfterRounding()
		{
			CopyEvaluation ok = Run(Master(), Setup("PA-02|Slave|1/2|No|No|No|No|No|Executions|No|Rithmic"), Mes("PA-02", Direction.Long, 2, 1, 380));
			CopyEvaluation off = Run(Master(), Setup("PA-02|Slave|1/2|No|No|No|No|No|Executions|No|Rithmic"), Mes("PA-02", Direction.Long, 1, 1, 380));

			Assert.Equal(2, Only(ok).Expected.Quantity);      // 3 x 0.5 = 1.5, rounds up
			Assert.Empty(Only(ok).Warnings);
			Assert.Equal(new[] { "expected 2 MES, filled 1 MES" }, Only(off).Warnings.ToArray());
		}

		[Fact]
		public void QuantityModeFollower_ExpectsTheFixedCountPerMasterOrder()
		{
			CopierSnapshot setup = CopierSnapshotParser.Parse(MasterAccount, true, "All", "ES", "Round Up At 0.5", "Quantity",
				new[] { "PA-02|Slave|2|No|No|No|No|No|Default|No|Rithmic" }, "copier_live");

			CopyEvaluation e = Run(Master(), setup, Mes("PA-02", Direction.Long, 2, 1, 380));

			Assert.Equal(2, Only(e).Expected.Quantity);
			Assert.Empty(Only(e).Warnings);
		}

		[Fact]
		public void UnknownRoundingOrMultiplierMode_SkipsTheQuantityCheckAndSaysWhy()
		{
			CopierSnapshot setup = CopierSnapshotParser.Parse(MasterAccount, true, "All", "ES", "Round Down", "Fixed Size", new[] { MicroOrders }, "copier_live");

			CopyEvaluation e = Run(Master(), setup, Mes("PA-02", Direction.Long, 1, 1, 380));

			CopyResult r = Only(e);
			Assert.Null(r.Expected.Quantity);
			Assert.Empty(r.Warnings);
			Assert.Contains(e.Diagnostics, d => d.Contains("not understood"));
		}

		[Fact]
		public void ANanoFollowerWithAnUnmappedSymbolIsMatchedByTimingWithAWarning()
		{
			InstrumentSpec nano = new InstrumentSpec { FullName = "NANOES 12-26", Symbol = "NANOES", TickSize = 0.25m, PointValue = 0.5m };
			CopyCandidate copy = CopyCandidate.FromClosed(Closed("PA-02", nano, Direction.Long, 3, 1, 380));

			CopyEvaluation e = Run(Master(), Setup("PA-02|Slave|1|Nano|No|No|No|No|Default|No|Rithmic"), copy);

			CopyResult r = Only(e);
			Assert.Equal(CopyOutcome.Matched, r.Outcome);
			Assert.Equal(new[] { "nano contract; instrument not checked" }, r.Warnings.ToArray());
		}

		[Fact]
		public void ANanoFollowerWhoseSymbolIsMapped_IsMatchedByMarket()
		{
			MatchOptions options = new MatchOptions { Families = new MarketFamilies(new[] { new[] { "ES", "MES", "NANOES" } }) };
			InstrumentSpec nano = new InstrumentSpec { FullName = "NANOES 12-26", Symbol = "NANOES", TickSize = 0.25m, PointValue = 0.5m };
			CopyCandidate copy = CopyCandidate.FromClosed(Closed("PA-02", nano, Direction.Long, 3, 1, 380));

			CopyEvaluation e = CopyMatcher.Evaluate(Master(), Setup("PA-02|Slave|1|Nano|No|No|No|No|Default|No|Rithmic"), options, new[] { copy });

			Assert.Equal(CopyOutcome.Matched, Only(e).Outcome);
			Assert.DoesNotContain("nano contract; instrument not checked", Only(e).Warnings);
		}

		// ---- existing positions

		[Fact]
		public void AFollowerAlreadyInAPositionBeforeTheWindow_IsMissedNotMatched()
		{
			// PA-02 went long MES at -60 s and was still in it when the master entered at 0.
			CopyCandidate existing = Mes("PA-02", Direction.Long, 3, -60, 200);

			CopyEvaluation e = Run(Master(), Setup(MicroOrders), existing);

			CopyResult r = Only(e);
			Assert.Equal(CopyOutcome.Missed, r.Outcome);
			Assert.Contains("already in a position", r.Warnings[0]);
			Assert.Empty(r.Fills);
		}

		[Fact]
		public void APositionThatClosedBeforeTheWindowOpened_IsNotInTheWay()
		{
			CopyCandidate oldTrade = Mes("PA-02", Direction.Long, 3, -300, -100);
			CopyCandidate copy = Mes("PA-02", Direction.Long, 3, 1, 380);

			CopyEvaluation e = Run(Master(), Setup(MicroOrders), oldTrade, copy);

			Assert.Equal(CopyOutcome.Matched, Only(e).Outcome);
			Assert.Equal(3, Only(e).Quantity);
		}

		// ---- still open

		[Fact]
		public void ACopyStillOpen_IsReportedWithItsFillsSoFar_ThenMatchedOnceFlat()
		{
			TradeTracker follower = new TradeTracker();
			follower.Apply(Buy("f1", "fo1", "Entry", 3, 100m, 1, 1.5m, 3, "PA-02", TestData.Mes, isEntry: true));
			ApplyResult partial = follower.Apply(Sell("f2", "fo2", "Target1", 2, 101m, 70, 1.0m, 1, "PA-02", TestData.Mes, isExit: true));
			OpenTradeInfo open = follower.OpenTrades.Single();

			CopyEvaluation whileOpen = Run(Master(), Setup(MicroOrders), CopyCandidate.FromOpen(open));

			CopyResult r = Only(whileOpen);
			Assert.Equal(CopyOutcome.StillOpen, r.Outcome);
			Assert.Equal(2, r.Fills.Count);
			Assert.Equal(3, r.Quantity);
			Assert.Equal(100m, r.EntryAveragePrice);
			Assert.Equal(101m, r.ExitAveragePrice);
			Assert.Equal(10.00m, r.Performance.GrossPnl);     // 2 MES * 1 point * $5
			Assert.Equal(2.50m, r.Performance.Commission);    // every fill so far
			Assert.Equal(7.50m, r.Performance.NetPnl);
			Assert.Null(r.ExitedAt);
			Assert.Equal(1, whileOpen.Summary.StillOpen);
			Assert.Equal(7.50m, whileOpen.Summary.NetPnl);
			Assert.Empty(r.Warnings);   // an open copy may still be scaling: no quantity check yet

			CompletedTrade done = follower.Apply(Sell("f3", "fo3", "Stop1", 1, 100.5m, 380, 0.5m, 0, "PA-02", TestData.Mes, isExit: true)).Closed[0];
			CopyEvaluation later = Run(Master(), Setup(MicroOrders), CopyCandidate.FromClosed(done));

			Assert.Equal(CopyOutcome.Matched, Only(later).Outcome);
			Assert.NotNull(Only(later).ExitedAt);
		}

		[Fact]
		public void AnOpenCopyWithNothingExitedYet_HasNoPerformance()
		{
			OpenTradeInfo open = Open("PA-02", TestData.Mes, Direction.Long, 3, 1);

			CopyEvaluation e = Run(Master(), Setup(MicroOrders), CopyCandidate.FromOpen(open));

			CopyResult r = Only(e);
			Assert.Equal(CopyOutcome.StillOpen, r.Outcome);
			Assert.Null(r.Performance);
			Assert.Null(r.ExitAveragePrice);
			Assert.Equal(0m, e.Summary.NetPnl);
		}

		// ---- independent exits

		[Fact]
		public void ACopyThatExitsDifferentlyFromTheMasterIsStillTheCopy()
		{
			// Closed early by a daily-loss exit at a different price and time.
			CopyCandidate early = CopyCandidate.FromClosed(Closed("PA-02", TestData.Mes, Direction.Long, 3, 1, 20, 100m, 98m));

			CopyEvaluation e = Run(Master(), Setup(MicroOrders), early);

			CopyResult r = Only(e);
			Assert.Equal(CopyOutcome.Matched, r.Outcome);
			Assert.Equal(-30.00m, r.Performance.GrossPnl);
		}

		// ---- auto-detect

		[Fact]
		public void AutoDetect_AnyAccountEnteringInTheWindowAfterTheMasterIsACopy()
		{
			CopyEvaluation e = Run(Master(), null, Mes("PA-02", Direction.Long, 3, 2, 380), Mes("PA-05", Direction.Long, 3, 30, 380));

			Assert.Equal("auto_detect", e.Source);
			CopyResult r = Only(e);
			Assert.Equal("PA-02", r.Account);
			Assert.Null(r.Expected);
		}

		[Fact]
		public void AutoDetect_AlsoAcceptsAnEntryJustBeforeTheMaster()
		{
			CopyEvaluation e = Run(Master(), null, Mes("PA-02", Direction.Long, 3, -4, 380));

			Assert.Equal(CopyOutcome.Matched, Only(e).Outcome);
		}

		[Fact]
		public void AutoDetect_SimPlaybackAndJournaledMastersAreNeverCopies()
		{
			MatchOptions options = new MatchOptions();
			options.JournaledMasters.Add("TEST-ACCT-200");

			CopyEvaluation e = CopyMatcher.Evaluate(Master(), null, options, new[]
			{
				Mes("Sim101", Direction.Long, 3, 1, 380),
				Mes("Playback101", Direction.Long, 3, 1, 380),
				Mes("TEST-ACCT-200", Direction.Long, 3, 1, 380),
				Mes("PA-02", Direction.Long, 3, 1, 380)
			});

			Assert.Equal(new[] { "PA-02" }, e.Copies.Select(c => c.Account).ToArray());
		}

		[Fact]
		public void AutoDetect_OnlyRecentlyMatchedAccountsCanBeReportedMissed()
		{
			CompletedTrade master = Master();
			MatchOptions options = new MatchOptions();
			options.AutoDetectHistory["PA-RECENT"] = master.EntryAt.AddDays(-3);
			options.AutoDetectHistory["PA-STALE"] = master.EntryAt.AddDays(-45);

			CopyEvaluation e = CopyMatcher.Evaluate(master, null, options, new CopyCandidate[0]);

			CopyResult r = Only(e);
			Assert.Equal("PA-RECENT", r.Account);
			Assert.Equal(CopyOutcome.Missed, r.Outcome);
		}

		[Fact]
		public void AutoDetect_AnAccountThatCopiedIsNotAlsoReportedMissed()
		{
			CompletedTrade master = Master();
			MatchOptions options = new MatchOptions();
			options.AutoDetectHistory["PA-02"] = master.EntryAt.AddDays(-1);

			CopyEvaluation e = CopyMatcher.Evaluate(master, null, options, new[] { Mes("PA-02", Direction.Long, 3, 1, 380) });

			Assert.Equal(CopyOutcome.Matched, Only(e).Outcome);
		}

		// ---- no followers

		[Fact]
		public void WhenEveryExpectedFollowerMisses_AndNothingWasTradedNearby_ADiagnosticSaysTheCopierMayNotHaveActed()
		{
			CopyEvaluation e = Run(Master(), Setup(MicroOrders, "PA-03|Slave|1|No|No|No|No|No|Default|No|Rithmic"));

			Assert.Equal(2, e.Copies.Count);
			Assert.All(e.Copies, c => Assert.Equal(CopyOutcome.Missed, c.Outcome));
			Assert.Contains(e.Diagnostics, d => d.Contains("may not have copied this trade"));
		}

		[Fact]
		public void NoDiagnosticWhenSomeoneCopied_OrWhenAFollowerTradedTheWrongWay()
		{
			CopyEvaluation someoneCopied = Run(Master(), Setup(MicroOrders, "PA-03|Slave|1|No|No|No|No|No|Default|No|Rithmic"),
				Mes("PA-02", Direction.Long, 3, 1, 380));
			CopyEvaluation wrongWay = Run(Master(), Setup(MicroOrders), Mes("PA-02", Direction.Short, 3, 1, 380));

			Assert.DoesNotContain(someoneCopied.Diagnostics, d => d.Contains("may not have copied"));
			Assert.DoesNotContain(wrongWay.Diagnostics, d => d.Contains("may not have copied"));
		}

		[Fact]
		public void ASetupWithNoFollowers_HasNoCopiesAndNoSummary()
		{
			CopyEvaluation e = Run(Master(), Setup());

			Assert.Equal("copier_live", e.Source);
			Assert.Empty(e.Copies);
			Assert.Null(e.Summary);
		}

		// ---- Select Trade Direction

		private static CopierSnapshot FilteredSetup(bool enabled, bool allowLong, bool allowShort, params string[] rows)
		{
			return CopierSnapshotParser.Parse(MasterAccount, true, "All", "ES", "Round Up At 0.5", "Multiplier", rows, CopierSnapshotParser.SourceLive,
				selectTradeDirectionEnabled: enabled, allowLong: allowLong, allowShort: allowShort);
		}

		// Short 1 from -60 s, then a 4-lot buy at 0 s reverses it into a long 3, closed at 380 s.
		private static CompletedTrade ReversedLongMaster()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Sell("rv1", "orv1", "Entry", 1, 100m, -60, 0m, -1, MasterAccount, Es, isEntry: true));
			tracker.Apply(Buy("rv2", "orv2", "Entry", 4, 100m, 0, 0m, 3, MasterAccount, Es, isEntry: true, isExit: true));
			return tracker.Apply(Sell("rv3", "orv3", "Close", 3, 101m, 380, 0m, 0, MasterAccount, Es, isExit: true)).Closed[0];
		}

		[Fact]
		public void DirectionFilter_BlocksAnExecutionsModeFollowerOnAnEntryFromFlat()
		{
			CopyEvaluation e = Run(Master(Direction.Long), FilteredSetup(true, false, true, MicroExecutions));

			Assert.Empty(e.Copies);
			Assert.Contains(e.Diagnostics, d => d.Contains("Select Trade Direction does not allow long entries from flat") && d.Contains("PA-02"));
			Assert.DoesNotContain(e.Diagnostics, d => d.Contains("may not have copied"));
		}

		[Fact]
		public void DirectionFilter_BlocksShortEntriesWhenShortIsNotAllowed()
		{
			CopyEvaluation e = Run(Master(Direction.Short), FilteredSetup(true, true, false, MicroExecutions));

			Assert.Empty(e.Copies);
			Assert.Contains(e.Diagnostics, d => d.Contains("does not allow short entries from flat"));
		}

		[Fact]
		public void DirectionFilter_DoesNotApplyToOrdersModeFollowers()
		{
			CopyEvaluation e = Run(Master(Direction.Long), FilteredSetup(true, false, true, MicroOrders));

			Assert.Equal(CopyOutcome.Missed, Only(e).Outcome);
			Assert.DoesNotContain(e.Diagnostics, d => d.Contains("Select Trade Direction"));
		}

		[Fact]
		public void DirectionFilter_DoesNotApplyWhenTheMasterTradeWasOpenedByAReversal()
		{
			CompletedTrade master = ReversedLongMaster();
			Assert.True(master.OpenedByReversal);

			CopyEvaluation e = Run(master, FilteredSetup(true, false, true, MicroExecutions));

			Assert.Equal(CopyOutcome.Missed, Only(e).Outcome);
			Assert.DoesNotContain(e.Diagnostics, d => d.Contains("Select Trade Direction"));
		}

		[Fact]
		public void DirectionFilter_AnAllowedDirectionIsStillExpected()
		{
			CopyEvaluation e = Run(Master(Direction.Long), FilteredSetup(true, true, false, MicroExecutions));

			Assert.Equal(CopyOutcome.Missed, Only(e).Outcome);
		}

		[Fact]
		public void DirectionFilter_AllowFlagsAreIgnoredWhenTheFilterIsOff()
		{
			CopyEvaluation e = Run(Master(Direction.Long), FilteredSetup(false, false, false, MicroExecutions));

			Assert.Equal(CopyOutcome.Missed, Only(e).Outcome);
		}

		[Fact]
		public void DirectionFilter_AFollowerThatCopiedAnywayIsStillMatched()
		{
			CopyEvaluation e = Run(Master(Direction.Long), FilteredSetup(true, false, true, MicroExecutions),
				Mes("PA-02", Direction.Long, 3, 1, 380));

			Assert.Equal(CopyOutcome.Matched, Only(e).Outcome);
			Assert.DoesNotContain(e.Diagnostics, d => d.Contains("Select Trade Direction"));
		}

		[Fact]
		public void DirectionFilter_OnlyTheExecutionsModeFollowerIsExcluded()
		{
			CopyEvaluation e = Run(Master(Direction.Long),
				FilteredSetup(true, false, true, MicroExecutions, "PA-03|Slave|1|No|No|No|No|No|Default|No|Rithmic"));

			CopyResult missed = Only(e);
			Assert.Equal("PA-03", missed.Account);
			Assert.Equal(CopyOutcome.Missed, missed.Outcome);
			Assert.Contains(e.Diagnostics, d => d.Contains("Select Trade Direction") && d.Contains("PA-02") && !d.Contains("PA-03"));
		}
	}
}
