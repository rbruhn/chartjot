using System.IO;
using ChartJot.Core;
using static ChartJot.Core.Tests.TestData;

namespace ChartJot.Core.Tests
{
	public class AddonStateFileTests
	{
		private const string Account = "APEX-24570-135";
		private static readonly DateTimeOffset Now = new DateTimeOffset(2026, 9, 29, 12, 0, 0, TimeSpan.Zero);

		private static readonly Fill Entry = Buy("e1", "o1", "Entry", 1, 7700m, 0, 1.29m, 1, isEntry: true);
		private static readonly Fill Exit = Sell("e2", "o2", "Target1", 1, 7702m, 60, 1.29m, 0, isExit: true);

		private static CompletedTrade ClosedTrade(string account = Account)
		{
			TradeTracker tracker = new TradeTracker();
			tracker.Apply(Buy("c1", "oc1", "Entry", 1, 7700m, 0, 1.29m, 1, account, isEntry: true));
			return tracker.Apply(Sell("c2", "oc2", "Close", 1, 7701m, 30, 1.29m, 0, account, isExit: true)).Closed[0];
		}

		private static string TempPath()
		{
			return Path.Combine(Path.GetTempPath(), "chartjot-tests-" + Guid.NewGuid().ToString("N"), "state.json");
		}

		private static void DeleteDirectory(string path)
		{
			string directory = Path.GetDirectoryName(path);
			if (Directory.Exists(directory))
				Directory.Delete(directory, recursive: true);
		}

		[Fact]
		public void SerializeDeserialize_RoundTripsEveryPiece()
		{
			AddonState state = new AddonState();
			state.PendingNotes.Add(Account, "NQ 12-26", new NoteRecord { Body = "waiting", Phase = NotePhases.PreTrade, OccurredAt = At(-5) });
			OpenTradeInfo opened = state.Tracker.Apply(Entry).Opened;
			state.AddTradeNote(opened.TradeId, new NoteRecord { Body = "in", Phase = NotePhases.InTrade, OccurredAt = At(10) });
			CompletedTrade awaiting = ClosedTrade("PA-02");
			state.RecordClosed(awaiting);
			state.Staged.Add(new StagedTrade { Trade = ClosedTrade(), Notes = new List<NoteRecord>(), TradeType = "2EL" });
			state.Deliveries.Enqueue("t-queued", "{\"trade_id\":\"t-queued\"}");
			state.Deliveries.MarkSending("t-queued");
			state.Deliveries.RecordResult("t-queued", DeliveryOutcome.Retryable, Now, statusCode: 503);

			AddonState reloaded = AddonState.Deserialize(state.Serialize());

			Assert.Equal("waiting", Assert.Single(reloaded.PendingNotes.For(Account, "NQ 12-26")).Body);
			Assert.Equal("in", Assert.Single(reloaded.NotesFor(opened.TradeId)).Body);
			Assert.Equal(awaiting.TradeId, Assert.Single(reloaded.AwaitingStage).TradeId);
			Assert.Equal("2EL", Assert.Single(reloaded.Staged.All).TradeType);
			Assert.Equal(DeliveryState.QueuedForRetry, reloaded.Deliveries.Find("t-queued").State);
			Assert.Equal(503, reloaded.Deliveries.Find("t-queued").LastStatusCode);
			Assert.Empty(reloaded.Tracker.OpenTrades); // open trades wait for Reconcile
		}

		[Fact]
		public void SerializeDeserialize_ASavedOpenTradeSurvivesUntilReconciled()
		{
			AddonState state = new AddonState();
			state.Tracker.Apply(Entry);
			state.Tracker.OnPrice(Es.FullName, 7704m);

			AddonState once = AddonState.Deserialize(state.Serialize());
			AddonState twice = AddonState.Deserialize(once.Serialize());
			ReconcileResult result = twice.Reconcile(Account, Es.FullName, new[] { Entry }, 1);

			Assert.True(result.Resumed);
			Assert.Equal(7704m, result.Open.Excursion.High);
		}

		[Fact]
		public void SerializeDeserialize_EmptyState_RoundTrips()
		{
			AddonState reloaded = AddonState.Deserialize(new AddonState().Serialize());

			Assert.Empty(reloaded.AwaitingStage);
			Assert.Empty(reloaded.Staged.All);
			Assert.Empty(reloaded.Deliveries.All);
			Assert.True(reloaded.PendingNotes.IsEmpty);
		}

		[Fact]
		public void Deserialize_KeepsTheQueueHaltedAfterAConfigurationError()
		{
			AddonState state = new AddonState();
			state.Deliveries.Enqueue("t1", "{}");
			state.Deliveries.MarkSending("t1");
			state.Deliveries.RecordResult("t1", DeliveryOutcome.ConfigurationError, Now, statusCode: 401);

			AddonState reloaded = AddonState.Deserialize(state.Serialize());

			Assert.True(reloaded.Deliveries.ConfigurationErrorHalted);
			Assert.Empty(reloaded.Deliveries.Due(Now));
		}

		[Fact]
		public void Deserialize_UsesTheGivenBackoff()
		{
			AddonState state = new AddonState();
			state.Deliveries.Enqueue("t1", "{}");

			AddonState reloaded = AddonState.Deserialize(state.Serialize(), initialBackoff: TimeSpan.FromSeconds(7));
			reloaded.Deliveries.MarkSending("t1");
			reloaded.Deliveries.RecordResult("t1", DeliveryOutcome.Retryable, Now);

			Assert.Equal(Now + TimeSpan.FromSeconds(7), reloaded.Deliveries.Find("t1").NextAttemptAt);
		}

		[Fact]
		public void Deserialize_UnsupportedVersion_ThrowsFormatException()
		{
			string json = new AddonState().Serialize().Replace("\"version\":1", "\"version\":99");

			Assert.Throws<FormatException>(() => AddonState.Deserialize(json));
		}

		[Fact]
		public void SaveLoad_RoundTripsThroughRealDisk()
		{
			string path = TempPath();
			try
			{
				AddonState state = new AddonState();
				string tradeId = state.Tracker.Apply(Entry).Opened.TradeId;
				state.Tracker.OnPrice(Es.FullName, 7698m);
				state.AddTradeNote(tradeId, new NoteRecord { Body = "in", Phase = NotePhases.InTrade, OccurredAt = At(5) });
				state.Save(path);

				AddonState loaded = AddonState.Load(path);
				ReconcileResult result = loaded.Reconcile(Account, Es.FullName, new[] { Entry, Exit }, 0);

				CompletedTrade closed = Assert.Single(result.ClosedWhileAway);
				Assert.Equal(tradeId, closed.TradeId);
				Assert.Equal(2m, closed.Excursion.MaePoints);
				Assert.Single(loaded.Stage(tradeId).Notes);
			}
			finally
			{
				DeleteDirectory(path);
			}
		}

		[Fact]
		public void Load_WhenFileMissing_ReturnsAnEmptyState()
		{
			AddonState state = AddonState.Load(TempPath());

			Assert.Empty(state.Tracker.OpenTrades);
			Assert.Empty(state.Deliveries.All);
		}

		[Fact]
		public void Save_OverwritesThePreviousFile()
		{
			string path = TempPath();
			try
			{
				AddonState state = new AddonState();
				state.Save(path);
				state.Deliveries.Enqueue("t1", "{}");
				state.Save(path);

				Assert.Equal("t1", Assert.Single(AddonState.Load(path).Deliveries.All).TradeId);
			}
			finally
			{
				DeleteDirectory(path);
			}
		}
	}
}
