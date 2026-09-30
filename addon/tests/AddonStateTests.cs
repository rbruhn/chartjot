using ChartJot.Core;
using static ChartJot.Core.Tests.TestData;

namespace ChartJot.Core.Tests
{
	public class AddonStateTests
	{
		private const string Account = "TEST-ACCT-001";
		private const string EsName = "ES 12-26";

		private static readonly Fill Entry = Buy("e1", "o1", "Entry", 2, 7700m, 0, 2.58m, 2, isEntry: true);
		private static readonly Fill Target = Sell("e2", "o2", "Target1", 1, 7703m, 60, 1.29m, 1, isExit: true);
		private static readonly Fill Stop = Sell("e3", "o3", "Stop1", 1, 7701m, 300, 1.29m, 0, isExit: true);

		private static NoteRecord Note(string body, string phase, int seconds)
		{
			return new NoteRecord { Body = body, Phase = phase, OccurredAt = At(seconds) };
		}

		// Long 2 at 7700 with ticks 7697..7705 observed, then a restart: only the state file survives.
		private static AddonState OpenThenRestart(out string tradeId)
		{
			AddonState state = new AddonState();
			state.PendingNotes.Add(Account, EsName, Note("H2 at the EMA", NotePhases.PreTrade, -20));
			OpenTradeInfo opened = state.Tracker.Apply(Entry).Opened;
			state.AttachPendingNotes(opened);
			state.Tracker.OnPrice(EsName, 7697m);
			state.Tracker.OnPrice(EsName, 7705m);
			state.AddTradeNote(opened.TradeId, Note("holding", NotePhases.InTrade, 30));
			tradeId = opened.TradeId;
			return AddonState.Deserialize(state.Serialize());
		}

		[Fact]
		public void Reconcile_AfterRestartMidTrade_ResumesTheSameTradeIdWithItsObservedRange()
		{
			string tradeId;
			AddonState state = OpenThenRestart(out tradeId);

			ReconcileResult result = state.Reconcile(Account, EsName, new[] { Entry }, 2);

			Assert.True(result.Consistent);
			Assert.True(result.Resumed);
			Assert.Equal(tradeId, result.Open.TradeId);
			Assert.True(result.Open.Excursion.HadTicks);
			Assert.Equal(7705m, result.Open.Excursion.High);
			Assert.Equal(7697m, result.Open.Excursion.Low);
			Assert.True(result.Open.FeedInterrupted);
			Assert.Empty(result.ClosedWhileAway);
			Assert.Empty(result.UnresolvedTradeIds);
		}

		[Fact]
		public void Reconcile_AfterRestartMidTrade_KeepsNotesAndTheirPhases()
		{
			string tradeId;
			AddonState state = OpenThenRestart(out tradeId);
			state.Reconcile(Account, EsName, new[] { Entry }, 2);

			state.Tracker.Apply(Target);
			CompletedTrade closed = state.Tracker.Apply(Stop).Closed[0];
			state.RecordClosed(closed);
			StagedTrade staged = state.Stage(closed.TradeId);

			Assert.Equal(tradeId, closed.TradeId);
			Assert.Equal(new[] { NotePhases.PreTrade, NotePhases.InTrade }, staged.Notes.Select(n => n.Phase));
			Assert.Equal(new[] { At(-20), At(30) }, staged.Notes.Select(n => n.OccurredAt));
			Assert.True(state.PendingNotes.IsEmpty);
		}

		[Fact]
		public void Reconcile_AfterRestartMidTrade_LegThatExitedBeforeTheRestartKeepsItsOwnRange()
		{
			AddonState state = new AddonState();
			string tradeId = state.Tracker.Apply(Entry).Opened.TradeId;
			state.Tracker.OnPrice(EsName, 7699m);
			state.Tracker.Apply(Target);
			state.Tracker.OnPrice(EsName, 7710m);
			AddonState restarted = AddonState.Deserialize(state.Serialize());

			restarted.Reconcile(Account, EsName, new[] { Entry, Target }, 1);
			CompletedTrade closed = restarted.Tracker.Apply(Stop).Closed[0];

			Assert.Equal(tradeId, closed.TradeId);
			Assert.Equal(3m, closed.Legs[0].MfePoints); // high was 7703 when Target1 filled, not the later 7710
			Assert.Equal(1m, closed.Legs[0].MaePoints);
			Assert.Equal(10m, closed.Legs[1].MfePoints);
			Assert.Equal(10m, closed.Excursion.MfePoints);
			Assert.False(closed.ExcursionComplete);
		}

