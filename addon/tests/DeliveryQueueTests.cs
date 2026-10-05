using ChartJot.Core;

namespace ChartJot.Core.Tests
{
	public class DeliveryQueueTests
	{
		private static readonly DateTimeOffset Now = new DateTimeOffset(2026, 9, 29, 12, 0, 0, TimeSpan.Zero);

		[Fact]
		public void Enqueue_NewTrade_IsPendingAndDue()
		{
			DeliveryQueue queue = new DeliveryQueue();

			QueuedDelivery d = queue.Enqueue("NT8-Sim101-ES-abc", "{\"trade_id\":\"NT8-Sim101-ES-abc\"}");

			Assert.Equal(DeliveryState.Pending, d.State);
			Assert.Equal(0, d.Attempts);
			Assert.Same(d, Assert.Single(queue.Due(Now)));
		}

		[Fact]
		public void RecordResult_Accepted_MarksSentAndClearsFromDue()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			queue.MarkSending("t1");

			queue.RecordResult("t1", DeliveryOutcome.Accepted, Now, statusCode: 201);

			QueuedDelivery d = queue.Find("t1");
			Assert.Equal(DeliveryState.Sent, d.State);
			Assert.Equal(Now, d.SentAt);
			Assert.Empty(queue.Due(Now));
		}

		[Fact]
		public void RecordResult_AcceptedIdempotent_MarksSent()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			queue.MarkSending("t1");

			queue.RecordResult("t1", DeliveryOutcome.AcceptedIdempotent, Now, statusCode: 200);

