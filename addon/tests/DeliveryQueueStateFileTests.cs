using System.IO;
using ChartJot.Core;

namespace ChartJot.Core.Tests
{
	public class DeliveryQueueStateFileTests
	{
		private static readonly DateTimeOffset Now = new DateTimeOffset(2026, 9, 29, 12, 0, 0, TimeSpan.Zero);

		[Fact]
		public void SerializeDeserialize_RoundTripsAPendingDelivery()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{\"trade_id\":\"t1\"}");

			DeliveryQueue reloaded = DeliveryQueue.Deserialize(queue.Serialize());

			QueuedDelivery d = reloaded.Find("t1");
			Assert.Equal(DeliveryState.Pending, d.State);
			Assert.Equal("{\"trade_id\":\"t1\"}", d.PayloadJson);
			Assert.Equal(0, d.Attempts);
			Assert.Null(d.NextAttemptAt);
			Assert.False(reloaded.ConfigurationErrorHalted);
		}

		[Fact]
		public void SerializeDeserialize_RoundTripsAQueuedForRetryDeliveryWithBackoffAndError()
		{
			DeliveryQueue queue = new DeliveryQueue(initialBackoff: TimeSpan.FromSeconds(5));
			queue.Enqueue("t1", "{}");
			queue.MarkSending("t1");
			queue.RecordResult("t1", DeliveryOutcome.Retryable, Now, errorMessage: "Connection timed out");

			DeliveryQueue reloaded = DeliveryQueue.Deserialize(queue.Serialize());

			QueuedDelivery d = reloaded.Find("t1");
			Assert.Equal(DeliveryState.QueuedForRetry, d.State);
			Assert.Equal(1, d.Attempts);
			Assert.Equal(Now + TimeSpan.FromSeconds(5), d.NextAttemptAt);
			Assert.Equal("Connection timed out", d.LastErrorMessage);
			Assert.Null(d.LastStatusCode);
		}

		[Fact]
		public void SerializeDeserialize_RoundTripsAConfigurationErrorAndTheHalt()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			queue.MarkSending("t1");
			queue.RecordResult("t1", DeliveryOutcome.ConfigurationError, Now, statusCode: 401);

			DeliveryQueue reloaded = DeliveryQueue.Deserialize(queue.Serialize());

			Assert.True(reloaded.ConfigurationErrorHalted);
			QueuedDelivery d = reloaded.Find("t1");
			Assert.Equal(DeliveryState.Failed, d.State);
			Assert.True(d.IsConfigurationError);
			Assert.Equal(401, d.LastStatusCode);
			Assert.Empty(reloaded.Due(Now));
		}

		[Fact]
		public void SerializeDeserialize_RoundTripsASentDeliveryWithSentAt()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			queue.MarkSending("t1");
			queue.RecordResult("t1", DeliveryOutcome.Accepted, Now, statusCode: 201);

			DeliveryQueue reloaded = DeliveryQueue.Deserialize(queue.Serialize());

			QueuedDelivery d = reloaded.Find("t1");
			Assert.Equal(DeliveryState.Sent, d.State);
			Assert.Equal(Now, d.SentAt);
		}

		[Fact]
		public void SerializeDeserialize_PreservesEnqueueOrder()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{}");
			queue.Enqueue("t2", "{}");
			queue.Enqueue("t3", "{}");

			DeliveryQueue reloaded = DeliveryQueue.Deserialize(queue.Serialize());

			Assert.Equal(new[] { "t1", "t2", "t3" }, reloaded.All.Select(d => d.TradeId));
		}

		[Fact]
		public void SerializeDeserialize_EmptyQueue_RoundTrips()
		{
			DeliveryQueue queue = new DeliveryQueue();

			DeliveryQueue reloaded = DeliveryQueue.Deserialize(queue.Serialize());

			Assert.Empty(reloaded.All);
			Assert.False(reloaded.ConfigurationErrorHalted);
		}

		[Fact]
		public void Deserialize_ADeliverySavedMidRequest_ComesBackPendingAndDue()
		{
			DeliveryQueue queue = new DeliveryQueue();
			queue.Enqueue("t1", "{\"a\":1}");
			queue.MarkSending("t1");

			DeliveryQueue reloaded = DeliveryQueue.Deserialize(queue.Serialize());

			QueuedDelivery d = reloaded.Find("t1");
			Assert.Equal(DeliveryState.Pending, d.State);
			Assert.Equal("{\"a\":1}", d.PayloadJson);
			Assert.Equal("t1", Assert.Single(reloaded.Due(Now)).TradeId);
		}

		[Fact]
		public void Deserialize_UnrecognizedState_ThrowsFormatException()
		{
			string json = "{\"configuration_error_halted\":false,\"deliveries\":[{\"trade_id\":\"t1\",\"payload_json\":\"{}\"," +
				"\"state\":\"Bogus\",\"attempts\":0,\"next_attempt_at\":null,\"sent_at\":null,\"last_status_code\":null," +
				"\"last_server_body\":null,\"last_error_message\":null,\"is_configuration_error\":false}]}";

			Assert.Throws<FormatException>(() => DeliveryQueue.Deserialize(json));
		}

		[Fact]
		public void StateFile_WriteAtomicThenRead_RoundTripsAQueueThroughRealDisk()
		{
			string path = Path.Combine(Path.GetTempPath(), "chartjot-tests-" + Guid.NewGuid().ToString("N"), "queue.json");
			try
			{
				DeliveryQueue queue = new DeliveryQueue();
				queue.Enqueue("t1", "{\"a\":1}");
				queue.MarkSending("t1");
				queue.RecordResult("t1", DeliveryOutcome.Retryable, Now, statusCode: 503, serverBody: "server exploded");

				StateFile.WriteAtomic(path, queue.Serialize());
				string loaded = StateFile.Read(path);
				DeliveryQueue reloaded = DeliveryQueue.Deserialize(loaded);

				QueuedDelivery d = reloaded.Find("t1");
				Assert.Equal(DeliveryState.QueuedForRetry, d.State);
				Assert.Equal(503, d.LastStatusCode);
				Assert.Equal("server exploded", d.LastServerBody);
			}
			finally
			{
				string directory = Path.GetDirectoryName(path);
				if (Directory.Exists(directory))
					Directory.Delete(directory, recursive: true);
			}
		}

		[Fact]
		public void StateFile_Read_WhenFileMissing_ReturnsNull()
		{
			string path = Path.Combine(Path.GetTempPath(), "chartjot-tests-" + Guid.NewGuid().ToString("N"), "missing.json");

			Assert.Null(StateFile.Read(path));
		}

		[Fact]
		public void StateFile_WriteAtomic_OverwritesAnExistingFile()
		{
			string path = Path.Combine(Path.GetTempPath(), "chartjot-tests-" + Guid.NewGuid().ToString("N"), "queue.json");
			try
			{
				StateFile.WriteAtomic(path, "{\"first\":true}");
				StateFile.WriteAtomic(path, "{\"second\":true}");

				Assert.Equal("{\"second\":true}", StateFile.Read(path));
			}
			finally
			{
				string directory = Path.GetDirectoryName(path);
				if (Directory.Exists(directory))
					Directory.Delete(directory, recursive: true);
			}
		}
	}
}
