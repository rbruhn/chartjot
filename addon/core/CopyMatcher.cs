using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;

namespace ChartJot.Core
{
	public enum CopyOutcome
	{
		Matched,
		Missed,
		StillOpen
	}

	public sealed class CopyPerformance
	{
		public decimal Points { get; set; }
		public int Ticks { get; set; }
		public decimal GrossPnl { get; set; }
		public decimal Commission { get; set; }
		public decimal? Fees { get; set; }
		public decimal NetPnl { get; set; }
	}

	/// <summary>What the copier setup says this follower should have done.</summary>
	public sealed class ExpectedCopy
	{
		public string ContractSize { get; set; }
		public decimal Multiplier { get; set; }
		public bool Faded { get; set; }
		public bool Blown { get; set; }

		/// <summary>Null when it cannot be worked out (unknown rounding or multiplier mode).</summary>
		public int? Quantity { get; set; }
	}

	/// <summary>A round turn seen in a non-master account: closed, or still open.</summary>
	public sealed class CopyCandidate
	{
		public string Account { get; set; }
		public InstrumentSpec Instrument { get; set; }
		public Direction Direction { get; set; }
		public DateTimeOffset EntryAt { get; set; }
		public DateTimeOffset? ExitAt { get; set; }
		public bool IsOpen { get; set; }
		public CompletedTrade Trade { get; set; }
		public OpenTradeInfo Open { get; set; }

		public static CopyCandidate FromClosed(CompletedTrade trade)
		{
			return new CopyCandidate
			{
				Account = trade.Account,
				Instrument = trade.Instrument,
				Direction = trade.Direction,
				EntryAt = trade.EntryAt,
				ExitAt = trade.ExitAt,
				Trade = trade
			};
		}

		public static CopyCandidate FromOpen(OpenTradeInfo open)
		{
			return new CopyCandidate
			{
				Account = open.Account,
				Instrument = open.Instrument,
				Direction = open.Direction,
				EntryAt = open.EntryAt,
				IsOpen = true,
				Open = open
			};
		}
	}

	public sealed class CopyResult
	{
		public string Account { get; set; }
		public CopyOutcome Outcome { get; set; }
		public InstrumentSpec Instrument { get; set; }

		/// <summary>Null when there is no copier setup (auto-detect).</summary>
		public ExpectedCopy Expected { get; set; }

		public IList<string> Warnings { get; set; }
		public Direction? Direction { get; set; }
		public int Quantity { get; set; }
		public decimal? EntryAveragePrice { get; set; }
		public decimal? ExitAveragePrice { get; set; }
		public DateTimeOffset? EnteredAt { get; set; }
		public DateTimeOffset? ExitedAt { get; set; }

		/// <summary>Null for a missed copy, and for an open copy with nothing exited yet.</summary>
		public CopyPerformance Performance { get; set; }

		public IList<TradeFill> Fills { get; set; }
	}

	public sealed class CopySummary
	{
		public int Accounts { get; set; }
		public int Matched { get; set; }
		public int Missed { get; set; }
		public int StillOpen { get; set; }

		/// <summary>Combined net P&L of matched and still-open copies.</summary>
		public decimal NetPnl { get; set; }
	}

	public sealed class CopyEvaluation
	{
		/// <summary>copier_live, copier_workspace or auto_detect.</summary>
		public string Source { get; set; }

		public IList<CopyResult> Copies { get; set; }

		/// <summary>Null when there are no copies and no followers.</summary>
		public CopySummary Summary { get; set; }

		/// <summary>Tolerated oddities, for the diagnostic log.</summary>
		public IList<string> Diagnostics { get; set; }
	}

	public sealed class MatchOptions
	{
		public const string AutoDetect = "auto_detect";

		public MatchOptions()
		{
			Window = TimeSpan.FromSeconds(5);
			Families = MarketFamilies.Default;
			NeverCopy = new List<string> { "Sim101", "Playback*" };
			JournaledMasters = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
			HistoryDays = 30;
			AutoDetectHistory = new Dictionary<string, DateTimeOffset>(StringComparer.OrdinalIgnoreCase);
		}

		/// <summary>Copy matching window. Default 5 seconds.</summary>
		public TimeSpan Window { get; set; }

		public MarketFamilies Families { get; set; }

		/// <summary>Accounts never treated as copies when auto-detecting. A trailing * matches a prefix.</summary>
		public IList<string> NeverCopy { get; set; }

		/// <summary>Accounts whose own trades are journaled; never copies of another master.</summary>
		public ISet<string> JournaledMasters { get; set; }