			Assert.Equal(DeliveryState.Sent, queue.Find("t1").State);
		}

		[Fact]
		public void RecordResult_Retryable_QueuesWithBackoffAndIsNotDueUntilThen()
		{
			DeliveryQueue queue = new DeliveryQueue(initialBackoff: TimeSpan.FromSeconds(5));
			queue.Enqueue("t1", "{}");
			queue.MarkSending("t1");

			queue.RecordResult("t1", DeliveryOutcome.Retryable, Now, statusCode: 503);

			QueuedDelivery d = queue.Find("t1");
			Assert.Equal(DeliveryState.QueuedForRetry, d.State);
			Assert.Equal(1, d.Attempts);
			Assert.Equal(Now + TimeSpan.FromSeconds(5), d.NextAttemptAt);
			Assert.Empty(queue.Due(Now));
			Assert.Same(d, Assert.Single(queue.Due(Now + TimeSpan.FromSeconds(5))));
		}

		[Fact]
		public void RecordResult_RetryableRepeatedly_BacksOffExponentiallyUpToCap()
		{
			DeliveryQueue queue = new DeliveryQueue(initialBackoff: TimeSpan.FromSeconds(5), maxBackoff: TimeSpan.FromSeconds(30), maxAutomaticAttempts: 10);
			queue.Enqueue("t1", "{}");

			DateTimeOffset now = Now;
			TimeSpan[] expected = { TimeSpan.FromSeconds(5), TimeSpan.FromSeconds(10), TimeSpan.FromSeconds(20), TimeSpan.FromSeconds(30), TimeSpan.FromSeconds(30) };
			foreach (TimeSpan delay in expected)
			{
				queue.MarkSending("t1");
				queue.RecordResult("t1", DeliveryOutcome.Retryable, now, statusCode: 503);
				QueuedDelivery d = queue.Find("t1");
				Assert.Equal(now + delay, d.NextAttemptAt);
				now = d.NextAttemptAt.Value;
			}
		}

		[Fact]
		public void RecordResult_ConfigurationError_FailsAndHaltsWholeQueue()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			queue.Enqueue("t2", "{}");
			queue.MarkSending("t1");

			queue.RecordResult("t1", DeliveryOutcome.ConfigurationError, Now, statusCode: 401);

			QueuedDelivery d = queue.Find("t1");
			Assert.Equal(DeliveryState.Failed, d.State);
			Assert.True(d.IsConfigurationError);
			Assert.True(queue.ConfigurationErrorHalted);
			// t2 was never sent, but the halt still hides it from Due: a bad token would fail it too.
			Assert.Empty(queue.Due(Now));
		}

		[Fact]
		public void RecordResult_ValidationError_FailsWithoutHaltingQueue()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			queue.Enqueue("t2", "{}");
			queue.MarkSending("t1");

			queue.RecordResult("t1", DeliveryOutcome.ValidationError, Now, statusCode: 422, serverBody: "{\"message\":\"bad instrument\"}");

			QueuedDelivery d = queue.Find("t1");
			Assert.Equal(DeliveryState.Failed, d.State);
			Assert.False(d.IsConfigurationError);
			Assert.Equal("{\"message\":\"bad instrument\"}", d.LastServerBody);
			Assert.False(queue.ConfigurationErrorHalted);
			Assert.Same(queue.Find("t2"), Assert.Single(queue.Due(Now)));
		}

		[Fact]
		public void Enqueue_SameTradeIdWhileFailed_ReplacesPayloadAndRestartsFromPending()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{\"a\":1}");
			queue.MarkSending("t1");
			queue.RecordResult("t1", DeliveryOutcome.ValidationError, Now, statusCode: 422);

			QueuedDelivery d = queue.Enqueue("t1", "{\"a\":2}");

			Assert.Equal("{\"a\":2}", d.PayloadJson);
			Assert.Equal(DeliveryState.Pending, d.State);
			Assert.Equal(0, d.Attempts);
			Assert.Null(d.LastServerBody);
		}

		[Fact]
		public void Enqueue_SameTradeIdAlreadySent_IsNoOp()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{\"a\":1}");
			queue.MarkSending("t1");
			queue.RecordResult("t1", DeliveryOutcome.Accepted, Now, statusCode: 201);

			QueuedDelivery d = queue.Enqueue("t1", "{\"a\":2}");

			Assert.Equal("{\"a\":1}", d.PayloadJson);
			Assert.Equal(DeliveryState.Sent, d.State);
		}

		[Fact]
		public void RetryManually_FromFailed_ReturnsToPendingAndClearsHalt()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			queue.MarkSending("t1");
			queue.RecordResult("t1", DeliveryOutcome.ConfigurationError, Now, statusCode: 403);
			Assert.True(queue.ConfigurationErrorHalted);

			queue.RetryManually("t1", Now);

			QueuedDelivery d = queue.Find("t1");
			Assert.Equal(DeliveryState.Pending, d.State);
			Assert.False(queue.ConfigurationErrorHalted);
			Assert.Same(d, Assert.Single(queue.Due(Now)));
		}

		[Fact]
		public void RetryManually_FromNonFailedState_Throws()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");

			Assert.Throws<InvalidOperationException>(() => queue.RetryManually("t1", Now));
		}

		[Fact]
		public void MarkSending_WhenNotDue_Throws()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			queue.MarkSending("t1");

			Assert.Throws<InvalidOperationException>(() => queue.MarkSending("t1"));
		}

		[Fact]
		public void Due_PreservesEnqueueOrder()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			queue.Enqueue("t2", "{}");
			queue.Enqueue("t3", "{}");

			IList<QueuedDelivery> due = queue.Due(Now);

			Assert.Equal(new[] { "t1", "t2", "t3" }, due.Select(d => d.TradeId));
		}

		[Fact]
		public void NextBackoff_DoublesFromInitialAndCapsAtMax()
		{
			TimeSpan initial = TimeSpan.FromSeconds(5);
			TimeSpan max = TimeSpan.FromSeconds(30);

			Assert.Equal(TimeSpan.FromSeconds(5), DeliveryQueue.NextBackoff(1, initial, max));
			Assert.Equal(TimeSpan.FromSeconds(10), DeliveryQueue.NextBackoff(2, initial, max));
			Assert.Equal(TimeSpan.FromSeconds(20), DeliveryQueue.NextBackoff(3, initial, max));
			Assert.Equal(TimeSpan.FromSeconds(30), DeliveryQueue.NextBackoff(4, initial, max));
			Assert.Equal(TimeSpan.FromSeconds(30), DeliveryQueue.NextBackoff(50, initial, max));
		}

		// ---- automatic retries stop after three attempts; the trader retries by hand from there

		[Fact]
		public void RecordResult_RetryableOnTheThirdAttempt_FailsWithTheLastError()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			DateTimeOffset now = Now;

			for (int attempt = 1; attempt <= 3; attempt++)
			{
				queue.MarkSending("t1");
				queue.RecordResult("t1", DeliveryOutcome.Retryable, now, errorMessage: "Connection refused");
				if (attempt < 3)
				{
					Assert.Equal(DeliveryState.QueuedForRetry, queue.Find("t1").State);
					now = queue.Find("t1").NextAttemptAt.Value;
				}
			}

			QueuedDelivery d = queue.Find("t1");
			Assert.Equal(DeliveryState.Failed, d.State);
			Assert.Equal(3, d.Attempts);
			Assert.Null(d.NextAttemptAt);
			Assert.False(d.IsConfigurationError);
			Assert.Equal("Connection refused", d.LastErrorMessage);
			Assert.Equal("{}", d.PayloadJson);
			Assert.Empty(queue.Due(now.AddHours(1)));
			Assert.False(queue.ConfigurationErrorHalted);
		}

		[Fact]
		public void RetryManually_AfterAutomaticRetriesRanOut_GivesThreeMoreAttempts()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			for (int attempt = 1; attempt <= 3; attempt++)
			{
				queue.MarkSending("t1");
				queue.RecordResult("t1", DeliveryOutcome.Retryable, Now, statusCode: 503);
			}

			queue.RetryManually("t1", Now);

			QueuedDelivery d = queue.Find("t1");
			Assert.Equal(DeliveryState.Pending, d.State);
			Assert.Equal(0, d.Attempts);
			queue.MarkSending("t1");
			queue.RecordResult("t1", DeliveryOutcome.Retryable, Now, statusCode: 503);
			Assert.Equal(DeliveryState.QueuedForRetry, d.State);
		}

		[Fact]
		public void AFailedDelivery_SurvivesARestartAsFailed()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{\"trade_id\":\"t1\"}");
			for (int attempt = 1; attempt <= 3; attempt++)
			{
				queue.MarkSending("t1");
				queue.RecordResult("t1", DeliveryOutcome.Retryable, Now, statusCode: 503);
			}

			QueuedDelivery reloaded = DeliveryQueue.Deserialize(queue.Serialize()).Find("t1");

			Assert.Equal(DeliveryState.Failed, reloaded.State);
			Assert.Equal("{\"trade_id\":\"t1\"}", reloaded.PayloadJson);
		}
	}
}
