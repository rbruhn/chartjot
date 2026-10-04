using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Text;

namespace ChartJot.Core
{
	/// <summary>
	/// Encrypts the intake token at rest. The NT8 layer implements it with Windows DPAPI (CurrentUser scope, NT8.md
	/// "Local storage and secrets"); core stays free of platform APIs so it can be unit tested anywhere.
	/// </summary>
	public interface ITokenProtector
	{
		byte[] Protect(byte[] plain);
		byte[] Unprotect(byte[] protectedBytes);
	}

	/// <summary>
	/// The AddOn settings needed to submit trades (NT8.md, "Configuration"): the journal endpoint, the intake token
	/// and the data folder. The token is only ever held here in protected form; <see cref="Serialize"/> writes that
	/// form and nothing else.
	/// </summary>
	public sealed class AddonSettings
	{
		private const int Version = 1;
		private const string FolderName = "ChartJot";

		private static readonly UTF8Encoding Utf8 = new UTF8Encoding(false);

		// Folder names (one path segment, case-insensitive) that sync clients own. "OneDrive - <org>" is the
		// business-account form.
		private static readonly string[] SyncFolders = { "OneDrive", "Dropbox", "Google Drive", "My Drive", "iCloudDrive", "iCloud Drive" };

		private string protectedToken;

		public string EndpointUrl { get; set; }

		/// <summary>Where the state file (and later the screenshots) live. Defaults to <c>%USERPROFILE%\ChartJot</c>.</summary>
		public string DataFolder { get; set; }

		public string StatePath
		{
			get { return Path.Combine(DataFolder, "state.json"); }
		}

		public bool HasToken
		{
			get { return protectedToken != null; }
		}

		public static AddonSettings Defaults(string userProfile)
		{
			return new AddonSettings { DataFolder = Path.Combine(userProfile, FolderName) };
		}

		/// <summary>
		/// The settings file itself always lives at <c>%USERPROFILE%\ChartJot\settings.json</c>, whatever the data
		/// folder is: it is what says where the data folder is.
		/// </summary>
		public static string SettingsFilePath(string userProfile)
		{
			return Path.Combine(userProfile, FolderName, "settings.json");
		}

		/// <summary>Stores <paramref name="token"/> protected. Blank clears it.</summary>
		public void SetToken(string token, ITokenProtector protector)
		{
			if (protector == null)
				throw new ArgumentNullException("protector");

			protectedToken = string.IsNullOrWhiteSpace(token)
				? null
				: Convert.ToBase64String(protector.Protect(Utf8.GetBytes(token.Trim())));
		}

		/// <summary>The plain token for the Authorization header, or null when none is set.</summary>
		public string GetToken(ITokenProtector protector)
		{
			if (protector == null)
				throw new ArgumentNullException("protector");

			return protectedToken == null ? null : Utf8.GetString(protector.Unprotect(Convert.FromBase64String(protectedToken)));
		}

		/// <summary>Every reason these settings cannot be used to submit; empty when they can.</summary>
		public IList<string> Validate()
		{
			List<string> errors = new List<string>();

			Uri uri;
			if (string.IsNullOrWhiteSpace(EndpointUrl))
				errors.Add("The journal endpoint URL is required.");
			else if (!Uri.TryCreate(EndpointUrl.Trim(), UriKind.Absolute, out uri) || uri.Scheme != Uri.UriSchemeHttps)
				errors.Add("The journal endpoint must be an absolute https:// URL.");
			else if (!string.IsNullOrEmpty(uri.Query) || !string.IsNullOrEmpty(uri.UserInfo))
				errors.Add("The journal endpoint must not contain a query string or credentials; the token goes in the token field.");

			if (!HasToken)
				errors.Add("The journal intake token is required.");

			if (string.IsNullOrWhiteSpace(DataFolder) || !Path.IsPathRooted(DataFolder))
				errors.Add("The data folder must be a full path.");

			return errors;
		}

		/// <summary>
		/// A warning when <paramref name="folder"/> is inside a cloud-sync folder (OneDrive, Dropbox, Google Drive, ...),
		/// where a sync client would fight the AddOn over file locks; null otherwise. Warn only, never block.
		/// </summary>
		public static string CloudSyncWarning(string folder)
		{
			if (string.IsNullOrWhiteSpace(folder))
				return null;

			foreach (string segment in folder.Split(new[] { '\\', '/' }, StringSplitOptions.RemoveEmptyEntries))
			{
				string match = SyncFolders.FirstOrDefault(s =>
					string.Equals(segment, s, StringComparison.OrdinalIgnoreCase)
					|| segment.StartsWith(s + " - ", StringComparison.OrdinalIgnoreCase));
				if (match != null)
					return "This folder is inside " + match + ". A sync client can lock the AddOn's files while it writes them; a folder outside it is safer.";
			}
			return null;
		}

		public string Serialize()
		{
			JsonWriter w = new JsonWriter();
			w.BeginObject();
			w.Property("version", Version);
			w.Property("endpoint_url", EndpointUrl);
			w.Property("data_folder", DataFolder);
			w.Property("protected_token", protectedToken);
			w.EndObject();
			return w.ToString();
		}

		public static AddonSettings Deserialize(string json)
		{
			JsonValue root = JsonValue.Parse(json);
			if (!root.Has("version") || root["version"].AsInt32() != Version)
				throw new FormatException("Unsupported settings file version.");

			return new AddonSettings
			{
				EndpointUrl = root["endpoint_url"].AsString(),
				DataFolder = root["data_folder"].AsString(),
				protectedToken = root["protected_token"].AsString()
			};
		}

		/// <summary>Loads the settings file, or the defaults when there is none yet.</summary>
		public static AddonSettings Load(string userProfile)
		{
			string json = StateFile.Read(SettingsFilePath(userProfile));
			return json == null ? Defaults(userProfile) : Deserialize(json);
		}

		public void Save(string userProfile)
		{
			StateFile.WriteAtomic(SettingsFilePath(userProfile), Serialize());
		}
	}
}
