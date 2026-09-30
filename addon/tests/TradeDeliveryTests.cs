using System.Net;
using System.Net.Http;
using System.Text;
using ChartJot.Core;

namespace ChartJot.Core.Tests
{
	public class TradeDeliveryTests
	{
		private const string Endpoint = "https://journal.test/api/v1/trades";
		private const string Token = "secret-intake-token";
		private static readonly DateTimeOffset Now = new DateTimeOffset(2026, 9, 29, 12, 0, 0, TimeSpan.Zero);

		// Answers every request with the given function and keeps what was sent, read while the request is live.
		private sealed class StubHandler : HttpMessageHandler
		{
			private readonly Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> respond;

			public StubHandler(Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> respond)
			{
				this.respond = respond;
			}

			public readonly List<SentRequest> Sent = new List<SentRequest>();

			protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
			{
				SentRequest sent = new SentRequest { Request = request, ContentType = request.Content.Headers.ContentType };
				foreach (HttpContent part in (MultipartFormDataContent)request.Content)
				{
					sent.Parts.Add(new SentPart
					{
						Name = part.Headers.ContentDisposition.Name.Trim('"'),
						FileName = part.Headers.ContentDisposition.FileName == null ? null : part.Headers.ContentDisposition.FileName.Trim('"'),
						ContentType = part.Headers.ContentType,
						Bytes = await part.ReadAsByteArrayAsync()
					});
				}
				Sent.Add(sent);
				return await respond(request, cancellationToken);
			}
		}

		private sealed class SentRequest
		{
			public HttpRequestMessage Request;
			public System.Net.Http.Headers.MediaTypeHeaderValue ContentType;
			public readonly List<SentPart> Parts = new List<SentPart>();

			public SentPart Part(string name)
			{
				return Parts.SingleOrDefault(p => p.Name == name);
			}
		}

		private sealed class SentPart
		{
			public string Name;
			public string FileName;
			public System.Net.Http.Headers.MediaTypeHeaderValue ContentType;
			public byte[] Bytes;
		}

		private static StubHandler Answer(HttpStatusCode status, string body = "{}")
		{
			return new StubHandler((r, ct) => Task.FromResult(new HttpResponseMessage(status) { Content = new StringContent(body) }));
		}

		private static TradeDelivery Delivery(HttpMessageHandler handler, Func<DateTimeOffset> clock = null, TimeSpan? timeout = null)
		{
			return new TradeDelivery(new HttpClient(handler), Endpoint, Token, "1.0.0", timeout ?? TimeSpan.FromSeconds(30), clock ?? (() => Now));
		}

		private static QueuedDelivery Queued(string tradeId = "NT8-A-ES_12_26-0123456789abcdef", string payload = "{\"trade_id\":\"x\"}")
		{
			DeliveryQueue queue = new DeliveryQueue();
			return queue.Enqueue(tradeId, payload);
		}

		private static readonly byte[] Png = { 0x89, 0x50, 0x4E, 0x47, 1, 2, 3 };

		// ---- status mapping

		[Theory]
		[InlineData(201, DeliveryOutcome.Accepted)]
		[InlineData(200, DeliveryOutcome.AcceptedIdempotent)]
		[InlineData(500, DeliveryOutcome.Retryable)]
		[InlineData(502, DeliveryOutcome.Retryable)]
		[InlineData(503, DeliveryOutcome.Retryable)]
		[InlineData(599, DeliveryOutcome.Retryable)]
		[InlineData(408, DeliveryOutcome.Retryable)]
		[InlineData(429, DeliveryOutcome.Retryable)]
		[InlineData(401, DeliveryOutcome.ConfigurationError)]
		[InlineData(403, DeliveryOutcome.ConfigurationError)]
		[InlineData(422, DeliveryOutcome.ValidationError)]
		public void MapStatus_DocumentedCodes(int status, DeliveryOutcome expected)
		{
			Assert.Equal(expected, TradeDelivery.MapStatus(status));
		}

