using ChartJot.Core;
using static ChartJot.Core.Tests.TestData;

namespace ChartJot.Core.Tests
{
	public class SubmitTests
	{
		private const string Account = "SIM-TEST-001";
		private const string AddonVersion = "0.2.0";
		private const string Connection = "Sim";

		// Long 2 at 7700, out at 7703 and 7701. The exit commissions are missing at close, as with a live broker
		// that reports them late.
		private static readonly Fill Entry = Buy("t1", "o1", "Entry", 2, 7700m, 0, 2.58m, 2, Account, isEntry: true);
		private static readonly Fill Target = Sell("t2", "o2", "Target1", 1, 7703m, 60, 0m, 1, Account, isExit: true);
		private static readonly Fill Stop = Sell("t3", "o3", "Stop1", 1, 7701m, 300, 0m, 0, Account, isExit: true);

		private static AddonState Closed(out string tradeId)
		{
			AddonState state = new AddonState();
			OpenTradeInfo opened = state.Tracker.Apply(Entry).Opened;
			state.AddTradeNote(opened.TradeId, new NoteRecord { Body = "H2 at the EMA", Phase = NotePhases.InTrade, OccurredAt = At(10) });
			state.Tracker.Apply(Target);
			CompletedTrade closed = Assert.Single(state.Tracker.Apply(Stop).Closed);
			state.RecordClosed(closed);
			tradeId = closed.TradeId;
			return state;
		}

		private static AddonState Staged(out string tradeId, string tradeType = "2EL")
		{
			AddonState state = Closed(out tradeId);
			state.Stage(tradeId).TradeType = tradeType;
			return state;
		}

		[Fact]
		public void Submit_FreezesTheStagedTradeIntoTheDeliveryQueue()
		{
			string tradeId;
			AddonState state = Staged(out tradeId);
			state.Staged.Find(tradeId).StopPrice = 7697.25m;

			QueuedDelivery queued = state.Submit(tradeId, AddonVersion, Connection);

			Assert.Equal(DeliveryState.Pending, queued.State);
			Assert.Same(queued, state.Deliveries.Find(tradeId));
			JsonValue payload = JsonValue.Parse(queued.PayloadJson);
			Assert.Equal(tradeId, payload["trade_id"].AsString());
			Assert.Equal("2EL", payload["trade_type"].AsString());
			Assert.Equal(AddonVersion, payload["addon_version"].AsString());
			Assert.Equal(Connection, payload["connection"].AsString());
			Assert.Equal("H2 at the EMA", Assert.Single(payload["notes"].Items)["body"].AsString());
			Assert.Equal("7697.25", payload["stop_price"].AsString());
		}

		[Fact]
		public void Submit_KeepsTheTradeInTheStagedListUntilItIsSent()
		{
			string tradeId;
			AddonState state = Staged(out tradeId);

			state.Submit(tradeId, AddonVersion, Connection);

			Assert.NotNull(state.Staged.Find(tradeId));
		}

		[Fact]
		public void Submit_WithoutATradeType_ThrowsAndQueuesNothing()
		{
			string tradeId;
			AddonState state = Staged(out tradeId, tradeType: null);

			Assert.Throws<ArgumentException>(() => state.Submit(tradeId, AddonVersion, Connection));
			Assert.Null(state.Deliveries.Find(tradeId));
		}

		[Fact]
		public void Submit_ATradeThatIsNotStaged_Throws()
		{
			string tradeId;
			AddonState state = Closed(out tradeId);

			Assert.Throws<InvalidOperationException>(() => state.Submit(tradeId, AddonVersion, Connection));
			Assert.Null(state.Deliveries.Find(tradeId));
		}

		[Fact]
		public void Submit_Twice_WhileTheFirstIsStillInFlight_Throws()
		{
			string tradeId;
			AddonState state = Staged(out tradeId);
			string firstPayload = state.Submit(tradeId, AddonVersion, Connection).PayloadJson;
			state.Staged.Find(tradeId).TradeType = "RL";

			Assert.Throws<InvalidOperationException>(() => state.Submit(tradeId, AddonVersion, Connection));
			Assert.Equal(firstPayload, state.Deliveries.Find(tradeId).PayloadJson);
		}

		[Fact]
		public void Submit_AfterAValidationFailure_FreezesANewPayloadWithTheSameTradeId()
		{
			string tradeId;
			AddonState state = Staged(out tradeId);
			state.Submit(tradeId, AddonVersion, Connection);
			state.Deliveries.MarkSending(tradeId);
			state.Deliveries.RecordResult(tradeId, DeliveryOutcome.ValidationError, At(400), 422, "{\"message\":\"invalid\"}", null);
			state.Staged.Find(tradeId).TradeType = "RL";

			QueuedDelivery resubmitted = state.Submit(tradeId, AddonVersion, Connection);

			Assert.Equal(DeliveryState.Pending, resubmitted.State);
			Assert.Equal("RL", JsonValue.Parse(resubmitted.PayloadJson)["trade_type"].AsString());
			Assert.Single(state.Deliveries.All);
		}

		[Fact]
		public void CanEdit_IsTrueUntilSubmitted_FalseInFlight_AndTrueAgainAfterAFailure()
		{
			string tradeId;
			AddonState state = Staged(out tradeId);
			Assert.True(state.CanEdit(tradeId));

			state.Submit(tradeId, AddonVersion, Connection);
			Assert.False(state.CanEdit(tradeId));

			state.Deliveries.MarkSending(tradeId);
			Assert.False(state.CanEdit(tradeId));

			state.Deliveries.RecordResult(tradeId, DeliveryOutcome.ValidationError, At(400), 422, null, null);
			Assert.True(state.CanEdit(tradeId));
		}

