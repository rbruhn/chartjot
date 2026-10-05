using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;

namespace ChartJot.Core
{
	/// <summary>The five states the panel shows for a submission (NT8.md, "Clear delivery state").</summary>
	public enum DeliveryState
	{
		Pending,
		Sending,
		Sent,
		QueuedForRetry,
		Failed
	}

	/// <summary>What a send attempt resolved to. The caller (NT8 layer) maps its HTTP result onto this.</summary>
	public enum DeliveryOutcome
	{
		/// <summary>HTTP 201: a new trade was accepted.</summary>
		Accepted,

		/// <summary>HTTP 200: the server already had this trade_id and accepted the retry idempotently.</summary>
		AcceptedIdempotent,

		/// <summary>Timeout, connection error, or HTTP 5xx. Keep the same payload and back off; after the last automatic attempt the delivery fails and waits for a manual retry.</summary>
		Retryable,

		/// <summary>HTTP 401 or 403. The intake token is wrong; automatic retries stop for the whole queue.</summary>
		ConfigurationError,

		/// <summary>HTTP 422. The payload is retained; the trader must resolve it and retry manually.</summary>
		ValidationError
	}

	/// <summary>One frozen trade payload and its delivery history.</summary>
	public sealed class QueuedDelivery
	{
		public string TradeId { get; internal set; }
		public string PayloadJson { get; internal set; }
		public DeliveryState State { get; internal set; }
		public int Attempts { get; internal set; }
		public DateTimeOffset? NextAttemptAt { get; internal set; }
		public DateTimeOffset? SentAt { get; internal set; }

		/// <summary>The HTTP status of the last response, whatever it was (2xx included); null for a timeout,
		/// connection error or cancellation, when no response arrived.</summary>
		public int? LastStatusCode { get; internal set; }

		public string LastServerBody { get; internal set; }

		/// <summary>Set for a timeout or connection error; null when the server responded.</summary>
		public string LastErrorMessage { get; internal set; }

		/// <summary>True when <see cref="State"/> is <see cref="DeliveryState.Failed"/> because of a 401/403, not a 422.</summary>
		public bool IsConfigurationError { get; internal set; }
	}

	/// <summary>
	/// Holds one frozen JSON payload per trade_id and tracks its delivery state so a trade is never lost and never
	/// resent with different content (NT8.md, "Success and retry behavior"). Has no NinjaTrader types and does no
	/// I/O or networking itself: the caller supplies "now" and reports the outcome of each send attempt.
	/// </summary>
	public sealed class DeliveryQueue
	{
		private readonly Dictionary<string, QueuedDelivery> byTradeId = new Dictionary<string, QueuedDelivery>();
		private readonly List<QueuedDelivery> order = new List<QueuedDelivery>();
		private readonly TimeSpan initialBackoff;
		private readonly TimeSpan maxBackoff;
		private readonly int maxAutomaticAttempts;

		/// <param name="maxAutomaticAttempts">Send attempts before a timeout, connection error or 5xx stops being
		/// retried automatically and the delivery fails (the trader retries it by hand). Default 3.</param>
		public DeliveryQueue(TimeSpan? initialBackoff = null, TimeSpan? maxBackoff = null, int maxAutomaticAttempts = 3)
		{
			if (maxAutomaticAttempts < 1)
				throw new ArgumentOutOfRangeException("maxAutomaticAttempts");
			this.initialBackoff = initialBackoff ?? TimeSpan.FromSeconds(5);
			this.maxBackoff = maxBackoff ?? TimeSpan.FromMinutes(5);
			this.maxAutomaticAttempts = maxAutomaticAttempts;
		}

		/// <summary>Send attempts per delivery before it fails and waits for the trader's Retry.</summary>
		public int MaxAutomaticAttempts
		{
			get { return maxAutomaticAttempts; }
		}

		/// <summary>
		/// True once any queued delivery has hit a 401/403. While true, <see cref="Due"/> returns nothing: a bad
		/// token fails every request, so there is no point retrying the rest of the queue automatically.
		/// </summary>
		public bool ConfigurationErrorHalted { get; private set; }

		public IList<QueuedDelivery> All
		{
			get { return new List<QueuedDelivery>(order); }
		}

		public QueuedDelivery Find(string tradeId)
		{
			QueuedDelivery d;
			return byTradeId.TryGetValue(tradeId, out d) ? d : null;
		}