		/// <summary>Auto-detect: when each account was last matched as a copy. Only recent ones can be reported missed.</summary>
		public IDictionary<string, DateTimeOffset> AutoDetectHistory { get; set; }

		public int HistoryDays { get; set; }
	}

	/// <summary>
	/// Attaches follower round turns to a master trade. It is a pure function of what was observed, so it can be
	/// run again when a copy that was still open goes flat before submission.
	/// </summary>
	public static class CopyMatcher
	{
		public const string RoundUpAtHalf = "Round Up At 0.5";
		public const string RoundDown = "Round Down";
		public const string MultiplierModeName = "Multiplier";
		public const string QuantityModeName = "Quantity";

		public static CopyEvaluation Evaluate(CompletedTrade master, CopierSnapshot setup, MatchOptions options,
			IEnumerable<CopyCandidate> candidates)
		{
			List<CopyCandidate> all = candidates.Where(c => !SameAccount(c.Account, master.Account)).ToList();
			List<string> diagnostics = new List<string>();
			List<CopyResult> results = new List<CopyResult>();

			if (setup != null)
			{
				diagnostics.AddRange(setup.Diagnostics ?? new List<string>());
				List<string> filtered = new List<string>();
				foreach (FollowerSetup follower in setup.FollowerRows)
				{
					if (SameAccount(follower.Account, master.Account))
						continue;
					CopyResult result = EvaluateFollower(master, setup, follower, options, all, diagnostics, filtered);
					if (result != null)
						results.Add(result);
				}
				if (filtered.Count > 0)
				{
					diagnostics.Add("Select Trade Direction does not allow " + DirectionWord(master.Direction) + " entries from flat; "
						+ "Executions-mode followers not expected to copy this trade: " + string.Join(", ", filtered));
				}
			}
			else
			{
				results.AddRange(EvaluateAutoDetect(master, options, all));
			}

			// If nobody at all copied, the copier itself probably did not act, which is not the same as several
			// followers missing on their own. A Select Trade Direction filter is not a cause here: the followers it
			// applies to are already excluded above, and it never blocks Orders-mode followers or reversals.
			if (setup != null && results.Count > 0 && results.All(r => r.Outcome == CopyOutcome.Missed) && !all.Any(c => c.EntryAt >= master.EntryAt - options.Window))
			{
				diagnostics.Add("no expected follower entered near the master's entry; the copier may not have copied this trade "
					+ "(for example paused, off, or its dashboard not in the active workspace tab)");
			}

			return new CopyEvaluation
			{
				Source = setup != null ? setup.Source : MatchOptions.AutoDetect,
				Copies = results,
				Summary = Summarize(results),
				Diagnostics = diagnostics
			};
		}

		// ---- copier setup

		private static CopyResult EvaluateFollower(CompletedTrade master, CopierSnapshot setup, FollowerSetup follower,
			MatchOptions options, List<CopyCandidate> all, List<string> diagnostics, List<string> filtered)
		{
			ExpectedCopy expected = BuildExpected(master, setup, follower, diagnostics);
			DateTimeOffset lower = master.EntryAt - options.Window;
			DateTimeOffset upper = follower.ExecutionsMode ? master.EntryAt + options.Window : master.ExitAt;

			bool nanoUnmapped = follower.Size == ContractSize.Nano && !options.Families.IsMapped(FollowerSymbol(all, follower.Account));
			Direction wanted = follower.Fade ? Opposite(master.Direction) : master.Direction;

			List<CopyCandidate> mine = all.Where(c => SameAccount(c.Account, follower.Account)).ToList();

			CopyCandidate match = mine
				.Where(c => c.EntryAt >= lower && c.EntryAt <= upper && c.Direction == wanted)
				.Where(c => nanoUnmapped || options.Families.SameMarket(master.Instrument, c.Instrument))
				.OrderBy(c => c.EntryAt)
				.FirstOrDefault();

			if (match != null)
			{
				List<string> warnings = new List<string>();
				if (follower.Fade)
					warnings.Add("faded copy");
				if (nanoUnmapped)
					warnings.Add("nano contract; instrument not checked");
				return Build(match, expected, warnings, master, follower.Size, options);
			}

			if (!IsExpected(setup, follower, master))
				return null;

			if (DirectionFiltered(setup, follower, master))
			{
				filtered.Add(follower.Account);
				return null;
			}

			// A follower already holding a position in this market before the window opened is not matched.
			bool alreadyIn = mine.Any(c => options.Families.SameMarket(master.Instrument, c.Instrument)
				&& c.EntryAt < lower && (c.IsOpen || c.ExitAt > lower));

			bool wrongWay = mine.Any(c => c.EntryAt >= lower && c.EntryAt <= upper && c.Direction != wanted
				&& (nanoUnmapped || options.Families.SameMarket(master.Instrument, c.Instrument)));

			List<string> missedWarnings = new List<string>();
			if (alreadyIn)
				missedWarnings.Add("already in a position before the master entered; not matched");
			else if (wrongWay)
				missedWarnings.Add("entered " + DirectionWord(Opposite(wanted)) + " but expected " + DirectionWord(wanted) + "; not matched");
			else
				missedWarnings.Add(MissedMessage(master, follower, expected, options, follower.ExecutionsMode));

			return new CopyResult
			{
				Account = follower.Account,
				Outcome = CopyOutcome.Missed,
				Expected = expected,
				Warnings = missedWarnings,
				Quantity = 0,
				Fills = new List<TradeFill>()
			};
		}

