using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;

namespace ChartJot.Core
{
	/// <summary>
	/// A closed round-turn trade waiting in the trader's review list: "Ready to review" through the moment they
	/// click Submit. Everything here is still editable (notes, trade type, the screenshot) up to that point, which
	/// is why it is a separate type from the frozen JSON <see cref="PayloadBuilder"/> produces on submission.
	/// </summary>
	public sealed class StagedTrade
	{
		public CompletedTrade Trade { get; set; }
		public IList<NoteRecord> Notes { get; set; }

		/// <summary>Null until the trader picks one; required before Submit (see <see cref="PayloadBuilder"/>).</summary>
		public string TradeType { get; set; }
		public string TradeTypeOther { get; set; }

		/// <summary>Null when capture failed or is disabled; that must never block submission.</summary>
		public ScreenshotMeta Screenshot { get; set; }

		/// <summary>Null when the copier is off or this account is not a journaled master.</summary>
		public CopyEvaluation Copies { get; set; }
	}

	/// <summary>Trades awaiting review, keyed by trade_id, with the same save/load round trip as
	/// <see cref="DeliveryQueue"/> and <see cref="PendingNotes"/>.</summary>
	public sealed class StagedTrades
	{
		private readonly Dictionary<string, StagedTrade> byTradeId = new Dictionary<string, StagedTrade>();
		private readonly List<string> order = new List<string>();

		public void Add(StagedTrade trade)
		{
			if (trade == null)
				throw new ArgumentNullException("trade");
			if (trade.Trade == null || string.IsNullOrEmpty(trade.Trade.TradeId))
				throw new ArgumentException("A staged trade needs a completed trade with a trade_id.", "trade");

			if (!byTradeId.ContainsKey(trade.Trade.TradeId))
				order.Add(trade.Trade.TradeId);
			byTradeId[trade.Trade.TradeId] = trade;
		}

		public StagedTrade Find(string tradeId)
		{
			StagedTrade trade;
			return byTradeId.TryGetValue(tradeId, out trade) ? trade : null;
		}

		public void Remove(string tradeId)
		{
			if (byTradeId.Remove(tradeId))
				order.Remove(tradeId);
		}

		public IList<StagedTrade> All
		{
			get { return order.Select(id => byTradeId[id]).ToList(); }
		}

		public string Serialize()
		{
			JsonWriter w = new JsonWriter();
			w.BeginArray();
			foreach (string tradeId in order)
			{
				WriteStagedTrade(w, byTradeId[tradeId]);
			}
			w.EndArray();
			return w.ToString();
		}

		public static StagedTrades Deserialize(string json)
		{
			return FromJson(JsonValue.Parse(json));
		}

		internal static StagedTrades FromJson(JsonValue root)
		{
			StagedTrades staged = new StagedTrades();
			foreach (JsonValue item in root.Items)
				staged.Add(ReadStagedTrade(item));
			return staged;
		}

		// ---- staged trade ----

		private static void WriteStagedTrade(JsonWriter w, StagedTrade s)
		{
			w.BeginObject();
			w.Name("trade");
			WriteCompletedTrade(w, s.Trade);

			w.Name("notes").BeginArray();
			foreach (NoteRecord note in s.Notes ?? new List<NoteRecord>())
				WriteNote(w, note);
			w.EndArray();

			w.Property("trade_type", s.TradeType);
			w.Property("trade_type_other", s.TradeTypeOther);

			if (s.Screenshot == null)
				w.Name("screenshot").Null();
			else
				WriteScreenshot(w, s.Screenshot);

			if (s.Copies == null)
				w.Name("copies").Null();
			else
				WriteCopyEvaluation(w, s.Copies);

			w.EndObject();
		}

