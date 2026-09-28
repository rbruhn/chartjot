using ChartJot.Core;

namespace ChartJot.Core.Tests
{
	internal static class TestData
	{
		public static readonly InstrumentSpec Es = new InstrumentSpec { FullName = "ES 12-26", Symbol = "ES", TickSize = 0.25m, PointValue = 50m };
		public static readonly InstrumentSpec Mes = new InstrumentSpec { FullName = "MES 12-26", Symbol = "MES", TickSize = 0.25m, PointValue = 5m };

		public static readonly DateTimeOffset Start = new DateTimeOffset(2026, 9, 24, 9, 30, 0, TimeSpan.FromHours(-4));

		public static DateTimeOffset At(int seconds)
		{
			return Start.AddSeconds(seconds);
		}

		public static Fill Buy(string id, string order, string name, int qty, decimal price, int seconds,
			decimal commission = 0m, int? positionAfter = null, string account = "APEX-24570-135", InstrumentSpec instrument = null,
			bool isEntry = false, bool isExit = false)
		{
			return Make(Side.Buy, id, order, name, qty, price, seconds, commission, positionAfter, account, instrument, isEntry, isExit);
		}

		public static Fill Sell(string id, string order, string name, int qty, decimal price, int seconds,
			decimal commission = 0m, int? positionAfter = null, string account = "APEX-24570-135", InstrumentSpec instrument = null,
			bool isEntry = false, bool isExit = false)
		{
			return Make(Side.Sell, id, order, name, qty, price, seconds, commission, positionAfter, account, instrument, isEntry, isExit);
		}

		private static Fill Make(Side side, string id, string order, string name, int qty, decimal price, int seconds,
			decimal commission, int? positionAfter, string account, InstrumentSpec instrument, bool isEntry, bool isExit)
		{
			return new Fill
			{
				ExecutionId = id,
				OrderId = order,
				OrderName = name,
				Account = account,
				Instrument = instrument ?? Es,
				Time = At(seconds),
				Side = side,
				Quantity = qty,
				Price = price,
				Commission = commission,
				PositionAfter = positionAfter,
				IsEntry = isEntry,
				IsExit = isExit
			};
		}
	}
}