		private static string MissedMessage(CompletedTrade master, FollowerSetup follower, ExpectedCopy expected, MatchOptions options, bool executionsMode)
		{
			string what;
			if (expected.Quantity.HasValue)
			{
				string symbol = options.Families.ExpectedSymbol(master.Instrument, follower.Size);
				what = "expected " + expected.Quantity.Value.ToString(CultureInfo.InvariantCulture) + " " + (symbol ?? "contracts") + ", ";
			}
			else
			{
				what = "";
			}

			string when = executionsMode
				? "no entry within " + options.Window.TotalSeconds.ToString("0.###", CultureInfo.InvariantCulture) + " seconds"
				: "no entry before the master trade closed";
			return what + when;
		}

		private static ExpectedCopy BuildExpected(CompletedTrade master, CopierSnapshot setup, FollowerSetup follower, List<string> diagnostics)
		{
			return new ExpectedCopy
			{
				ContractSize = follower.Size.ToString().ToLowerInvariant(),
				Multiplier = follower.Multiplier,
				Faded = follower.Fade,
				Blown = follower.Blown,
				Quantity = ExpectedQuantity(EntryOrderQuantities(master), follower.Multiplier, setup.RoundingMode, setup.MultiplierMode,
					follower.ExecutionsMode, diagnostics, setup.SizeColumnEnabled)
			};
		}

		/// <summary>The master's entry quantity, one figure per entry order (an order that fills in pieces counts once).</summary>
		public static IList<int> EntryOrderQuantities(CompletedTrade master)
		{
			List<string> order = new List<string>();
			Dictionary<string, int> quantity = new Dictionary<string, int>();
			foreach (TradeFill f in master.Fills)
			{
				if (f.Role != FillRole.Entry)
					continue;
				string key = string.IsNullOrEmpty(f.Fill.OrderId) ? f.Fill.ExecutionId : f.Fill.OrderId;
				if (!quantity.ContainsKey(key))
				{
					quantity[key] = 0;
					order.Add(key);
				}
				quantity[key] += f.AllocatedQuantity;
			}
			return order.Select(k => quantity[k]).ToList();
		}

		/// <summary>
		/// How many contracts a follower should have entered in total, from the copier's own rules (vendor-confirmed
		/// 2026-09-28):
		/// <list type="bullet">
		/// <item>IsXEnabled = false: the Size column is off entirely. Every follower takes the master's exact
		/// quantity order for order, regardless of Size Calculation, Size, or Size Rounding.</item>
		/// <item>Size Calculation = Multiplier, Executions mode: the master's total times the multiplier, rounded per
		/// the Size Rounding setting.</item>
		/// <item>Size Calculation = Multiplier, Orders mode: each master order is mirrored and rounded on its own by
		/// the same Size Rounding setting as Executions mode, then raised to 1 if that rounds to less, so the total
		/// is the sum over orders.</item>
		/// <item>Size Calculation = Quantity: every master order is replaced by the same fixed count; rounding does
		/// not apply.</item>
		/// </list>
		/// The result is never below 1. A Size Rounding value that is not understood gives null (no check) and a
		/// diagnostic, because a wrong guess would put false warnings on real copies.
		/// </summary>
		public static int? ExpectedQuantity(IList<int> masterEntryOrders, decimal size, string roundingMode, string multiplierMode,
			bool executionsMode, IList<string> diagnostics, bool sizeColumnEnabled = true)
		{
			if (masterEntryOrders == null || masterEntryOrders.Count == 0)
				return null;

			if (!sizeColumnEnabled)
				return masterEntryOrders.Sum();

			string mode = (multiplierMode ?? "").Trim();
			if (string.Equals(mode, QuantityModeName, StringComparison.OrdinalIgnoreCase))
			{
				int perOrder = Math.Max(1, (int)Math.Round(size, MidpointRounding.AwayFromZero));
				return perOrder * masterEntryOrders.Count;
			}

			if (!string.Equals(mode, MultiplierModeName, StringComparison.OrdinalIgnoreCase))
			{
				AddOnce(diagnostics, "size calculation '" + multiplierMode + "' is not understood; expected quantity is not checked");
				return null;
			}

			if (executionsMode)
			{
				int? total = Resolve(masterEntryOrders.Sum() * size, roundingMode, diagnostics);
				return total.HasValue ? Math.Max(1, total.Value) : (int?)null;
			}

			int sum = 0;
			foreach (int orderQuantity in masterEntryOrders)
			{
				int? resolved = Resolve(orderQuantity * size, roundingMode, diagnostics);
				if (!resolved.HasValue)
					return null;
				sum += Math.Max(1, resolved.Value);
			}
			return sum;
		}

