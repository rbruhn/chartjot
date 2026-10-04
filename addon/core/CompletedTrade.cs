using System;
using System.Collections.Generic;

namespace ChartJot.Core
{
	public enum FillRole
	{
		Entry,
		Exit
	}

	/// <summary>A fill's commission and fee as NT8 reports them now, for re-reading after the settle period.</summary>
	public sealed class FillCharges
	{
		public decimal Commission { get; set; }
		public decimal Fee { get; set; }
	}

	/// <summary>A fill's contribution to one trade. A reversal fill contributes to two trades.</summary>
	public sealed class TradeFill
	{
		public Fill Fill { get; set; }
		public FillRole Role { get; set; }

		/// <summary>Part of the fill's quantity that belongs to this trade.</summary>
		public int AllocatedQuantity { get; set; }

		/// <summary>Commission and fee pro-rated by allocated / total quantity.</summary>
		public decimal Commission { get; set; }
		public decimal Fee { get; set; }

		/// <summary>Signed position within this trade after this fill.</summary>
		public int PositionAfter { get; set; }

		/// <summary>Running high/low right after this fill.</summary>
		public ExcursionSnapshot Excursion { get; set; }
	}

	/// <summary>One exit order. An order that fills in pieces is still one leg.</summary>
	public sealed class Leg
	{
		public int Sequence { get; set; }
		public bool Runner { get; set; }
		public string ExitOrderId { get; set; }
		public string OrderName { get; set; }
		public string Reason { get; set; }
		public int Quantity { get; set; }
		public DateTimeOffset ExitedAt { get; set; }
		public decimal AverageExitPrice { get; set; }
		public decimal Points { get; set; }
		public decimal GrossPnl { get; set; }

		/// <summary>Null when no live price was observed.</summary>
		public decimal? MaePoints { get; set; }
		public decimal? MfePoints { get; set; }
	}

	/// <summary>One completed round-turn trade in one account and instrument.</summary>
	public sealed class CompletedTrade
	{
		public string TradeId { get; set; }
		public string Account { get; set; }
		public InstrumentSpec Instrument { get; set; }
		public Direction Direction { get; set; }

		/// <summary>Maximum absolute open position during the round turn.</summary>
		public int Quantity { get; set; }

		/// <summary>Sum of allocated entry quantities.</summary>
		public int TotalEntryQuantity { get; set; }

		public DateTimeOffset EntryAt { get; set; }
		public DateTimeOffset ExitAt { get; set; }
		public decimal AverageEntryPrice { get; set; }
		public decimal AverageExitPrice { get; set; }
		public string EntryOrderName { get; set; }
		public string ExitOrderName { get; set; }
		public string ExitReason { get; set; }

		public decimal Points { get; set; }
		public int Ticks { get; set; }
		public decimal GrossPnl { get; set; }
		public decimal Commission { get; set; }

		/// <summary>Null when NT8 reported no separate fees.</summary>
		public decimal? Fees { get; set; }
		public decimal NetPnl { get; set; }

		/// <summary>Null when no live price was observed.</summary>
		public ExcursionResult Excursion { get; set; }

		/// <summary>False when the price feed was interrupted while the trade was open.</summary>
		public bool ExcursionComplete { get; set; }

		/// <summary>
		/// True when this trade was opened by the fill that also closed the previous trade (a reversal), rather than
		/// from flat.
		/// </summary>
		public bool OpenedByReversal { get; set; }

		public IList<Leg> Legs { get; set; }
		public IList<TradeFill> Fills { get; set; }
	}
}
