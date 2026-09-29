using System;
using System.Collections.Generic;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Threading;
using System.Threading.Tasks;

namespace ChartJot.Core
{
	/// <summary>The chart image sent as the optional <c>screenshot_file</c> part.</summary>
	public sealed class DeliveryScreenshot
	{
		public byte[] Bytes { get; set; }

		/// <summary>"png" or "jpeg" (<see cref="ScreenshotMeta.Format"/>).</summary>
		public string Format { get; set; }
	}

	/// <summary>What one send attempt came back with, before it is applied to the queue.</summary>
	public sealed class DeliveryAttempt
	{
		public DeliveryOutcome Outcome { get; set; }

		/// <summary>Null when no response arrived (timeout, connection error, cancellation).</summary>
		public int? StatusCode { get; set; }

		public string ServerBody { get; set; }

		/// <summary>Set when no response arrived.</summary>
		public string ErrorMessage { get; set; }
	}

	/// <summary>One delivery the pump attempted, for the "delivery result and server status" line.</summary>
	public sealed class DeliveryReport
	{
		public string TradeId { get; set; }
		public DeliveryOutcome Outcome { get; set; }
		public int? StatusCode { get; set; }
		public string ErrorMessage { get; set; }
		public bool ScreenshotAttached { get; set; }

		/// <summary>Set when the screenshot could not be loaded; the trade was sent without it.</summary>
		public string ScreenshotError { get; set; }
	}

	/// <summary>
	/// Sends queued trades to the Chart Jot intake endpoint (NT8.md, "Delivery Requirements"): one multipart
	/// <c>POST</c> per trade with the frozen payload, the trade_id as <c>Idempotency-Key</c>, and a finite timeout,
	/// and maps the response onto <see cref="DeliveryOutcome"/> for <see cref="DeliveryQueue"/>. Has no NinjaTrader
	/// types. The caller supplies one shared <see cref="HttpClient"/> (with TLS 1.2+ enabled on .NET Framework) and an
	/// already-decrypted token; the token is only ever written to the Authorization header.
	/// </summary>
	public sealed class TradeDelivery
	{
		/// <summary>Server bodies kept for display are cut to this many characters, so a proxy's HTML error page
		/// cannot bloat the state file.</summary>
		public const int MaxServerBodyLength = 16 * 1024;

		private readonly HttpClient client;
		private readonly Uri endpoint;
		private readonly string token;
		private readonly string userAgent;
		private readonly TimeSpan timeout;
		private readonly Func<DateTimeOffset> clock;

		/// <param name="allowInsecureHttp">Only for a local development server. Production requires HTTPS.</param>
		public TradeDelivery(HttpClient client, string endpoint, string token, string addonVersion, TimeSpan timeout,
			Func<DateTimeOffset> clock = null, bool allowInsecureHttp = false)
		{
			if (client == null)
				throw new ArgumentNullException("client");
			if (string.IsNullOrWhiteSpace(token))
				throw new ArgumentException("An intake token is required.", "token");
			if (string.IsNullOrWhiteSpace(addonVersion))
				throw new ArgumentException("The AddOn version is required.", "addonVersion");
			if (timeout <= TimeSpan.Zero || timeout == Timeout.InfiniteTimeSpan)
				throw new ArgumentOutOfRangeException("timeout", "The timeout must be finite and positive.");

			Uri uri;
			if (!Uri.TryCreate(endpoint, UriKind.Absolute, out uri))
				throw new ArgumentException("The journal endpoint must be an absolute URL.", "endpoint");
			if (uri.Scheme != Uri.UriSchemeHttps && !(allowInsecureHttp && uri.Scheme == Uri.UriSchemeHttp))
				throw new ArgumentException("The journal endpoint must use HTTPS.", "endpoint");

			this.client = client;
			this.endpoint = uri;
			this.token = token;
			userAgent = "ChartJot-NT8/" + addonVersion;
			this.timeout = timeout;
			this.clock = clock ?? (() => DateTimeOffset.Now);
		}