		[Theory]
		[InlineData(202)]
		[InlineData(204)]
		[InlineData(301)]
		[InlineData(400)]
		[InlineData(404)]
		[InlineData(413)]
		[InlineData(600)]
		public void MapStatus_UnrecognizedCode_KeepsThePayloadForAManualRetryAndNeverMarksItSent(int status)
		{
			Assert.Equal(DeliveryOutcome.ValidationError, TradeDelivery.MapStatus(status));
		}

		// ---- the request

		[Fact]
		public async Task SendAsync_PostsToTheEndpointWithTheContractHeaders()
		{
			StubHandler handler = Answer(HttpStatusCode.Created);
			QueuedDelivery d = Queued();

			await Delivery(handler).SendAsync(d, null);

			HttpRequestMessage r = Assert.Single(handler.Sent).Request;
			Assert.Equal(HttpMethod.Post, r.Method);
			Assert.Equal(new Uri(Endpoint), r.RequestUri);
			Assert.Equal("Bearer", r.Headers.Authorization.Scheme);
			Assert.Equal(Token, r.Headers.Authorization.Parameter);
			Assert.Equal(d.TradeId, Assert.Single(r.Headers.GetValues("Idempotency-Key")));
			Assert.Equal("ChartJot-NT8/1.0.0", string.Join(" ", r.Headers.GetValues("User-Agent")));
			Assert.DoesNotContain(Token, r.RequestUri.ToString());
		}

		[Fact]
		public async Task SendAsync_SendsTheFrozenPayloadAsTheUtf8TradePart()
		{
			StubHandler handler = Answer(HttpStatusCode.Created);
			string payload = "{\"trade_id\":\"x\",\"notes\":[{\"body\":\"café – runner ✓\"}]}";

			await Delivery(handler).SendAsync(Queued(payload: payload), null);

			SentRequest sent = Assert.Single(handler.Sent);
			Assert.Equal("multipart/form-data", sent.ContentType.MediaType);
			SentPart trade = sent.Part("trade");
			Assert.NotNull(trade);
			Assert.Null(trade.FileName);
			Assert.Equal("application/json", trade.ContentType.MediaType);
			Assert.Equal("utf-8", trade.ContentType.CharSet);
			Assert.Equal(Encoding.UTF8.GetBytes(payload), trade.Bytes);
		}

		[Fact]
		public async Task SendAsync_WithoutAScreenshot_SendsOnlyTheTradePart()
		{
			StubHandler handler = Answer(HttpStatusCode.Created);

			await Delivery(handler).SendAsync(Queued(), null);

			Assert.Equal("trade", Assert.Single(Assert.Single(handler.Sent).Parts).Name);
		}

		[Fact]
		public async Task SendAsync_WithAPngScreenshot_AttachesItAsScreenshotFile()
		{
			StubHandler handler = Answer(HttpStatusCode.Created);
			QueuedDelivery d = Queued();

			await Delivery(handler).SendAsync(d, new DeliveryScreenshot { Bytes = Png, Format = "png" });

			SentPart image = Assert.Single(handler.Sent).Part("screenshot_file");
			Assert.Equal("image/png", image.ContentType.MediaType);
			Assert.Equal(d.TradeId + ".png", image.FileName);
			Assert.Equal(Png, image.Bytes);
		}

		[Fact]
		public async Task SendAsync_WithAJpegScreenshot_UsesTheJpegMediaType()
		{
			StubHandler handler = Answer(HttpStatusCode.Created);
			QueuedDelivery d = Queued();

			await Delivery(handler).SendAsync(d, new DeliveryScreenshot { Bytes = new byte[] { 0xFF, 0xD8, 0xFF }, Format = "jpeg" });

			SentPart image = Assert.Single(handler.Sent).Part("screenshot_file");
			Assert.Equal("image/jpeg", image.ContentType.MediaType);
			Assert.Equal(d.TradeId + ".jpeg", image.FileName);
		}