		private static int? Resolve(decimal value, string roundingMode, IList<string> diagnostics)
		{
			if (value == decimal.Truncate(value))
				return (int)value; // no fraction, so the rounding setting does not matter

			string mode = (roundingMode ?? "").Trim();
			if (string.Equals(mode, RoundUpAtHalf, StringComparison.OrdinalIgnoreCase))
				return (int)Math.Round(value, MidpointRounding.AwayFromZero);
			if (string.Equals(mode, RoundDown, StringComparison.OrdinalIgnoreCase))
				return (int)decimal.Truncate(value);

			AddOnce(diagnostics, "rounding mode '" + roundingMode + "' is not understood; expected quantity is not checked");
			return null;
		}

		private static bool IsExpected(CopierSnapshot setup, FollowerSetup follower, CompletedTrade master)
		{
			if (!setup.Enabled || follower.Blown)
				return false;
			if (setup.AllInstruments)
				return true;
			string single = (setup.SingleInstrument ?? "").Trim();
			return string.Equals(single, master.Instrument.Symbol, StringComparison.OrdinalIgnoreCase)
				|| string.Equals(single, master.Instrument.FullName, StringComparison.OrdinalIgnoreCase);
		}

		/// <summary>
		/// The copier's Select Trade Direction filter (vendor-confirmed 2026-09-28) only blocks a master entry that
		/// opens a position from flat, never a scale-in, exit or reversal (even one into the blocked direction), and
		/// only for Executions-mode followers. Such a follower is not expected, like one outside Single instrument.
		/// </summary>
		private static bool DirectionFiltered(CopierSnapshot setup, FollowerSetup follower, CompletedTrade master)
		{
			if (!setup.SelectTradeDirectionEnabled || !follower.ExecutionsMode || master.OpenedByReversal)
				return false;
			return master.Direction == Direction.Long ? !setup.AllowLong : !setup.AllowShort;
		}

		// ---- auto-detect

		private static IEnumerable<CopyResult> EvaluateAutoDetect(CompletedTrade master, MatchOptions options, List<CopyCandidate> all)
		{
			DateTimeOffset lower = master.EntryAt - options.Window;
			DateTimeOffset upper = master.EntryAt + options.Window;
			List<CopyResult> results = new List<CopyResult>();
			HashSet<string> handled = new HashSet<string>(StringComparer.OrdinalIgnoreCase);

			foreach (IGrouping<string, CopyCandidate> group in all.Where(c => Eligible(c.Account, options)).GroupBy(c => c.Account, StringComparer.OrdinalIgnoreCase))
			{
				CopyCandidate match = group
					.Where(c => c.EntryAt >= lower && c.EntryAt <= upper && c.Direction == master.Direction)
					.Where(c => options.Families.SameMarket(master.Instrument, c.Instrument))
					.OrderBy(c => c.EntryAt)
					.FirstOrDefault();
				if (match == null)
					continue;

				handled.Add(group.Key);
				results.Add(Build(match, null, new List<string>(), master, ContractSize.Mini, options));
			}

			// Only accounts matched as a copy within the history window can be reported missed.
			DateTimeOffset since = master.EntryAt - TimeSpan.FromDays(options.HistoryDays);
			foreach (KeyValuePair<string, DateTimeOffset> seen in options.AutoDetectHistory.OrderBy(p => p.Key, StringComparer.OrdinalIgnoreCase))
			{
				if (handled.Contains(seen.Key) || seen.Value < since || !Eligible(seen.Key, options) || SameAccount(seen.Key, master.Account))
					continue;

				results.Add(new CopyResult
				{
					Account = seen.Key,
					Outcome = CopyOutcome.Missed,
					Warnings = new List<string> { "no entry within " + options.Window.TotalSeconds.ToString("0.###", CultureInfo.InvariantCulture) + " seconds" },
					Fills = new List<TradeFill>()
				});
			}
			return results;
		}

