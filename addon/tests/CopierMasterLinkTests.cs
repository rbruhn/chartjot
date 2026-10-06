using ChartJot.Core;
using static ChartJot.Core.Tests.TestData;

namespace ChartJot.Core.Tests
{
	/// <summary>
	/// #79: a copier follower's payload names the master trade it copied (copier_master_trade_id), so the journal can
	/// link the two. The master's own payload, like any normal trade's, sends null.
	/// </summary>
	public class CopierMasterLinkTests
	{
		private const string Account = "TEST-ACCT-001";
		private const string Other = "TEST-ACCT-002";
		private const string EsName = "ES 12-26";

		/// <summary>A closed long master trade on Account and a closed MES follower on Other, ready to submit.</summary>
		private static AddonState MasterAndFollower(out string masterId, out string followerId)
		{
			AddonState state = new AddonState();
			masterId = state.Tracker.Apply(Buy("p1", "o1", "Entry", 2, 7700m, 0, 0m, 2, Account, isEntry: true)).Opened.TradeId;
			state.Tracker.Apply(Buy("f1", "g1", "AI", 2, 7700.25m, 1, 0m, 2, Other, Mes, isEntry: true));
			state.Tracker.Apply(Sell("p2", "o2", "Target1", 1, 7704m, 60, 0m, 1, Account, isExit: true));
			state.RecordClosed(Assert.Single(state.Tracker.Apply(Sell("p3", "o3", "Stop1", 1, 7700m, 300, 0m, 0, Account, isExit: true)).Closed));
			state.RecordClosed(Assert.Single(state.Tracker.Apply(Sell("f2", "g2", "AI", 2, 7703m, 61, 0m, 0, Other, Mes, isExit: true)).Closed));
			followerId = state.FollowerCandidates(Account).Single().TradeId;
			state.UpdateForm(Account, EsName, "idea", "2EL", null, At(0));
			return state;
		}

		[Fact]
		public void AFollowersPayload_NamesItsMaster_AndTheMastersNamesNone()
		{
			string masterId, followerId;
			AddonState state = MasterAndFollower(out masterId, out followerId);

			IList<QueuedDelivery> sent = state.SubmitForm(Account, EsName, "0.5.0", a => "Sim", new[] { followerId }, At(400), id => masterId);

			Assert.True(JsonValue.Parse(sent[0].PayloadJson)["copier_master_trade_id"].IsNull);
			Assert.Equal(masterId, JsonValue.Parse(sent[1].PayloadJson)["copier_master_trade_id"].AsString());
		}

		[Fact]
		public void AFollowerWhoseMasterIsUnknown_NamesTheFirstMaster_LikeItsImages()
		{
			string masterId, followerId;
			AddonState state = MasterAndFollower(out masterId, out followerId);

			IList<QueuedDelivery> sent = state.SubmitForm(Account, EsName, "0.5.0", a => "Sim", new[] { followerId }, At(400), null);

			Assert.Equal(masterId, JsonValue.Parse(sent[1].PayloadJson)["copier_master_trade_id"].AsString());
		}

		[Fact]
		public void AFollowersMaster_SurvivesARestart()
		{
			string masterId, followerId;
			AddonState state = MasterAndFollower(out masterId, out followerId);
			state.SubmitForm(Account, EsName, "0.5.0", a => "Sim", new[] { followerId }, At(400), id => masterId);

			AddonState restarted = AddonState.Deserialize(state.Serialize());

			Assert.Equal(masterId, restarted.Staged.Find(followerId).CopierMasterTradeId);
			Assert.Null(restarted.Staged.Find(masterId).CopierMasterTradeId);
		}
	}
}
