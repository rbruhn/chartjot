using ChartJot.Core;
using static ChartJot.Core.Tests.TestData;

namespace ChartJot.Core.Tests
{
	/// <summary>
	/// The one form per chart (Chart Trader account + instrument): one note and a trade type, written before,
	/// during or after the trade. Submit sends the master's closed trade(s) and the matched followers' trades,
	/// each with its own data and the shared note and type. Reset clears it for the next idea.
	/// </summary>
	public class TradeFormTests
	{
		private const string Master = "TEST-ACCT-001";
		private const string Follower = "TEST-ACCT-002";
		private const string EsName = "ES 12-26";
		private const string Version = "0.3.0";

		private static readonly Func<string, string> Connection = account => "Sim";

		private static readonly Fill Entry = Buy("f1", "o1", "Entry", 1, 7700m, 0, 1.29m, 1, Master, isEntry: true);
		private static readonly Fill Exit = Sell("f2", "o2", "Target1", 1, 7703m, 60, 1.29m, 0, Master, isExit: true);
		private static readonly Fill FollowerEntry = Buy("g1", "p1", "AI", 2, 7700.25m, 1, 0.5m, 2, Follower, Mes, isEntry: true);
		private static readonly Fill FollowerExit = Sell("g2", "p2", "AI", 2, 7703m, 61, 0.5m, 0, Follower, Mes, isExit: true);

		// Master closed and staged (and, when withFollower, the follower's copy too).
		private static AddonState Closed(out string masterId, out string followerId, bool withFollower = false)
		{
			AddonState state = new AddonState();
			state.Tracker.Apply(Entry);
			CompletedTrade master = Assert.Single(state.Tracker.Apply(Exit).Closed);
			state.RecordClosed(master);
			state.Stage(master.TradeId, At(62));
			masterId = master.TradeId;
			followerId = null;

			if (withFollower)
			{
				state.Tracker.Apply(FollowerEntry);
				CompletedTrade copy = Assert.Single(state.Tracker.Apply(FollowerExit).Closed);
				state.RecordClosed(copy);
				state.Stage(copy.TradeId, At(63));
				followerId = copy.TradeId;
			}
			return state;
		}

		// ---- editing the form

		[Fact]
		public void UpdateForm_KeepsTheTextAndTypeAndWhenTheNoteWasStarted()
		{
			AddonState state = new AddonState();

			state.UpdateForm(Master, EsName, "watching for H2", null, null, At(-30));
			state.UpdateForm(Master, EsName, "watching for H2, took it", "2EL", null, At(90));

			TradeForm form = state.FormFor(Master, EsName);
			Assert.Equal("watching for H2, took it", form.Body);
			Assert.Equal("2EL", form.TradeType);
			Assert.Equal(At(-30), form.StartedAt);
		}

		[Fact]
		public void UpdateForm_KeepsTheOtherDescriptionOnlyForOther()
		{
			AddonState state = new AddonState();

			state.UpdateForm(Master, EsName, "", "Other", "  breakout retest  ", At(0));
			Assert.Equal("breakout retest", state.FormFor(Master, EsName).TradeTypeOther);

			state.UpdateForm(Master, EsName, "", "RL", "ignored", At(1));
			Assert.Null(state.FormFor(Master, EsName).TradeTypeOther);
		}

		[Fact]
		public void UpdateForm_WithAnUnknownTradeType_IsRefused()
		{
			AddonState state = new AddonState();

			Assert.Throws<ArgumentException>(() => state.UpdateForm(Master, EsName, "x", "XYZ", null, At(0)));
		}

		[Fact]
		public void Forms_AreSeparatePerAccountAndInstrument()
		{
			AddonState state = new AddonState();

			state.UpdateForm(Master, EsName, "es idea", null, null, At(0));
			state.UpdateForm(Master, "NQ 12-26", "nq idea", null, null, At(0));

			Assert.Equal("es idea", state.FormFor(Master, EsName).Body);
			Assert.Equal("nq idea", state.FormFor(Master, "NQ 12-26").Body);
			Assert.Null(state.FormFor(Follower, EsName).Body);
		}

		// ---- what Submit would send

		[Fact]
		public void PendingForForm_IsTheScopesClosedUnsubmittedTrades()
		{
			string masterId, followerId;
			AddonState state = Closed(out masterId, out followerId, withFollower: true);

			Assert.Equal(new[] { masterId }, state.PendingForForm(Master, EsName).Select(t => t.TradeId));
			Assert.Empty(state.PendingForForm(Master, "NQ 12-26"));
		}

