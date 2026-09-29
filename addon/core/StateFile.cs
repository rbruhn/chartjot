using System;
using System.IO;
using System.Text;

namespace ChartJot.Core
{
	/// <summary>
	/// Durable save/load for the AddOn's state text (NT8.md: "Never discard unsent payloads merely because NT8 is
	/// closed or restarted"). Writes to a uniquely-named temp file first and only then replaces the target, so a
	/// crash or power loss mid-write leaves the previous, still-valid file in place instead of a truncated one.
	/// </summary>
	public static class StateFile
	{
		private static readonly UTF8Encoding Utf8NoBom = new UTF8Encoding(false);

		public static void WriteAtomic(string path, string contents)
		{
			if (string.IsNullOrEmpty(path))
				throw new ArgumentException("A file path is required.", "path");
			if (contents == null)
				throw new ArgumentNullException("contents");

			string directory = Path.GetDirectoryName(path);
			if (!string.IsNullOrEmpty(directory))
				Directory.CreateDirectory(directory);

			string tempPath = path + "." + Guid.NewGuid().ToString("N") + ".tmp";
			File.WriteAllText(tempPath, contents, Utf8NoBom);

			if (File.Exists(path))
				File.Delete(path);
			File.Move(tempPath, path);
		}

		/// <summary>Null when the file does not exist yet (first run, or nothing has been persisted).</summary>
		public static string Read(string path)
		{
			if (string.IsNullOrEmpty(path))
				throw new ArgumentException("A file path is required.", "path");

			return File.Exists(path) ? File.ReadAllText(path, Utf8NoBom) : null;
		}
	}
}
