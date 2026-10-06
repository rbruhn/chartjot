using ChartJot.Core;
using static ChartJot.Core.Tests.TestData;

namespace ChartJot.Core.Tests
{
	/// <summary>
	/// Copier followers carry the master's note, trade type and screenshot; each still sends its own trade data.
	/// Matching rules are the vendor-confirmed ones from before #28: same market family and contract month, the
	/// master's direction (or the opposite for a fading follower), entering within the window of the master's
	/// entry (Executions mode) or before the master's exit (Orders mode).
	/// </summary>
	public class FollowerMatchingTests
	{
		private const string Master = "TEST-ACCT-001";
		private const string FollowerA = "TEST-ACCT-002";
		private const string FollowerB = "TEST-ACCT-003";
		private static readonly TimeSpan Window = TimeSpan.FromSeconds(5);
		private static readonly InstrumentSpec Nq = new InstrumentSpec { FullName = "NQ 12-26", Symbol = "NQ", TickSize = 0.25m, PointValue = 20m };
		private static readonly InstrumentSpec MesMarch = new InstrumentSpec { FullName = "MES 03-27", Symbol = "MES", TickSize = 0.25m, PointValue = 5m };

		private static int sequence;

		// A closed round turn: entry at entrySeconds, exit at exitSeconds.
		private static CompletedTrade Trade(string account, InstrumentSpec instrument, Direction direction, int entrySeconds, int exitSeconds)
		{
			TradeTracker tracker = new TradeTracker();
			string id = "x" + (++sequence).ToString(System.Globalization.CultureInfo.InvariantCulture);
			if (direction == Direction.Long)
			{
				tracker.Apply(Buy(id + "a", id + "o1", "Entry", 1, 7700m, entrySeconds, 0m, 1, account, instrument, isEntry: true));
				return Assert.Single(tracker.Apply(Sell(id + "b", id + "o2", "Target1", 1, 7702m, exitSeconds, 0m, 0, account, instrument, isExit: true)).Closed);
			}
			tracker.Apply(Sell(id + "a", id + "o1", "Entry", 1, 7700m, entrySeconds, 0m, -1, account, instrument, isEntry: true));
			return Assert.Single(tracker.Apply(Buy(id + "b", id + "o2", "Target1", 1, 7698m, exitSeconds, 0m, 0, account, instrument, isExit: true)).Closed);
		}

		private static FollowerSetup Follower(string account, bool fade = false, bool executionsMode = true)
		{
			return new FollowerSetup { Account = account, Fade = fade, ExecutionsMode = executionsMode };
		}

		// ---- market families

		[Theory]
		[InlineData("ES 12-26", "ES", "MES 12-26", "MES", true)]
		[InlineData("NQ 12-26", "NQ", "MNQ 12-26", "MNQ", true)]
		[InlineData("ES 12-26", "ES", "ES 12-26", "ES", true)]
		[InlineData("ES 12-26", "ES", "NQ 12-26", "NQ", false)]
		[InlineData("ES 12-26", "ES", "MES 03-27", "MES", false)]
		[InlineData("ZB 12-26", "ZB", "ZB 12-26", "ZB", true)]
		[InlineData("ZB 12-26", "ZB", "ZN 12-26", "ZN", false)]
		public void SameMarket_IsTheSameFamilyAndContractMonth(string nameA, string symbolA, string nameB, string symbolB, bool expected)
		{
			InstrumentSpec a = new InstrumentSpec { FullName = nameA, Symbol = symbolA };
			InstrumentSpec b = new InstrumentSpec { FullName = nameB, Symbol = symbolB };

			Assert.Equal(expected, MarketFamilies.Default.SameMarket(a, b));
		}

		// ---- copier setup rows (vendor column map: name|role|size|type|fade|hide|goalLossAutoExit|fundedAutoClose|mode|blown|connection)

		[Fact]
		public void CopierSetup_KeepsOnlyFollowerRowsOtherThanTheMaster()
		{
			IList<FollowerSetup> followers = CopierSetup.Followers(Master, new[]
			{
				Master + "|Master|1x|Yes|No|No|No|No|Orders|No|Rithmic",
				FollowerA + "|Slave|1x|No|No|No|No|No|Executions|No|Rithmic",
				FollowerB + "|Slave|2x|Yes|Yes|No|No|No|Orders|Yes|Rithmic",
				"TEST-ACCT-009|None|1x|Yes|No|No|No|No|Orders|No|Rithmic",
				"",
				"garbage"
			});

			Assert.Equal(new[] { FollowerA, FollowerB }, followers.Select(f => f.Account));
			Assert.False(followers[0].Fade);
			Assert.True(followers[0].ExecutionsMode);
			Assert.True(followers[1].Fade);
			Assert.False(followers[1].ExecutionsMode);
			Assert.True(followers[1].Blown);
		}

