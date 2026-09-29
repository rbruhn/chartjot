using ChartJot.Core;

namespace ChartJot.Core.Tests
{
	public class JsonReaderTests
	{
		[Fact]
		public void Parse_Primitives()
		{
			Assert.Equal(JsonKind.Null, JsonValue.Parse("null").Kind);
			Assert.True(JsonValue.Parse("true").AsBool());
			Assert.False(JsonValue.Parse("false").AsBool());
			Assert.Equal(42m, JsonValue.Parse("42").AsDecimal());
			Assert.Equal(-3.5m, JsonValue.Parse("-3.5").AsDecimal());
			Assert.Equal(150m, JsonValue.Parse("1.5e2").AsDecimal());
			Assert.Equal("hi", JsonValue.Parse("\"hi\"").AsString());
		}

		[Fact]
		public void Parse_StringEscapes()
		{
			JsonValue v = JsonValue.Parse("\"line1\\nline2\\t\\\"q\\\"\\u0041\"");
			Assert.Equal("line1\nline2\t\"q\"A", v.AsString());
		}

		[Fact]
		public void Parse_NestedObjectAndArray()
		{
			JsonValue v = JsonValue.Parse("{\"a\":1,\"b\":[1,2,3],\"c\":{\"d\":\"e\"},\"f\":null}");

			Assert.Equal(JsonKind.Object, v.Kind);
			Assert.Equal(1m, v["a"].AsDecimal());
			Assert.Equal(3, v["b"].Count);
			Assert.Equal(2m, v["b"][1].AsDecimal());
			Assert.Equal("e", v["c"]["d"].AsString());
			Assert.True(v["f"].IsNull);
			Assert.False(v.Has("missing"));
			Assert.True(v["missing"].IsNull);
		}

		[Fact]
		public void Parse_EmptyObjectAndArray()
		{
			JsonValue obj = JsonValue.Parse("{}");
			JsonValue arr = JsonValue.Parse("[]");

			Assert.Equal(0, obj.Count);
			Assert.Equal(0, arr.Count);
		}

		[Fact]
		public void Parse_WhitespaceAroundValue_IsIgnored()
		{
			JsonValue v = JsonValue.Parse("  \n\t { \"a\" : 1 }  \n");

			Assert.Equal(1m, v["a"].AsDecimal());
		}

		[Fact]
		public void Parse_RoundTripsJsonWriterOutput()
		{
			JsonWriter w = new JsonWriter();
			w.BeginObject();
			w.Property("name", "trade \"one\"\n2");
			w.Property("count", 3);
			w.Property("active", true);
			w.Property("missing", (string)null);
			w.Name("items").BeginArray();
			w.String("x");
			w.Int(5);
			w.Bool(false);
			w.EndArray();
			w.EndObject();

			JsonValue v = JsonValue.Parse(w.ToString());

			Assert.Equal("trade \"one\"\n2", v["name"].AsString());
			Assert.Equal(3, v["count"].AsInt32());
			Assert.True(v["active"].AsBool());
			Assert.True(v["missing"].IsNull);
			Assert.Equal("x", v["items"][0].AsString());
			Assert.Equal(5, v["items"][1].AsInt32());
			Assert.False(v["items"][2].AsBool());
		}

		[Theory]
		[InlineData("")]
		[InlineData("{")]
		[InlineData("{\"a\":1")]
		[InlineData("[1,2")]
		[InlineData("{\"a\" 1}")]
		[InlineData("nul")]
		[InlineData("\"unterminated")]
		[InlineData("{\"a\":1} trailing")]
		public void Parse_MalformedInput_Throws(string json)
		{
			Assert.ThrowsAny<FormatException>(() => JsonValue.Parse(json));
		}

		[Fact]
		public void AsString_OnWrongKind_Throws()
		{
			JsonValue v = JsonValue.Parse("42");
			Assert.Throws<InvalidOperationException>(() => v.AsString());
		}

		[Fact]
		public void Indexer_OnNonObject_Throws()
		{
			JsonValue v = JsonValue.Parse("[1,2]");
			Assert.Throws<InvalidOperationException>(() => v["a"].ToString());
		}
	}
}
