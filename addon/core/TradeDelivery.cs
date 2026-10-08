using System;
using System.Collections.Generic;
using System.IO;
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
		/// <summary>Server bodies kept for display are cut to at most this many bytes once UTF-8 encoded, so a proxy's
		/// HTML error page cannot bloat the state file. No more than this is ever read off the wire either: the rest
		/// of a longer body is discarded unread, so a huge response cannot exhaust memory in the NT8 process.</summary>
		public const int MaxServerBodyLength = 16 * 1024;

		/// <summary>The longest timeout a <see cref="CancellationTokenSource"/> accepts (int.MaxValue ms, ~24.8 days).</summary>
		public static readonly TimeSpan MaxTimeout = TimeSpan.FromMilliseconds(int.MaxValue);

		private static readonly Encoding Utf8 = new UTF8Encoding(false);

		private readonly HttpClient client;
		private readonly Uri endpoint;
		private readonly string token;
		private readonly string userAgent;
		private readonly TimeSpan timeout;
		private readonly Func<DateTimeOffset> clock;

		/// <summary>
		/// Whether the AddOn may send to this endpoint: HTTPS, or plain HTTP to a journal on this PC (localhost,
		/// 127.0.0.1, ::1), which is how a self-hosted journal runs (#102). The trades never leave the machine then.
		/// </summary>
		public static bool IsAllowedEndpoint(Uri uri)
		{
			return uri != null && uri.IsAbsoluteUri
				&& (uri.Scheme == Uri.UriSchemeHttps || (uri.Scheme == Uri.UriSchemeHttp && uri.IsLoopback));
		}

		/// <param name="allowInsecureHttp">Only for a local development server on another host name. HTTP to this PC is always allowed.</param>
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
			if (timeout > MaxTimeout)
				throw new ArgumentOutOfRangeException("timeout", "The timeout must not exceed " + MaxTimeout.TotalDays.ToString("0.#", System.Globalization.CultureInfo.InvariantCulture) + " days.");

			Uri uri;
			if (!Uri.TryCreate(endpoint, UriKind.Absolute, out uri))
				throw new ArgumentException("The journal endpoint must be an absolute URL.", "endpoint");
			if (!IsAllowedEndpoint(uri) && !(allowInsecureHttp && uri.Scheme == Uri.UriSchemeHttp))
				throw new ArgumentException("The journal endpoint must use HTTPS, or HTTP to this PC.", "endpoint");

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
		public Task<DeliveryAttempt> SendAsync(QueuedDelivery delivery, DeliveryScreenshot screenshot,
			CancellationToken cancellationToken = default(CancellationToken))
		{
			return SendAsync(delivery, screenshot, null, cancellationToken);
		}

		/// <summary>As above, with the entry image (#52) as the <c>entry_screenshot_file</c> part when given.</summary>
		public async Task<DeliveryAttempt> SendAsync(QueuedDelivery delivery, DeliveryScreenshot screenshot, DeliveryScreenshot entryScreenshot,
			CancellationToken cancellationToken = default(CancellationToken))
		{
			if (delivery == null)
				throw new ArgumentNullException("delivery");

			using (HttpRequestMessage request = BuildRequest(delivery, screenshot, entryScreenshot))
			using (CancellationTokenSource timeoutSource = new CancellationTokenSource(timeout))
			using (CancellationTokenSource linked = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken, timeoutSource.Token))
			{
				try
				{
					// ResponseHeadersRead: the default would buffer the whole body before returning, whatever its size.
					using (HttpResponseMessage response = await client.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, linked.Token).ConfigureAwait(false))
					{
						int status = (int)response.StatusCode;
						string body = await ReadBodyAsync(response.Content, linked.Token).ConfigureAwait(false);
						return new DeliveryAttempt { Outcome = MapStatus(status), StatusCode = status, ServerBody = body };
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
				catch (IOException ex)
				{
					// The connection dropped while the body was being read.
					return new DeliveryAttempt { Outcome = DeliveryOutcome.Retryable, ErrorMessage = ex.Message };
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

		private HttpRequestMessage BuildRequest(QueuedDelivery delivery, DeliveryScreenshot screenshot, DeliveryScreenshot entryScreenshot)
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
			if (entryScreenshot != null)
			{
				if (!IsUsable(entryScreenshot))
					throw new ArgumentException("An entry image must be non-empty PNG or JPEG.", "entryScreenshot");
				ByteArrayContent image = new ByteArrayContent(entryScreenshot.Bytes);
				image.Headers.ContentType = new MediaTypeHeaderValue(MediaType(entryScreenshot.Format));
				content.Add(image, "entry_screenshot_file", delivery.TradeId + "-entry." + Extension(entryScreenshot.Format));
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

		/// <summary>
		/// Reads at most <see cref="MaxServerBodyLength"/> bytes of the body and decodes them with the response's
		/// charset (UTF-8 when absent or unknown). A multi-byte character cut off by the read limit is dropped, not
		/// turned into a replacement character, and the result is trimmed to fit <see cref="MaxServerBodyLength"/>
		/// UTF-8 bytes, since another charset can take more bytes than that once re-encoded.
		/// </summary>
		private static async Task<string> ReadBodyAsync(HttpContent content, CancellationToken cancellationToken)
		{
			if (content == null)
				return null;

			byte[] buffer = new byte[MaxServerBodyLength];
			int read = 0;
			using (Stream stream = await content.ReadAsStreamAsync().ConfigureAwait(false))
			{
				while (read < buffer.Length)
				{
					int n = await stream.ReadAsync(buffer, read, buffer.Length - read, cancellationToken).ConfigureAwait(false);
					if (n == 0)
						break;
					read += n;
				}
			}
			// Anything past the limit is left unread; disposing the response abandons it.

			Decoder decoder = ResponseEncoding(content).GetDecoder();
			char[] chars = new char[decoder.GetCharCount(buffer, 0, read, false)];
			int count = decoder.GetChars(buffer, 0, read, chars, 0, false);
			return LimitUtf8Bytes(new string(chars, 0, count), MaxServerBodyLength);
		}

		private static Encoding ResponseEncoding(HttpContent content)
		{
			string charset = content.Headers.ContentType == null ? null : content.Headers.ContentType.CharSet;
			if (!string.IsNullOrWhiteSpace(charset))
			{
				try
				{
					return Encoding.GetEncoding(charset.Trim().Trim('"'));
				}
				catch (ArgumentException)
				{
					// Unknown charset: fall back to UTF-8.
				}
			}
			return Utf8;
		}

		/// <summary>Cuts <paramref name="text"/> to at most <paramref name="maxBytes"/> bytes of UTF-8, never
		/// splitting a surrogate pair.</summary>
		private static string LimitUtf8Bytes(string text, int maxBytes)
		{
			if (text == null || Utf8.GetByteCount(text) <= maxBytes)
				return text;

			int bytes = 0;
			int i = 0;
			while (i < text.Length)
			{
				int width = char.IsHighSurrogate(text[i]) && i + 1 < text.Length && char.IsLowSurrogate(text[i + 1]) ? 2 : 1;
				int size = Utf8.GetByteCount(text.ToCharArray(i, width));
				if (bytes + size > maxBytes)
					break;
				bytes += size;
				i += width;
			}
			return text.Substring(0, i);
		}

		private static string Describe(HttpRequestException ex)
		{
			// The inner exception (socket, DNS, TLS) is usually the useful part. Neither carries request headers.
			Exception inner = ex.InnerException;
			return inner == null ? ex.Message : ex.Message + " (" + inner.Message + ")";
		}
	}
}