		/// <summary>
		/// Maps an HTTP status onto a delivery outcome (NT8.md, "Success and retry behavior"). Beyond the documented
		/// codes: 408 and 429 are transient by definition, so they retry like a 5xx. Anything else (another 2xx, a
		/// 3xx that was not followed, 400, 404, 413, ...) is not something retrying unchanged will fix, and it is not
		/// a success either: it maps to <see cref="DeliveryOutcome.ValidationError"/>, which keeps the payload and
		/// the server body and waits for the trader's manual retry. A trade is never marked sent on a response the
		/// contract does not define as success.
		/// </summary>
		public static DeliveryOutcome MapStatus(int statusCode)
		{
			switch (statusCode)
			{
				case 201:
					return DeliveryOutcome.Accepted;
				case 200:
					return DeliveryOutcome.AcceptedIdempotent;
				case 401:
				case 403:
					return DeliveryOutcome.ConfigurationError;
				case 408:
				case 429:
					return DeliveryOutcome.Retryable;
				case 422:
					return DeliveryOutcome.ValidationError;
			}
			return statusCode >= 500 && statusCode <= 599 ? DeliveryOutcome.Retryable : DeliveryOutcome.ValidationError;
		}

		/// <summary>
		/// Sends one delivery's frozen payload, with the screenshot when given. Never throws for a network failure,
		/// a timeout or a cancellation: those come back as <see cref="DeliveryOutcome.Retryable"/>.
		/// </summary>
		public async Task<DeliveryAttempt> SendAsync(QueuedDelivery delivery, DeliveryScreenshot screenshot,
			CancellationToken cancellationToken = default(CancellationToken))
		{
			if (delivery == null)
				throw new ArgumentNullException("delivery");

			using (HttpRequestMessage request = BuildRequest(delivery, screenshot))
			using (CancellationTokenSource timeoutSource = new CancellationTokenSource(timeout))
			using (CancellationTokenSource linked = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken, timeoutSource.Token))
			{
				try
				{
					using (HttpResponseMessage response = await client.SendAsync(request, linked.Token).ConfigureAwait(false))
					{
						string body = response.Content == null ? null : await response.Content.ReadAsStringAsync().ConfigureAwait(false);
						int status = (int)response.StatusCode;
						return new DeliveryAttempt { Outcome = MapStatus(status), StatusCode = status, ServerBody = Truncate(body) };
					}
				}
				catch (OperationCanceledException)
				{
					string message = cancellationToken.IsCancellationRequested
						? "Cancelled before a response arrived"
						: "Timed out after " + timeout.TotalSeconds.ToString("0.###", System.Globalization.CultureInfo.InvariantCulture) + " seconds";
					return new DeliveryAttempt { Outcome = DeliveryOutcome.Retryable, ErrorMessage = message };
				}
				catch (HttpRequestException ex)
				{
					return new DeliveryAttempt { Outcome = DeliveryOutcome.Retryable, ErrorMessage = Describe(ex) };
				}
			}
		}

