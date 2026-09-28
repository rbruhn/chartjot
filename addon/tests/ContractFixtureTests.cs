using System.Text.Json;

namespace ChartJot.Core.Tests
{
	public class ContractFixtureTests
	{
		private static string FixtureDirectory()
		{
			string dir = AppContext.BaseDirectory;
			while (dir != null && !File.Exists(Path.Combine(dir, "artisan")))
				dir = Path.GetDirectoryName(dir);
			Assert.True(dir != null, "Could not find the repository root (the folder with artisan).");
			return Path.Combine(dir, "tests", "Fixtures", "addon");
		}

		private static string Pretty(string json)
		{
			using (JsonDocument doc = JsonDocument.Parse(json))
				return JsonSerializer.Serialize(doc.RootElement, new JsonSerializerOptions { WriteIndented = true }) + "\n";
		}

		[Fact]
		public void FixturesAreUpToDate_RegenerateWithPatsWriteFixtures()
		{
			string dir = FixtureDirectory();
			bool write = Environment.GetEnvironmentVariable("PATS_WRITE_FIXTURES") == "1";
			if (write)
				Directory.CreateDirectory(dir);

			foreach (KeyValuePair<string, string> fixture in PayloadFixtures.All())
			{
				string path = Path.Combine(dir, fixture.Key + ".json");
				string expected = Pretty(fixture.Value);
				if (write)
				{
					File.WriteAllText(path, expected);
					continue;
				}

				Assert.True(File.Exists(path), "Missing fixture " + path + ". Run with PATS_WRITE_FIXTURES=1.");
				Assert.Equal(File.ReadAllText(path), expected);
			}
		}

		[Fact]
		public void NoStrayFixturesAreLeftBehind()
		{
			string dir = FixtureDirectory();
			if (!Directory.Exists(dir))
				return;

			string[] expected = PayloadFixtures.All().Keys.Select(k => k + ".json").OrderBy(x => x).ToArray();
			string[] actual = Directory.GetFiles(dir, "*.json").Select(Path.GetFileName).OrderBy(x => x).ToArray();
			Assert.Equal(expected, actual);
		}
	}
}
