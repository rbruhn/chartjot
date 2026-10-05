using ChartJot.Core;
using static ChartJot.Core.Tests.TestData;

namespace ChartJot.Core.Tests
{
	/// <summary>
	/// Chart images (#37/#52): an entry image shortly after the trade opens (when enabled) and an exit image
	/// shortly after flat, which Recapture replaces before Submit. The master's images go with each follower.
	/// </summary>
	public class ScreenshotTests
	{
		private const string Master = "TEST-ACCT-001";
		private const string Follower = "TEST-ACCT-002";
		private const string EsName = "ES 12-26";
		private const string Version = "0.4.0";

		private static readonly Func<string, string> Connection = account => "Sim";

		private static readonly Fill Entry = Buy("s1", "o1", "Entry", 1, 7700m, 0, 1.29m, 1, Master, isEntry: true);
		private static readonly Fill Exit = Sell("s2", "o2", "Target1", 1, 7703m, 60, 1.29m, 0, Master, isExit: true);

		private static ScreenshotMeta Image(int seconds)
		{
			return new ScreenshotMeta { CapturedAt = At(seconds), Format = "png" };
		}

		private static AddonState Opened(out string tradeId)
		{
			AddonState state = new AddonState();
			tradeId = state.Tracker.Apply(Entry).Opened.TradeId;
			return state;
		}

		private static void Close(AddonState state)
		{
			state.RecordClosed(Assert.Single(state.Tracker.Apply(Exit).Closed));
		}

		// ---- settings

		[Fact]
		public void CaptureEntryImage_IsOffByDefault_AndRoundTrips()
		{
			AddonSettings settings = AddonSettings.Defaults("/home/trader");
			Assert.False(settings.CaptureEntryImage);

			settings.CaptureEntryImage = true;
			Assert.True(AddonSettings.Deserialize(settings.Serialize()).CaptureEntryImage);
		}

		[Fact]
		public void CaptureEntryImage_IsOffForASettingsFileWrittenBeforeItExisted()
		{
			string json = AddonSettings.Defaults("/home/trader").Serialize().Replace(",\"capture_entry_image\":false", "");

			Assert.False(AddonSettings.Deserialize(json).CaptureEntryImage);
		}

		// ---- recording captures

		[Fact]
		public void AnEntryImageCapturedWhileOpen_IsOnTheStagedTrade()
		{
			string tradeId;
			AddonState state = Opened(out tradeId);

			Assert.True(state.RecordCapture(tradeId, true, Image(1)));
			Close(state);
			StagedTrade staged = state.Stage(tradeId, At(62));

			Assert.Equal(At(1), staged.EntryScreenshot.CapturedAt);
			Assert.Null(staged.Screenshot);
		}

		[Fact]
		public void AnExitImageCapturedBeforeStaging_IsOnTheStagedTrade()
		{
			string tradeId;
			AddonState state = Opened(out tradeId);
			Close(state);

			Assert.True(state.RecordCapture(tradeId, false, Image(61)));

			Assert.Equal(At(61), state.Stage(tradeId, At(62)).Screenshot.CapturedAt);
		}

		[Fact]
		public void Recapture_ReplacesTheExitImageOfAStagedTrade()
		{
			string tradeId;
			AddonState state = Opened(out tradeId);
			Close(state);
			state.RecordCapture(tradeId, false, Image(61));
			state.Stage(tradeId, At(62));

			Assert.True(state.RecordCapture(tradeId, false, Image(300)));

			Assert.Equal(At(300), state.Staged.Find(tradeId).Screenshot.CapturedAt);
			Assert.Equal(At(300), state.ImagesFor(tradeId).Exit.CapturedAt);
		}

		[Fact]
		public void Captures_AreRefusedOnceTheTradeIsSubmitted()
		{
			string tradeId;
			AddonState state = Opened(out tradeId);
			Close(state);
			state.RecordCapture(tradeId, false, Image(61));
			state.UpdateForm(Master, EsName, "idea", "2EL", null, At(0));
			state.SubmitForm(Master, EsName, Version, Connection, new string[0], At(120));

			Assert.False(state.RecordCapture(tradeId, false, Image(300)));
			Assert.Equal(At(61), state.Staged.Find(tradeId).Screenshot.CapturedAt);
		}

		[Fact]
		public void Captures_ForAnUnknownTrade_AreRefused()
		{
			Assert.False(new AddonState().RecordCapture("no-such-trade", false, Image(1)));
		}

		[Fact]
		public void AnOpenTradesEntryImage_SurvivesARestart()
		{
			string tradeId;
			AddonState state = Opened(out tradeId);
			state.RecordCapture(tradeId, true, Image(1));

			AddonState restarted = AddonState.Deserialize(state.Serialize());
			restarted.Reconcile(Master, EsName, new[] { Entry }, 1);

			Assert.Equal(At(1), restarted.ImagesFor(tradeId).Entry.CapturedAt);
		}

