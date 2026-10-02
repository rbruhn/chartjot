using System.Text.Json;
using ChartJot.Core;
using static ChartJot.Core.Tests.TestData;

namespace ChartJot.Core.Tests
{
	public class PayloadBuilderTests
	{
		private static CompletedTrade WorkedExample()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("a1b2c3d4e5f6", "ord-1001", "Entry", 3, 7700.00m, 0, 3.87m, 3, isEntry: true));
			tracker.OnPrice("ES 12-26", 7699.25m);
			tracker.Apply(Sell("a1b2c3d4e5f7", "ord-1002", "Target1", 2, 7701.00m, 70, 2.58m, 1, isExit: true));
			tracker.OnPrice("ES 12-26", 7703.25m);
			return tracker.Apply(Sell("a1b2c3d4e5f8", "ord-1003", "Stop1", 1, 7702.50m, 380, 1.29m, 0, isExit: true)).Closed[0];
		}

		private static SubmissionInfo Info(string tradeType = "2EL")
		{
			return new SubmissionInfo
			{
				AddonVersion = "1.0.0",
				Connection = "Rithmic",
				TradeType = tradeType,
				Notes = new List<NoteRecord>
				{
					new NoteRecord { Body = "Runner stopped. Good management.", Phase = "post_trade", OccurredAt = At(400) },
					new NoteRecord { Body = "H2 at the EMA. Follow the plan.", Phase = "pre_trade", OccurredAt = At(-20) },
					new NoteRecord { Body = "T1 filled. Letting the runner work.", Phase = "in_trade", OccurredAt = At(85) }
				},
				Screenshot = new ScreenshotMeta { CapturedAt = At(381), Caption = "5-minute ES with H2 at EMA" }
			};
		}

		private static JsonElement Parse(string json)
		{
			return JsonDocument.Parse(json).RootElement;
		}

		[Fact]
		public void WorkedExample_MatchesTheDocumentedPayload()
		{
			JsonElement root = Parse(PayloadBuilder.Build(WorkedExample(), Info()));

			Assert.Equal(1, root.GetProperty("schema_version").GetInt32());
			Assert.Equal("ninjatrader_8", root.GetProperty("source").GetString());
			Assert.Equal("1.0.0", root.GetProperty("addon_version").GetString());
			Assert.Equal("TEST-ACCT-001", root.GetProperty("account_name").GetString());
			Assert.Equal("Rithmic", root.GetProperty("connection").GetString());
			Assert.Equal("2EL", root.GetProperty("trade_type").GetString());
			Assert.Equal(JsonValueKind.Null, root.GetProperty("trade_type_other").ValueKind);
			Assert.Equal("long", root.GetProperty("direction").GetString());
			Assert.Equal(3, root.GetProperty("quantity").GetInt32());
			Assert.Equal(3, root.GetProperty("total_entry_quantity").GetInt32());

			JsonElement instrument = root.GetProperty("instrument");
			Assert.Equal("ES", instrument.GetProperty("symbol").GetString());
			Assert.Equal("ES 12-26", instrument.GetProperty("contract").GetString());
			Assert.Equal("0.25", instrument.GetProperty("tick_size").GetString());
			Assert.Equal("50", instrument.GetProperty("point_value").GetString());

			JsonElement entry = root.GetProperty("entry");
			Assert.Equal("2026-09-24T09:30:00-04:00", entry.GetProperty("occurred_at").GetString());
			Assert.Equal("7700.00", entry.GetProperty("average_price").GetString());
			Assert.Equal("Entry", entry.GetProperty("order_name").GetString());

			JsonElement exit = root.GetProperty("exit");
			Assert.Equal("2026-09-24T09:36:20-04:00", exit.GetProperty("occurred_at").GetString());
			Assert.Equal("7701.50", exit.GetProperty("average_price").GetString());
			Assert.Equal("Stop1", exit.GetProperty("order_name").GetString());
			Assert.Equal("stop", exit.GetProperty("reason").GetString());

			JsonElement perf = root.GetProperty("performance");
			Assert.Equal("1.50", perf.GetProperty("points").GetString());
			Assert.Equal(6, perf.GetProperty("ticks").GetInt32());
			Assert.Equal("225.00", perf.GetProperty("gross_pnl").GetString());
			Assert.Equal("7.74", perf.GetProperty("commission").GetString());
			Assert.Equal(JsonValueKind.Null, perf.GetProperty("fees").ValueKind);
			Assert.Equal("217.26", perf.GetProperty("net_pnl").GetString());

			JsonElement exc = root.GetProperty("excursion");
			Assert.Equal("0.75", exc.GetProperty("mae_points").GetString());
			Assert.Equal("3.25", exc.GetProperty("mfe_points").GetString());
			Assert.Equal("7699.25", exc.GetProperty("max_adverse_price").GetString());
			Assert.Equal("7703.25", exc.GetProperty("max_favorable_price").GetString());
			Assert.True(exc.GetProperty("complete").GetBoolean());
		}

		[Fact]
		public void WorkedExample_LegsExecutionsAndCommissions()
		{
			JsonElement root = Parse(PayloadBuilder.Build(WorkedExample(), Info()));

			JsonElement legs = root.GetProperty("legs");
			Assert.Equal(2, legs.GetArrayLength());
			Assert.False(legs[0].GetProperty("runner").GetBoolean());
			Assert.Equal("ord-1002", legs[0].GetProperty("exit_order_id").GetString());
			Assert.Equal("profit_target", legs[0].GetProperty("reason").GetString());
			Assert.Equal("100.00", legs[0].GetProperty("gross_pnl").GetString());
			Assert.Equal("1.00", legs[0].GetProperty("mfe_points").GetString());
			Assert.True(legs[1].GetProperty("runner").GetBoolean());
			Assert.Equal("125.00", legs[1].GetProperty("gross_pnl").GetString());
			Assert.Equal("3.25", legs[1].GetProperty("mfe_points").GetString());

			JsonElement ex = root.GetProperty("executions");
			Assert.Equal(3, ex.GetArrayLength());
			Assert.Equal("a1b2c3d4e5f6", ex[0].GetProperty("execution_id").GetString());
			Assert.Equal("buy", ex[0].GetProperty("action").GetString());
			Assert.Equal("entry", ex[0].GetProperty("role").GetString());
			Assert.Equal("3.87", ex[0].GetProperty("commission").GetString());
			Assert.Equal(JsonValueKind.Null, ex[0].GetProperty("fee").ValueKind);
			Assert.Equal(3, ex[0].GetProperty("position_after").GetInt32());
			Assert.Equal("sell", ex[1].GetProperty("action").GetString());
			Assert.Equal("exit", ex[1].GetProperty("role").GetString());
			Assert.Equal("7701.00", ex[1].GetProperty("price").GetString());
			Assert.Equal(0, ex[2].GetProperty("position_after").GetInt32());
		}

		[Fact]
		public void NotesAreOrderedByTimeAndScreenshotMetadataIsIncluded()
		{
			JsonElement root = Parse(PayloadBuilder.Build(WorkedExample(), Info()));

			JsonElement notes = root.GetProperty("notes");
			Assert.Equal(new[] { "pre_trade", "in_trade", "post_trade" },
				notes.EnumerateArray().Select(n => n.GetProperty("phase").GetString()).ToArray());
			Assert.Equal("2026-09-24T09:29:40-04:00", notes[0].GetProperty("occurred_at").GetString());

			JsonElement shot = root.GetProperty("screenshot");
			Assert.Equal("2026-09-24T09:36:21-04:00", shot.GetProperty("captured_at").GetString());
			Assert.Equal("5-minute ES with H2 at EMA", shot.GetProperty("caption").GetString());
		}

		[Fact]
		public void WithoutAScreenshot_TheScreenshotObjectIsOmitted()
		{
			SubmissionInfo info = Info();
			info.Screenshot = null;

			JsonElement root = Parse(PayloadBuilder.Build(WorkedExample(), info));

			Assert.False(root.TryGetProperty("screenshot", out _));
		}

		[Fact]
		public void StopPrice_IsSentAsAPriceStringWhenKnown()
		{
			SubmissionInfo info = Info();
			info.StopPrice = 7698.5m;

			JsonElement root = Parse(PayloadBuilder.Build(WorkedExample(), info));

			Assert.Equal(JsonValueKind.String, root.GetProperty("stop_price").ValueKind);
			Assert.Equal("7698.50", root.GetProperty("stop_price").GetString());
		}

		[Fact]
		public void StopPrice_IsSentAsNullWhenUnknown_AndNeverBlocksSubmission()
		{
			SubmissionInfo info = Info();
			info.StopPrice = null;

			JsonElement root = Parse(PayloadBuilder.Build(WorkedExample(), info));

			Assert.Equal(JsonValueKind.Null, root.GetProperty("stop_price").ValueKind);
		}

		[Fact]
		public void CarriesNoTradeCopierFields()
		{
			JsonElement root = Parse(PayloadBuilder.Build(WorkedExample(), Info()));

			Assert.False(root.TryGetProperty("copies_source", out _));
			Assert.False(root.TryGetProperty("copies_summary", out _));
			Assert.False(root.TryGetProperty("copies", out _));
		}

		[Fact]
		public void AFollowerAccountsTradeIsBuiltLikeAnyOther()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("f1", "fo1", "AI", 3, 7700.25m, 1, 1.17m, 3, "TEST-ACCT-002", Mes, isEntry: true));
			CompletedTrade follower = tracker.Apply(Sell("f2", "fo2", "AI", 3, 7701.50m, 380, 1.17m, 0, "TEST-ACCT-002", Mes, isExit: true)).Closed[0];

			JsonElement root = Parse(PayloadBuilder.Build(follower, Info()));

			Assert.Equal("TEST-ACCT-002", root.GetProperty("account_name").GetString());
			Assert.Equal("MES", root.GetProperty("instrument").GetProperty("symbol").GetString());
			Assert.Equal("5", root.GetProperty("instrument").GetProperty("point_value").GetString());
			Assert.Equal("18.75", root.GetProperty("performance").GetProperty("gross_pnl").GetString());
			Assert.Equal("other", root.GetProperty("exit").GetProperty("reason").GetString());
		}

		[Fact]
		public void EveryPriceAndMoneyValueIsAString()
		{
			JsonElement root = Parse(PayloadBuilder.Build(WorkedExample(), Info()));

			foreach (string[] path in new[]
			{
				new[] { "entry", "average_price" }, new[] { "exit", "average_price" },
				new[] { "performance", "points" }, new[] { "performance", "gross_pnl" },
				new[] { "performance", "commission" }, new[] { "performance", "net_pnl" },
				new[] { "instrument", "tick_size" }, new[] { "instrument", "point_value" }
			})
			{
				JsonElement value = root;
				foreach (string part in path)
					value = value.GetProperty(part);
				Assert.Equal(JsonValueKind.String, value.ValueKind);
			}
		}

		[Fact]
		public void TradeTypeMustBeChosen()
		{
			Assert.Throws<ArgumentException>(() => PayloadBuilder.Build(WorkedExample(), Info("")));
			Assert.Throws<ArgumentException>(() => PayloadBuilder.Build(WorkedExample(), Info("XYZ")));
			Assert.Throws<ArgumentException>(() => PayloadBuilder.Build(WorkedExample(), Info(null)));
		}

		[Fact]
		public void TradeTypeOther_CarriesTheDescriptionOnlyForOther()
		{
			SubmissionInfo other = Info("Other");
			other.TradeTypeOther = "  breakout retest  ";
			Assert.Equal("breakout retest",
				Parse(PayloadBuilder.Build(WorkedExample(), other)).GetProperty("trade_type_other").GetString());

			SubmissionInfo notOther = Info("RL");
			notOther.TradeTypeOther = "ignored";
			Assert.Equal(JsonValueKind.Null,
				Parse(PayloadBuilder.Build(WorkedExample(), notOther)).GetProperty("trade_type_other").ValueKind);
		}

		[Fact]
		public void BlankOrderNamesAreSentAsNull_SoTradesWithoutAnAtmStillSubmit()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("a", "o1", "", 1, 100m, 0, 1m, 1, isEntry: true));
			CompletedTrade trade = tracker.Apply(Sell("b", "o2", "", 1, 101m, 5, 1m, 0, isExit: true)).Closed[0];

			JsonElement root = Parse(PayloadBuilder.Build(trade, Info()));

			Assert.Equal(JsonValueKind.Null, root.GetProperty("entry").GetProperty("order_name").ValueKind);
			Assert.Equal(JsonValueKind.Null, root.GetProperty("exit").GetProperty("order_name").ValueKind);
			Assert.Equal(JsonValueKind.Null, root.GetProperty("legs")[0].GetProperty("order_name").ValueKind);
			Assert.Equal(JsonValueKind.Null, root.GetProperty("executions")[0].GetProperty("order_name").ValueKind);
			Assert.Equal("other", root.GetProperty("exit").GetProperty("reason").GetString());
		}

		[Fact]
		public void MissingConnectionFallsBackBecauseTheServerRequiresOne()
		{
			SubmissionInfo info = Info();
			info.Connection = null;

			JsonElement root = Parse(PayloadBuilder.Build(WorkedExample(), info));

			Assert.Equal(PayloadBuilder.UnknownConnection, root.GetProperty("connection").GetString());
		}

		[Fact]
		public void NoLiveTicks_SendsNullExcursionAndIncomplete()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("a", "o1", "Entry", 1, 100m, 0, 1m, 1, isEntry: true));
			CompletedTrade trade = tracker.Apply(Sell("b", "o2", "Close", 1, 101m, 5, 1m, 0, isExit: true)).Closed[0];

			JsonElement exc = Parse(PayloadBuilder.Build(trade, Info())).GetProperty("excursion");

			Assert.Equal(JsonValueKind.Null, exc.GetProperty("mae_points").ValueKind);
			Assert.Equal(JsonValueKind.Null, exc.GetProperty("max_favorable_price").ValueKind);
			Assert.False(exc.GetProperty("complete").GetBoolean());
		}

		[Fact]
		public void FeesAreSentWhenNt8ReportsThem()
		{
			TradeTracker tracker = new TradeTracker();
			Fill entry = Buy("a", "o1", "Entry", 1, 100m, 0, 1m, 1, isEntry: true);
			entry.Fee = 0.50m;
			tracker.Apply(entry);
			CompletedTrade trade = tracker.Apply(Sell("b", "o2", "Close", 1, 101m, 5, 1m, 0, isExit: true)).Closed[0];

			JsonElement root = Parse(PayloadBuilder.Build(trade, Info()));

			Assert.Equal("0.50", root.GetProperty("performance").GetProperty("fees").GetString());
			Assert.Equal("0.50", root.GetProperty("executions")[0].GetProperty("fee").GetString());
			Assert.Equal("47.50", root.GetProperty("performance").GetProperty("net_pnl").GetString()); // 50 - 2.00 - 0.50
		}

		[Fact]
		public void TimestampsKeepMillisecondsWhenThereAreSome()
		{
			DateTimeOffset t = new DateTimeOffset(2026, 9, 24, 9, 30, 0, 250, TimeSpan.FromHours(-4));

			Assert.Equal("2026-09-24T09:30:00.250-04:00", PayloadBuilder.Timestamp(t));
			Assert.Equal("2026-09-24T09:30:00-04:00", PayloadBuilder.Timestamp(t.AddMilliseconds(-250)));
		}

		[Fact]
		public void ExitBeforeEntry_IsRejectedBeforeSending()
		{
			CompletedTrade trade = WorkedExample();
			trade.ExitAt = trade.EntryAt.AddSeconds(-1);

			Assert.Throws<ArgumentException>(() => PayloadBuilder.Build(trade, Info()));
		}

		[Fact]
		public void EntryAndExitInTheSameSecond_IsValid()
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("a", "o1", "Entry", 1, 100m, 0, 1m, 1, isEntry: true));
			CompletedTrade trade = tracker.Apply(Sell("b", "o2", "Stop1", 1, 99m, 0, 1m, 0, isExit: true)).Closed[0];

			JsonElement root = Parse(PayloadBuilder.Build(trade, Info()));

			Assert.Equal(root.GetProperty("entry").GetProperty("occurred_at").GetString(),
				root.GetProperty("exit").GetProperty("occurred_at").GetString());
		}

		[Fact]
		public void JsonWriter_EscapesStrings()
		{
			JsonWriter w = new JsonWriter();
			w.BeginObject().Property("body", "line1\nquote \" back\\slash \t tab \u0001").EndObject();

			Assert.Equal("line1\nquote \" back\\slash \t tab \u0001", Parse(w.ToString()).GetProperty("body").GetString());
		}
	}
}
