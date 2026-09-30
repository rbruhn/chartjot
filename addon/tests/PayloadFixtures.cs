using ChartJot.Core;
using static ChartJot.Core.Tests.TestData;

namespace ChartJot.Core.Tests
{
	/// <summary>
	/// Payloads the AddOn produces, shared with the Laravel test suite as fixtures in tests/Fixtures/addon.
	/// Laravel posts every file to the real intake endpoint, so the two sides cannot drift apart unnoticed.
	/// Regenerate with PATS_WRITE_FIXTURES=1 dotnet test addon/tests.
	/// Every account's round turn is its own submission, master or copier follower alike; nothing links them.
	/// </summary>
	internal static class PayloadFixtures
	{
		private const string Master = "APEX-24570-135";

		public static IDictionary<string, string> All()
		{
			return new SortedDictionary<string, string>
			{
				{ "long-runner", LongRunner() },
				{ "follower-micro-long-runner", FollowerMicroLongRunner() },
				{ "follower-executions-mode-ai-orders", FollowerExecutionsModeAiOrders() },
				{ "short-no-atm-blank-names", ShortNoAtm() },
				{ "reversal-second-trade", ReversalSecondTrade() },
				{ "sim-same-second-scalp", SameSecondScalp() }
			};
		}

		private static SubmissionInfo Info(string type = "2EL")
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
				Screenshot = new ScreenshotMeta { CapturedAt = At(381), Caption = "5-minute ES with H2 at EMA" }
			};
		}

		private static string LongRunner()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("a1b2c3d4e5f6", "ord-1001", "Entry", 3, 7700.00m, 0, 3.87m, 3, isEntry: true));
			tracker.OnPrice("ES 12-26", 7699.25m);
			tracker.Apply(Sell("a1b2c3d4e5f7", "ord-1002", "Target1", 2, 7701.00m, 70, 2.58m, 1, isExit: true));
			tracker.OnPrice("ES 12-26", 7703.25m);
			CompletedTrade trade = tracker.Apply(Sell("a1b2c3d4e5f8", "ord-1003", "Stop1", 1, 7702.50m, 380, 1.29m, 0, isExit: true)).Closed[0];

			return PayloadBuilder.Build(trade, Info());
		}

		/// <summary>An Orders-mode Micro follower of the long runner above, sent as its own trade.</summary>
		private static string FollowerMicroLongRunner()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("b1c2d3e4f5a6", "ord-2001", "Entry", 3, 7700.25m, 1, 1.17m, 3, "PA-APEX-24570-02", Mes, isEntry: true));
			tracker.OnPrice("MES 12-26", 7699.50m);
			tracker.Apply(Sell("b1c2d3e4f5a7", "ord-2002", "Target1", 2, 7701.00m, 70, 0.78m, 1, "PA-APEX-24570-02", Mes, isExit: true));
			tracker.OnPrice("MES 12-26", 7703.25m);
			CompletedTrade trade = tracker.Apply(Sell("b1c2d3e4f5a8", "ord-2003", "Stop1", 1, 7702.50m, 380, 0.39m, 0, "PA-APEX-24570-02", Mes, isExit: true)).Closed[0];

			SubmissionInfo info = Info();
			info.Screenshot = null;
			return PayloadBuilder.Build(trade, info);
		}

		/// <summary>An Executions-mode follower: its orders are all named "AI", so the exit reason is "other".</summary>
		private static string FollowerExecutionsModeAiOrders()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Sell("s1", "so1", "AI", 4, 7800.00m, 1, 1.0m, -4, "PA-APEX-24570-05", Mes, isEntry: true));
			tracker.Apply(Buy("s2", "so2", "AI", 2, 7799.00m, 120, 1.0m, -2, "PA-APEX-24570-05", Mes, isExit: true));
			CompletedTrade trade = tracker.Apply(Buy("s3", "so3", "AI", 2, 7798.50m, 240, 1.0m, 0, "PA-APEX-24570-05", Mes, isExit: true)).Closed[0];

			SubmissionInfo info = Info("2ES");
			info.Notes = new List<NoteRecord>();
			info.Screenshot = null;
			return PayloadBuilder.Build(trade, info);
		}

		private static string ShortNoAtm()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Sell("n1", "no1", "", 1, 7794.25m, 0, 1.99m, -1, Master, isEntry: true));
			tracker.OnPrice("ES 12-26", 7793.00m);
			tracker.OnPrice("ES 12-26", 7796.00m);
			CompletedTrade trade = tracker.Apply(Buy("n2", "no2", "", 1, 7796.75m, 200, 1.99m, 0, Master, isExit: true)).Closed[0];

			SubmissionInfo info = Info("RS");
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

			SubmissionInfo info = Info("F2EL");
			info.Connection = "NinjaTrader";
			return PayloadBuilder.Build(second, info);
		}

		private static string SameSecondScalp()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("z1", "zo1", "Entry", 1, 7700.00m, 0, 1.99m, 1, "Sim101", isEntry: true));
			CompletedTrade trade = tracker.Apply(Sell("z2", "zo2", "Stop1", 1, 7699.50m, 0, 1.99m, 0, "Sim101", isExit: true)).Closed[0];

			SubmissionInfo info = Info("Other");
			info.TradeTypeOther = "fast stop-out";
			info.Connection = "NinjaTrader";
			return PayloadBuilder.Build(trade, info);
		}
	}
}
