using System;
using System.Collections.Generic;
using System.Globalization;
using System.Text;

namespace ChartJot.Core
{
	public enum JsonKind
	{
		Null,
		Bool,
		Number,
		String,
		Array,
		Object
	}

	/// <summary>
	/// A small forward-only JSON reader, the counterpart to <see cref="JsonWriter"/>. Parses into an immutable
	/// tree so state-file loading can walk it without a JSON library, which NinjaScript cannot reference reliably.
	/// </summary>
	public sealed class JsonValue
	{
		public static readonly JsonValue Null = new JsonValue(JsonKind.Null);

		public JsonKind Kind { get; private set; }

		private readonly string str;
		private readonly decimal number;
		private readonly bool boolean;
		private readonly List<JsonValue> items;
		private readonly Dictionary<string, JsonValue> members;

		private JsonValue(JsonKind kind) { Kind = kind; }
		private JsonValue(string value) { Kind = JsonKind.String; str = value; }
		private JsonValue(decimal value) { Kind = JsonKind.Number; number = value; }
		private JsonValue(bool value) { Kind = JsonKind.Bool; boolean = value; }
		private JsonValue(List<JsonValue> value) { Kind = JsonKind.Array; items = value; }
		private JsonValue(Dictionary<string, JsonValue> value) { Kind = JsonKind.Object; members = value; }

		public static JsonValue Parse(string json)
		{
			if (json == null)
				throw new ArgumentNullException("json");

			Parser parser = new Parser(json);
			parser.SkipWhitespace();
			JsonValue value = parser.ParseValue();
			parser.SkipWhitespace();
			if (!parser.AtEnd)
				throw parser.Error("Unexpected trailing content after the JSON value.");
			return value;
		}

		public bool IsNull { get { return Kind == JsonKind.Null; } }

		public string AsString()
		{
			if (Kind == JsonKind.Null)
				return null;
			if (Kind != JsonKind.String)
				throw new InvalidOperationException("Expected a JSON string, found " + Kind + ".");
			return str;
		}

		public decimal AsDecimal()
		{
			if (Kind != JsonKind.Number)
				throw new InvalidOperationException("Expected a JSON number, found " + Kind + ".");
			return number;
		}

		public long AsInt64()
		{
			return (long)AsDecimal();
		}

		public int AsInt32()
		{
			return (int)AsDecimal();
		}

		public bool AsBool()
		{
			if (Kind != JsonKind.Bool)
				throw new InvalidOperationException("Expected a JSON bool, found " + Kind + ".");
			return boolean;
		}

		/// <summary>Property lookup on an object. Missing names return <see cref="Null"/> rather than throwing,
		/// so callers can read optional state-file fields without a null check at every step.</summary>
		public JsonValue this[string name]
		{
			get
			{
				if (Kind != JsonKind.Object)
					throw new InvalidOperationException("Expected a JSON object, found " + Kind + ".");
				JsonValue value;
				return members.TryGetValue(name, out value) ? value : Null;
			}
		}

		public bool Has(string name)
		{
			return Kind == JsonKind.Object && members.ContainsKey(name);
		}

		public JsonValue this[int index]
		{
			get
			{
				if (Kind != JsonKind.Array)
					throw new InvalidOperationException("Expected a JSON array, found " + Kind + ".");
				return items[index];
			}
		}

		public int Count
		{
			get
			{
				if (Kind == JsonKind.Array)
					return items.Count;
				if (Kind == JsonKind.Object)
					return members.Count;
				throw new InvalidOperationException("Expected a JSON array or object, found " + Kind + ".");
			}
		}

		public IEnumerable<JsonValue> Items
		{
			get
			{
				if (Kind != JsonKind.Array)
					throw new InvalidOperationException("Expected a JSON array, found " + Kind + ".");
				return items;
			}
		}

		private sealed class Parser
		{
			private readonly string s;
			private int pos;

			public Parser(string s)
			{
				this.s = s;
			}

			public bool AtEnd { get { return pos >= s.Length; } }

			public JsonValue ParseValue()
			{
				if (AtEnd)
					throw Error("Unexpected end of JSON.");

				char c = s[pos];
				switch (c)
				{
					case '{': return ParseObject();
					case '[': return ParseArray();
					case '"': return new JsonValue(ParseStringLiteral());
					case 't': Expect("true"); return new JsonValue(true);
					case 'f': Expect("false"); return new JsonValue(false);
					case 'n': Expect("null"); return Null;
					default:
						if (c == '-' || (c >= '0' && c <= '9'))
							return new JsonValue(ParseNumber());
						throw Error("Unexpected character '" + c + "'.");
				}
			}