		[Fact]
		public async Task SendAsync_WithAnUnsupportedScreenshotFormat_Throws()
		{
			await Assert.ThrowsAsync<ArgumentException>(() =>
				Delivery(Answer(HttpStatusCode.Created)).SendAsync(Queued(), new DeliveryScreenshot { Bytes = Png, Format = "bmp" }));
		}

		// ---- the response

		[Fact]
		public async Task SendAsync_Created_IsAcceptedWithTheServerBody()
		{
			DeliveryAttempt a = await Delivery(Answer(HttpStatusCode.Created, "{\"id\":42}")).SendAsync(Queued(), null);

			Assert.Equal(DeliveryOutcome.Accepted, a.Outcome);
			Assert.Equal(201, a.StatusCode);
			Assert.Equal("{\"id\":42}", a.ServerBody);
			Assert.Null(a.ErrorMessage);
		}

		[Fact]
		public async Task SendAsync_UnprocessableEntity_KeepsTheValidationBodyForDisplay()
		{
			string body = "{\"message\":\"The trade type field is required.\"}";

			DeliveryAttempt a = await Delivery(Answer((HttpStatusCode)422, body)).SendAsync(Queued(), null);

			Assert.Equal(DeliveryOutcome.ValidationError, a.Outcome);
			Assert.Equal(422, a.StatusCode);
			Assert.Equal(body, a.ServerBody);
		}

		[Fact]
		public async Task SendAsync_LongServerBody_IsTruncated()
		{
			string body = new string('x', TradeDelivery.MaxServerBodyLength + 500);

			DeliveryAttempt a = await Delivery(Answer(HttpStatusCode.BadGateway, body)).SendAsync(Queued(), null);

			Assert.Equal(TradeDelivery.MaxServerBodyLength, a.ServerBody.Length);
		}

		[Fact]
		public async Task SendAsync_MultiByteServerBody_IsCappedInUtf8BytesWithoutASplitCharacter()
		{
			// "é" is 2 bytes and "😀" 4 bytes in UTF-8; both would pass a character-count cap at twice the size or more.
			string body = "x" + string.Concat(Enumerable.Repeat("é😀", TradeDelivery.MaxServerBodyLength));

			DeliveryAttempt a = await Delivery(Answer(HttpStatusCode.UnprocessableEntity, body)).SendAsync(Queued(), null);

			int bytes = Encoding.UTF8.GetByteCount(a.ServerBody);
			Assert.True(bytes <= TradeDelivery.MaxServerBodyLength, bytes + " bytes");
			Assert.True(bytes > TradeDelivery.MaxServerBodyLength - 4, bytes + " bytes");
			Assert.StartsWith(a.ServerBody, body);
			Assert.DoesNotContain('\uFFFD', a.ServerBody);
		}

		[Fact]
		public async Task SendAsync_NonUtf8Charset_IsDecodedAndStillCappedInUtf8Bytes()
		{
			// UTF-16 takes 2 bytes per "ÿ", so the 16KB read yields half the characters, which are also 2 bytes each in UTF-8.
			string body = new string('ÿ', TradeDelivery.MaxServerBodyLength);
			StubHandler handler = new StubHandler((r, ct) => Task.FromResult(new HttpResponseMessage(HttpStatusCode.BadGateway)
			{
				Content = new StringContent(body, Encoding.Unicode, "text/plain")
			}));

			DeliveryAttempt a = await Delivery(handler).SendAsync(Queued(), null);

			Assert.Equal(new string('ÿ', TradeDelivery.MaxServerBodyLength / 2), a.ServerBody);
		}

		[Fact]
		public async Task SendAsync_HugeServerBody_ReadsNoMoreThanTheCap()
		{
			CountingStream stream = new CountingStream(100L * 1024 * 1024);
			StubHandler handler = new StubHandler((r, ct) => Task.FromResult(new HttpResponseMessage(HttpStatusCode.BadGateway)
			{
				Content = new StreamContent(stream, 1024)
			}));

			DeliveryAttempt a = await Delivery(handler).SendAsync(Queued(), null);

			Assert.Equal(DeliveryOutcome.Retryable, a.Outcome);
			Assert.Equal(502, a.StatusCode);
			Assert.Equal(TradeDelivery.MaxServerBodyLength, a.ServerBody.Length);
			Assert.True(stream.BytesRead <= TradeDelivery.MaxServerBodyLength + 1024, stream.BytesRead + " bytes read");
		}

