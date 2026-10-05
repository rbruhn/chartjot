using ChartJot.Core;
using static ChartJot.Core.Tests.TestData;

namespace ChartJot.Core.Tests
{
	/// <summary>The note panel's rules (NT8.md, "Note targeting", "Note phases", acceptance checklist).</summary>
	public class NotePanelTests
	{
		private const string Account = "TEST-ACCT-001";
		private const string Other = "TEST-ACCT-002";
		private const string EsName = "ES 12-26";
		private const string AddonVersion = "0.3.0";

		private static readonly Fill Entry = Buy("n1", "o1", "Entry", 1, 7700m, 0, 1.29m, 1, Account, isEntry: true);
		private static readonly Fill Exit = Sell("n2", "o2", "Target1", 1, 7703m, 60, 1.29m, 0, Account, isExit: true);

		private static AddonState Opened(out string tradeId)
		{
			AddonState state = new AddonState();
			tradeId = state.Tracker.Apply(Entry).Opened.TradeId;
			return state;
		}

		private static AddonState StagedTrade(out string tradeId)
		{
			AddonState state = Opened(out tradeId);
			state.RecordClosed(Assert.Single(state.Tracker.Apply(Exit).Closed));
			state.Stage(tradeId);
			return state;
		}

		// ---- targeting

		[Fact]
		public void SaveNote_WhileAPositionIsOpen_IsAnInTradeNoteForThatTrade()
		{
			string tradeId;
			AddonState state = Opened(out tradeId);

			NoteRecord note = state.SaveNote(Account, EsName, "holding for the target", At(30), null);

			Assert.Equal(NotePhases.InTrade, note.Phase);
			Assert.Equal(At(30), note.OccurredAt);
			Assert.Same(note, Assert.Single(state.NotesFor(tradeId)));
			Assert.Empty(state.PendingNotes.For(Account, EsName));
		}

		[Fact]
		public void SaveNote_WhileAPositionIsOpen_IgnoresAStagedTarget()
		{
			string stagedId;
			AddonState state = StagedTrade(out stagedId);
			string openId = state.Tracker.Apply(Buy("n3", "o3", "Entry", 1, 7701m, 120, 0m, 1, Account, isEntry: true)).Opened.TradeId;

			NoteRecord note = state.SaveNote(Account, EsName, "second trade", At(130), stagedId);

			Assert.Equal(NotePhases.InTrade, note.Phase);
			Assert.Same(note, Assert.Single(state.NotesFor(openId)));
			Assert.Empty(state.Staged.Find(stagedId).Notes);
		}

		[Fact]
		public void SaveNote_WhileFlatWithNoTarget_IsAPendingPreTradeNote()
		{
			AddonState state = new AddonState();

			NoteRecord note = state.SaveNote(Account, EsName, "watching for H2", At(-20), null);

			Assert.Equal(NotePhases.PreTrade, note.Phase);
			Assert.Same(note, Assert.Single(state.PendingNotes.For(Account, EsName)));
		}

		[Fact]
		public void SaveNote_WhileFlatWithAStagedTarget_IsAPostTradeNoteOnThatTrade()
		{
			string tradeId;
			AddonState state = StagedTrade(out tradeId);

			NoteRecord note = state.SaveNote(Account, EsName, "took it too early", At(90), tradeId);

			Assert.Equal(NotePhases.PostTrade, note.Phase);
			Assert.Same(note, Assert.Single(state.Staged.Find(tradeId).Notes));
			Assert.Empty(state.PendingNotes.For(Account, EsName));
		}

		[Fact]
		public void SaveNote_ToATradeAlreadySubmitted_IsRefused()
		{
			string tradeId;
			AddonState state = StagedTrade(out tradeId);
			state.Staged.Find(tradeId).TradeType = "2EL";
			state.Submit(tradeId, AddonVersion, null);

			Assert.Throws<InvalidOperationException>(() => state.SaveNote(Account, EsName, "late", At(200), tradeId));
			Assert.Empty(state.Staged.Find(tradeId).Notes);
		}

		[Fact]
		public void SaveNote_ToAStagedTradeOnAnotherAccount_IsRefused()
		{
			string tradeId;
			AddonState state = StagedTrade(out tradeId);

			Assert.Throws<ArgumentException>(() => state.SaveNote(Other, EsName, "wrong account", At(90), tradeId));
			Assert.Empty(state.Staged.Find(tradeId).Notes);
		}

		[Theory]
		[InlineData(null)]
		[InlineData("")]
		[InlineData("   ")]
		public void SaveNote_WithABlankBody_IsRefused(string body)
		{
			AddonState state = new AddonState();

			Assert.Throws<ArgumentException>(() => state.SaveNote(Account, EsName, body, At(0), null));
			Assert.True(state.PendingNotes.IsEmpty);
		}

		[Fact]
		public void Notes_FromTwoTrades_NeverMix()
		{
			string firstId;
			AddonState state = StagedTrade(out firstId);
			state.SaveNote(Account, EsName, "first trade review", At(90), firstId);
			string secondId = state.Tracker.Apply(Buy("n3", "o3", "Entry", 1, 7701m, 120, 0m, 1, Account, isEntry: true)).Opened.TradeId;
			state.SaveNote(Account, EsName, "second trade in progress", At(130), null);

			Assert.Equal("first trade review", Assert.Single(state.Staged.Find(firstId).Notes).Body);
			Assert.Equal("second trade in progress", Assert.Single(state.NotesFor(secondId)).Body);
		}

		// ---- edit / delete