		[Fact]
		public void Reconcile_WhenTheTradeClosedWhileAway_RecordsItWithTheRangeObservedBefore()
		{
			AddonState state = new AddonState();
			string tradeId = state.Tracker.Apply(Entry).Opened.TradeId;
			state.Tracker.OnPrice(EsName, 7699m);
			state.Tracker.Apply(Target);
			state.Tracker.OnPrice(EsName, 7710m);
			AddonState restarted = AddonState.Deserialize(state.Serialize());

			ReconcileResult result = restarted.Reconcile(Account, EsName, new[] { Entry, Target, Stop }, 0);

			Assert.True(result.Consistent);
			Assert.Null(result.Open);
			CompletedTrade closed = Assert.Single(result.ClosedWhileAway);
			Assert.Equal(tradeId, closed.TradeId);
			Assert.Equal(10m, closed.Excursion.MfePoints);
			Assert.Equal(1m, closed.Excursion.MaePoints);
			Assert.Equal(3m, closed.Legs[0].MfePoints);
			Assert.Equal(10m, closed.Legs[1].MfePoints);
			Assert.False(closed.ExcursionComplete);
			Assert.Equal(tradeId, Assert.Single(restarted.AwaitingStage).TradeId);
		}

		[Fact]
		public void Reconcile_WhenTheTradeClosedWhileAway_ItsNotesStayWithItUntilStaged()
		{
			string tradeId;
			AddonState state = OpenThenRestart(out tradeId);

			state.Reconcile(Account, EsName, new[] { Entry, Target, Stop }, 0);
			StagedTrade staged = state.Stage(tradeId);

			Assert.Equal(2, staged.Notes.Count);
			Assert.Empty(state.AwaitingStage);
			Assert.Empty(state.NotesFor(tradeId));
		}

		[Fact]
		public void Reconcile_RunTwice_LeavesTheSameState()
		{
			string tradeId;
			AddonState state = OpenThenRestart(out tradeId);

			ReconcileResult first = state.Reconcile(Account, EsName, new[] { Entry }, 2);
			string afterFirst = state.Serialize();
			ReconcileResult second = state.Reconcile(Account, EsName, new[] { Entry }, 2);

			Assert.Equal(afterFirst, state.Serialize());
			Assert.True(second.Resumed);
			Assert.Equal(first.Open.TradeId, second.Open.TradeId);
			Assert.Equal(first.Open.Excursion, second.Open.Excursion);
		}

		[Fact]
		public void Reconcile_RunTwiceAfterTheTradeClosedWhileAway_RecordsItOnce()
		{
			string tradeId;
			AddonState state = OpenThenRestart(out tradeId);
			Fill[] history = { Entry, Target, Stop };

			state.Reconcile(Account, EsName, history, 0);
			string afterFirst = state.Serialize();
			ReconcileResult second = state.Reconcile(Account, EsName, history, 0);

			Assert.Equal(afterFirst, state.Serialize());
			Assert.Empty(second.ClosedWhileAway);
			Assert.Single(state.AwaitingStage);
		}

		[Fact]
		public void Reconcile_AfterTheTradeWasStaged_DoesNotRecordItAgain()
		{
			string tradeId;
			AddonState state = OpenThenRestart(out tradeId);
			Fill[] history = { Entry, Target, Stop };
			state.Reconcile(Account, EsName, history, 0);
			state.Stage(tradeId);

			ReconcileResult again = state.Reconcile(Account, EsName, history, 0);

			Assert.Empty(again.ClosedWhileAway);
			Assert.Empty(state.AwaitingStage);
			Assert.Single(state.Staged.All);
		}

		[Fact]
		public void Reconcile_OnReconnectWithALiveTrade_KeepsTheLiveRangeAndFlagsTheGap()
		{
			AddonState state = new AddonState();
			string tradeId = state.Tracker.Apply(Entry).Opened.TradeId;
			state.Tracker.OnPrice(EsName, 7695m);

			ReconcileResult result = state.Reconcile(Account, EsName, new[] { Entry }, 2);

			Assert.True(result.Resumed);
			Assert.Equal(tradeId, result.Open.TradeId);
			Assert.Equal(7695m, result.Open.Excursion.Low);
			Assert.True(result.Open.FeedInterrupted);
		}