		[Fact]
		public async Task SendAsync_ConnectionDropsMidBody_IsRetryableWithTheError()
		{
			StubHandler handler = new StubHandler((r, ct) => Task.FromResult(new HttpResponseMessage(HttpStatusCode.Created)
			{
				Content = new StreamContent(new CountingStream(10, failAfter: 5))
			}));

			DeliveryAttempt a = await Delivery(handler).SendAsync(Queued(), null);

			Assert.Equal(DeliveryOutcome.Retryable, a.Outcome);
			Assert.Null(a.StatusCode);
			Assert.Contains("connection reset", a.ErrorMessage);
		}

		// A long body of 'x' that records how much of it was read, and can fail partway like a dropped connection.
		private sealed class CountingStream : Stream
		{
			private readonly long length;
			private readonly long failAfter;

			public CountingStream(long length, long failAfter = -1)
			{
				this.length = length;
				this.failAfter = failAfter;
			}

			public long BytesRead { get; private set; }

			public override int Read(byte[] buffer, int offset, int count)
			{
				if (failAfter >= 0 && BytesRead >= failAfter)
					throw new IOException("connection reset");
				int n = (int)Math.Min(count, length - BytesRead);
				if (failAfter >= 0)
					n = (int)Math.Min(n, failAfter - BytesRead);
				for (int i = 0; i < n; i++)
					buffer[offset + i] = (byte)'x';
				BytesRead += n;
				return n;
			}

			public override bool CanRead { get { return true; } }
			public override bool CanSeek { get { return false; } }
			public override bool CanWrite { get { return false; } }
			public override long Length { get { throw new NotSupportedException(); } }
			public override long Position { get { throw new NotSupportedException(); } set { throw new NotSupportedException(); } }
			public override void Flush() { }
			public override long Seek(long offset, SeekOrigin origin) { throw new NotSupportedException(); }
			public override void SetLength(long value) { throw new NotSupportedException(); }
			public override void Write(byte[] buffer, int offset, int count) { throw new NotSupportedException(); }
		}

		[Fact]
		public async Task SendAsync_Timeout_IsRetryableWithNoStatus()
		{
			StubHandler handler = new StubHandler(async (r, ct) =>
			{
				await Task.Delay(Timeout.Infinite, ct);
				return new HttpResponseMessage(HttpStatusCode.Created);
			});

			DeliveryAttempt a = await Delivery(handler, timeout: TimeSpan.FromMilliseconds(50)).SendAsync(Queued(), null);

			Assert.Equal(DeliveryOutcome.Retryable, a.Outcome);
			Assert.Null(a.StatusCode);
			Assert.Contains("Timed out", a.ErrorMessage);
		}

		[Fact]
		public async Task SendAsync_ConnectionError_IsRetryable()
		{
			StubHandler handler = new StubHandler((r, ct) => throw new HttpRequestException("No such host is known."));

			DeliveryAttempt a = await Delivery(handler).SendAsync(Queued(), null);

			Assert.Equal(DeliveryOutcome.Retryable, a.Outcome);
			Assert.Null(a.StatusCode);
			Assert.Contains("No such host", a.ErrorMessage);
			Assert.DoesNotContain(Token, a.ErrorMessage);
		}

		[Fact]
		public async Task SendAsync_CallerCancels_IsRetryable()
		{
			using (CancellationTokenSource cts = new CancellationTokenSource())
			{
				StubHandler handler = new StubHandler(async (r, ct) =>
				{
					cts.Cancel();
					await Task.Delay(Timeout.Infinite, ct);
					return new HttpResponseMessage(HttpStatusCode.Created);
				});

				DeliveryAttempt a = await Delivery(handler).SendAsync(Queued(), null, cts.Token);

				Assert.Equal(DeliveryOutcome.Retryable, a.Outcome);
				Assert.Contains("Cancelled", a.ErrorMessage);
			}
		}

