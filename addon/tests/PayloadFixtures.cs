using ChartJot.Core;
using static ChartJot.Core.Tests.TestData;

namespace ChartJot.Core.Tests
{
	/// <summary>
	/// Payloads the AddOn produces, shared with the Laravel test suite as fixtures in tests/Fixtures/addon.
	/// Laravel posts every file to the real intake endpoint, so the two sides cannot drift apart unnoticed.
	/// Regenerate with PATS_WRITE_FIXTURES=1 dotnet test addon/tests.
	/// </summary>
	internal static class PayloadFixtures
	{
		private const string Master = "TEST-ACCT-001";

		public static IDictionary<string, string> All()
		{
			return new SortedDictionary<string, string>
			{
				{ "long-runner-with-copies", LongRunnerWithCopies() },
				{ "short-no-atm-blank-names", ShortNoAtm() },
				{ "reversal-second-trade", ReversalSecondTrade() },
				{ "still-open-copy-and-fade", StillOpenCopyAndFade() },
				{ "sim-same-second-scalp", SameSecondScalp() }
			};
		}

		private static SubmissionInfo Info(CopyEvaluation copies, string type = "2EL")
		{
			return new SubmissionInfo
			{
				AddonVersion = "1.0.0",
				Connection = "Rithmic",
				TradeType = type,
				Notes = new List<NoteRecord>
				{
					new NoteRecord { Body = "H2 at the EMA. Follow the plan.", Phase = "pre_trade", OccurredAt = At(-20) },
					new NoteRecord { Body = "T1 filled. Letting the runner work.", Phase = "in_trade", OccurredAt = At(85) },
					new NoteRecord { Body = "Runner stopped. Good management.", Phase = "post_trade", OccurredAt = At(400) }
				},
				Screenshot = new ScreenshotMeta { CapturedAt = At(381), Caption = "5-minute ES with H2 at EMA" },
				Copies = copies
			};
		}

		private static string LongRunnerWithCopies()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("a1b2c3d4e5f6", "ord-1001", "Entry", 3, 7700.00m, 0, 3.87m, 3, isEntry: true));
			tracker.OnPrice("ES 12-26", 7699.25m);
			tracker.Apply(Sell("a1b2c3d4e5f7", "ord-1002", "Target1", 2, 7701.00m, 70, 2.58m, 1, isExit: true));
			tracker.OnPrice("ES 12-26", 7703.25m);
			CompletedTrade master = tracker.Apply(Sell("a1b2c3d4e5f8", "ord-1003", "Stop1", 1, 7702.50m, 380, 1.29m, 0, isExit: true)).Closed[0];

			TradeTracker follower = new TradeTracker();
			follower.Apply(Buy("b1c2d3e4f5a6", "ord-2001", "Entry", 3, 7700.25m, 1, 1.17m, 3, "TEST-ACCT-002", Mes, isEntry: true));
			follower.Apply(Sell("b1c2d3e4f5a7", "ord-2002", "Target1", 2, 7701.00m, 70, 0.78m, 1, "TEST-ACCT-002", Mes, isExit: true));
			CompletedTrade copy = follower.Apply(Sell("b1c2d3e4f5a8", "ord-2003", "Stop1", 1, 7702.50m, 380, 0.39m, 0, "TEST-ACCT-002", Mes, isExit: true)).Closed[0];

			CopierSnapshot setup = CopierSnapshotParser.Parse(Master, true, "All", "ES", "Round Up At 0.5", "Multiplier", new[]
			{
				"TEST-ACCT-002|Slave|1|No|No|No|No|No|Default|No|Rithmic",
				"TEST-ACCT-03|Slave|1|No|No|No|No|No|Default|No|Rithmic",
				"TEST-ACCT-04|Slave|3|No|No|No|No|No|Default|Yes|Rithmic"
			}, CopierSnapshotParser.SourceLive);