		/// <summary>
		/// Freezes a payload for delivery. Calling this again for a trade_id already queued replaces the payload
		/// and restarts delivery from Pending (the "changing it after a 422 produces a new frozen payload with the
		/// same trade_id" case). Calling it again once the trade is already Sent is a no-op: a sent trade is never
		/// resent from here.
		/// </summary>
		public QueuedDelivery Enqueue(string tradeId, string payloadJson)
		{
			if (string.IsNullOrEmpty(tradeId))
				throw new ArgumentException("A trade_id is required.", "tradeId");
			if (payloadJson == null)
				throw new ArgumentNullException("payloadJson");

			QueuedDelivery existing;
			if (byTradeId.TryGetValue(tradeId, out existing))
			{
				if (existing.State == DeliveryState.Sent)
					return existing;

				existing.PayloadJson = payloadJson;
				existing.State = DeliveryState.Pending;
				existing.Attempts = 0;
				existing.NextAttemptAt = null;
				existing.LastStatusCode = null;
				existing.LastServerBody = null;
				existing.LastErrorMessage = null;
				existing.IsConfigurationError = false;
				return existing;
			}

			QueuedDelivery created = new QueuedDelivery
			{
				TradeId = tradeId,
				PayloadJson = payloadJson,
				State = DeliveryState.Pending
			};
			byTradeId[tradeId] = created;
			order.Add(created);
			return created;
		}

		/// <summary>Deliveries ready to send now, in the order they were queued. Empty while halted by a 401/403.</summary>
		public IList<QueuedDelivery> Due(DateTimeOffset now)
		{
			if (ConfigurationErrorHalted)
				return new List<QueuedDelivery>();

			return order.Where(d => d.State == DeliveryState.Pending
				|| (d.State == DeliveryState.QueuedForRetry && d.NextAttemptAt.HasValue && d.NextAttemptAt.Value <= now))
				.ToList();
		}

		/// <summary>Marks a due delivery as in flight so it is not picked up twice while its HTTP call is pending.</summary>
		public void MarkSending(string tradeId)
		{
			QueuedDelivery d = Require(tradeId);
			if (d.State != DeliveryState.Pending && d.State != DeliveryState.QueuedForRetry)
				throw new InvalidOperationException("Trade " + tradeId + " is not due for sending (state: " + d.State + ").");
			d.State = DeliveryState.Sending;
		}

		/// <summary>Applies the result of a send attempt started with <see cref="MarkSending"/>.</summary>
		public void RecordResult(string tradeId, DeliveryOutcome outcome, DateTimeOffset now,
			int? statusCode = null, string serverBody = null, string errorMessage = null)
		{
			QueuedDelivery d = Require(tradeId);
			d.LastStatusCode = statusCode;
			d.LastServerBody = serverBody;
			d.LastErrorMessage = errorMessage;

			switch (outcome)
			{
				case DeliveryOutcome.Accepted:
				case DeliveryOutcome.AcceptedIdempotent:
					d.State = DeliveryState.Sent;
					d.NextAttemptAt = null;
					d.SentAt = now;
					break;

				case DeliveryOutcome.Retryable:
					d.Attempts++;
					if (d.Attempts >= maxAutomaticAttempts)
					{
						// Out of automatic attempts: keep the payload and wait for the trader's Retry.
						d.State = DeliveryState.Failed;
						d.NextAttemptAt = null;
						d.IsConfigurationError = false;
						break;
					}
					d.State = DeliveryState.QueuedForRetry;
					d.NextAttemptAt = now + NextBackoff(d.Attempts, initialBackoff, maxBackoff);
					break;

				case DeliveryOutcome.ConfigurationError:
					d.State = DeliveryState.Failed;
					d.NextAttemptAt = null;
					d.IsConfigurationError = true;
					ConfigurationErrorHalted = true;
					break;

				case DeliveryOutcome.ValidationError:
					d.State = DeliveryState.Failed;
					d.NextAttemptAt = null;
					d.IsConfigurationError = false;
					break;

				default:
					throw new ArgumentOutOfRangeException("outcome");
			}
		}

		/// <summary>
		/// The trader asked to retry a Failed delivery (journal reachable again, token corrected, or validation issue
		/// resolved) with its existing payload unchanged, with a fresh set of automatic attempts. Also lifts <see cref="ConfigurationErrorHalted"/>, since acting on it is
		/// the trader's signal that the token is fixed.
		/// </summary>
		public void RetryManually(string tradeId, DateTimeOffset now)
		{
			QueuedDelivery d = Require(tradeId);
			if (d.State != DeliveryState.Failed)
				throw new InvalidOperationException("Trade " + tradeId + " is not Failed (state: " + d.State + ").");

			d.State = DeliveryState.Pending;
			d.Attempts = 0;
			d.NextAttemptAt = null;
			ConfigurationErrorHalted = false;
		}

		/// <summary>Every Failed delivery, in the order queued: what the trader still has to retry.</summary>
		public IList<QueuedDelivery> FailedDeliveries
		{
			get { return order.Where(d => d.State == DeliveryState.Failed).ToList(); }
		}

		/// <summary>
		/// The trader clicked Retry: every Failed delivery goes back to Pending with a fresh set of automatic attempts,
		/// its payload unchanged (see <see cref="RetryManually"/>). Returns the retried trade_ids.
		/// </summary>
		public IList<string> RetryAllFailed(DateTimeOffset now)
		{
			List<string> retried = FailedDeliveries.Select(d => d.TradeId).ToList();
			foreach (string tradeId in retried)
				RetryManually(tradeId, now);
			ConfigurationErrorHalted = false;
			return retried;
		}