		[Fact]
		public void Reconcile_OnReconnect_ALiveTradeThatClosedDuringTheDisconnectKeepsItsRange()
		{
			AddonState state = new AddonState();
			string tradeId = state.Tracker.Apply(Entry).Opened.TradeId;
			state.Tracker.OnPrice(EsName, 7695m);

			ReconcileResult result = state.Reconcile(Account, EsName, new[] { Entry, Target, Stop }, 0);

			CompletedTrade closed = Assert.Single(result.ClosedWhileAway);
			Assert.Equal(tradeId, closed.TradeId);
			Assert.Equal(5m, closed.Excursion.MaePoints);
			Assert.Empty(state.Tracker.OpenTrades);
		}

		[Fact]
		public void Reconcile_WhenFillsDoNotMatchThePosition_ChangesNothingAndKeepsTheSavedTrade()
		{
			string tradeId;
			AddonState state = OpenThenRestart(out tradeId);

			ReconcileResult wrong = state.Reconcile(Account, EsName, new[] { Entry }, 5);
			ReconcileResult right = state.Reconcile(Account, EsName, new[] { Entry }, 2);

			Assert.False(wrong.Consistent);
			Assert.Equal(2, wrong.ComputedPosition);
			Assert.Equal(5, wrong.ReportedPosition);
			Assert.Null(wrong.Open);
			Assert.True(right.Resumed);
			Assert.Equal(7705m, right.Open.Excursion.High);
		}

		[Fact]
		public void Reconcile_WhenTheSavedTradeIsNotInTheFills_KeepsItAndItsNotes()
		{
			string tradeId;
			AddonState state = OpenThenRestart(out tradeId);

			ReconcileResult result = state.Reconcile(Account, EsName, new Fill[0], 0);
			AddonState reloaded = AddonState.Deserialize(state.Serialize());
			ReconcileResult later = reloaded.Reconcile(Account, EsName, new[] { Entry }, 2);

			Assert.Equal(tradeId, Assert.Single(result.UnresolvedTradeIds));
			Assert.Equal(2, reloaded.NotesFor(tradeId).Count);
			Assert.True(later.Resumed);
			Assert.Equal(7705m, later.Open.Excursion.High);
		}

		[Fact]
		public void Reconcile_AnOrphanExitNeverOpensATrade()
		{
			AddonState state = new AddonState();
			Fill orphan = Sell("x1", "o9", "Stop1", 1, 7701m, 10, isExit: true);

			ReconcileResult result = state.Reconcile(Account, EsName, new[] { orphan }, 0);

			Assert.True(result.Consistent);
			Assert.Null(result.Open);
			Assert.Empty(state.Tracker.OpenTrades);
			Assert.Empty(state.AwaitingStage);
		}

		[Fact]
		public void Reconcile_ATradeThatOpenedAndClosedWhileAway_IsNotJournaled()
		{
			AddonState state = new AddonState();

			ReconcileResult result = state.Reconcile(Account, EsName, new[] { Entry, Target, Stop }, 0);

			Assert.Empty(result.ClosedWhileAway);
			Assert.Empty(state.AwaitingStage);
		}

		[Fact]
		public void Reconcile_ATradeOpenedWhileAway_IsOpenButNotResumed()
		{
			AddonState state = new AddonState();

			ReconcileResult result = state.Reconcile(Account, EsName, new[] { Entry }, 2);

			Assert.NotNull(result.Open);
			Assert.False(result.Resumed);
			Assert.True(result.Open.FeedInterrupted);
		}

		[Fact]
		public void Reconcile_OnlyTouchesTheGivenAccountAndInstrument()
		{
			string tradeId;
			AddonState state = OpenThenRestart(out tradeId);
			Fill mes = Buy("m1", "om1", "Entry", 1, 7700m, 0, positionAfter: 1, instrument: Mes, isEntry: true);

			ReconcileResult other = state.Reconcile(Account, Mes.FullName, new[] { mes }, 1);
			ReconcileResult es = state.Reconcile(Account, EsName, new[] { Entry }, 2);

			Assert.False(other.Resumed);
			Assert.Empty(other.UnresolvedTradeIds);
			Assert.True(es.Resumed);
			Assert.Equal(tradeId, es.Open.TradeId);
		}

