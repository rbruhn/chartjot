using System;
using System.Collections.Generic;
using System.Linq;

namespace ChartJot.Core
{
	/// <summary>
	/// The numbers for a trade that is not flat yet: what has been exited so far. Used for copies that are
	/// still open when the master trade is staged.
	/// </summary>
	public sealed class PartialTrade
	{
		public int Quantity { get; set; }
		public decimal AverageEntryPrice { get; set; }

		/// <summary>Null until the first exit fill.</summary>
		public decimal? AverageExitPrice { get; set; }

		public CopyPerformance Performance { get; set; }

		public static PartialTrade Compute(IList<TradeFill> fills, Direction direction, InstrumentSpec instrument)
		{
			List<TradeFill> entries = fills.Where(f => f.Role == FillRole.Entry).ToList();
			List<TradeFill> exits = fills.Where(f => f.Role == FillRole.Exit).ToList();
			if (entries.Count == 0)
				throw new InvalidOperationException("A trade needs at least one entry fill.");

			decimal sign = direction == Direction.Long ? 1m : -1m;
			decimal averageEntry = entries.Sum(f => f.Fill.Price * f.AllocatedQuantity) / entries.Sum(f => (decimal)f.AllocatedQuantity);

			PartialTrade partial = new PartialTrade
			{
				Quantity = fills.Max(f => Math.Abs(f.PositionAfter)),
				AverageEntryPrice = averageEntry
			};
			if (exits.Count == 0)
				return partial;

			decimal exitQuantity = exits.Sum(f => (decimal)f.AllocatedQuantity);
			decimal averageExit = exits.Sum(f => f.Fill.Price * f.AllocatedQuantity) / exitQuantity;
			decimal points = sign * (averageExit - averageEntry);
			decimal gross = exits.Sum(f => sign * (f.Fill.Price - averageEntry) * instrument.PointValue * f.AllocatedQuantity);
			decimal commission = fills.Sum(f => f.Commission);
			decimal fees = fills.Sum(f => f.Fee);

			partial.AverageExitPrice = averageExit;
			partial.Performance = new CopyPerformance
			{
				Points = points,
				Ticks = instrument.TickSize > 0m ? (int)Math.Round(points / instrument.TickSize, MidpointRounding.AwayFromZero) : 0,
				GrossPnl = gross,
				Commission = commission,
				Fees = fees == 0m ? (decimal?)null : fees,
				NetPnl = gross - commission - fees
			};
			return partial;
		}
	}
}
