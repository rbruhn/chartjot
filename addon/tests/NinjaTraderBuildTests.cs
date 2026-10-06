using System.Reflection;
using System.Reflection.Metadata;
using System.Reflection.PortableExecutable;

namespace ChartJot.Core.Tests
{
	/// <summary>
	/// #88: the net462 ChartJot.Core.dll is what NinjaTrader loads (it runs on NinjaTrader's .NET Framework 4.8 runtime). NinjaScript compiles against the real .NET
	/// Framework runtime assemblies in C:\Windows\Microsoft.NET\Framework64\v4.0.30319, which are all version
	/// 4.0.0.0. A DLL built against newer reference assemblies (System.Net.Http 4.2.0.0 in the net471+ packs, including net48) fails
	/// NinjaTrader's import with CS1705, so every framework reference must stay at 4.0.0.0 or below.
	/// </summary>
	public class NinjaTraderBuildTests
	{
		private static string Net462Dll()
		{
			return typeof(NinjaTraderBuildTests).Assembly.GetCustomAttributes<AssemblyMetadataAttribute>()
				.Single(a => a.Key == "CoreNet462Dll").Value;
		}

		private static IList<AssemblyName> ReferencesOf(string path)
		{
			using (FileStream stream = File.OpenRead(path))
			using (PEReader pe = new PEReader(stream))
			{
				MetadataReader md = pe.GetMetadataReader();
				return md.AssemblyReferences
					.Select(h => md.GetAssemblyReference(h).GetAssemblyName())
					.ToList();
			}
		}

		[Fact]
		public void TheNinjaTraderBuild_TargetsDotNetFramework462()
		{
			Assert.True(File.Exists(Net462Dll()), "net462 build missing: " + Net462Dll());

			Assert.Contains(".NETFramework,Version=v4.6.2", System.Text.Encoding.UTF8.GetString(File.ReadAllBytes(Net462Dll())));
		}

		[Fact]
		public void TheNinjaTraderBuild_ReferencesNoFrameworkAssemblyNewerThanNinjaTradersRuntime()
		{
			Version runtime = new Version(4, 0, 0, 0);

			List<string> tooNew = ReferencesOf(Net462Dll())
				.Where(r => r.Version > runtime)
				.Select(r => r.Name + " " + r.Version)
				.ToList();

			Assert.Empty(tooNew);
		}

		[Fact]
		public void TheNinjaTraderBuild_DoesNotNeedNetstandard()
		{
			Assert.DoesNotContain(ReferencesOf(Net462Dll()), r => r.Name == "netstandard");
		}
	}
}