		private static StagedTrade ReadStagedTrade(JsonValue v)
		{
			return new StagedTrade
			{
				Trade = ReadCompletedTrade(v["trade"]),
				Notes = v["notes"].Items.Select(ReadNote).ToList(),
				TradeType = v["trade_type"].AsString(),
				TradeTypeOther = v["trade_type_other"].AsString(),
				Screenshot = v["screenshot"].IsNull ? null : ReadScreenshot(v["screenshot"]),
				Copies = v["copies"].IsNull ? null : ReadCopyEvaluation(v["copies"])
			};
		}

		// ---- CompletedTrade ----

		internal static void WriteCompletedTrade(JsonWriter w, CompletedTrade t)
		{
			w.BeginObject();
			w.Property("trade_id", t.TradeId);
			w.Property("account", t.Account);
			w.Name("instrument");
			WriteInstrument(w, t.Instrument);
			w.Property("direction", t.Direction.ToString());
			w.Property("quantity", t.Quantity);
			w.Property("total_entry_quantity", t.TotalEntryQuantity);
			w.Property("entry_at", Timestamp(t.EntryAt));
			w.Property("exit_at", Timestamp(t.ExitAt));
			w.Property("average_entry_price", t.AverageEntryPrice);
			w.Property("average_exit_price", t.AverageExitPrice);
			w.Property("entry_order_name", t.EntryOrderName);
			w.Property("exit_order_name", t.ExitOrderName);
			w.Property("exit_reason", t.ExitReason);
			w.Property("points", t.Points);
			w.Property("ticks", t.Ticks);
			w.Property("gross_pnl", t.GrossPnl);
			w.Property("commission", t.Commission);
			w.Name("fees");
			if (t.Fees.HasValue) w.Decimal(t.Fees.Value); else w.Null();
			w.Property("net_pnl", t.NetPnl);

			if (t.Excursion == null)
			{
				w.Name("excursion").Null();
			}
			else
			{
				w.Name("excursion").BeginObject();
				w.Property("mae_points", t.Excursion.MaePoints);
				w.Property("mfe_points", t.Excursion.MfePoints);
				w.Property("max_adverse_price", t.Excursion.MaxAdversePrice);
				w.Property("max_favorable_price", t.Excursion.MaxFavorablePrice);
				w.EndObject();
			}
			w.Property("excursion_complete", t.ExcursionComplete);
			w.Property("opened_by_reversal", t.OpenedByReversal);

			w.Name("legs").BeginArray();
			foreach (Leg leg in t.Legs ?? new List<Leg>())
				WriteLeg(w, leg);
			w.EndArray();

			w.Name("fills").BeginArray();
			foreach (TradeFill fill in t.Fills ?? new List<TradeFill>())
				WriteTradeFill(w, fill);
			w.EndArray();

			w.EndObject();
		}

		internal static CompletedTrade ReadCompletedTrade(JsonValue v)
		{
			CompletedTrade trade = new CompletedTrade
			{
				TradeId = v["trade_id"].AsString(),
				Account = v["account"].AsString(),
				Instrument = ReadInstrument(v["instrument"]),
				Direction = ParseEnum<Direction>(v["direction"].AsString()),
				Quantity = v["quantity"].AsInt32(),
				TotalEntryQuantity = v["total_entry_quantity"].AsInt32(),
				EntryAt = ParseTimestamp(v["entry_at"]).Value,
				ExitAt = ParseTimestamp(v["exit_at"]).Value,
				AverageEntryPrice = v["average_entry_price"].AsDecimal(),
				AverageExitPrice = v["average_exit_price"].AsDecimal(),
				EntryOrderName = v["entry_order_name"].AsString(),
				ExitOrderName = v["exit_order_name"].AsString(),
				ExitReason = v["exit_reason"].AsString(),
				Points = v["points"].AsDecimal(),
				Ticks = v["ticks"].AsInt32(),
				GrossPnl = v["gross_pnl"].AsDecimal(),
				Commission = v["commission"].AsDecimal(),
				Fees = v["fees"].IsNull ? (decimal?)null : v["fees"].AsDecimal(),
				NetPnl = v["net_pnl"].AsDecimal(),
				Excursion = v["excursion"].IsNull ? null : new ExcursionResult
				{
					MaePoints = v["excursion"]["mae_points"].AsDecimal(),
					MfePoints = v["excursion"]["mfe_points"].AsDecimal(),
					MaxAdversePrice = v["excursion"]["max_adverse_price"].AsDecimal(),
					MaxFavorablePrice = v["excursion"]["max_favorable_price"].AsDecimal()
				},
				ExcursionComplete = v["excursion_complete"].AsBool(),
				Legs = v["legs"].Items.Select(ReadLeg).ToList(),
				Fills = v["fills"].Items.Select(ReadTradeFill).ToList()
			};

			// Files written before the flag existed: a reversal's new trade is the only one whose first entry fill
			// is split (only the remainder of the flipping fill is allocated to it), so the fills still tell.
			trade.OpenedByReversal = v.Has("opened_by_reversal")
				? v["opened_by_reversal"].AsBool()
				: trade.Fills.Where(f => f.Role == FillRole.Entry).Take(1).Any(f => f.AllocatedQuantity < f.Fill.Quantity);
			return trade;
		}

