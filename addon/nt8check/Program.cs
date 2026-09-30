// Dev-only tool: dumps the real public API surface of NinjaTrader 8 types via
// System.Reflection.MetadataLoadContext (reflection-only, never executes NT8 code),
// so addon/nt8 can be written against exact, verified signatures instead of guesses.
// Not part of the shipped product. Usage: dotnet run -- <FullTypeName> [<FullTypeName> ...]

using System;
using System.IO;
using System.Linq;
using System.Reflection;

if (args.Length == 0)
{
	Console.WriteLine("Usage: dotnet run -- <FullTypeName> [<FullTypeName> ...]");
	Console.WriteLine("Example: dotnet run -- NinjaTrader.NinjaScript.AddOnBase NinjaTrader.Cbi.Account");
	return 1;
}

string frameworkDir = "/mnt/c/Windows/Microsoft.NET/Framework64/v4.0.30319";
string wpfDir = Path.Combine(frameworkDir, "WPF");
string nt8Dir = "/mnt/c/Program Files/NinjaTrader 8/bin";

foreach (string dir in new[] { frameworkDir, wpfDir, nt8Dir })
{
	if (!Directory.Exists(dir))
	{
		Console.Error.WriteLine("Missing directory: " + dir);
		return 1;
	}
}

string[] paths = new[] { frameworkDir, wpfDir, nt8Dir }
	.SelectMany(dir => Directory.GetFiles(dir, "*.dll"))
	// When the same file name exists in more than one folder, keep the first (most specific) one.
	.GroupBy(Path.GetFileName)
	.Select(g => g.First())
	.ToArray();

using MetadataLoadContext mlc = new MetadataLoadContext(new PathAssemblyResolver(paths), "mscorlib");

// Load every NinjaTrader.*.dll once, up front: MetadataLoadContext identifies assemblies by name, so
// loading the same one again on a later lookup throws instead of returning the cached instance.
Assembly[] ninjaTraderAssemblies = paths
	.Where(p => Path.GetFileName(p).StartsWith("NinjaTrader.", StringComparison.OrdinalIgnoreCase))
	.Select(p =>
	{
		try { return mlc.LoadFromAssemblyPath(p); }
		catch { return null; } // a few NT8 DLLs are native/mixed-mode and not loadable as metadata-only
	})
	.Where(a => a != null)
	.ToArray();

foreach (string typeName in args)
{
	if (typeName.StartsWith("?", StringComparison.Ordinal))
	{
		string needle = typeName.Substring(1);
		Console.WriteLine("==== search: " + needle + " ====");
		foreach (Assembly a in ninjaTraderAssemblies)
		{
			Type[] found;
			try { found = a.GetTypes(); }
			catch (ReflectionTypeLoadException ex) { found = ex.Types.Where(t => t != null).ToArray(); }
			foreach (Type t in found.Where(t => t.FullName != null && t.FullName.Contains(needle, StringComparison.OrdinalIgnoreCase)))
				Console.WriteLine("  " + t.FullName + "  (" + a.GetName().Name + ")");
		}
		Console.WriteLine();
		continue;
	}

	Type[] matches = ninjaTraderAssemblies.Select(a => a.GetType(typeName, throwOnError: false)).Where(t => t != null).ToArray();
	Console.WriteLine("==== " + typeName + " ====");
	if (matches.Length > 1)
		Console.WriteLine("(defined in " + matches.Length + " assemblies: " + string.Join(", ", matches.Select(t => t.Assembly.GetName().Name)) + " -- showing each)");

	if (matches.Length == 0)
	{
		Console.WriteLine("NOT FOUND in any NinjaTrader.*.dll");
		Console.WriteLine();
		continue;
	}

	foreach (Type type in matches)
		Dump(type);
}

return 0;

void Dump(Type type)
{
	Console.WriteLine("Assembly: " + type.Assembly.GetName().Name);
	Console.WriteLine("BaseType: " + Describe(type.BaseType));
	Console.WriteLine("Interfaces: " + string.Join(", ", type.GetInterfaces().Select(Describe)));
	Console.WriteLine();

	Console.WriteLine("-- Constructors --");
	foreach (ConstructorInfo c in type.GetConstructors(BindingFlags.Public | BindingFlags.Instance))
		Console.WriteLine("  " + type.Name + "(" + string.Join(", ", c.GetParameters().Select(DescribeParam)) + ")");

	Console.WriteLine("-- Properties --");
	foreach (PropertyInfo p in type.GetProperties(BindingFlags.Public | BindingFlags.Instance | BindingFlags.Static).OrderBy(p => p.Name))
		Console.WriteLine("  " + (IsStaticProperty(p) ? "static " : "") + Describe(p.PropertyType) + " " + p.Name
			+ (p.GetIndexParameters().Length > 0 ? "[" + string.Join(", ", p.GetIndexParameters().Select(DescribeParam)) + "]" : ""));

	Console.WriteLine("-- Methods (declared here, including protected -- AddOnBase overrides are protected) --");
	foreach (MethodInfo m in type.GetMethods(BindingFlags.Public | BindingFlags.NonPublic | BindingFlags.Instance | BindingFlags.Static | BindingFlags.DeclaredOnly)
		.Where(m => !m.IsSpecialName && (m.IsPublic || m.IsFamily)).OrderBy(m => m.Name))
		Console.WriteLine("  " + (m.IsFamily ? "protected " : "") + (m.IsStatic ? "static " : "") + Describe(m.ReturnType) + " " + m.Name
			+ "(" + string.Join(", ", m.GetParameters().Select(DescribeParam)) + ")");

	Console.WriteLine("-- Events --");
	foreach (EventInfo e in type.GetEvents(BindingFlags.Public | BindingFlags.Instance | BindingFlags.Static))
		Console.WriteLine("  " + (IsStaticEvent(e) ? "static " : "") + Describe(e.EventHandlerType) + " " + e.Name);

	Console.WriteLine();
}

string Describe(Type t)
{
	if (t == null)
		return "null";
	if (!t.IsGenericType)
		return t.Name;
	return t.Name.Split('`')[0] + "<" + string.Join(", ", t.GetGenericArguments().Select(Describe)) + ">";
}

string DescribeParam(ParameterInfo p)
{
	return Describe(p.ParameterType) + " " + p.Name;
}

bool IsStaticProperty(PropertyInfo p)
{
	MethodInfo m = p.GetMethod ?? p.SetMethod;
	return m != null && m.IsStatic;
}

bool IsStaticEvent(EventInfo e)
{
	MethodInfo m = e.AddMethod;
	return m != null && m.IsStatic;
}
