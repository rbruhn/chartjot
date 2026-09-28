using System.Security.Cryptography;
using System.Text;

namespace ChartJot.Core
{
	public static class TradeIds
	{
		/// <summary>
		/// NT8-{account}-{instrument}-{hash}. The hash is the first 16 lowercase hex characters of
		/// SHA-256("{account}|{instrument full name}|{first entry ExecutionId}") using the raw names, so the
		/// same trade gets the same ID after a restart or replay.
		/// </summary>
		public static string Create(string account, string instrumentFullName, string firstEntryExecutionId)
		{
			string raw = account + "|" + instrumentFullName + "|" + firstEntryExecutionId;
			byte[] hash;
			using (SHA256 sha = SHA256.Create())
				hash = sha.ComputeHash(Encoding.UTF8.GetBytes(raw));

			StringBuilder hex = new StringBuilder(16);
			for (int i = 0; i < 8; i++)
				hex.Append(hash[i].ToString("x2"));

			return "NT8-" + Sanitize(account) + "-" + Sanitize(instrumentFullName) + "-" + hex;
		}

		/// <summary>Every character outside [A-Za-z0-9] becomes an underscore.</summary>
		public static string Sanitize(string value)
		{
			StringBuilder sb = new StringBuilder((value ?? "").Length);
			foreach (char c in value ?? "")
			{
				bool ok = (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') || (c >= '0' && c <= '9');
				sb.Append(ok ? c : '_');
			}
			return sb.ToString();
		}
	}
}