		/// <summary>Exponential backoff from attempt 1, doubling each time and capped at max.</summary>
		public static TimeSpan NextBackoff(int attempt, TimeSpan initial, TimeSpan max)
		{
			if (attempt < 1)
				throw new ArgumentOutOfRangeException("attempt");

			double factor = Math.Pow(2, attempt - 1);
			double ms = initial.TotalMilliseconds * factor;
			if (double.IsInfinity(ms) || ms > max.TotalMilliseconds)
				return max;
			return TimeSpan.FromMilliseconds(ms);
		}

		private QueuedDelivery Require(string tradeId)
		{
			QueuedDelivery d = Find(tradeId);
			if (d == null)
				throw new InvalidOperationException("No delivery queued for trade " + tradeId + ".");
			return d;
		}

		/// <summary>The state-file JSON for this queue: <see cref="ConfigurationErrorHalted"/> plus every
		/// delivery, in enqueue order, with its frozen payload and full history.</summary>
		public string Serialize()
		{
			JsonWriter w = new JsonWriter();
			w.BeginObject();
			w.Property("configuration_error_halted", ConfigurationErrorHalted);
			w.Name("deliveries").BeginArray();
			foreach (QueuedDelivery d in order)
			{
				w.BeginObject();
				w.Property("trade_id", d.TradeId);
				w.Property("payload_json", d.PayloadJson);
				w.Property("state", d.State.ToString());
				w.Property("attempts", d.Attempts);
				w.Property("next_attempt_at", FormatTimestamp(d.NextAttemptAt));
				w.Property("sent_at", FormatTimestamp(d.SentAt));
				w.Name("last_status_code");
				if (d.LastStatusCode.HasValue)
					w.Int(d.LastStatusCode.Value);
				else
					w.Null();
				w.Property("last_server_body", d.LastServerBody);
				w.Property("last_error_message", d.LastErrorMessage);
				w.Property("is_configuration_error", d.IsConfigurationError);
				w.EndObject();
			}
			w.EndArray();
			w.EndObject();
			return w.ToString();
		}

		/// <summary>Rebuilds a queue from <see cref="Serialize"/>'s output. Backoff timing for any future
		/// retries uses this queue's own <paramref name="initialBackoff"/>/<paramref name="maxBackoff"/>, not
		/// whatever produced the file. A delivery saved while <see cref="DeliveryState.Sending"/> never had its answer
		/// recorded (NT8 closed mid-request), so it comes back <see cref="DeliveryState.Pending"/> to be sent again;
		/// the server's Idempotency-Key check makes that safe if the first request did arrive.</summary>
		public static DeliveryQueue Deserialize(string json, TimeSpan? initialBackoff = null, TimeSpan? maxBackoff = null)
		{
			return FromJson(JsonValue.Parse(json), initialBackoff, maxBackoff);
		}

		internal static DeliveryQueue FromJson(JsonValue root, TimeSpan? initialBackoff, TimeSpan? maxBackoff)
		{
			DeliveryQueue queue = new DeliveryQueue(initialBackoff, maxBackoff);

			foreach (JsonValue item in root["deliveries"].Items)
			{
				QueuedDelivery d = new QueuedDelivery
				{
					TradeId = item["trade_id"].AsString(),
					PayloadJson = item["payload_json"].AsString(),
					State = ParseState(item["state"].AsString()),
					Attempts = item["attempts"].AsInt32(),
					NextAttemptAt = ParseTimestamp(item["next_attempt_at"]),
					SentAt = ParseTimestamp(item["sent_at"]),
					LastStatusCode = item["last_status_code"].IsNull ? (int?)null : item["last_status_code"].AsInt32(),
					LastServerBody = item["last_server_body"].AsString(),
					LastErrorMessage = item["last_error_message"].AsString(),
					IsConfigurationError = item["is_configuration_error"].AsBool()
				};
				Resume(d);
				queue.byTradeId[d.TradeId] = d;
				queue.order.Add(d);
			}

			queue.ConfigurationErrorHalted = root["configuration_error_halted"].AsBool();
			return queue;
		}

		private static string FormatTimestamp(DateTimeOffset? value)
		{
			return value.HasValue ? PayloadBuilder.Timestamp(value.Value) : null;
		}

		private static DateTimeOffset? ParseTimestamp(JsonValue value)
		{
			return value.IsNull ? (DateTimeOffset?)null : DateTimeOffset.Parse(value.AsString(), CultureInfo.InvariantCulture, DateTimeStyles.None);
		}

		// A delivery saved mid-request goes back to Pending with no scheduled retry, like a fresh one.
		private static void Resume(QueuedDelivery d)
		{
			if (d.State != DeliveryState.Sending)
				return;
			d.State = DeliveryState.Pending;
			d.NextAttemptAt = null;
		}

		private static DeliveryState ParseState(string value)
		{
			try
			{
				return (DeliveryState)Enum.Parse(typeof(DeliveryState), value);
			}
			catch (Exception ex)
			{
				throw new FormatException("Unrecognized delivery state '" + value + "' in the state file.", ex);
			}
		}
	}
}