			private JsonValue ParseObject()
			{
				pos++; // '{'
				Dictionary<string, JsonValue> members = new Dictionary<string, JsonValue>();
				SkipWhitespace();
				if (!AtEnd && s[pos] == '}')
				{
					pos++;
					return new JsonValue(members);
				}

				while (true)
				{
					SkipWhitespace();
					if (AtEnd || s[pos] != '"')
						throw Error("Expected a property name.");
					string name = ParseStringLiteral();
					SkipWhitespace();
					if (AtEnd || s[pos] != ':')
						throw Error("Expected ':' after a property name.");
					pos++;
					SkipWhitespace();
					members[name] = ParseValue();
					SkipWhitespace();
					if (AtEnd)
						throw Error("Unterminated object.");
					if (s[pos] == ',')
					{
						pos++;
						continue;
					}
					if (s[pos] == '}')
					{
						pos++;
						break;
					}
					throw Error("Expected ',' or '}' in object.");
				}
				return new JsonValue(members);
			}

			private JsonValue ParseArray()
			{
				pos++; // '['
				List<JsonValue> items = new List<JsonValue>();
				SkipWhitespace();
				if (!AtEnd && s[pos] == ']')
				{
					pos++;
					return new JsonValue(items);
				}

				while (true)
				{
					SkipWhitespace();
					items.Add(ParseValue());
					SkipWhitespace();
					if (AtEnd)
						throw Error("Unterminated array.");
					if (s[pos] == ',')
					{
						pos++;
						continue;
					}
					if (s[pos] == ']')
					{
						pos++;
						break;
					}
					throw Error("Expected ',' or ']' in array.");
				}
				return new JsonValue(items);
			}

			private string ParseStringLiteral()
			{
				pos++; // opening quote
				StringBuilder sb = new StringBuilder();
				while (true)
				{
					if (AtEnd)
						throw Error("Unterminated string.");
					char c = s[pos++];
					if (c == '"')
						return sb.ToString();
					if (c != '\\')
					{
						sb.Append(c);
						continue;
					}

					if (AtEnd)
						throw Error("Unterminated escape sequence.");
					char esc = s[pos++];
					switch (esc)
					{
						case '"': sb.Append('"'); break;
						case '\\': sb.Append('\\'); break;
						case '/': sb.Append('/'); break;
						case 'n': sb.Append('\n'); break;
						case 'r': sb.Append('\r'); break;
						case 't': sb.Append('\t'); break;
						case 'b': sb.Append('\b'); break;
						case 'f': sb.Append('\f'); break;
						case 'u':
							if (pos + 4 > s.Length)
								throw Error("Truncated \\u escape.");
							string hex = s.Substring(pos, 4);
							int code;
							if (!int.TryParse(hex, NumberStyles.AllowHexSpecifier, CultureInfo.InvariantCulture, out code))
								throw Error("Invalid \\u escape '" + hex + "'.");
							sb.Append((char)code);
							pos += 4;
							break;
						default:
							throw Error("Unknown escape '\\" + esc + "'.");
					}
				}
			}

			private decimal ParseNumber()
			{
				int start = pos;
				if (!AtEnd && s[pos] == '-')
					pos++;
				while (!AtEnd && s[pos] >= '0' && s[pos] <= '9')
					pos++;
				if (!AtEnd && s[pos] == '.')
				{
					pos++;
					while (!AtEnd && s[pos] >= '0' && s[pos] <= '9')
						pos++;
				}
				if (!AtEnd && (s[pos] == 'e' || s[pos] == 'E'))
				{
					pos++;
					if (!AtEnd && (s[pos] == '+' || s[pos] == '-'))
						pos++;
					while (!AtEnd && s[pos] >= '0' && s[pos] <= '9')
						pos++;
				}

				string token = s.Substring(start, pos - start);
				decimal value;
				if (!decimal.TryParse(token, NumberStyles.Float | NumberStyles.AllowLeadingSign, CultureInfo.InvariantCulture, out value))
					throw Error("Invalid number '" + token + "'.");
				return value;
			}

			private void Expect(string literal)
			{
				if (pos + literal.Length > s.Length || string.CompareOrdinal(s, pos, literal, 0, literal.Length) != 0)
					throw Error("Expected '" + literal + "'.");
				pos += literal.Length;
			}

			public void SkipWhitespace()
			{
				while (!AtEnd && (s[pos] == ' ' || s[pos] == '\t' || s[pos] == '\n' || s[pos] == '\r'))
					pos++;
			}

			public FormatException Error(string message)
			{
				return new FormatException(message + " (at position " + pos + ")");
			}
		}
	}
}