		// ---- Leg ----

		private static void WriteLeg(JsonWriter w, Leg leg)
		{
			w.BeginObject();
			w.Property("sequence", leg.Sequence);
			w.Property("runner", leg.Runner);
			w.Property("exit_order_id", leg.ExitOrderId);
			w.Property("order_name", leg.OrderName);
			w.Property("reason", leg.Reason);
			w.Property("quantity", leg.Quantity);
			w.Property("exited_at", Timestamp(leg.ExitedAt));
			w.Property("average_exit_price", leg.AverageExitPrice);
			w.Property("points", leg.Points);
			w.Property("gross_pnl", leg.GrossPnl);
			w.Name("mae_points");
			if (leg.MaePoints.HasValue) w.Decimal(leg.MaePoints.Value); else w.Null();
			w.Name("mfe_points");
			if (leg.MfePoints.HasValue) w.Decimal(leg.MfePoints.Value); else w.Null();
			w.EndObject();
		}

		private static Leg ReadLeg(JsonValue v)
		{
			return new Leg
			{
				Sequence = v["sequence"].AsInt32(),
				Runner = v["runner"].AsBool(),
				ExitOrderId = v["exit_order_id"].AsString(),
				OrderName = v["order_name"].AsString(),
				Reason = v["reason"].AsString(),
				Quantity = v["quantity"].AsInt32(),
				ExitedAt = ParseTimestamp(v["exited_at"]).Value,
				AverageExitPrice = v["average_exit_price"].AsDecimal(),
				Points = v["points"].AsDecimal(),
				GrossPnl = v["gross_pnl"].AsDecimal(),
				MaePoints = v["mae_points"].IsNull ? (decimal?)null : v["mae_points"].AsDecimal(),
				MfePoints = v["mfe_points"].IsNull ? (decimal?)null : v["mfe_points"].AsDecimal()
			};
		}

		// ---- TradeFill / Fill ----

		private static void WriteTradeFill(JsonWriter w, TradeFill tf)
		{
			w.BeginObject();
			w.Name("fill");
			WriteFill(w, tf.Fill);
			w.Property("role", tf.Role.ToString());
			w.Property("allocated_quantity", tf.AllocatedQuantity);
			w.Property("commission", tf.Commission);
			w.Property("fee", tf.Fee);
			w.Property("position_after", tf.PositionAfter);
			w.Name("excursion").BeginObject();
			w.Property("high", tf.Excursion.High);
			w.Property("low", tf.Excursion.Low);
			w.Property("had_ticks", tf.Excursion.HadTicks);
			w.EndObject();
			w.EndObject();
		}