		// ---- configuration

		[Theory]
		[InlineData("http://journal.test/api/v1/trades")]
		[InlineData("/api/v1/trades")]
		[InlineData("not a url")]
		public void Constructor_RejectsAnEndpointThatIsNotAbsoluteHttps(string endpoint)
		{
			Assert.Throws<ArgumentException>(() => new TradeDelivery(new HttpClient(), endpoint, Token, "1.0.0", TimeSpan.FromSeconds(30)));
		}

		[Fact]
		public void Constructor_AllowsHttpOnlyWhenAskedFor()
		{
			new TradeDelivery(new HttpClient(), "http://localhost:8000/api/v1/trades", Token, "1.0.0", TimeSpan.FromSeconds(30), allowInsecureHttp: true);
		}

		[Fact]
		public void Constructor_RequiresAToken()
		{
			Assert.Throws<ArgumentException>(() => new TradeDelivery(new HttpClient(), Endpoint, " ", "1.0.0", TimeSpan.FromSeconds(30)));
		}

		[Fact]
		public void Constructor_RequiresAFiniteTimeout()
		{
			Assert.Throws<ArgumentOutOfRangeException>(() => new TradeDelivery(new HttpClient(), Endpoint, Token, "1.0.0", Timeout.InfiniteTimeSpan));
			Assert.Throws<ArgumentOutOfRangeException>(() => new TradeDelivery(new HttpClient(), Endpoint, Token, "1.0.0", TimeSpan.Zero));
		}

		[Fact]
		public void Constructor_RejectsATimeoutBeyondWhatCancellationTokenSourceAccepts()
		{
			Assert.Throws<ArgumentOutOfRangeException>(() => new TradeDelivery(new HttpClient(), Endpoint, Token, "1.0.0", TimeSpan.FromDays(30)));
			Assert.Throws<ArgumentOutOfRangeException>(() => new TradeDelivery(new HttpClient(), Endpoint, Token, "1.0.0", TradeDelivery.MaxTimeout + TimeSpan.FromMilliseconds(1)));
			new TradeDelivery(new HttpClient(), Endpoint, Token, "1.0.0", TradeDelivery.MaxTimeout);
		}

		// ---- the pump

		[Fact]
		public async Task SendDueAsync_MarksSendingDuringTheRequestAndSentAfter()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			DeliveryState? during = null;
			StubHandler handler = new StubHandler((r, ct) =>
			{
				during = queue.Find("t1").State;
				return Task.FromResult(new HttpResponseMessage(HttpStatusCode.Created) { Content = new StringContent("{\"id\":1}") });
			});

			IList<DeliveryReport> reports = await Delivery(handler).SendDueAsync(queue);

			Assert.Equal(DeliveryState.Sending, during);
			QueuedDelivery d = queue.Find("t1");
			Assert.Equal(DeliveryState.Sent, d.State);
			Assert.Equal(Now, d.SentAt);
			Assert.Equal(201, d.LastStatusCode);
			Assert.Equal("{\"id\":1}", d.LastServerBody);
			DeliveryReport report = Assert.Single(reports);
			Assert.Equal(DeliveryOutcome.Accepted, report.Outcome);
			Assert.Equal(201, report.StatusCode);
		}

		[Fact]
		public async Task SendDueAsync_SendsEveryDueDeliveryInQueueOrder()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			queue.Enqueue("t2", "{}");
			queue.Enqueue("t3", "{}");
			StubHandler handler = Answer(HttpStatusCode.OK);

			await Delivery(handler).SendDueAsync(queue);

