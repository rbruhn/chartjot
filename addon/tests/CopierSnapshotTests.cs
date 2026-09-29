using ChartJot.Core;

namespace ChartJot.Core.Tests
{
	public class CopierSnapshotTests
	{
		private static CopierSnapshot Parse(params string[] rows)
		{
			return CopierSnapshotParser.Parse("TEST-ACCT-001", true, "All", "ES", "Round Up At 0.5", "Multiplier", rows, CopierSnapshotParser.SourceLive);
		}

		[Fact]
		public void ParsesARealRow()
		{
			CopierSnapshot snap = Parse("TEST-ACCT-04|Slave|3|No|No|No|No|No|Default|No|Rithmic");

			FollowerSetup f = Assert.Single(snap.Followers);
			Assert.Equal("TEST-ACCT-04", f.Account);
			Assert.True(f.IsFollower);
			Assert.Equal(3m, f.Multiplier);
			Assert.Equal(ContractSize.Micro, f.Size);
			Assert.False(f.Fade);
			Assert.False(f.Blown);
			Assert.False(f.ExecutionsMode);
			Assert.Equal("Rithmic", f.Connection);
			Assert.Equal("copier_live", snap.Source);
		}

		[Fact]
		public void SizeColumnAndDirectionFilterSettings_DefaultToTheirSafeValues()
		{
			CopierSnapshot snap = Parse("PA-02|Slave|1|No|No|No|No|No|Default|No|Rithmic");

			Assert.True(snap.SizeColumnEnabled);
			Assert.False(snap.SelectTradeDirectionEnabled);
			Assert.True(snap.AllowLong);
			Assert.True(snap.AllowShort);
		}

		[Fact]
		public void SizeColumnAndDirectionFilterSettings_ThreadThroughWhenProvided()
		{
			CopierSnapshot snap = CopierSnapshotParser.Parse("TEST-ACCT-001", true, "All", "ES", "Round Up At 0.5", "Multiplier",
				new[] { "PA-02|Slave|1|No|No|No|No|No|Default|No|Rithmic" }, CopierSnapshotParser.SourceLive,
				sizeColumnEnabled: false, selectTradeDirectionEnabled: true, allowLong: true, allowShort: false);

			Assert.False(snap.SizeColumnEnabled);
			Assert.True(snap.SelectTradeDirectionEnabled);
			Assert.True(snap.AllowLong);
			Assert.False(snap.AllowShort);
		}

		[Fact]
		public void TheMastersOwnRowIsSkipped_AndNoneRoleIsNotAFollower()
		{
			CopierSnapshot snap = Parse(
				"TEST-ACCT-001|None|1|Yes|No|No|No|No|Default|No|Rithmic",
				"Sim101|None|1|No|No|No|No|No|Default|No|Rithmic",
				"TEST-ACCT-136|Slave|1|Yes|No|No|No|No|Default|No|Rithmic");

			Assert.Equal(new[] { "Sim101", "TEST-ACCT-136" }, snap.Followers.Select(f => f.Account).ToArray());
			Assert.Equal(new[] { "TEST-ACCT-136" }, snap.FollowerRows.Select(f => f.Account).ToArray());
		}

		[Theory]
		[InlineData("Yes", ContractSize.Mini)]
		[InlineData("No", ContractSize.Micro)]
		[InlineData("Nano", ContractSize.Nano)]
		public void ContractSizeColumn(string value, ContractSize expected)
		{
			Assert.Equal(expected, Parse("A|Slave|1|" + value).Followers[0].Size);
		}

		[Fact]
		public void FadeExecutionsModeAndBlownColumns()
		{
			FollowerSetup f = Parse("A|Slave|2|No|Yes|No|No|No|Executions|Yes|Rithmic").Followers[0];

			Assert.True(f.Fade);
			Assert.True(f.ExecutionsMode);
			Assert.True(f.Blown);
		}

		[Theory]
		[InlineData("1/2", 0.5)]
		[InlineData("1/4", 0.25)]
		[InlineData("2/3", 0.6666666666666666666666666667)]
		public void MultiplierAcceptsFractions(string text, double expected)
		{
			Assert.Equal((decimal)expected, Parse("A|Slave|" + text + "|Yes").Followers[0].Multiplier, 10);
		}

		[Theory]
		[InlineData("1/0")]
		[InlineData("/2")]
		[InlineData("a/b")]
		public void BadFractionsFallBackToOneWithADiagnostic(string text)
		{
			CopierSnapshot snap = Parse("A|Slave|" + text + "|Yes");

			Assert.Equal(1m, snap.Followers[0].Multiplier);
			Assert.Single(snap.Diagnostics);
		}

		[Fact]
		public void ABlankSize_MeansTheSizeColumnIsOff_AndNeedsNoDiagnostic()
		{
			CopierSnapshot snap = Parse("A|Slave||Yes");

			Assert.Equal(1m, snap.Followers[0].Multiplier);
			Assert.Empty(snap.Diagnostics);
		}

		[Theory]
		[InlineData("3x", 3)]
		[InlineData("3", 3)]
		[InlineData("0.5", 0.5)]
		[InlineData("1X", 1)]
		public void MultiplierAcceptsTheDashboardForms(string text, double expected)
		{
			Assert.Equal((decimal)expected, Parse("A|Slave|" + text + "|Yes").Followers[0].Multiplier);
		}

		[Fact]
		public void ShortRowsDefaultToMiniNoFadeNotBlownDefaultMode()
		{
			FollowerSetup f = Parse("A|Slave|2").Followers[0];

			Assert.Equal(ContractSize.Mini, f.Size);
			Assert.False(f.Fade);
			Assert.False(f.Blown);
			Assert.False(f.ExecutionsMode);
			Assert.Null(f.Connection == "" ? null : f.Connection);
		}

