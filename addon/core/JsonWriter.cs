using System;
using System.Collections.Generic;
using System.Globalization;
using System.Text;

namespace ChartJot.Core
{
	/// <summary>
	/// A small forward-only JSON writer. It avoids any JSON library, which NinjaScript cannot reference
	/// reliably, and never writes numbers as floating point: decimals are passed in as strings.
	/// </summary>
	public sealed class JsonWriter
	{
		private readonly StringBuilder sb = new StringBuilder();
		private readonly Stack<bool> hasItems = new Stack<bool>();
		private bool afterName;

		public JsonWriter BeginObject()
		{
			BeforeValue();
			sb.Append('{');
			hasItems.Push(false);
			return this;
		}

		public JsonWriter EndObject()
		{
			hasItems.Pop();
			sb.Append('}');
			return this;
		}

		public JsonWriter BeginArray()
		{
			BeforeValue();
			sb.Append('[');
			hasItems.Push(false);
			return this;
		}

		public JsonWriter EndArray()
		{
			hasItems.Pop();
			sb.Append(']');
			return this;
		}

		public JsonWriter Name(string name)
		{
			BeforeItem();
			WriteString(name);
			sb.Append(':');
			afterName = true;
			return this;
		}

		public JsonWriter String(string value)
		{
			if (value == null)
				return Null();
			BeforeValue();
			WriteString(value);
			return this;
		}

		public JsonWriter Int(long value)
		{
			BeforeValue();
			sb.Append(value.ToString(CultureInfo.InvariantCulture));
			return this;
		}

		/// <summary>
		/// A raw JSON number, for state files read back by <see cref="JsonValue"/> (which parses numbers straight
		/// into <c>decimal</c>, never through <c>double</c>). The outgoing API payload uses strings instead
		/// (see <see cref="PayloadBuilder"/>) because that consumer's own JSON reader is not under our control.
		/// </summary>
		public JsonWriter Decimal(decimal value)
		{
			BeforeValue();
			sb.Append(value.ToString(CultureInfo.InvariantCulture));
			return this;
		}

		public JsonWriter Bool(bool value)
		{
			BeforeValue();
			sb.Append(value ? "true" : "false");
			return this;
		}

		public JsonWriter Null()
		{
			BeforeValue();
			sb.Append("null");
			return this;
		}

		// name/value shortcuts
		public JsonWriter Property(string name, string value) { return Name(name).String(value); }
		public JsonWriter Property(string name, long value) { return Name(name).Int(value); }
		public JsonWriter Property(string name, bool value) { return Name(name).Bool(value); }
		public JsonWriter Property(string name, decimal value) { return Name(name).Decimal(value); }

		public override string ToString()
		{
			return sb.ToString();
		}

		private void BeforeValue()
		{
			if (afterName)
			{
				afterName = false;
				return;
			}
			BeforeItem();
		}

		private void BeforeItem()
		{
			if (hasItems.Count == 0)
				return;
			if (hasItems.Pop())
				sb.Append(',');
			hasItems.Push(true);
		}

		private void WriteString(string value)
		{
			sb.Append('"');
			foreach (char c in value)
			{
				switch (c)
				{
					case '"': sb.Append("\\\""); break;
					case '\\': sb.Append("\\\\"); break;
					case '\n': sb.Append("\\n"); break;
					case '\r': sb.Append("\\r"); break;
					case '\t': sb.Append("\\t"); break;
					case '\b': sb.Append("\\b"); break;
					case '\f': sb.Append("\\f"); break;
					default:
						if (c < 0x20)
							sb.Append("\\u").Append(((int)c).ToString("x4", CultureInfo.InvariantCulture));
						else
							sb.Append(c);
						break;
				}
			}
			sb.Append('"');
		}
	}
}