		[Fact]
		public void CanEdit_IsFalseForATradeThatIsNotStaged()
		{
			string tradeId;
			AddonState state = Closed(out tradeId);

			Assert.False(state.CanEdit(tradeId));
		}

		[Fact]
		public void RemoveSent_DropsStagedTradesOnceTheServerAcceptedThem_AndLeavesTheRest()
		{
			string tradeId;
			AddonState state = Staged(out tradeId);
			state.Submit(tradeId, AddonVersion, Connection);
			state.Deliveries.MarkSending(tradeId);
			state.Deliveries.RecordResult(tradeId, DeliveryOutcome.Accepted, At(400), 201, "{}", null);

			IList<string> removed = state.RemoveSent();

			Assert.Equal(new[] { tradeId }, removed);
			Assert.Null(state.Staged.Find(tradeId));
			Assert.Equal(DeliveryState.Sent, state.Deliveries.Find(tradeId).State);
		}

		[Fact]
		public void RemoveSent_KeepsTradesThatAreStillPendingOrFailed()
		{
			string tradeId;
			AddonState state = Staged(out tradeId);
			state.Submit(tradeId, AddonVersion, Connection);

			Assert.Empty(state.RemoveSent());
			Assert.NotNull(state.Staged.Find(tradeId));
		}

		[Fact]
		public void SubmittedTrade_SurvivesARestartWithItsFrozenPayload()
		{
			string tradeId;
			AddonState state = Staged(out tradeId);
			string payload = state.Submit(tradeId, AddonVersion, Connection).PayloadJson;

			AddonState restarted = AddonState.Deserialize(state.Serialize());

			Assert.Equal(payload, restarted.Deliveries.Find(tradeId).PayloadJson);
			Assert.NotNull(restarted.Staged.Find(tradeId));
			Assert.False(restarted.CanEdit(tradeId));
		}

		// ---- commission refresh (NT8.md: re-read commissions after the settle period, refreshable until submit)

		[Fact]
		public void RefreshCharges_BeforeStaging_RecomputesCommissionAndNetWithTheSameTradeId()
		{
			string tradeId;
			AddonState state = Closed(out tradeId);
			decimal gross = Assert.Single(state.AwaitingStage).GrossPnl;

			bool changed = state.RefreshCharges(tradeId, ChargesFor(("t2", 1.29m, 0.10m), ("t3", 1.29m, 0.10m)));

			Assert.True(changed);
			CompletedTrade refreshed = state.Stage(tradeId).Trade;
			Assert.Equal(tradeId, refreshed.TradeId);
			Assert.Equal(2.58m + 1.29m + 1.29m, refreshed.Commission);
			Assert.Equal((decimal?)0.20m, refreshed.Fees);
			Assert.Equal(gross, refreshed.GrossPnl);
			Assert.Equal(gross - refreshed.Commission - 0.20m, refreshed.NetPnl);
		}

		[Fact]
		public void RefreshCharges_OnAStagedTrade_KeepsTheTradersChoicesAndNotes()
		{
			string tradeId;
			AddonState state = Staged(out tradeId, tradeType: "2ES");

			Assert.True(state.RefreshCharges(tradeId, ChargesFor(("t2", 1.29m, 0m), ("t3", 1.29m, 0m))));

			StagedTrade staged = state.Staged.Find(tradeId);
			Assert.Equal("2ES", staged.TradeType);
			Assert.Single(staged.Notes);
			Assert.Equal(5.16m, staged.Trade.Commission);
		}

		[Fact]
		public void RefreshCharges_ProRatesAReversalFillByTheQuantityAllocatedToThisTrade()
		{
			AddonState state = new AddonState();
			state.Tracker.Apply(Buy("r1", "o1", "Entry", 1, 7700m, 0, 0m, 1, Account, isEntry: true));
			// Sell 3 from long 1: closes 1, opens short 2.
			CompletedTrade closed = Assert.Single(state.Tracker.Apply(Sell("r2", "o2", "Reverse", 3, 7702m, 60, 0m, -2, Account, isExit: true)).Closed);
			state.RecordClosed(closed);

			state.RefreshCharges(closed.TradeId, ChargesFor(("r1", 1.29m, 0m), ("r2", 3.87m, 0m)));

			Assert.Equal(1.29m + 1.29m, Assert.Single(state.AwaitingStage).Commission);
		}

		[Fact]
		public void RefreshCharges_WithNothingNew_ReturnsFalseAndChangesNothing()
		{
			string tradeId;
			AddonState state = Closed(out tradeId);
			CompletedTrade before = Assert.Single(state.AwaitingStage);

			Assert.False(state.RefreshCharges(tradeId, id => null));
			Assert.Same(before, Assert.Single(state.AwaitingStage));
		}

		[Fact]
		public void RefreshCharges_AfterSubmission_IsRefused_SoTheFrozenPayloadAndTheStagedTradeAgree()
		{
			string tradeId;
			AddonState state = Staged(out tradeId);
			state.Submit(tradeId, AddonVersion, Connection);

			Assert.False(state.RefreshCharges(tradeId, ChargesFor(("t2", 1.29m, 0m))));
			Assert.Equal(2.58m, state.Staged.Find(tradeId).Trade.Commission);
		}

		private static Func<string, FillCharges> ChargesFor(params (string ExecutionId, decimal Commission, decimal Fee)[] charges)
		{
			Dictionary<string, FillCharges> byId = charges.ToDictionary(c => c.ExecutionId, c => new FillCharges { Commission = c.Commission, Fee = c.Fee });
			return id => byId.TryGetValue(id, out FillCharges found) ? found : null;
		}
	}
}