		private static TradeFill ReadTradeFill(JsonValue v)
		{
			JsonValue e = v["excursion"];
			return new TradeFill
			{
				Fill = ReadFill(v["fill"]),
				Role = ParseEnum<FillRole>(v["role"].AsString()),
				AllocatedQuantity = v["allocated_quantity"].AsInt32(),
				Commission = v["commission"].AsDecimal(),
				Fee = v["fee"].AsDecimal(),
				PositionAfter = v["position_after"].AsInt32(),
				Excursion = new ExcursionSnapshot
				{
					High = e["high"].AsDecimal(),
					Low = e["low"].AsDecimal(),
					HadTicks = e["had_ticks"].AsBool()
				}
			};
		}

		private static void WriteFill(JsonWriter w, Fill f)
		{
			w.BeginObject();
			w.Property("execution_id", f.ExecutionId);
			w.Property("order_id", f.OrderId);
			w.Property("order_name", f.OrderName);
			w.Property("account", f.Account);
			w.Name("instrument");
			WriteInstrument(w, f.Instrument);
			w.Property("time", Timestamp(f.Time));
			w.Property("side", f.Side.ToString());
			w.Property("quantity", f.Quantity);
			w.Property("price", f.Price);
			w.Property("commission", f.Commission);
			w.Property("fee", f.Fee);
			w.Name("position_after");
			if (f.PositionAfter.HasValue) w.Int(f.PositionAfter.Value); else w.Null();
			w.Property("is_entry", f.IsEntry);
			w.Property("is_exit", f.IsExit);
			w.EndObject();
		}

		private static Fill ReadFill(JsonValue v)
		{
			return new Fill
			{
				ExecutionId = v["execution_id"].AsString(),
				OrderId = v["order_id"].AsString(),
				OrderName = v["order_name"].AsString(),
				Account = v["account"].AsString(),
				Instrument = ReadInstrument(v["instrument"]),
				Time = ParseTimestamp(v["time"]).Value,
				Side = ParseEnum<Side>(v["side"].AsString()),
				Quantity = v["quantity"].AsInt32(),
				Price = v["price"].AsDecimal(),
				Commission = v["commission"].AsDecimal(),
				Fee = v["fee"].AsDecimal(),
				PositionAfter = v["position_after"].IsNull ? (int?)null : v["position_after"].AsInt32(),
				IsEntry = v["is_entry"].AsBool(),
				IsExit = v["is_exit"].AsBool()
			};
		}

		// ---- InstrumentSpec ----

		private static void WriteInstrument(JsonWriter w, InstrumentSpec instrument)
		{
			w.BeginObject();
			w.Property("full_name", instrument.FullName);
			w.Property("symbol", instrument.Symbol);
			w.Property("tick_size", instrument.TickSize);
			w.Property("point_value", instrument.PointValue);
			w.EndObject();
		}

		private static InstrumentSpec ReadInstrument(JsonValue v)
		{
			return new InstrumentSpec
			{
				FullName = v["full_name"].AsString(),
				Symbol = v["symbol"].AsString(),
				TickSize = v["tick_size"].AsDecimal(),
				PointValue = v["point_value"].AsDecimal()
			};
		}

		// ---- NoteRecord ----

		internal static void WriteNote(JsonWriter w, NoteRecord note)
		{
			w.BeginObject();
			w.Property("body", note.Body);
			w.Property("phase", note.Phase);
			w.Property("occurred_at", Timestamp(note.OccurredAt));
			w.EndObject();
		}

		internal static NoteRecord ReadNote(JsonValue v)
		{
			return new NoteRecord
			{
				Body = v["body"].AsString(),
				Phase = v["phase"].AsString(),
				OccurredAt = ParseTimestamp(v["occurred_at"]).Value
			};
		}

		// ---- ScreenshotMeta ----

