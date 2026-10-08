using System.Text;
using ChartJot.Core;

namespace ChartJot.Core.Tests
{
	public class AddonSettingsTests
	{
		private const string UserProfile = "/home/trader";
		private const string Token = "synthetic-intake-token-7f3a";

		/// <summary>Stands in for DPAPI: reversible, and never leaves the plain text in its output.</summary>
		private sealed class XorProtector : ITokenProtector
		{
			public byte[] Protect(byte[] plain)
			{
				return plain.Select(b => (byte)(b ^ 0x5A)).ToArray();
			}

			public byte[] Unprotect(byte[] protectedBytes)
			{
				return protectedBytes.Select(b => (byte)(b ^ 0x5A)).ToArray();
			}
		}

		private static AddonSettings Valid()
		{
			AddonSettings settings = AddonSettings.Defaults(UserProfile);
			settings.EndpointUrl = "https://journal.example.com/api/v1/trades";
			settings.SetToken(Token, new XorProtector());
			return settings;
		}

		[Fact]
		public void Defaults_PutTheDataFolderAndStateFileUnderUserProfileChartJot()
		{
			AddonSettings settings = AddonSettings.Defaults(UserProfile);

			Assert.Equal(Path.Combine(UserProfile, "ChartJot"), settings.DataFolder);
			Assert.Equal(Path.Combine(UserProfile, "ChartJot", "state.json"), settings.StatePath);
			Assert.Null(settings.EndpointUrl);
			Assert.False(settings.HasToken);
		}

		[Fact]
		public void SettingsFilePath_IsFixedUnderUserProfile_SoItCanBeFoundBeforeTheDataFolderIsKnown()
		{
			Assert.Equal(Path.Combine(UserProfile, "ChartJot", "settings.json"), AddonSettings.SettingsFilePath(UserProfile));
		}

		[Fact]
		public void Token_IsStoredOnlyInProtectedForm_AndReadsBackThroughTheProtector()
		{
			AddonSettings settings = Valid();

			string json = settings.Serialize();

			Assert.DoesNotContain(Token, json);
			Assert.DoesNotContain(Convert.ToBase64String(Encoding.UTF8.GetBytes(Token)), json);
			Assert.True(settings.HasToken);
			Assert.Equal(Token, AddonSettings.Deserialize(json).GetToken(new XorProtector()));
		}

		[Fact]
		public void RoundTrip_KeepsEndpointAndDataFolder()
		{
			AddonSettings settings = Valid();
			settings.DataFolder = Path.Combine("/data", "journal");

			AddonSettings reloaded = AddonSettings.Deserialize(settings.Serialize());

			Assert.Equal("https://journal.example.com/api/v1/trades", reloaded.EndpointUrl);
			Assert.Equal(Path.Combine("/data", "journal"), reloaded.DataFolder);
			Assert.Equal(Path.Combine("/data", "journal", "state.json"), reloaded.StatePath);
		}

		[Fact]
		public void SetToken_WithBlank_ClearsIt()
		{
			AddonSettings settings = Valid();

			settings.SetToken("  ", new XorProtector());

			Assert.False(settings.HasToken);
			Assert.Null(settings.GetToken(new XorProtector()));
		}

		[Fact]
		public void Validate_AcceptsACompleteHttpsConfiguration()
		{
			Assert.Empty(Valid().Validate());
		}

		[Theory]
		[InlineData(null)]
		[InlineData("")]
		[InlineData("journal.example.com/api/v1/trades")]
		[InlineData("http://journal.example.com/api/v1/trades")]
		[InlineData("http://192.168.1.20:8000/api/v1/trades")]
		[InlineData("ftp://journal.example.com/api/v1/trades")]
		[InlineData("https://journal.example.com/api/v1/trades?token=abc")]
		[InlineData("https://user:secret@journal.example.com/api/v1/trades")]
		public void Validate_RejectsAnEndpointThatIsNotAPlainAbsoluteHttpsUrl(string endpoint)
		{
			AddonSettings settings = Valid();
			settings.EndpointUrl = endpoint;

			Assert.Single(settings.Validate());
		}

		[Theory]
		[InlineData("http://localhost:8000/api/v1/trades")]
		[InlineData("http://127.0.0.1:8000/api/v1/trades")]
		[InlineData("http://[::1]:8000/api/v1/trades")]
		public void Validate_AcceptsHttpToAJournalOnThisPc(string endpoint)
		{
			AddonSettings settings = Valid();
			settings.EndpointUrl = endpoint;

			Assert.Empty(settings.Validate());
		}

		[Fact]
		public void Validate_RequiresAToken()
		{
			AddonSettings settings = Valid();
			settings.SetToken(null, new XorProtector());

			Assert.Single(settings.Validate());
		}

		[Fact]
		public void Validate_RequiresAnAbsoluteDataFolder()
		{
			AddonSettings settings = Valid();
			settings.DataFolder = "ChartJot";

			Assert.Single(settings.Validate());
		}

		[Theory]
		[InlineData(@"C:\Users\trader\OneDrive\ChartJot")]
		[InlineData(@"C:\Users\trader\OneDrive - Example Corp\ChartJot")]
		[InlineData(@"D:\Dropbox\ChartJot")]
		[InlineData(@"G:\My Drive\ChartJot")]
		[InlineData(@"C:\Users\trader\Google Drive\ChartJot")]
		[InlineData("/home/trader/Dropbox/ChartJot")]
		public void CloudSyncWarning_FlagsFoldersInsideCommonSyncClients(string folder)
		{
			Assert.NotNull(AddonSettings.CloudSyncWarning(folder));
		}

		[Theory]
		[InlineData(@"C:\Users\trader\ChartJot")]
		[InlineData(@"D:\Trading\OneDriveBackupNotes\ChartJot")]
		[InlineData(null)]
		public void CloudSyncWarning_IsNullForOrdinaryFolders(string folder)
		{
			Assert.Null(AddonSettings.CloudSyncWarning(folder));
		}
	}
}
