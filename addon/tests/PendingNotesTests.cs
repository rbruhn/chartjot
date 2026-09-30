using ChartJot.Core;
using static ChartJot.Core.Tests.TestData;

namespace ChartJot.Core.Tests
{
	public class PendingNotesTests
	{
		private static NoteRecord Note(string body, int seconds)
		{
			return new NoteRecord { Body = body, Phase = NotePhases.PreTrade, OccurredAt = At(seconds) };
		}

		[Fact]
		public void Add_ThenFor_ReturnsNotesForThatAccountAndInstrument()
		{
			PendingNotes pending = new PendingNotes();
			pending.Add("APEX-24570-135", "ES 12-26", Note("watching for a breakout", 0));
			pending.Add("APEX-24570-135", "ES 12-26", Note("still waiting", 5));

			IList<NoteRecord> notes = pending.For("APEX-24570-135", "ES 12-26");

			Assert.Equal(2, notes.Count);
			Assert.Equal("watching for a breakout", notes[0].Body);
			Assert.Equal("still waiting", notes[1].Body);
		}

		[Fact]
		public void For_UnknownAccountOrInstrument_ReturnsEmptyNotNull()
		{
			PendingNotes pending = new PendingNotes();

			Assert.Empty(pending.For("APEX-24570-135", "ES 12-26"));
		}

		[Fact]
		public void DifferentInstrumentsOnTheSameAccount_AreKeptSeparate()
		{
			PendingNotes pending = new PendingNotes();
			pending.Add("APEX-24570-135", "ES 12-26", Note("es note", 0));
			pending.Add("APEX-24570-135", "MES 12-26", Note("mes note", 0));

			Assert.Single(pending.For("APEX-24570-135", "ES 12-26"));
			Assert.Single(pending.For("APEX-24570-135", "MES 12-26"));
		}

		[Fact]
		public void Take_RemovesAndReturnsTheBucket()
		{
			PendingNotes pending = new PendingNotes();
			pending.Add("APEX-24570-135", "ES 12-26", Note("note", 0));

			IList<NoteRecord> taken = pending.Take("APEX-24570-135", "ES 12-26");

			Assert.Single(taken);
			Assert.Empty(pending.For("APEX-24570-135", "ES 12-26"));
			Assert.True(pending.IsEmpty);
		}

		[Fact]
		public void Take_WhenNothingPending_ReturnsEmpty()
		{
			PendingNotes pending = new PendingNotes();

			Assert.Empty(pending.Take("APEX-24570-135", "ES 12-26"));
		}

		[Fact]
		public void Add_NullNote_Throws()
		{
			PendingNotes pending = new PendingNotes();

			Assert.Throws<ArgumentNullException>(() => pending.Add("APEX-24570-135", "ES 12-26", null));
		}

		[Fact]
		public void SerializeDeserialize_RoundTripsNotesAcrossAccountsAndInstruments()
		{
			PendingNotes pending = new PendingNotes();
			pending.Add("APEX-24570-135", "ES 12-26", Note("es note", 0));
			pending.Add("APEX-24570-135", "ES 12-26", Note("second es note", 5));
			pending.Add("Sim101", "MES 12-26", Note("sim note", 10));

			PendingNotes reloaded = PendingNotes.Deserialize(pending.Serialize());

			IList<NoteRecord> es = reloaded.For("APEX-24570-135", "ES 12-26");
			Assert.Equal(2, es.Count);
			Assert.Equal("es note", es[0].Body);
			Assert.Equal(NotePhases.PreTrade, es[0].Phase);
			Assert.Equal(At(0), es[0].OccurredAt);
			Assert.Equal("second es note", es[1].Body);

			IList<NoteRecord> sim = reloaded.For("Sim101", "MES 12-26");
			Assert.Single(sim);
			Assert.Equal("sim note", sim[0].Body);
		}

		[Fact]
		public void SerializeDeserialize_Empty_RoundTrips()
		{
			PendingNotes pending = new PendingNotes();

			PendingNotes reloaded = PendingNotes.Deserialize(pending.Serialize());

			Assert.True(reloaded.IsEmpty);
		}
	}
}