		private static void WriteScreenshot(JsonWriter w, ScreenshotMeta s)
		{
			w.Name("screenshot").BeginObject();
			w.Property("captured_at", Timestamp(s.CapturedAt));
			w.Property("caption", s.Caption);
			w.Property("format", s.Format);
			w.EndObject();
		}

		private static ScreenshotMeta ReadScreenshot(JsonValue v)
		{
			return new ScreenshotMeta
			{
				CapturedAt = ParseTimestamp(v["captured_at"]).Value,
				Caption = v["caption"].AsString(),
				Format = v["format"].AsString()
			};
		}

		// ---- CopyEvaluation ----

		private static void WriteCopyEvaluation(JsonWriter w, CopyEvaluation c)
		{
			w.Name("copies").BeginObject();
			w.Property("source", c.Source);

			w.Name("results").BeginArray();
			foreach (CopyResult copy in c.Copies ?? new List<CopyResult>())
				WriteCopyResult(w, copy);
			w.EndArray();

			if (c.Summary == null)
			{
				w.Name("summary").Null();
			}
			else
			{
				w.Name("summary").BeginObject();
				w.Property("accounts", c.Summary.Accounts);
				w.Property("matched", c.Summary.Matched);
				w.Property("missed", c.Summary.Missed);
				w.Property("still_open", c.Summary.StillOpen);
				w.Property("net_pnl", c.Summary.NetPnl);
				w.EndObject();
			}

			w.Name("diagnostics").BeginArray();
			foreach (string d in c.Diagnostics ?? new List<string>())
				w.String(d);
			w.EndArray();

			w.EndObject();
		}

		private static CopyEvaluation ReadCopyEvaluation(JsonValue v)
		{
			return new CopyEvaluation
			{
				Source = v["source"].AsString(),
				Copies = v["results"].Items.Select(ReadCopyResult).ToList(),
				Summary = v["summary"].IsNull ? null : new CopySummary
				{
					Accounts = v["summary"]["accounts"].AsInt32(),
					Matched = v["summary"]["matched"].AsInt32(),
					Missed = v["summary"]["missed"].AsInt32(),
					StillOpen = v["summary"]["still_open"].AsInt32(),
					NetPnl = v["summary"]["net_pnl"].AsDecimal()
				},
				Diagnostics = v["diagnostics"].Items.Select(d => d.AsString()).ToList()
			};
		}

		private static void WriteCopyResult(JsonWriter w, CopyResult copy)
		{
			w.BeginObject();
			w.Property("account", copy.Account);
			w.Property("outcome", copy.Outcome.ToString());

			if (copy.Instrument == null)
				w.Name("instrument").Null();
			else
			{
				w.Name("instrument");
				WriteInstrument(w, copy.Instrument);
			}

			if (copy.Expected == null)
			{
				w.Name("expected").Null();
			}
			else
			{
				w.Name("expected").BeginObject();
				w.Property("contract_size", copy.Expected.ContractSize);
				w.Property("multiplier", copy.Expected.Multiplier);
				w.Property("faded", copy.Expected.Faded);
				w.Property("blown", copy.Expected.Blown);
				w.Name("quantity");
				if (copy.Expected.Quantity.HasValue) w.Int(copy.Expected.Quantity.Value); else w.Null();
				w.EndObject();
			}

			w.Name("warnings").BeginArray();
			foreach (string warning in copy.Warnings ?? new List<string>())
				w.String(warning);
			w.EndArray();

			w.Name("direction");
			if (copy.Direction.HasValue) w.String(copy.Direction.Value.ToString()); else w.Null();
			w.Property("quantity", copy.Quantity);
			w.Name("entry_average_price");
			if (copy.EntryAveragePrice.HasValue) w.Decimal(copy.EntryAveragePrice.Value); else w.Null();
			w.Name("exit_average_price");
			if (copy.ExitAveragePrice.HasValue) w.Decimal(copy.ExitAveragePrice.Value); else w.Null();
			w.Property("entered_at", copy.EnteredAt.HasValue ? Timestamp(copy.EnteredAt.Value) : null);
			w.Property("exited_at", copy.ExitedAt.HasValue ? Timestamp(copy.ExitedAt.Value) : null);

			if (copy.Performance == null)
			{
				w.Name("performance").Null();
			}
			else
			{
				w.Name("performance").BeginObject();
				w.Property("points", copy.Performance.Points);
				w.Property("ticks", copy.Performance.Ticks);
				w.Property("gross_pnl", copy.Performance.GrossPnl);
				w.Property("commission", copy.Performance.Commission);
				w.Name("fees");
				if (copy.Performance.Fees.HasValue) w.Decimal(copy.Performance.Fees.Value); else w.Null();
				w.Property("net_pnl", copy.Performance.NetPnl);
				w.EndObject();
			}

			w.Name("fills").BeginArray();
			foreach (TradeFill fill in copy.Fills ?? new List<TradeFill>())
				WriteTradeFill(w, fill);
			w.EndArray();

			w.EndObject();
		}