		[Fact]
		public void AStagedTradesImages_SurviveARestart()
		{
			string tradeId;
			AddonState state = Opened(out tradeId);
			state.RecordCapture(tradeId, true, Image(1));
			Close(state);
			state.RecordCapture(tradeId, false, Image(61));
			state.Stage(tradeId, At(62));

			TradeImages images = AddonState.Deserialize(state.Serialize()).ImagesFor(tradeId);

			Assert.Equal(At(1), images.Entry.CapturedAt);
			Assert.Equal(At(61), images.Exit.CapturedAt);
		}

		[Fact]
		public void Reset_ForgetsTheImagesOfTheDroppedTrade()
		{
			string tradeId;
			AddonState state = Opened(out tradeId);
			Close(state);
			state.RecordCapture(tradeId, false, Image(61));

			state.ResetForm(Master, EsName);

			Assert.Null(state.ImagesFor(tradeId).Exit);
			Assert.False(state.RecordCapture(tradeId, false, Image(70)));
		}

		// ---- what Submit sends

		[Fact]
		public void Submit_SendsBothImagesMetadataWithTheMasterTrade()
		{
			string tradeId;
			AddonState state = Opened(out tradeId);
			state.RecordCapture(tradeId, true, Image(1));
			Close(state);
			state.RecordCapture(tradeId, false, Image(61));
			state.UpdateForm(Master, EsName, "idea", "2EL", null, At(0));

			JsonValue payload = JsonValue.Parse(Assert.Single(state.SubmitForm(Master, EsName, Version, Connection, new string[0], At(120))).PayloadJson);

			Assert.Equal(PayloadBuilder.Timestamp(At(61)), payload["screenshot"]["captured_at"].AsString());
			Assert.Equal(PayloadBuilder.Timestamp(At(1)), payload["entry_screenshot"]["captured_at"].AsString());
		}

		[Fact]
		public void Submit_WithoutImages_SendsNeitherObject()
		{
			string tradeId;
			AddonState state = Opened(out tradeId);
			Close(state);
			state.UpdateForm(Master, EsName, "idea", "2EL", null, At(0));

			JsonValue payload = JsonValue.Parse(Assert.Single(state.SubmitForm(Master, EsName, Version, Connection, new string[0], At(120))).PayloadJson);

			Assert.False(payload.Has("screenshot"));
			Assert.False(payload.Has("entry_screenshot"));
		}

		[Fact]
		public void Submit_GivesEachFollowerItsMastersImages()
		{
			string masterId;
			AddonState state = Opened(out masterId);
			state.RecordCapture(masterId, true, Image(1));
			Close(state);
			state.RecordCapture(masterId, false, Image(61));
			state.Tracker.Apply(Buy("t1", "p1", "AI", 1, 7700m, 1, 0.5m, 1, Follower, Mes, isEntry: true));
			CompletedTrade copy = Assert.Single(state.Tracker.Apply(Sell("t2", "p2", "AI", 1, 7703m, 61, 0.5m, 0, Follower, Mes, isExit: true)).Closed);
			state.RecordClosed(copy);
			state.UpdateForm(Master, EsName, "idea", "2EL", null, At(0));

			IList<QueuedDelivery> sent = state.SubmitForm(Master, EsName, Version, Connection, new[] { copy.TradeId }, At(120), id => masterId);

			JsonValue follower = JsonValue.Parse(sent[1].PayloadJson);
			Assert.Equal(copy.TradeId, follower["trade_id"].AsString());
			Assert.Equal(PayloadBuilder.Timestamp(At(61)), follower["screenshot"]["captured_at"].AsString());
			Assert.Equal(PayloadBuilder.Timestamp(At(1)), follower["entry_screenshot"]["captured_at"].AsString());
			Assert.Equal(At(61), state.ImagesFor(copy.TradeId).Exit.CapturedAt);
		}

		[Fact]
		public void Payload_WritesTheEntryImageCaptureTimeOnly()
		{
			string tradeId;
			AddonState state = Opened(out tradeId);
			state.RecordCapture(tradeId, true, new ScreenshotMeta { CapturedAt = At(1), Format = "png", Caption = "ignored" });
			Close(state);
			state.UpdateForm(Master, EsName, "idea", "2EL", null, At(0));

			JsonValue entry = JsonValue.Parse(Assert.Single(state.SubmitForm(Master, EsName, Version, Connection, new string[0], At(120))).PayloadJson)["entry_screenshot"];

			Assert.Equal(PayloadBuilder.Timestamp(At(1)), entry["captured_at"].AsString());
			Assert.False(entry.Has("caption"));
		}
	}
}
