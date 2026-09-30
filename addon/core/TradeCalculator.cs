using System;
using System.Collections.Generic;
using System.Linq;

namespace ChartJot.Core
{
	/// <summary>Turns a finished list of trade fills into the numbers the payload needs.</summary>
	public static class TradeCalculator
	{
		public static CompletedTrade Complete(string account, InstrumentSpec instrument, Direction direction,
			int maxQuantity, IList<TradeFill> fills, bool feedInterrupted, bool openedByReversal = false)
		{
			List<TradeFill> entries = fills.Where(f => f.Role == FillRole.Entry).ToList();
			List<TradeFill> exits = fills.Where(f => f.Role == FillRole.Exit).ToList();
			if (entries.Count == 0 || exits.Count == 0)
				throw new InvalidOperationException("A completed trade needs at least one entry and one exit fill.");

			decimal sign = direction == Direction.Long ? 1m : -1m;
			decimal averageEntry = WeightedAverage(entries);
			decimal averageExit = WeightedAverage(exits);
			TradeFill first = fills[0];
			TradeFill last = fills[fills.Count - 1];
			TradeFill lastExit = exits[exits.Count - 1];

			decimal gross = exits.Sum(f => sign * (f.Fill.Price - averageEntry) * instrument.PointValue * f.AllocatedQuantity);
			decimal commission = fills.Sum(f => f.Commission);
			decimal fees = fills.Sum(f => f.Fee);
			decimal points = sign * (averageExit - averageEntry);

			int ticks = 0;
			if (instrument.TickSize > 0m)
				ticks = (int)Math.Round(points / instrument.TickSize, MidpointRounding.AwayFromZero);

			ExcursionResult excursion = ExcursionResult.Compute(direction, averageEntry, last.Excursion);

			return new CompletedTrade
			{
				TradeId = TradeIds.Create(account, instrument.FullName, entries[0].Fill.ExecutionId),
				Account = account,
				Instrument = instrument,
				Direction = direction,
				Quantity = maxQuantity,
				TotalEntryQuantity = entries.Sum(f => f.AllocatedQuantity),
				EntryAt = first.Fill.Time,
				ExitAt = lastExit.Fill.Time,
				AverageEntryPrice = averageEntry,
				AverageExitPrice = averageExit,
				EntryOrderName = entries[0].Fill.OrderName,
				ExitOrderName = lastExit.Fill.OrderName,
				ExitReason = ExitReason.Classify(lastExit.Fill.OrderName),
				Points = points,
				Ticks = ticks,
				GrossPnl = gross,
				Commission = commission,
				Fees = fees == 0m ? (decimal?)null : fees,
				NetPnl = gross - commission - fees,
				Excursion = excursion,
				ExcursionComplete = excursion != null && !feedInterrupted,
				Legs = BuildLegs(fills, direction, averageEntry, instrument),
				Fills = fills,
				OpenedByReversal = openedByReversal
			};
		}

		private static IList<Leg> BuildLegs(IList<TradeFill> fills, Direction direction, decimal averageEntry, InstrumentSpec instrument)
		{
			decimal sign = direction == Direction.Long ? 1m : -1m;

			// One leg per exit order; an order that fills in pieces is still one leg.
			Dictionary<string, List<int>> byOrder = new Dictionary<string, List<int>>();
			List<string> orderKeys = new List<string>();
			for (int i = 0; i < fills.Count; i++)
			{
				if (fills[i].Role != FillRole.Exit)
					continue;
				string key = string.IsNullOrEmpty(fills[i].Fill.OrderId) ? fills[i].Fill.ExecutionId : fills[i].Fill.OrderId;
				List<int> indexes;
				if (!byOrder.TryGetValue(key, out indexes))
				{
					indexes = new List<int>();
					byOrder[key] = indexes;
					orderKeys.Add(key);
				}
				indexes.Add(i);
			}

			// Numbered by the order in which each leg's final fill occurred.
			List<string> ordered = orderKeys.OrderBy(k => byOrder[k][byOrder[k].Count - 1]).ToList();

			List<Leg> legs = new List<Leg>();
			foreach (string key in ordered)
			{
				List<TradeFill> pieces = byOrder[key].Select(i => fills[i]).ToList();
				TradeFill final = pieces[pieces.Count - 1];
				decimal average = WeightedAverage(pieces);
				ExcursionResult excursion = ExcursionResult.Compute(direction, averageEntry, final.Excursion);

				legs.Add(new Leg
				{
					Sequence = legs.Count + 1,
					Runner = legs.Count > 0,
					ExitOrderId = final.Fill.OrderId,
					OrderName = final.Fill.OrderName,
					Reason = ExitReason.Classify(final.Fill.OrderName),
					Quantity = pieces.Sum(p => p.AllocatedQuantity),
					ExitedAt = final.Fill.Time,
					AverageExitPrice = average,
					Points = sign * (average - averageEntry),
					GrossPnl = pieces.Sum(p => sign * (p.Fill.Price - averageEntry) * instrument.PointValue * p.AllocatedQuantity),
					MaePoints = excursion == null ? (decimal?)null : excursion.MaePoints,
					MfePoints = excursion == null ? (decimal?)null : excursion.MfePoints
				});
			}
			return legs;
		}

		private static decimal WeightedAverage(IList<TradeFill> fills)
		{
			decimal quantity = fills.Sum(f => (decimal)f.AllocatedQuantity);
			return fills.Sum(f => f.Fill.Price * f.AllocatedQuantity) / quantity;
		}
	}
}