		private static CopyResult ReadCopyResult(JsonValue v)
		{
			return new CopyResult
			{
				Account = v["account"].AsString(),
				Outcome = ParseEnum<CopyOutcome>(v["outcome"].AsString()),
				Instrument = v["instrument"].IsNull ? null : ReadInstrument(v["instrument"]),
				Expected = v["expected"].IsNull ? null : new ExpectedCopy
				{
					ContractSize = v["expected"]["contract_size"].AsString(),
					Multiplier = v["expected"]["multiplier"].AsDecimal(),
					Faded = v["expected"]["faded"].AsBool(),
					Blown = v["expected"]["blown"].AsBool(),
					Quantity = v["expected"]["quantity"].IsNull ? (int?)null : v["expected"]["quantity"].AsInt32()
				},
				Warnings = v["warnings"].Items.Select(w => w.AsString()).ToList(),
				Direction = v["direction"].IsNull ? (Direction?)null : ParseEnum<Direction>(v["direction"].AsString()),
				Quantity = v["quantity"].AsInt32(),
				EntryAveragePrice = v["entry_average_price"].IsNull ? (decimal?)null : v["entry_average_price"].AsDecimal(),
				ExitAveragePrice = v["exit_average_price"].IsNull ? (decimal?)null : v["exit_average_price"].AsDecimal(),
				EnteredAt = ParseTimestamp(v["entered_at"]),
				ExitedAt = ParseTimestamp(v["exited_at"]),
				Performance = v["performance"].IsNull ? null : new CopyPerformance
				{
					Points = v["performance"]["points"].AsDecimal(),
					Ticks = v["performance"]["ticks"].AsInt32(),
					GrossPnl = v["performance"]["gross_pnl"].AsDecimal(),
					Commission = v["performance"]["commission"].AsDecimal(),
					Fees = v["performance"]["fees"].IsNull ? (decimal?)null : v["performance"]["fees"].AsDecimal(),
					NetPnl = v["performance"]["net_pnl"].AsDecimal()
				},
				Fills = v["fills"].Items.Select(ReadTradeFill).ToList()
			};
		}

		// ---- shared helpers ----

		private static string Timestamp(DateTimeOffset value)
		{
			return PayloadBuilder.Timestamp(value);
		}

		private static DateTimeOffset? ParseTimestamp(JsonValue value)
		{
			return value.IsNull ? (DateTimeOffset?)null : DateTimeOffset.Parse(value.AsString(), CultureInfo.InvariantCulture, DateTimeStyles.None);
		}

		private static T ParseEnum<T>(string value) where T : struct
		{
			try
			{
				return (T)Enum.Parse(typeof(T), value);
			}
			catch (Exception ex)
			{
				throw new FormatException("Unrecognized " + typeof(T).Name + " value '" + value + "' in the state file.", ex);
			}
		}
	}
}
