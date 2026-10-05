using System;
using System.Collections.Generic;
using System.Globalization;

namespace ChartJot.Core
{
	public static class TradeTypes
	{
		public static readonly string[] All = { "2ES", "2EL", "RS", "RL", "F2ES", "F2EL", "Other" };

		public static bool IsValid(string value)
		{
			return Array.IndexOf(All, value) >= 0;
		}

		/// <summary>The dropdown label for a submitted value (NT8.md, "Trade type selector"); null when unknown.</summary>
		public static string Label(string value)
		{
			switch (value)
			{
				case "2ES": return "Second Entry Short";
				case "2EL": return "Second Entry Long";
				case "RS": return "Range Short";
				case "RL": return "Range Long";
				case "F2ES": return "Failed Second Entry Short";
				case "F2EL": return "Failed Second Entry Long";
				case "Other": return "Other type of entry";
			}
			return null;
		}

		/// <summary>
		/// The direction a type is traded in, or null when it implies none (<c>Other</c>, unknown). A failed second
		/// entry is traded the opposite way: <c>F2ES</c> is taken long and <c>F2EL</c> short (confirmed by the
		/// trader, 2026-10-04).
		/// </summary>
		public static Direction? ImpliedDirection(string value)
		{
			switch (value)
			{
				case "2ES":
				case "RS":
				case "F2EL":
					return Direction.Short;
				case "2EL":
				case "RL":
				case "F2ES":
					return Direction.Long;
			}
			return null;
		}

		/// <summary>The non-blocking warning shown when the chosen type implies the other direction; null otherwise.</summary>
		public static string DirectionWarning(string value, Direction actual)
		{
			Direction? implied = ImpliedDirection(value);
			if (!implied.HasValue || implied.Value == actual)
				return null;
			return Label(value) + " is usually a " + implied.Value.ToString().ToLowerInvariant()
				+ " trade, but this trade was " + actual.ToString().ToLowerInvariant() + ".";
		}
	}

	public static class NotePhases
	{
		public const string PreTrade = "pre_trade";
		public const string InTrade = "in_trade";
		public const string PostTrade = "post_trade";
		public const string General = "general";
	}

	public sealed class NoteRecord
	{
		public string Body { get; set; }
		public string Phase { get; set; }
		public DateTimeOffset OccurredAt { get; set; }
	}

	/// <summary>A trade's two chart images (#52); either may be null.</summary>
	public sealed class TradeImages
	{
		public ScreenshotMeta Exit { get; set; }
		public ScreenshotMeta Entry { get; set; }
	}

	public sealed class ScreenshotMeta
	{
		public DateTimeOffset CapturedAt { get; set; }
		public string Caption { get; set; }

		/// <summary>"png" or "jpeg", whatever the format setting was at capture time. Settings can change later,
		/// so this is what locates the file on disk by trade_id, not the current setting.</summary>
		public string Format { get; set; }
	}

	/// <summary>What the trader chose or the AddOn knows at submission time.</summary>
	public sealed class SubmissionInfo
	{
		public string AddonVersion { get; set; }
		public string Connection { get; set; }
		public string TradeType { get; set; }
		public string TradeTypeOther { get; set; }
		public IList<NoteRecord> Notes { get; set; }

		/// <summary>Null when no screenshot file is attached.</summary>
		public ScreenshotMeta Screenshot { get; set; }

		/// <summary>The entry image (#52); null when none is attached. Only its capture time is sent.</summary>
		public ScreenshotMeta EntryScreenshot { get; set; }

		/// <summary>Optional (<see cref="StagedTrade.StopPrice"/>); null is sent as null and never blocks submission.</summary>
		public decimal? StopPrice { get; set; }
	}

	/// <summary>Builds the JSON `trade` part of the intake request (NT8.md, Provisional Payload Contract).</summary>
	public static class PayloadBuilder
	{
		public const int SchemaVersion = 1;

		public const string UnknownConnection = "Unknown";

