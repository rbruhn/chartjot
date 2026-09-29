namespace ChartJot.Core
{
	/// <summary>The running high/low of an open trade at one moment.</summary>
	public struct ExcursionSnapshot
	{
		public decimal High;
		public decimal Low;

		/// <summary>True once at least one live last-trade price was seen. Fill prices alone do not count.</summary>
		public bool HadTicks;
	}

	/// <summary>
	/// Highest and lowest price since the first entry fill, including every fill price. Live ticks are
	/// tracked separately so a trade with no price data reports null excursion, not fill-only values.
	/// </summary>
	public sealed class ExcursionTracker
	{
		private decimal high;
		private decimal low;
		private bool any;

		public bool HadTicks { get; private set; }

		/// <summary>Set when the price feed was interrupted while the trade was open.</summary>
		public bool Interrupted { get; set; }

		public void AddFill(decimal price)
		{
			Include(price);
		}

		public void AddTick(decimal price)
		{
			if (price <= 0m)
				return;
			HadTicks = true;
			Include(price);
		}

		/// <summary>
		/// Folds in a high/low range observed before this tracker existed (a persisted snapshot from before an
		/// AddOn restart). Account.Executions can rebuild fills after a restart, but not the live tick history in
		/// between; this is the only way that range survives. A no-op when the snapshot never saw a live tick.
		/// </summary>
		public void Merge(ExcursionSnapshot snapshot)
		{
			if (!snapshot.HadTicks)
				return;
			HadTicks = true;
			Include(snapshot.High);
			Include(snapshot.Low);
		}

		public ExcursionSnapshot Snapshot()
		{
			return new ExcursionSnapshot { High = high, Low = low, HadTicks = HadTicks };
		}

		private void Include(decimal price)
		{
			if (!any)
			{
				high = price;
				low = price;
				any = true;
				return;
			}
			if (price > high)
				high = price;
			if (price < low)
				low = price;
		}
	}

	/// <summary>MAE/MFE in points from the trade's average entry, never negative.</summary>
	public sealed class ExcursionResult
	{
		public decimal MaePoints { get; set; }
		public decimal MfePoints { get; set; }
		public decimal MaxAdversePrice { get; set; }
		public decimal MaxFavorablePrice { get; set; }

		/// <summary>Null when no live price was observed.</summary>
		public static ExcursionResult Compute(Direction direction, decimal averageEntry, ExcursionSnapshot snapshot)
		{
			if (!snapshot.HadTicks)
				return null;

			decimal favorable = direction == Direction.Long ? snapshot.High : snapshot.Low;
			decimal adverse = direction == Direction.Long ? snapshot.Low : snapshot.High;
			decimal mfe = direction == Direction.Long ? favorable - averageEntry : averageEntry - favorable;
			decimal mae = direction == Direction.Long ? averageEntry - adverse : adverse - averageEntry;

			return new ExcursionResult
			{
				MfePoints = mfe < 0m ? 0m : mfe,
				MaePoints = mae < 0m ? 0m : mae,
				MaxFavorablePrice = favorable,
				MaxAdversePrice = adverse
			};
		}
	}
}