			CopyEvaluation copies = CopyMatcher.Evaluate(master, setup, new MatchOptions(), new[] { CopyCandidate.FromClosed(copy) });
			return PayloadBuilder.Build(master, Info(copies));
		}

		private static string ShortNoAtm()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Sell("n1", "no1", "", 1, 7794.25m, 0, 1.99m, -1, Master, isEntry: true));
			tracker.OnPrice("ES 12-26", 7793.00m);
			tracker.OnPrice("ES 12-26", 7796.00m);
			CompletedTrade trade = tracker.Apply(Buy("n2", "no2", "", 1, 7796.75m, 200, 1.99m, 0, Master, isExit: true)).Closed[0];

			SubmissionInfo info = Info(null, "RS");
			info.Notes = new List<NoteRecord>();
			info.Screenshot = null;
			return PayloadBuilder.Build(trade, info);
		}

		private static string ReversalSecondTrade()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("r1", "ro1", "", 1, 7804.75m, 0, 1.99m, 1, "Sim101", isEntry: true));
			tracker.Apply(Sell("r2", "ro2", "", 2, 7805.25m, 30, 3.98m, -1, "Sim101", isEntry: true, isExit: true));
			CompletedTrade second = tracker.Apply(Buy("r3", "ro3", "", 1, 7805.00m, 90, 1.99m, 0, "Sim101", isExit: true)).Closed[0];

			SubmissionInfo info = Info(null, "F2EL");
			info.Connection = "NinjaTrader";
			return PayloadBuilder.Build(second, info);
		}

		private static string StillOpenCopyAndFade()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Sell("m1", "mo1", "Entry", 2, 7800.00m, 0, 2.00m, -2, Master, isEntry: true));
			CompletedTrade master = tracker.Apply(Buy("m2", "mo2", "Target1", 2, 7799.00m, 120, 2.00m, 0, Master, isExit: true)).Closed[0];

			TradeTracker follower = new TradeTracker();
			follower.Apply(Sell("s1", "so1", "Entry", 4, 7800.00m, 1, 1.0m, -4, "TEST-ACCT-005", Mes, isEntry: true));
			follower.Apply(Buy("s2", "so2", "Target1", 2, 7799.00m, 120, 1.0m, -2, "TEST-ACCT-005", Mes, isExit: true));
			OpenTradeInfo open = follower.OpenTrades[0];

			TradeTracker faded = new TradeTracker();
			faded.Apply(Buy("f1", "fo1", "AI", 2, 7800.25m, 2, 1.0m, 2, "TEST-ACCT-06", Mes, isEntry: true));
			CompletedTrade fadedCopy = faded.Apply(Sell("f2", "fo2", "AI", 2, 7800.75m, 121, 1.0m, 0, "TEST-ACCT-06", Mes, isExit: true)).Closed[0];

			CopierSnapshot setup = CopierSnapshotParser.Parse(Master, true, "All", "ES", "Round Up At 0.5", "Multiplier", new[]
			{
				"TEST-ACCT-005|Slave|2|No|No|No|No|No|Default|No|Rithmic",
				"TEST-ACCT-06|Slave|1|No|Yes|No|No|No|Executions|No|Rithmic"
			}, CopierSnapshotParser.SourceWorkspace);

			CopyEvaluation copies = CopyMatcher.Evaluate(master, setup, new MatchOptions(),
				new[] { CopyCandidate.FromOpen(open), CopyCandidate.FromClosed(fadedCopy) });
			return PayloadBuilder.Build(master, Info(copies, "2ES"));
		}

		private static string SameSecondScalp()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("z1", "zo1", "Entry", 1, 7700.00m, 0, 1.99m, 1, "Sim101", isEntry: true));
			CompletedTrade trade = tracker.Apply(Sell("z2", "zo2", "Stop1", 1, 7699.50m, 0, 1.99m, 0, "Sim101", isExit: true)).Closed[0];

			SubmissionInfo info = Info(null, "Other");
			info.TradeTypeOther = "fast stop-out";
			info.Connection = "NinjaTrader";
			return PayloadBuilder.Build(trade, info);
		}
	}
}
