using ChartJot.Core;
using static ChartJot.Core.Tests.TestData;

namespace ChartJot.Core.Tests
{
	/// <summary>
	/// #53: the stop the trader was risking. The single protective stop's price is recorded while it is still on
	/// the losing side of the entry (the ATM's stop, then any tightening or loosening); once it moves to breakeven or
	/// into profit (after Target 1, a trail, or by hand) it stops updating, so the trade keeps the last at-risk stop.
	/// </summary>
	public class StopPriceTests
	{
		private const string Account = "TEST-ACCT-001";
		private const string Other = "TEST-ACCT-002";
		private const string EsName = "ES 12-26";

		private static readonly Fill LongEntry = Buy("p1", "o1", "Entry", 2, 7700m, 0, 0m, 2, Account, isEntry: true);
		private static readonly Fill LongTarget = Sell("p2", "o2", "Target1", 1, 7704m, 60, 0m, 1, Account, isExit: true);
		private static readonly Fill LongStopOut = Sell("p3", "o3", "Stop1", 1, 7700m, 300, 0m, 0, Account, isExit: true);

		private static AddonState OpenLong(out string tradeId)
		{
			AddonState state = new AddonState();
			tradeId = state.Tracker.Apply(LongEntry).Opened.TradeId;
			return state;
		}

		private static StagedTrade CloseAndStage(AddonState state, string tradeId)
		{
			state.Tracker.Apply(LongTarget);
			state.RecordClosed(Assert.Single(state.Tracker.Apply(LongStopOut).Closed));
			return state.Stage(tradeId, At(301));
		}

		[Fact]
		public void TheAtmStop_IsRecordedAndStaged()
		{
			string tradeId;
			AddonState state = OpenLong(out tradeId);

			Assert.True(state.RecordStop(Account, EsName, 7696m));

			Assert.Equal(7696m, CloseAndStage(state, tradeId).StopPrice);
		}

		[Fact]
		public void LooseningOrTighteningWhileAtRisk_ReplacesIt()
		{
			string tradeId;
			AddonState state = OpenLong(out tradeId);
			state.RecordStop(Account, EsName, 7696m);

			Assert.True(state.RecordStop(Account, EsName, 7694m));	// more risk
			Assert.Equal(7694m, state.StopFor(tradeId));
			Assert.True(state.RecordStop(Account, EsName, 7698.75m));	// tighter, still below entry
			Assert.Equal(7698.75m, state.StopFor(tradeId));
		}

		[Fact]
		public void MovingToBreakevenOrIntoProfit_IsIgnored()
		{
			string tradeId;
			AddonState state = OpenLong(out tradeId);
			state.RecordStop(Account, EsName, 7694m);

			Assert.False(state.RecordStop(Account, EsName, 7700m));	// breakeven after Target 1
			Assert.False(state.RecordStop(Account, EsName, 7702m));	// trailing into profit

			Assert.Equal(7694m, CloseAndStage(state, tradeId).StopPrice);
		}

		[Fact]
		public void ForAShort_AtRiskMeansAboveTheEntry()
		{
			AddonState state = new AddonState();
			string tradeId = state.Tracker.Apply(Sell("s1", "q1", "Entry", 1, 7700m, 0, 0m, -1, Account, isEntry: true)).Opened.TradeId;

			Assert.True(state.RecordStop(Account, EsName, 7704m));
			Assert.False(state.RecordStop(Account, EsName, 7700m));
			Assert.False(state.RecordStop(Account, EsName, 7698m));

			Assert.Equal(7704m, state.StopFor(tradeId));
		}

		[Fact]
		public void AtRisk_IsMeasuredAgainstTheAverageEntryAfterAScaleIn()
		{
			AddonState state = new AddonState();
			string tradeId = state.Tracker.Apply(Buy("a1", "r1", "Entry", 1, 7700m, 0, 0m, 1, Account, isEntry: true)).Opened.TradeId;
			state.Tracker.Apply(Buy("a2", "r2", "Add", 1, 7704m, 30, 0m, 2, Account, isEntry: true));	// average 7702

			Assert.True(state.RecordStop(Account, EsName, 7701m));
			Assert.False(state.RecordStop(Account, EsName, 7702m));
			Assert.Equal(7701m, state.StopFor(tradeId));
		}

		[Fact]
		public void WithNoOpenTrade_NothingIsRecorded()
		{
			Assert.False(new AddonState().RecordStop(Account, EsName, 7696m));
		}