		public static string Build(CompletedTrade trade, SubmissionInfo info)
		{
			if (!TradeTypes.IsValid(info.TradeType))
				throw new ArgumentException("A valid trade type is required before a trade can be submitted.", "info");
			if (trade.ExitAt < trade.EntryAt)
				throw new ArgumentException("The exit time precedes the entry time.", "trade");

			decimal tick = trade.Instrument.TickSize;
			JsonWriter w = new JsonWriter();
			w.BeginObject();

			w.Property("schema_version", SchemaVersion);
			w.Property("source", "ninjatrader_8");
			w.Property("addon_version", info.AddonVersion);
			w.Property("trade_id", trade.TradeId);
			w.Property("account_name", trade.Account);
			w.Property("connection", string.IsNullOrWhiteSpace(info.Connection) ? UnknownConnection : info.Connection);
			w.Property("trade_type", info.TradeType);
			w.Property("trade_type_other", info.TradeType == "Other" && !string.IsNullOrWhiteSpace(info.TradeTypeOther) ? info.TradeTypeOther.Trim() : null);

			WriteInstrument(w, "instrument", trade.Instrument);

			w.Property("direction", DirectionName(trade.Direction));
			w.Property("quantity", trade.Quantity);
			w.Property("total_entry_quantity", trade.TotalEntryQuantity);

			w.Name("entry").BeginObject();
			w.Property("occurred_at", Timestamp(trade.EntryAt));
			w.Property("average_price", DecimalFormat.Price(trade.AverageEntryPrice, tick));
			w.Property("order_name", NullIfBlank(trade.EntryOrderName));
			w.EndObject();

			w.Name("exit").BeginObject();
			w.Property("occurred_at", Timestamp(trade.ExitAt));
			w.Property("average_price", DecimalFormat.Price(trade.AverageExitPrice, tick));
			w.Property("order_name", NullIfBlank(trade.ExitOrderName));
			w.Property("reason", trade.ExitReason);
			w.EndObject();

			WritePerformance(w, "performance", tick, trade.Points, trade.Ticks, trade.GrossPnl, trade.Commission, trade.Fees, trade.NetPnl);

			w.Name("excursion").BeginObject();
			ExcursionResult e = trade.Excursion;
			w.Property("mae_points", e == null ? null : DecimalFormat.Price(e.MaePoints, tick));
			w.Property("mfe_points", e == null ? null : DecimalFormat.Price(e.MfePoints, tick));
			w.Property("max_adverse_price", e == null ? null : DecimalFormat.Price(e.MaxAdversePrice, tick));
			w.Property("max_favorable_price", e == null ? null : DecimalFormat.Price(e.MaxFavorablePrice, tick));
			w.Property("complete", trade.ExcursionComplete);
			w.EndObject();

			w.Property("stop_price", info.StopPrice.HasValue ? DecimalFormat.Price(info.StopPrice.Value, tick) : null);

			w.Name("legs").BeginArray();
			foreach (Leg leg in trade.Legs)
			{
				w.BeginObject();
				w.Property("sequence", leg.Sequence);
				w.Property("runner", leg.Runner);
				w.Property("exit_order_id", NullIfBlank(leg.ExitOrderId));
				w.Property("order_name", NullIfBlank(leg.OrderName));
				w.Property("reason", leg.Reason);
				w.Property("quantity", leg.Quantity);
				w.Property("exited_at", Timestamp(leg.ExitedAt));
				w.Property("average_exit_price", DecimalFormat.Price(leg.AverageExitPrice, tick));
				w.Property("points", DecimalFormat.Price(leg.Points, tick));
				w.Property("gross_pnl", DecimalFormat.Money(leg.GrossPnl));
				w.Property("mae_points", leg.MaePoints.HasValue ? DecimalFormat.Price(leg.MaePoints.Value, tick) : null);
				w.Property("mfe_points", leg.MfePoints.HasValue ? DecimalFormat.Price(leg.MfePoints.Value, tick) : null);
				w.EndObject();
			}
			w.EndArray();

			WriteExecutions(w, "executions", trade.Fills, tick);

			w.Name("notes").BeginArray();
			if (info.Notes != null)
			{
				foreach (NoteRecord note in Ordered(info.Notes))
				{
					w.BeginObject();
					w.Property("body", note.Body);
					w.Property("phase", note.Phase);
					w.Property("occurred_at", Timestamp(note.OccurredAt));
					w.EndObject();
				}
			}
			w.EndArray();

			if (info.Screenshot != null)
			{
				w.Name("screenshot").BeginObject();
				w.Property("captured_at", Timestamp(info.Screenshot.CapturedAt));
				w.Property("caption", NullIfBlank(info.Screenshot.Caption));
				w.EndObject();
			}

			if (info.EntryScreenshot != null)
			{
				w.Name("entry_screenshot").BeginObject();
				w.Property("captured_at", Timestamp(info.EntryScreenshot.CapturedAt));
				w.EndObject();
			}

			w.EndObject();
			return w.ToString();
		}