		[Fact]
		public void Reconcile_WhenAReversalClosedTheSavedTradeWhileAway_TheNewTradeIsOpenAndFlagged()
		{
			string tradeId;
			AddonState state = OpenThenRestart(out tradeId);
			Fill flip = Sell("e4", "o4", "Reverse", 3, 7702m, 120, positionAfter: -1, isEntry: true, isExit: true);

			ReconcileResult result = state.Reconcile(Account, EsName, new[] { Entry, flip }, -1);

			Assert.Equal(tradeId, Assert.Single(result.ClosedWhileAway).TradeId);
			Assert.False(result.ClosedWhileAway[0].OpenedByReversal);
			Assert.Equal(Direction.Short, result.Open.Direction);
			Assert.True(result.Open.OpenedByReversal);
			Assert.False(result.Resumed);
		}

		[Fact]
		public void Reconcile_NullFills_Throws()
		{
			Assert.Throws<ArgumentNullException>(() => new AddonState().Reconcile(Account, EsName, null, 0));
		}

		// ---- notes and staging

		[Fact]
		public void AttachPendingNotes_TakesOnlyThatAccountAndInstrument()
		{
			AddonState state = new AddonState();
			state.PendingNotes.Add(Account, EsName, Note("es", NotePhases.PreTrade, -10));
			state.PendingNotes.Add(Account, Mes.FullName, Note("mes", NotePhases.PreTrade, -10));
			OpenTradeInfo opened = state.Tracker.Apply(Entry).Opened;

			state.AttachPendingNotes(opened);

			Assert.Equal("es", Assert.Single(state.NotesFor(opened.TradeId)).Body);
			Assert.Single(state.PendingNotes.For(Account, Mes.FullName));
			Assert.Empty(state.PendingNotes.For(Account, EsName));
		}

		[Fact]
		public void NotesFor_ReturnsOldestFirst()
		{
			AddonState state = new AddonState();
			state.AddTradeNote("t1", Note("later", NotePhases.InTrade, 50));
			state.AddTradeNote("t1", Note("earlier", NotePhases.PreTrade, -5));

			Assert.Equal(new[] { "earlier", "later" }, state.NotesFor("t1").Select(n => n.Body));
		}

		[Fact]
		public void Stage_WhenTheTradeIsNotWaiting_Throws()
		{
			Assert.Throws<InvalidOperationException>(() => new AddonState().Stage("missing"));
		}

		[Fact]
		public void Stage_MovesTheTradeIntoTheReviewList()
		{
			AddonState state = new AddonState();
			state.Tracker.Apply(Entry);
			state.Tracker.Apply(Target);
			CompletedTrade closed = state.Tracker.Apply(Stop).Closed[0];
			state.RecordClosed(closed);

			StagedTrade staged = state.Stage(closed.TradeId);

			Assert.Same(closed, staged.Trade);
			Assert.Same(staged, state.Staged.Find(closed.TradeId));
			Assert.Empty(state.AwaitingStage);
		}

		[Fact]
		public void RecordClosed_IgnoresATradeAlreadyStagedOrQueued()
		{
			AddonState state = new AddonState();
			state.Tracker.Apply(Entry);
			state.Tracker.Apply(Target);
			CompletedTrade closed = state.Tracker.Apply(Stop).Closed[0];
			state.RecordClosed(closed);
			state.Stage(closed.TradeId);
			state.Deliveries.Enqueue(closed.TradeId, "{}");
			state.Staged.Remove(closed.TradeId);

			state.RecordClosed(closed);

			Assert.Empty(state.AwaitingStage);
		}

		[Fact]
		public void Discard_DropsTheTradeAndItsNotes()
		{
			AddonState state = new AddonState();
			state.Tracker.Apply(Entry);
			state.Tracker.Apply(Target);
			CompletedTrade closed = state.Tracker.Apply(Stop).Closed[0];
			state.RecordClosed(closed);
			state.AddTradeNote(closed.TradeId, Note("x", NotePhases.PostTrade, 400));

			state.Discard(closed.TradeId);

			Assert.Empty(state.AwaitingStage);
			Assert.Empty(state.NotesFor(closed.TradeId));
		}
	}
}