		/// <summary>
		/// Sends every delivery that is due now, one at a time, driving <see cref="DeliveryQueue.MarkSending"/> and
		/// <see cref="DeliveryQueue.RecordResult"/> around each request. Call it from a background timer, never an NT8
		/// event thread. <see cref="DeliveryQueue"/> is not thread-safe, so the caller must not touch the queue from
		/// elsewhere while this runs, and should save the state file afterwards.
		/// <para>
		/// Stops early after a 401/403 (the queue halts: a bad token fails every request) and on cancellation.
		/// <paramref name="screenshots"/> returns the image for a trade_id, or null for none; if it throws, the trade
		/// is sent without the image rather than held back, since a missing screenshot never blocks submission.
		/// </para>
		/// </summary>
		public async Task<IList<DeliveryReport>> SendDueAsync(DeliveryQueue queue, Func<string, DeliveryScreenshot> screenshots = null,
			CancellationToken cancellationToken = default(CancellationToken))
		{
			if (queue == null)
				throw new ArgumentNullException("queue");

			List<DeliveryReport> reports = new List<DeliveryReport>();
			foreach (QueuedDelivery due in queue.Due(clock()))
			{
				if (queue.ConfigurationErrorHalted || cancellationToken.IsCancellationRequested)
					break;

				DeliveryReport report = new DeliveryReport { TradeId = due.TradeId };
				DeliveryScreenshot screenshot = null;
				if (screenshots != null)
				{
					try
					{
						screenshot = screenshots(due.TradeId);
					}
					catch (Exception ex)
					{
						report.ScreenshotError = ex.Message;
					}
				}
				if (screenshot != null && !IsUsable(screenshot))
				{
					report.ScreenshotError = "unsupported screenshot format '" + screenshot.Format + "' or empty image";
					screenshot = null;
				}

				queue.MarkSending(due.TradeId);
				DeliveryAttempt attempt;
				try
				{
					attempt = await SendAsync(due, screenshot, cancellationToken).ConfigureAwait(false);
				}
				catch (Exception ex)
				{
					// Anything unforeseen must still settle the delivery, or it would sit in Sending and never be due again.
					attempt = new DeliveryAttempt { Outcome = DeliveryOutcome.Retryable, ErrorMessage = ex.GetType().Name + ": " + ex.Message };
				}
				queue.RecordResult(due.TradeId, attempt.Outcome, clock(), attempt.StatusCode, attempt.ServerBody, attempt.ErrorMessage);

				report.Outcome = attempt.Outcome;
				report.StatusCode = attempt.StatusCode;
				report.ErrorMessage = attempt.ErrorMessage;
				report.ScreenshotAttached = screenshot != null;
				reports.Add(report);
			}
			return reports;
		}

		private HttpRequestMessage BuildRequest(QueuedDelivery delivery, DeliveryScreenshot screenshot)
		{
			MultipartFormDataContent content = new MultipartFormDataContent();
			content.Add(new StringContent(delivery.PayloadJson, new UTF8Encoding(false), "application/json"), "trade");
			if (screenshot != null)
			{
				if (!IsUsable(screenshot))
					throw new ArgumentException("A screenshot must be non-empty PNG or JPEG.", "screenshot");
				ByteArrayContent image = new ByteArrayContent(screenshot.Bytes);
				image.Headers.ContentType = new MediaTypeHeaderValue(MediaType(screenshot.Format));
				content.Add(image, "screenshot_file", delivery.TradeId + "." + Extension(screenshot.Format));
			}

			HttpRequestMessage request = new HttpRequestMessage(HttpMethod.Post, endpoint) { Content = content };
			request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token);
			request.Headers.TryAddWithoutValidation("Idempotency-Key", delivery.TradeId);
			request.Headers.TryAddWithoutValidation("User-Agent", userAgent);
			request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
			return request;
		}

		private static bool IsUsable(DeliveryScreenshot screenshot)
		{
			return screenshot.Bytes != null && screenshot.Bytes.Length > 0 && MediaType(screenshot.Format) != null;
		}

		private static string MediaType(string format)
		{
			string f = (format ?? "").Trim().ToLowerInvariant();
			if (f == "png")
				return "image/png";
			if (f == "jpeg" || f == "jpg")
				return "image/jpeg";
			return null;
		}

		// Same names as the files under {Data folder}\images\ (ScreenshotMeta.Format).
		private static string Extension(string format)
		{
			return MediaType(format) == "image/png" ? "png" : "jpeg";
		}

		private static string Truncate(string body)
		{
			if (body == null || body.Length <= MaxServerBodyLength)
				return body;
			return body.Substring(0, MaxServerBodyLength);
		}

		private static string Describe(HttpRequestException ex)
		{
			// The inner exception (socket, DNS, TLS) is usually the useful part. Neither carries request headers.
			Exception inner = ex.InnerException;
			return inner == null ? ex.Message : ex.Message + " (" + inner.Message + ")";
		}
	}
}