		[Fact]
		public void EachAccountRecordsItsOwnStop()
		{
			string tradeId;
			AddonState state = OpenLong(out tradeId);
			string otherId = state.Tracker.Apply(Buy("f1", "g1", "AI", 2, 7700.25m, 1, 0m, 2, Other, isEntry: true)).Opened.TradeId;

			state.RecordStop(Account, EsName, 7696m);
			state.RecordStop(Other, EsName, 7695m);

			Assert.Equal(7696m, state.StopFor(tradeId));
			Assert.Equal(7695m, state.StopFor(otherId));
		}

		[Fact]
		public void ATradeThatNeverHadAnAtRiskStop_StagesWithoutOne()
		{
			string tradeId;
			AddonState state = OpenLong(out tradeId);

			Assert.Null(CloseAndStage(state, tradeId).StopPrice);
		}

		[Fact]
		public void AnOpenTradesStop_SurvivesARestart()
		{
			string tradeId;
			AddonState state = OpenLong(out tradeId);
			state.RecordStop(Account, EsName, 7696m);

			AddonState restarted = AddonState.Deserialize(state.Serialize());
			restarted.Reconcile(Account, EsName, new[] { LongEntry }, 2);

			Assert.Equal(7696m, restarted.StopFor(tradeId));
		}

		[Fact]
		public void Submit_SendsTheRecordedStop()
		{
			string tradeId;
			AddonState state = OpenLong(out tradeId);
			state.RecordStop(Account, EsName, 7696.25m);
			CloseAndStage(state, tradeId);
			state.UpdateForm(Account, EsName, "idea", "2EL", null, At(0));

			JsonValue payload = JsonValue.Parse(Assert.Single(state.SubmitForm(Account, EsName, "0.5.0", a => "Sim", new string[0], At(400))).PayloadJson);

			Assert.Equal("7696.25", payload["stop_price"].AsString());
		}

		// ---- followers (copier Executions mode copies fills only, so a follower may have no stop order of its own)

		[Fact]
		public void AFollowerWithoutItsOwnStop_InheritsTheMastersStop()
		{
			string masterId, followerId;
			AddonState state = OpenLong(out masterId);
			state.RecordStop(Account, EsName, 7696m);
			state.Tracker.Apply(Buy("f1", "g1", "AI", 2, 7700.25m, 1, 0m, 2, Other, Mes, isEntry: true));
			state.Tracker.Apply(LongTarget);
			state.RecordClosed(Assert.Single(state.Tracker.Apply(LongStopOut).Closed));
			state.RecordClosed(Assert.Single(state.Tracker.Apply(Sell("f2", "g2", "AI", 2, 7703m, 61, 0m, 0, Other, Mes, isExit: true)).Closed));
			followerId = state.FollowerCandidates(Account).Single().TradeId;
			state.UpdateForm(Account, EsName, "idea", "2EL", null, At(0));

			IList<QueuedDelivery> sent = state.SubmitForm(Account, EsName, "0.5.0", a => "Sim", new[] { followerId }, At(400), id => masterId);

			Assert.Equal("7696.00", JsonValue.Parse(sent[1].PayloadJson)["stop_price"].AsString());
		}

		[Fact]
		public void AFollowerWithItsOwnStop_KeepsIt()
		{
			string masterId;
			AddonState state = OpenLong(out masterId);
			state.RecordStop(Account, EsName, 7696m);
			state.Tracker.Apply(Buy("f1", "g1", "AI", 2, 7700.25m, 1, 0m, 2, Other, Mes, isEntry: true));
			state.RecordStop(Other, "MES 12-26", 7695.50m);
			state.Tracker.Apply(LongTarget);
			state.RecordClosed(Assert.Single(state.Tracker.Apply(LongStopOut).Closed));
			state.RecordClosed(Assert.Single(state.Tracker.Apply(Sell("f2", "g2", "AI", 2, 7703m, 61, 0m, 0, Other, Mes, isExit: true)).Closed));
			string followerId = state.FollowerCandidates(Account).Single().TradeId;
			state.UpdateForm(Account, EsName, "idea", "2EL", null, At(0));

			IList<QueuedDelivery> sent = state.SubmitForm(Account, EsName, "0.5.0", a => "Sim", new[] { followerId }, At(400), id => masterId);

			Assert.Equal("7695.50", JsonValue.Parse(sent[1].PayloadJson)["stop_price"].AsString());
		}
	}
}