		private static bool Eligible(string account, MatchOptions options)
		{
			if (options.JournaledMasters.Contains(account))
				return false;
			foreach (string pattern in options.NeverCopy)
			{
				if (pattern.EndsWith("*", StringComparison.Ordinal))
				{
					if (account.StartsWith(pattern.Substring(0, pattern.Length - 1), StringComparison.OrdinalIgnoreCase))
						return false;
				}
				else if (string.Equals(account, pattern, StringComparison.OrdinalIgnoreCase))
				{
					return false;
				}
			}
			return true;
		}

		// ---- results

		private static CopyResult Build(CopyCandidate candidate, ExpectedCopy expected, List<string> warnings,
			CompletedTrade master, ContractSize size, MatchOptions options)
		{
			CopyResult result = new CopyResult
			{
				Account = candidate.Account,
				Instrument = candidate.Instrument,
				Expected = expected,
				Warnings = warnings,
				Direction = candidate.Direction,
				EnteredAt = candidate.EntryAt
			};

			if (candidate.IsOpen)
			{
				PartialTrade partial = PartialTrade.Compute(candidate.Open.Fills, candidate.Direction, candidate.Instrument);
				result.Outcome = CopyOutcome.StillOpen;
				result.Quantity = partial.Quantity;
				result.EntryAveragePrice = partial.AverageEntryPrice;
				result.ExitAveragePrice = partial.AverageExitPrice;
				result.Performance = partial.Performance;
				result.Fills = candidate.Open.Fills;
			}
			else
			{
				CompletedTrade t = candidate.Trade;
				result.Outcome = CopyOutcome.Matched;
				result.Quantity = t.Quantity;
				result.EntryAveragePrice = t.AverageEntryPrice;
				result.ExitAveragePrice = t.AverageExitPrice;
				result.ExitedAt = t.ExitAt;
				result.Performance = new CopyPerformance
				{
					Points = t.Points, Ticks = t.Ticks, GrossPnl = t.GrossPnl,
					Commission = t.Commission, Fees = t.Fees, NetPnl = t.NetPnl
				};
				result.Fills = t.Fills;
			}

			if (expected != null)
			{
				string expectedSymbol = options.Families.ExpectedSymbol(master.Instrument, size);
				if (expectedSymbol != null && !string.Equals(expectedSymbol, candidate.Instrument.Symbol, StringComparison.OrdinalIgnoreCase)
					&& !warnings.Contains("nano contract; instrument not checked"))
				{
					warnings.Add("expected " + expectedSymbol + ", traded " + candidate.Instrument.Symbol);
				}
				// An open copy may still be scaling, so only a finished one is checked for quantity.
				if (!candidate.IsOpen && expected.Quantity.HasValue && expected.Quantity.Value != candidate.Trade.TotalEntryQuantity)
				{
					warnings.Add("expected " + expected.Quantity.Value.ToString(CultureInfo.InvariantCulture) + " "
						+ (expectedSymbol ?? candidate.Instrument.Symbol) + ", filled "
						+ candidate.Trade.TotalEntryQuantity.ToString(CultureInfo.InvariantCulture) + " " + candidate.Instrument.Symbol);
				}
			}
			return result;
		}

		private static CopySummary Summarize(IList<CopyResult> results)
		{
			if (results.Count == 0)
				return null;

			return new CopySummary
			{
				Accounts = results.Count,
				Matched = results.Count(r => r.Outcome == CopyOutcome.Matched),
				Missed = results.Count(r => r.Outcome == CopyOutcome.Missed),
				StillOpen = results.Count(r => r.Outcome == CopyOutcome.StillOpen),
				NetPnl = results.Where(r => r.Performance != null).Sum(r => r.Performance.NetPnl)
			};
		}

		private static string FollowerSymbol(IEnumerable<CopyCandidate> all, string account)
		{
			CopyCandidate first = all.FirstOrDefault(c => SameAccount(c.Account, account));
			return first == null ? "" : first.Instrument.Symbol;
		}

		private static string DirectionWord(Direction d)
		{
			return d == Direction.Long ? "long" : "short";
		}

		private static Direction Opposite(Direction d)
		{
			return d == Direction.Long ? Direction.Short : Direction.Long;
		}

		private static bool SameAccount(string a, string b)
		{
			return string.Equals(a, b, StringComparison.OrdinalIgnoreCase);
		}

		private static void AddOnce(IList<string> list, string message)
		{
			if (list != null && !list.Contains(message))
				list.Add(message);
		}
	}
}