		[Fact]
		public void CanSubmitForm_NeedsAClosedTradeAndAValidType()
		{
			AddonState state = new AddonState();
			state.UpdateForm(Master, EsName, "idea", "2EL", null, At(-10));
			Assert.False(state.CanSubmitForm(Master, EsName));

			state.Tracker.Apply(Entry);
			Assert.False(state.CanSubmitForm(Master, EsName));

			state.RecordClosed(Assert.Single(state.Tracker.Apply(Exit).Closed));
			Assert.True(state.CanSubmitForm(Master, EsName));

			state.UpdateForm(Master, EsName, "idea", null, null, At(70));
			Assert.False(state.CanSubmitForm(Master, EsName));
		}

		// ---- Submit

		[Fact]
		public void SubmitForm_SendsTheMasterTradeWithTheNoteAndType_ThenClearsTheForm()
		{
			string masterId, followerId;
			AddonState state = Closed(out masterId, out followerId);
			state.UpdateForm(Master, EsName, "H2 at the EMA, held for 2R", "2EL", null, At(-20));

			IList<QueuedDelivery> sent = state.SubmitForm(Master, EsName, Version, Connection, new string[0], At(120));

			JsonValue payload = JsonValue.Parse(Assert.Single(sent).PayloadJson);
			Assert.Equal(masterId, payload["trade_id"].AsString());
			Assert.Equal("2EL", payload["trade_type"].AsString());
			JsonValue note = Assert.Single(payload["notes"].Items);
			Assert.Equal("H2 at the EMA, held for 2R", note["body"].AsString());
			Assert.Equal(NotePhases.General, note["phase"].AsString());

			TradeForm form = state.FormFor(Master, EsName);
			Assert.Null(form.Body);
			Assert.Null(form.TradeType);
			Assert.Null(form.StartedAt);
			Assert.Equal(new[] { masterId }, form.LastSubmitted);
			Assert.Empty(state.PendingForForm(Master, EsName));
		}

		[Fact]
		public void SubmitForm_WithNoText_SendsNoNotes()
		{
			string masterId, followerId;
			AddonState state = Closed(out masterId, out followerId);
			state.UpdateForm(Master, EsName, "   ", "RS", null, At(0));

			JsonValue payload = JsonValue.Parse(Assert.Single(state.SubmitForm(Master, EsName, Version, Connection, new string[0], At(120))).PayloadJson);

			Assert.Empty(payload["notes"].Items);
		}

		[Fact]
		public void SubmitForm_SendsEachFollowersOwnTradeWithTheMastersNoteAndType()
		{
			string masterId, followerId;
			AddonState state = Closed(out masterId, out followerId, withFollower: true);
			state.UpdateForm(Master, EsName, "copied idea", "F2ES", null, At(-5));

			IList<QueuedDelivery> sent = state.SubmitForm(Master, EsName, Version, Connection, new[] { followerId }, At(120));

			Assert.Equal(new[] { masterId, followerId }, sent.Select(d => d.TradeId));
			JsonValue copy = JsonValue.Parse(sent[1].PayloadJson);
			Assert.Equal(Follower, copy["account_name"].AsString());
			Assert.Equal("MES 12-26", copy["instrument"]["contract"].AsString());
			Assert.Equal(2, copy["quantity"].AsInt32());
			Assert.Equal("F2ES", copy["trade_type"].AsString());
			Assert.Equal("copied idea", Assert.Single(copy["notes"].Items)["body"].AsString());
			Assert.Equal(new[] { masterId, followerId }, state.FormFor(Master, EsName).LastSubmitted);
		}

		[Fact]
		public void SubmitForm_StagesATradeStillInItsSettlePeriod()
		{
			AddonState state = new AddonState();
			state.Tracker.Apply(Entry);
			CompletedTrade master = Assert.Single(state.Tracker.Apply(Exit).Closed);
			state.RecordClosed(master);
			state.UpdateForm(Master, EsName, "quick", "RL", null, At(0));

			Assert.Equal(master.TradeId, Assert.Single(state.SubmitForm(Master, EsName, Version, Connection, new string[0], At(61))).TradeId);
			Assert.Empty(state.AwaitingStage);
		}

		[Fact]
		public void SubmitForm_WithNothingClosed_IsRefusedAndKeepsTheForm()
		{
			AddonState state = new AddonState();
			state.UpdateForm(Master, EsName, "idea", "2EL", null, At(0));

			Assert.Throws<InvalidOperationException>(() => state.SubmitForm(Master, EsName, Version, Connection, new string[0], At(10)));
			Assert.Equal("idea", state.FormFor(Master, EsName).Body);
		}

		[Fact]
		public void SubmitForm_WithoutATradeType_IsRefusedAndQueuesNothing()
		{
			string masterId, followerId;
			AddonState state = Closed(out masterId, out followerId);
			state.UpdateForm(Master, EsName, "idea", null, null, At(0));

			Assert.Throws<InvalidOperationException>(() => state.SubmitForm(Master, EsName, Version, Connection, new string[0], At(120)));
			Assert.Empty(state.Deliveries.All);
		}