		[Fact]
		public void EditNote_ChangesTheBodyAndKeepsTheOriginalTime()
		{
			AddonState state = new AddonState();
			NoteRecord note = state.SaveNote(Account, EsName, "draft", At(0), null);

			Assert.True(state.EditNote(note, "  final wording  "));

			Assert.Equal("final wording", note.Body);
			Assert.Equal(At(0), note.OccurredAt);
		}

		[Fact]
		public void EditNote_OnAStagedTradesNote_WorksUntilSubmission_ThenIsRefused()
		{
			string tradeId;
			AddonState state = StagedTrade(out tradeId);
			NoteRecord note = state.SaveNote(Account, EsName, "review", At(90), tradeId);
			Assert.True(state.EditNote(note, "review, edited"));

			state.Staged.Find(tradeId).TradeType = "2EL";
			state.Submit(tradeId, AddonVersion, null);

			Assert.False(state.EditNote(note, "after submit"));
			Assert.Equal("review, edited", note.Body);
		}

		[Fact]
		public void EditNote_WithABlankBody_IsRefused()
		{
			AddonState state = new AddonState();
			NoteRecord note = state.SaveNote(Account, EsName, "keep me", At(0), null);

			Assert.Throws<ArgumentException>(() => state.EditNote(note, " "));
			Assert.Equal("keep me", note.Body);
		}

		[Fact]
		public void DeleteNote_RemovesItFromPendingOpenOrStaged()
		{
			string stagedId;
			AddonState state = StagedTrade(out stagedId);
			NoteRecord posted = state.SaveNote(Account, EsName, "post", At(90), stagedId);
			NoteRecord pending = state.SaveNote(Account, EsName, "pre", At(100), null);
			OpenTradeInfo opened = state.Tracker.Apply(Buy("n3", "o3", "Entry", 1, 7701m, 120, 0m, 1, Account, isEntry: true)).Opened;
			state.AttachPendingNotes(opened);
			string openId = opened.TradeId;
			NoteRecord inTrade = state.SaveNote(Account, EsName, "in", At(130), null);

			Assert.True(state.DeleteNote(posted));
			Assert.True(state.DeleteNote(inTrade));
			Assert.Empty(state.Staged.Find(stagedId).Notes);
			Assert.Equal(new[] { "pre" }, state.NotesFor(openId).Select(n => n.Body));

			// The pending note moved onto the trade that just opened; it can still be deleted there.
			Assert.True(state.DeleteNote(pending));
			Assert.Empty(state.NotesFor(openId));
		}

		[Fact]
		public void DeleteNote_OnASubmittedTrade_IsRefused()
		{
			string tradeId;
			AddonState state = StagedTrade(out tradeId);
			NoteRecord note = state.SaveNote(Account, EsName, "review", At(90), tradeId);
			state.Staged.Find(tradeId).TradeType = "2EL";
			state.Submit(tradeId, AddonVersion, null);

			Assert.False(state.DeleteNote(note));
			Assert.Single(state.Staged.Find(tradeId).Notes);
		}

		[Fact]
		public void DeleteNote_ForANoteTheStateDoesNotHold_ReturnsFalse()
		{
			AddonState state = new AddonState();

			Assert.False(state.DeleteNote(new NoteRecord { Body = "stray", Phase = NotePhases.General, OccurredAt = At(0) }));
		}

		// ---- trade type and Submit

		[Fact]
		public void SetTradeType_StoresTheChoice_AndClearsTheDescriptionUnlessOther()
		{
			string tradeId;
			AddonState state = StagedTrade(out tradeId);

			state.SetTradeType(tradeId, "Other", "  breakout retest  ");
			Assert.Equal("Other", state.Staged.Find(tradeId).TradeType);
			Assert.Equal("breakout retest", state.Staged.Find(tradeId).TradeTypeOther);

			state.SetTradeType(tradeId, "2EL", "ignored");
			Assert.Equal("2EL", state.Staged.Find(tradeId).TradeType);
			Assert.Null(state.Staged.Find(tradeId).TradeTypeOther);
		}

		[Fact]
		public void SetTradeType_WithAnUnknownValue_IsRefused()
		{
			string tradeId;
			AddonState state = StagedTrade(out tradeId);

			Assert.Throws<ArgumentException>(() => state.SetTradeType(tradeId, "XYZ", null));
			Assert.Null(state.Staged.Find(tradeId).TradeType);
		}

		[Fact]
		public void SetTradeType_AfterSubmission_IsRefused()
		{
			string tradeId;
			AddonState state = StagedTrade(out tradeId);
			state.SetTradeType(tradeId, "2EL", null);
			state.Submit(tradeId, AddonVersion, null);

			Assert.Throws<InvalidOperationException>(() => state.SetTradeType(tradeId, "RL", null));
			Assert.Equal("2EL", state.Staged.Find(tradeId).TradeType);
		}

		[Fact]
		public void CanSubmit_NeedsAStagedEditableTradeWithAValidType()
		{
			string tradeId;
			AddonState state = StagedTrade(out tradeId);
			Assert.False(state.CanSubmit(tradeId));

			state.SetTradeType(tradeId, "F2EL", null);
			Assert.True(state.CanSubmit(tradeId));

			state.Submit(tradeId, AddonVersion, null);
			Assert.False(state.CanSubmit(tradeId));
		}

		[Fact]
		public void CanSubmit_IsFalseForAnOpenTrade()
		{
			string tradeId;
			AddonState state = Opened(out tradeId);

			Assert.False(state.CanSubmit(tradeId));
		}
	}
}