			Assert.Equal(new[] { "t1", "t2", "t3" }, handler.Sent.Select(s => s.Request.Headers.GetValues("Idempotency-Key").Single()));
			Assert.All(queue.All, d => Assert.Equal(DeliveryState.Sent, d.State));
		}

		[Fact]
		public async Task SendDueAsync_DoesNotResendASentDelivery()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			StubHandler handler = Answer(HttpStatusCode.Created);
			TradeDelivery delivery = Delivery(handler);

			await delivery.SendDueAsync(queue);
			IList<DeliveryReport> second = await delivery.SendDueAsync(queue);

			Assert.Single(handler.Sent);
			Assert.Empty(second);
		}

		[Fact]
		public async Task SendDueAsync_RetryableFailure_BacksOffAndResendsTheSamePayloadWhenDue()
		{
			DeliveryQueue queue = new DeliveryQueue(initialBackoff: TimeSpan.FromSeconds(5));
			queue.Enqueue("t1", "{\"frozen\":true}");
			DateTimeOffset now = Now;
			int calls = 0;
			StubHandler handler = new StubHandler((r, ct) => Task.FromResult(new HttpResponseMessage(++calls == 1 ? HttpStatusCode.ServiceUnavailable : HttpStatusCode.Created)));
			TradeDelivery delivery = Delivery(handler, () => now);

			await delivery.SendDueAsync(queue);
			QueuedDelivery d = queue.Find("t1");
			Assert.Equal(DeliveryState.QueuedForRetry, d.State);
			Assert.Equal(503, d.LastStatusCode);
			Assert.Equal(Now.AddSeconds(5), d.NextAttemptAt);

			await delivery.SendDueAsync(queue);
			Assert.Single(handler.Sent); // not due yet

			now = Now.AddSeconds(5);
			await delivery.SendDueAsync(queue);
			Assert.Equal(2, handler.Sent.Count);
			Assert.Equal(handler.Sent[0].Part("trade").Bytes, handler.Sent[1].Part("trade").Bytes);
			Assert.Equal(DeliveryState.Sent, d.State);
		}

		[Fact]
		public async Task SendDueAsync_TimeoutIsRecordedAsRetryableWithTheMessage()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			StubHandler handler = new StubHandler(async (r, ct) =>
			{
				await Task.Delay(Timeout.Infinite, ct);
				return new HttpResponseMessage(HttpStatusCode.Created);
			});

			await Delivery(handler, timeout: TimeSpan.FromMilliseconds(50)).SendDueAsync(queue);

			QueuedDelivery d = queue.Find("t1");
			Assert.Equal(DeliveryState.QueuedForRetry, d.State);
			Assert.Null(d.LastStatusCode);
			Assert.Contains("Timed out", d.LastErrorMessage);
		}

		[Fact]
		public async Task SendDueAsync_ConfigurationError_HaltsTheQueueAndStopsSending()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			queue.Enqueue("t2", "{}");
			StubHandler handler = Answer(HttpStatusCode.Unauthorized);

			IList<DeliveryReport> reports = await Delivery(handler).SendDueAsync(queue);

			Assert.Single(handler.Sent);
			Assert.Single(reports);
			Assert.True(queue.ConfigurationErrorHalted);
			Assert.True(queue.Find("t1").IsConfigurationError);
			Assert.Equal(DeliveryState.Pending, queue.Find("t2").State);
		}

		[Fact]
		public async Task SendDueAsync_ValidationError_FailsTheDeliveryAndKeepsThePayloadAndBody()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{\"a\":1}");
			queue.Enqueue("t2", "{}");
			StubHandler handler = new StubHandler((r, ct) => Task.FromResult(r.Headers.GetValues("Idempotency-Key").Single() == "t1"
				? new HttpResponseMessage((HttpStatusCode)422) { Content = new StringContent("{\"message\":\"bad\"}") }
				: new HttpResponseMessage(HttpStatusCode.Created)));

			await Delivery(handler).SendDueAsync(queue);

			QueuedDelivery d = queue.Find("t1");
			Assert.Equal(DeliveryState.Failed, d.State);
			Assert.False(d.IsConfigurationError);
			Assert.Equal("{\"a\":1}", d.PayloadJson);
			Assert.Equal("{\"message\":\"bad\"}", d.LastServerBody);
			Assert.Equal(DeliveryState.Sent, queue.Find("t2").State); // a 422 does not halt the rest
		}

		[Fact]
		public async Task SendDueAsync_AttachesAScreenshotOnlyForTradesThatHaveOne()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			queue.Enqueue("t2", "{}");
			StubHandler handler = Answer(HttpStatusCode.Created);

			IList<DeliveryReport> reports = await Delivery(handler).SendDueAsync(queue,
				id => id == "t1" ? new DeliveryScreenshot { Bytes = Png, Format = "png" } : null);

			Assert.NotNull(handler.Sent[0].Part("screenshot_file"));
			Assert.Null(handler.Sent[1].Part("screenshot_file"));
			Assert.True(reports[0].ScreenshotAttached);
			Assert.False(reports[1].ScreenshotAttached);
		}

		[Fact]
		public async Task SendDueAsync_WhenTheScreenshotCannotBeLoaded_SendsTheTradeWithoutIt()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			StubHandler handler = Answer(HttpStatusCode.Created);

			IList<DeliveryReport> reports = await Delivery(handler).SendDueAsync(queue, id => throw new IOException("file locked"));

			Assert.Null(Assert.Single(handler.Sent).Part("screenshot_file"));
			Assert.Equal("file locked", reports[0].ScreenshotError);
			Assert.False(reports[0].ScreenshotAttached);
			Assert.Equal(DeliveryState.Sent, queue.Find("t1").State);
		}

		[Fact]
		public async Task SendDueAsync_WhenTheScreenshotIsUnusable_SendsTheTradeWithoutIt()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			StubHandler handler = Answer(HttpStatusCode.Created);

			IList<DeliveryReport> reports = await Delivery(handler).SendDueAsync(queue, id => new DeliveryScreenshot { Bytes = new byte[0], Format = "png" });

			Assert.Null(Assert.Single(handler.Sent).Part("screenshot_file"));
			Assert.NotNull(reports[0].ScreenshotError);
		}

		[Fact]
		public async Task SendDueAsync_AnUnexpectedExceptionStillSettlesTheDelivery()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			StubHandler handler = new StubHandler((r, ct) => throw new InvalidOperationException("handler bug"));

			IList<DeliveryReport> reports = await Delivery(handler).SendDueAsync(queue);

			QueuedDelivery d = queue.Find("t1");
			Assert.Equal(DeliveryState.QueuedForRetry, d.State);
			Assert.Contains("handler bug", d.LastErrorMessage);
			Assert.Equal(DeliveryOutcome.Retryable, Assert.Single(reports).Outcome);
		}

		[Fact]
		public async Task SendDueAsync_CancelledMidRequest_RecordsItAndStops()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			queue.Enqueue("t2", "{}");
			using (CancellationTokenSource cts = new CancellationTokenSource())
			{
				StubHandler handler = new StubHandler(async (r, ct) =>
				{
					cts.Cancel();
					await Task.Delay(Timeout.Infinite, ct);
					return new HttpResponseMessage(HttpStatusCode.Created);
				});

				await Delivery(handler).SendDueAsync(queue, null, cts.Token);

				Assert.Single(handler.Sent);
				Assert.Equal(DeliveryState.QueuedForRetry, queue.Find("t1").State);
				Assert.Equal(DeliveryState.Pending, queue.Find("t2").State);
			}
		}

		[Fact]
		public async Task SendDueAsync_WhileHalted_SendsNothing()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			queue.MarkSending("t1");
			queue.RecordResult("t1", DeliveryOutcome.ConfigurationError, Now, statusCode: 403);
			queue.Enqueue("t2", "{}");
			StubHandler handler = Answer(HttpStatusCode.Created);

			IList<DeliveryReport> reports = await Delivery(handler).SendDueAsync(queue);

			Assert.Empty(handler.Sent);
			Assert.Empty(reports);
		}
	}
}