		public static void WriteInstrument(JsonWriter w, string name, InstrumentSpec instrument)
		{
			w.Name(name).BeginObject();
			w.Property("symbol", instrument.Symbol);
			w.Property("contract", instrument.FullName);
			w.Property("tick_size", DecimalFormat.Format(instrument.TickSize, 0));
			w.Property("point_value", DecimalFormat.Format(instrument.PointValue, 0));
			w.EndObject();
		}

		public static void WritePerformance(JsonWriter w, string name, decimal tick, decimal points, int ticks,
			decimal gross, decimal commission, decimal? fees, decimal net)
		{
			w.Name(name).BeginObject();
			w.Property("points", DecimalFormat.Price(points, tick));
			w.Property("ticks", ticks);
			w.Property("gross_pnl", DecimalFormat.Money(gross));
			w.Property("commission", DecimalFormat.Money(commission));
			w.Property("fees", fees.HasValue ? DecimalFormat.Money(fees.Value) : null);
			w.Property("net_pnl", DecimalFormat.Money(net));
			w.EndObject();
		}

		public static void WriteExecutions(JsonWriter w, string name, IList<TradeFill> fills, decimal tick)
		{
			w.Name(name).BeginArray();
			foreach (TradeFill tf in fills)
			{
				Fill f = tf.Fill;
				w.BeginObject();
				w.Property("execution_id", f.ExecutionId);
				w.Property("order_id", NullIfBlank(f.OrderId));
				w.Property("occurred_at", Timestamp(f.Time));
				w.Property("action", f.Side == Side.Buy ? "buy" : "sell");
				w.Property("role", tf.Role == FillRole.Entry ? "entry" : "exit");
				w.Property("quantity", f.Quantity);
				w.Property("allocated_quantity", tf.AllocatedQuantity);
				w.Property("price", DecimalFormat.Price(f.Price, tick));
				w.Property("commission", DecimalFormat.Money(tf.Commission));
				w.Property("fee", tf.Fee == 0m ? null : DecimalFormat.Money(tf.Fee));
				w.Property("order_name", NullIfBlank(f.OrderName));
				w.Property("position_after", tf.PositionAfter);
				w.EndObject();
			}
			w.EndArray();
		}

		public static string DirectionName(Direction direction)
		{
			return direction == Direction.Long ? "long" : "short";
		}

		/// <summary>ISO 8601 with the UTC offset; a fraction of a second is kept when there is one.</summary>
		public static string Timestamp(DateTimeOffset value)
		{
			string format = value.Millisecond == 0 ? "yyyy-MM-dd'T'HH:mm:sszzz" : "yyyy-MM-dd'T'HH:mm:ss.fffzzz";
			return value.ToString(format, CultureInfo.InvariantCulture);
		}

		private static string NullIfBlank(string value)
		{
			return string.IsNullOrWhiteSpace(value) ? null : value;
		}

		private static IEnumerable<NoteRecord> Ordered(IList<NoteRecord> notes)
		{
			List<NoteRecord> copy = new List<NoteRecord>(notes);
			// Stable: notes saved at the same instant keep their order.
			for (int i = 1; i < copy.Count; i++)
			{
				NoteRecord item = copy[i];
				int j = i - 1;
				while (j >= 0 && copy[j].OccurredAt > item.OccurredAt)
				{
					copy[j + 1] = copy[j];
					j--;
				}
				copy[j + 1] = item;
			}
			return copy;
		}
	}
}