		[Fact]
		public void ExtraColumnsAreIgnored()
		{
			FollowerSetup f = Parse("A|Slave|1|No|No|No|No|No|Default|No|Rithmic|something|new").Followers[0];

			Assert.Equal("Rithmic", f.Connection);
			Assert.Equal(ContractSize.Micro, f.Size);
		}

		[Fact]
		public void UnreadableMultiplierFallsBackToOneWithADiagnostic()
		{
			CopierSnapshot snap = Parse("A|Slave|lots|Yes");

			Assert.Equal(1m, snap.Followers[0].Multiplier);
			Assert.Contains(snap.Diagnostics, d => d.Contains("multiplier"));
		}

		[Fact]
		public void RowsTooShortToUseAreSkippedWithADiagnostic()
		{
			CopierSnapshot snap = Parse("only|two", "B|Slave|1|Yes");

			Assert.Equal(new[] { "B" }, snap.Followers.Select(f => f.Account).ToArray());
			Assert.Single(snap.Diagnostics);
		}

		[Fact]
		public void NothingParsable_ReturnsNullSoTheCallerFallsBack()
		{
			Assert.Null(Parse("bad", "also|bad"));
		}

		[Fact]
		public void UnknownMasterOrNoRows_ReturnsNull()
		{
			Assert.Null(CopierSnapshotParser.Parse("", true, "All", "ES", "x", "y", new[] { "A|Slave|1|Yes" }, "copier_live"));
			Assert.Null(CopierSnapshotParser.Parse(null, true, "All", "ES", "x", "y", new[] { "A|Slave|1|Yes" }, "copier_live"));
			Assert.Null(CopierSnapshotParser.Parse("M", true, "All", "ES", "x", "y", null, "copier_live"));
		}

		[Fact]
		public void NoRowsAtAll_IsAValidEmptySetup()
		{
			CopierSnapshot snap = Parse();

			Assert.NotNull(snap);
			Assert.Empty(snap.Followers);
		}

		[Fact]
		public void InstrumentModeSingle_IsRecorded()
		{
			CopierSnapshot snap = CopierSnapshotParser.Parse("M", true, "Single", "NQ", "Round Up At 0.5", "Multiplier", new[] { "A|Slave|1|Yes" }, "copier_live");

			Assert.False(snap.AllInstruments);
			Assert.Equal("NQ", snap.SingleInstrument);
		}
	}

	public class MarketFamiliesTests
	{
		private static InstrumentSpec Spec(string full)
		{
			string symbol = full.Split(' ')[0];
			return new InstrumentSpec { FullName = full, Symbol = symbol, TickSize = 0.25m, PointValue = 5m };
		}

		[Fact]
		public void MiniAndMicroOfTheSameMonthAreTheSameMarket()
		{
			MarketFamilies f = MarketFamilies.Default;

			Assert.True(f.SameMarket(Spec("ES 12-26"), Spec("MES 12-26")));
			Assert.True(f.SameMarket(Spec("MES 12-26"), Spec("ES 12-26")));
			Assert.True(f.SameMarket(Spec("ES 12-26"), Spec("ES 12-26")));
			Assert.True(f.SameMarket(Spec("RTY 12-26"), Spec("M2K 12-26")));
		}

		[Fact]
		public void DifferentMonthOrFamilyIsADifferentMarket()
		{
			MarketFamilies f = MarketFamilies.Default;

			Assert.False(f.SameMarket(Spec("ES 12-26"), Spec("MES 03-27")));
			Assert.False(f.SameMarket(Spec("ES 12-26"), Spec("NQ 12-26")));
			Assert.False(f.SameMarket(Spec("ES 12-26"), Spec("MNQ 12-26")));
		}

		[Fact]
		public void AnUnmappedInstrumentMatchesOnlyItself()
		{
			MarketFamilies f = MarketFamilies.Default;

			Assert.True(f.SameMarket(Spec("ZB 12-26"), Spec("ZB 12-26")));
			Assert.False(f.SameMarket(Spec("ZB 12-26"), Spec("ZN 12-26")));
			Assert.False(f.IsMapped("ZB"));
		}

		[Fact]
		public void CustomGroupsCanBeAdded()
		{
			MarketFamilies f = new MarketFamilies(new[] { new[] { "ZB", "MZB" } });

			Assert.True(f.SameMarket(Spec("ZB 12-26"), Spec("MZB 12-26")));
		}

		[Fact]
		public void ExpectedSymbolFollowsTheFollowersContractSize()
		{
			MarketFamilies f = MarketFamilies.Default;

			Assert.Equal("ES", f.ExpectedSymbol(Spec("ES 12-26"), ContractSize.Mini));
			Assert.Equal("MES", f.ExpectedSymbol(Spec("ES 12-26"), ContractSize.Micro));
			Assert.Equal("ES", f.ExpectedSymbol(Spec("MES 12-26"), ContractSize.Mini));
			Assert.Null(f.ExpectedSymbol(Spec("ES 12-26"), ContractSize.Nano));
		}

		[Fact]
		public void ContractMonthIsThePartAfterTheSymbol()
		{
			Assert.Equal("12-26", MarketFamilies.ContractMonth(Spec("ES 12-26")));
			Assert.Equal("", MarketFamilies.ContractMonth(new InstrumentSpec { FullName = "SPY", Symbol = "SPY" }));
		}
	}
}