		[Fact]
		public void CopierSetup_ShortRowsDefaultToNoFadeOrdersModeNotBlown()
		{
			FollowerSetup follower = Assert.Single(CopierSetup.Followers(Master, new[] { FollowerA + "|Slave|1x" }));

			Assert.False(follower.Fade);
			Assert.False(follower.ExecutionsMode);
			Assert.False(follower.Blown);
		}

		// ---- matching

		[Fact]
		public void Match_FindsEachFollowersTradeInTheSameMarket()
		{
			CompletedTrade master = Trade(Master, Es, Direction.Long, 0, 300);
			CompletedTrade micro = Trade(FollowerA, Mes, Direction.Long, 1, 300);
			CompletedTrade mini = Trade(FollowerB, Es, Direction.Long, 2, 301);

			IList<CompletedTrade> matched = FollowerMatcher.Match(master, new[] { Follower(FollowerA), Follower(FollowerB) }, new[] { mini, micro }, Window);

			Assert.Equal(new[] { micro.TradeId, mini.TradeId }, matched.Select(t => t.TradeId));
		}

		[Fact]
		public void Match_AFadingFollowerTradesTheOppositeDirection()
		{
			CompletedTrade master = Trade(Master, Es, Direction.Long, 0, 300);
			CompletedTrade sameWay = Trade(FollowerA, Mes, Direction.Long, 1, 300);
			CompletedTrade faded = Trade(FollowerA, Mes, Direction.Short, 1, 300);

			Assert.Equal(faded.TradeId, Assert.Single(FollowerMatcher.Match(master, new[] { Follower(FollowerA, fade: true) }, new[] { sameWay, faded }, Window)).TradeId);
			Assert.Equal(sameWay.TradeId, Assert.Single(FollowerMatcher.Match(master, new[] { Follower(FollowerA) }, new[] { sameWay, faded }, Window)).TradeId);
		}

		[Fact]
		public void Match_ExecutionsModeNeedsAnEntryWithinTheWindow()
		{
			CompletedTrade master = Trade(Master, Es, Direction.Long, 0, 300);
			CompletedTrade late = Trade(FollowerA, Mes, Direction.Long, 30, 300);

			Assert.Empty(FollowerMatcher.Match(master, new[] { Follower(FollowerA, executionsMode: true) }, new[] { late }, Window));
		}

		[Fact]
		public void Match_OrdersModeAcceptsAnEntryAnyTimeBeforeTheMastersExit()
		{
			CompletedTrade master = Trade(Master, Es, Direction.Long, 0, 300);
			CompletedTrade late = Trade(FollowerA, Mes, Direction.Long, 30, 310);
			CompletedTrade afterExit = Trade(FollowerA, Mes, Direction.Long, 301, 400);

			Assert.Equal(late.TradeId, Assert.Single(FollowerMatcher.Match(master, new[] { Follower(FollowerA, executionsMode: false) }, new[] { afterExit, late }, Window)).TradeId);
		}

		[Fact]
		public void Match_IgnoresOtherMarketsOtherMonthsAndAccountsThatAreNotFollowers()
		{
			CompletedTrade master = Trade(Master, Es, Direction.Long, 0, 300);
			CompletedTrade otherMarket = Trade(FollowerA, Nq, Direction.Long, 1, 300);
			CompletedTrade otherMonth = Trade(FollowerA, MesMarch, Direction.Long, 1, 300);
			CompletedTrade notAFollower = Trade("TEST-ACCT-009", Es, Direction.Long, 1, 300);
			CompletedTrade itself = Trade(Master, Es, Direction.Long, 2, 300);

			Assert.Empty(FollowerMatcher.Match(master, new[] { Follower(FollowerA), Follower(Master) },
				new[] { otherMarket, otherMonth, notAFollower, itself }, Window));
		}

		[Fact]
		public void Match_TakesTheEarliestQualifyingTradePerFollower()
		{
			CompletedTrade master = Trade(Master, Es, Direction.Long, 0, 300);
			CompletedTrade second = Trade(FollowerA, Mes, Direction.Long, 3, 300);
			CompletedTrade first = Trade(FollowerA, Mes, Direction.Long, 1, 300);

			Assert.Equal(first.TradeId, Assert.Single(FollowerMatcher.Match(master, new[] { Follower(FollowerA) }, new[] { second, first }, Window)).TradeId);
		}

		[Fact]
		public void Match_AcceptsAFollowerThatEnteredJustBeforeTheMaster()
		{
			CompletedTrade master = Trade(Master, Es, Direction.Long, 10, 300);
			CompletedTrade early = Trade(FollowerA, Mes, Direction.Long, 6, 300);

			Assert.Single(FollowerMatcher.Match(master, new[] { Follower(FollowerA) }, new[] { early }, Window));
		}
	}
}
