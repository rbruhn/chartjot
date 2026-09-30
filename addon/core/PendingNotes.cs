using System;
using System.Collections.Generic;
using System.Globalization;

namespace ChartJot.Core
{
	/// <summary>
	/// Notes written while flat, waiting for the next trade to open on that account/instrument (NT8.md: "While
	/// flat with no staged trade, a saved note is a pending pre_trade note for the next qualifying trade").
	/// </summary>
	public sealed class PendingNotes
	{
		private sealed class Bucket
		{
			public string Account;
			public string InstrumentFullName;
			public readonly List<NoteRecord> Notes = new List<NoteRecord>();
		}

		private readonly Dictionary<string, Bucket> buckets = new Dictionary<string, Bucket>();

		private static string Key(string account, string instrumentFullName)
		{
			return account + "|" + instrumentFullName;
		}

		public void Add(string account, string instrumentFullName, NoteRecord note)
		{
			if (note == null)
				throw new ArgumentNullException("note");

			string key = Key(account, instrumentFullName);
			Bucket bucket;
			if (!buckets.TryGetValue(key, out bucket))
			{
				bucket = new Bucket { Account = account, InstrumentFullName = instrumentFullName };
				buckets[key] = bucket;
			}
			bucket.Notes.Add(note);
		}

		/// <summary>The pending notes for an account/instrument, oldest first. Empty, never null, when there are none.</summary>
		public IList<NoteRecord> For(string account, string instrumentFullName)
		{
			Bucket bucket;
			return buckets.TryGetValue(Key(account, instrumentFullName), out bucket)
				? new List<NoteRecord>(bucket.Notes)
				: new List<NoteRecord>();
		}

		/// <summary>Removes and returns the pending notes for an account/instrument. Call this when a trade opens
		/// there: those notes become that trade's pre_trade notes and no longer belong in this buffer.</summary>
		public IList<NoteRecord> Take(string account, string instrumentFullName)
		{
			string key = Key(account, instrumentFullName);
			Bucket bucket;
			if (!buckets.TryGetValue(key, out bucket))
				return new List<NoteRecord>();
			buckets.Remove(key);
			return bucket.Notes;
		}

		public bool IsEmpty { get { return buckets.Count == 0; } }

		public string Serialize()
		{
			JsonWriter w = new JsonWriter();
			w.BeginArray();
			foreach (Bucket bucket in buckets.Values)
			{
				w.BeginObject();
				w.Property("account", bucket.Account);
				w.Property("instrument_full_name", bucket.InstrumentFullName);
				w.Name("notes").BeginArray();
				foreach (NoteRecord note in bucket.Notes)
				{
					w.BeginObject();
					w.Property("body", note.Body);
					w.Property("phase", note.Phase);
					w.Property("occurred_at", PayloadBuilder.Timestamp(note.OccurredAt));
					w.EndObject();
				}
				w.EndArray();
				w.EndObject();
			}
			w.EndArray();
			return w.ToString();
		}

		public static PendingNotes Deserialize(string json)
		{
			return FromJson(JsonValue.Parse(json));
		}

		internal static PendingNotes FromJson(JsonValue root)
		{
			PendingNotes pending = new PendingNotes();

			foreach (JsonValue bucket in root.Items)
			{
				string account = bucket["account"].AsString();
				string instrument = bucket["instrument_full_name"].AsString();
				foreach (JsonValue note in bucket["notes"].Items)
				{
					pending.Add(account, instrument, new NoteRecord
					{
						Body = note["body"].AsString(),
						Phase = note["phase"].AsString(),
						OccurredAt = DateTimeOffset.Parse(note["occurred_at"].AsString(), CultureInfo.InvariantCulture, DateTimeStyles.None)
					});
				}
			}

			return pending;
		}
	}
}