		[Fact]
		public void SubmitForm_WithAFollowerTradeThatIsNotPending_IsRefusedAndQueuesNothing()
		{
			string masterId, followerId;
			AddonState state = Closed(out masterId, out followerId);
			state.UpdateForm(Master, EsName, "idea", "2EL", null, At(0));

			Assert.Throws<InvalidOperationException>(() => state.SubmitForm(Master, EsName, Version, Connection, new[] { "no-such-trade" }, At(120)));
			Assert.Empty(state.Deliveries.All);
		}

		[Fact]
		public void FollowerCandidates_AreOtherAccountsUnsubmittedClosedTrades()
		{
			string masterId, followerId;
			AddonState state = Closed(out masterId, out followerId, withFollower: true);

			Assert.Equal(new[] { followerId }, state.FollowerCandidates(Master).Select(t => t.TradeId));
		}

		// ---- Reset

		[Fact]
		public void ResetForm_ClearsTheFormAndDropsTheScopesUnsubmittedTrades()
		{
			string masterId, followerId;
			AddonState state = Closed(out masterId, out followerId, withFollower: true);
			state.UpdateForm(Master, EsName, "never mind", "2ES", null, At(0));

			IList<string> dropped = state.ResetForm(Master, EsName);

			Assert.Equal(new[] { masterId }, dropped);
			Assert.Null(state.FormFor(Master, EsName).Body);
			Assert.Null(state.FormFor(Master, EsName).TradeType);
			Assert.Null(state.Staged.Find(masterId));
			Assert.NotNull(state.Staged.Find(followerId));
		}

		[Fact]
		public void ResetForm_LeavesSubmittedTradesAlone()
		{
			string masterId, followerId;
			AddonState state = Closed(out masterId, out followerId);
			state.UpdateForm(Master, EsName, "idea", "2EL", null, At(0));
			state.SubmitForm(Master, EsName, Version, Connection, new string[0], At(120));

			Assert.Empty(state.ResetForm(Master, EsName));
			Assert.NotNull(state.Staged.Find(masterId));
			Assert.Single(state.Deliveries.All);
		}

		// ---- persistence and pruning

		[Fact]
		public void Form_SurvivesARestart()
		{
			AddonState state = new AddonState();
			state.UpdateForm(Master, EsName, "half-written idea", "Other", "news fade", At(-15));
			state.FormFor(Master, EsName).LastSubmitted = new List<string> { "earlier-id" };

			TradeForm form = AddonState.Deserialize(state.Serialize()).FormFor(Master, EsName);

			Assert.Equal("half-written idea", form.Body);
			Assert.Equal("Other", form.TradeType);
			Assert.Equal("news fade", form.TradeTypeOther);
			Assert.Equal(At(-15), form.StartedAt);
			Assert.Equal(new[] { "earlier-id" }, form.LastSubmitted);
		}

		[Fact]
		public void StateFile_WithoutFormsOrStagedTimes_StillLoads()
		{
			string masterId, followerId;
			AddonState state = Closed(out masterId, out followerId);
			string json = state.Serialize().Replace(",\"forms\":[]", "").Replace("\"staged_at\":", "\"legacy_staged_at\":");

			AddonState reloaded = AddonState.Deserialize(json);

			Assert.NotNull(reloaded.Staged.Find(masterId));
			Assert.Null(reloaded.FormFor(Master, EsName).Body);
		}

		[Fact]
		public void PruneUnsubmitted_DropsStagedTradesNobodySubmittedWithinMaxAge()
		{
			string masterId, followerId;
			AddonState state = Closed(out masterId, out followerId, withFollower: true);
			state.UpdateForm(Master, EsName, "idea", "2EL", null, At(0));
			state.SubmitForm(Master, EsName, Version, Connection, new string[0], At(120));

			IList<string> dropped = state.PruneUnsubmitted(At(63).AddHours(24).AddSeconds(1), TimeSpan.FromHours(24));

			Assert.Equal(new[] { followerId }, dropped);
			Assert.NotNull(state.Staged.Find(masterId));
		}

		[Fact]
		public void PruneUnsubmitted_KeepsRecentTrades()
		{
			string masterId, followerId;
			AddonState state = Closed(out masterId, out followerId, withFollower: true);

			Assert.Empty(state.PruneUnsubmitted(At(63).AddHours(23), TimeSpan.FromHours(24)));
		}

		[Fact]
		public void PruneUnsubmitted_TreatsAStagedTradeWithNoStagedTimeAsOld()
		{
			string masterId, followerId;
			AddonState state = Closed(out masterId, out followerId);
			AddonState legacy = AddonState.Deserialize(state.Serialize().Replace("\"staged_at\":", "\"legacy_staged_at\":"));

			Assert.Equal(new[] { masterId }, legacy.PruneUnsubmitted(At(70), TimeSpan.FromHours(24)));
		}
	}
}
