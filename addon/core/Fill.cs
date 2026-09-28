using System;

namespace ChartJot.Core
{
	public enum Side
	{
		Buy,
		Sell
	}

	public enum Direction
	{
		Long,
		Short
	}

	public sealed class InstrumentSpec
	{
		/// <summary>NT8 instrument full name, e.g. "ES 12-26".</summary>
		public string FullName { get; set; }

		/// <summary>Root symbol, e.g. "ES".</summary>
		public string Symbol { get; set; }

		public decimal TickSize { get; set; }
		public decimal PointValue { get; set; }
	}

	/// <summary>One NT8 execution, adapted to plain types.</summary>
	public sealed class Fill
	{
		/// <summary>Opaque string. Rithmic IDs look like "1096239|2907664565|2907664565".</summary>
		public string ExecutionId { get; set; }

		public string OrderId { get; set; }
		public string OrderName { get; set; }
		public string Account { get; set; }
		public InstrumentSpec Instrument { get; set; }

		/// <summary>Time in NT8's configured time zone, with offset. Playback times are historical.</summary>
		public DateTimeOffset Time { get; set; }

		public Side Side { get; set; }
		public int Quantity { get; set; }
		public decimal Price { get; set; }
		public decimal Commission { get; set; }

		/// <summary>Zero when NT8 reports no separate fee.</summary>
		public decimal Fee { get; set; }

		/// <summary>NT8 Execution.Position: signed position after this fill, when known.</summary>
		public int? PositionAfter { get; set; }

		public bool IsEntry { get; set; }
		public bool IsExit { get; set; }

		public int SignedQuantity
		{
			get { return Side == Side.Buy ? Quantity : -Quantity; }
		}
	}
}
