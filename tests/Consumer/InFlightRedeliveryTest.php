<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Consumer;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\Contracts\InboundMessage;
use InitPHP\Queue\Consumer\Dispatcher;
use InitPHP\Queue\Consumer\IdempotencyOptions;
use InitPHP\Queue\Consumer\InMemoryIdempotencyStore;
use InitPHP\Queue\Consumer\OutcomeKind;
use InitPHP\Queue\Consumer\Worker;
use InitPHP\Queue\Consumer\WorkerOptions;
use InitPHP\Queue\Contracts\IdempotencyStore;
use InitPHP\Queue\Exceptions\ConfigurationException;
use InitPHP\Queue\Message\ReceivedMessage;
use InitPHP\Queue\Routing\HandlerMap;
use InitPHP\Queue\Tests\Support\ArrayTransport;
use InitPHP\Queue\Tests\Support\ClaimOnlyIdempotencyStore;
use InitPHP\Queue\Tests\Support\RecordingHandler;
use InitPHP\Queue\Transport\Pdo\PdoTransport;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Regression tests for the two ways an in-flight message used to be lost: a
 * redelivery that found the key claimed by an unfinished handler was dropped, so
 * (1) the message vanished when that handler's worker died, and (2) the failed
 * handler's retry had no message left to update.
 */
final class InFlightRedeliveryTest extends TestCase
{
    private const URN = 'urn:babel:orders:created';

    private const KEY = 'bq:idemp:msg-1';

    private function messageWithId(string $id, int $attempts = 0): ReceivedMessage
    {
        $envelope = EnvelopeCodec::make(self::URN, ['order_id' => 1], 'orders');
        $envelope['meta']['id'] = $id;
        $envelope['attempts'] = $attempts;

        return new ReceivedMessage('orders', EnvelopeCodec::encode($envelope), $envelope, 1);
    }

    private function payload(string $id): string
    {
        $envelope = EnvelopeCodec::make(self::URN, ['order_id' => 1], 'orders');
        $envelope['meta']['id'] = $id;

        return EnvelopeCodec::encode($envelope);
    }

    private function dispatcher(RecordingHandler $handler, IdempotencyStore $store, int $delay = 5): Dispatcher
    {
        return new Dispatcher(
            (new HandlerMap())->register(self::URN, $handler),
            idempotency: new IdempotencyOptions($store, inFlightDelaySeconds: $delay),
        );
    }

    public function test_a_redelivery_of_an_in_flight_key_is_released_not_dropped(): void
    {
        $store = new InMemoryIdempotencyStore();
        $store->claim(self::KEY, 300); // another worker is inside the handler
        $handler = new RecordingHandler();

        $outcome = $this->dispatcher($handler, $store, delay: 7)->dispatch($this->messageWithId('msg-1'));

        self::assertSame(OutcomeKind::Release, $outcome->kind);
        self::assertSame('in_flight', $outcome->reason);
        self::assertSame(7, $outcome->delaySeconds);
        self::assertSame([], $handler->handled, 'the handler must not run while another worker holds the claim');
    }

    public function test_a_redelivery_of_a_completed_key_is_still_dropped(): void
    {
        $store = new InMemoryIdempotencyStore();
        $store->remember(self::KEY);

        $outcome = $this->dispatcher(new RecordingHandler(), $store)->dispatch($this->messageWithId('msg-1'));

        self::assertSame(OutcomeKind::Drop, $outcome->kind);
        self::assertSame('duplicate', $outcome->reason);
    }

    public function test_a_store_that_cannot_tell_in_flight_from_completed_keeps_the_old_behaviour(): void
    {
        $store = new ClaimOnlyIdempotencyStore();
        $store->claim(self::KEY, 300);

        $outcome = $this->dispatcher(new RecordingHandler(), $store)->dispatch($this->messageWithId('msg-1'));

        self::assertSame(OutcomeKind::Drop, $outcome->kind, 'a pre-2.3 store is treated as before');
        self::assertSame('duplicate', $outcome->reason);
    }

    public function test_an_in_flight_message_runs_once_the_claim_is_released(): void
    {
        $store = new InMemoryIdempotencyStore();
        $store->claim(self::KEY, 300);
        $handler = new RecordingHandler();
        $dispatcher = $this->dispatcher($handler, $store);

        self::assertSame(OutcomeKind::Release, $dispatcher->dispatch($this->messageWithId('msg-1'))->kind);

        $store->forget(self::KEY); // the first worker failed, or its lease lapsed

        self::assertSame(OutcomeKind::Ack, $dispatcher->dispatch($this->messageWithId('msg-1'))->kind);
        self::assertCount(1, $handler->handled);
    }

    public function test_an_in_flight_message_is_dropped_once_the_other_handler_records_it(): void
    {
        $store = new InMemoryIdempotencyStore();
        $store->claim(self::KEY, 300);
        $handler = new RecordingHandler();
        $dispatcher = $this->dispatcher($handler, $store);

        self::assertSame(OutcomeKind::Release, $dispatcher->dispatch($this->messageWithId('msg-1'))->kind);

        $store->remember(self::KEY); // the first worker finished

        self::assertSame(OutcomeKind::Drop, $dispatcher->dispatch($this->messageWithId('msg-1'))->kind);
        self::assertSame([], $handler->handled, 'the handler already ran on the other worker');
    }

    public function test_waiting_on_an_in_flight_key_never_consumes_an_attempt(): void
    {
        // Path 1: the first worker died inside the handler and its claim is still held.
        $transport = new ArrayTransport();
        $store = new InMemoryIdempotencyStore();
        $store->claim(self::KEY, 300);
        $handler = new RecordingHandler();
        $events = [];
        $worker = new Worker(
            $transport,
            $this->dispatcher($handler, $store),
            new WorkerOptions(maxAttempts: 1, sleepWhenEmpty: 0.0),
            function (string $level, string $event, array $context) use (&$events): void {
                $events[] = [$event, $context['reason'] ?? null, $context['delay'] ?? null];
            },
        );

        $transport->publish($this->payload('msg-1'), 'orders');

        for ($i = 0; $i < 6; ++$i) {
            $worker->runOnce('orders');
        }

        self::assertSame(1, $transport->readyCount('orders'), 'the message is still queued');
        self::assertSame(0, $transport->deadCount('orders'), 'waiting must not dead-letter it, even with maxAttempts 1');
        self::assertCount(6, $transport->releases);
        self::assertSame([['job.released', 'in_flight', 5]], array_slice($events, 0, 1));

        foreach ($transport->releases as $release) {
            self::assertSame(5, $release['delay'], 'a waiting message is paced, not re-offered at once');
            self::assertSame(0, EnvelopeCodec::decode($release['payload'])['attempts']);
        }

        // The lease lapses (modelled by forgetting the key): the message is processed.
        $store->forget(self::KEY);
        $worker->runOnce('orders');

        self::assertCount(1, $handler->handled, 'the message that the dead worker never completed is handled');
        self::assertSame(0, $transport->readyCount('orders'));
        self::assertSame(0, $transport->reservedCount());
    }

    public function test_a_released_in_flight_message_does_not_spin(): void
    {
        $transport = new ArrayTransport();
        $store = new InMemoryIdempotencyStore();
        $store->claim(self::KEY, 300);
        $worker = new Worker(
            $transport,
            $this->dispatcher(new RecordingHandler(), $store),
            new WorkerOptions(sleepWhenEmpty: 0.2),
        );
        $transport->publish($this->payload('msg-1'), 'orders');

        $startedAt = microtime(true);
        $worker->runOnce('orders');

        self::assertGreaterThanOrEqual(0.15, microtime(true) - $startedAt, 'the worker pauses after deferring a message');
    }

    public function test_the_failed_handler_keeps_its_retry_when_a_redelivery_arrives_meanwhile(): void
    {
        // Path 2: the first handler is slow; the transport hands the message to a
        // second worker while it still runs; then the first handler throws.
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $transport = new PdoTransport($pdo, 'jobs', null, 'default', 0); // retryAfter 0: a reservation is at once reclaimable
        $transport->createSchema();
        $transport->publish($this->payload('msg-1'), 'orders');
        $otherTransport = new PdoTransport($pdo, 'jobs', null, 'default', 0); // the second worker's own connection

        $store = new InMemoryIdempotencyStore();
        $options = new IdempotencyOptions($store, inFlightDelaySeconds: 5);
        $workerOptions = new WorkerOptions(maxAttempts: 3, backoff: [30], sleepWhenEmpty: 0.0);
        $secondWorker = null;
        $secondWorkerRan = false;

        $handlers = (new HandlerMap())->register(self::URN, function (InboundMessage $message) use (&$secondWorker, &$secondWorkerRan): void {
            $secondWorkerRan = $secondWorker->runOnce('orders'); // the redelivery, while we are still in the handler

            throw new RuntimeException('downstream failed');
        });
        $firstWorker = new Worker($transport, new Dispatcher($handlers, idempotency: $options), $workerOptions);
        $secondWorker = new Worker($otherTransport, new Dispatcher($handlers, idempotency: $options), $workerOptions);

        $firstWorker->runOnce('orders');

        self::assertTrue($secondWorkerRan, 'the redelivery was reserved by a second worker');

        $row = $pdo->query('SELECT attempts FROM jobs')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $row, 'the message still has a row: neither a retry nor a dead letter was lost');
        self::assertSame(1, (int) $row[0]['attempts'], 'the failed attempt is counted');
        self::assertGreaterThan(time() + 20, strtotime((string) $pdo->query('SELECT available_at FROM jobs')->fetchColumn()), 'the retry keeps its back-off, the wait did not shorten it');
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM jobs_failed')->fetchColumn());
        self::assertFalse($store->seen(self::KEY), 'the failed handler released its claim');
    }

    public function test_an_in_flight_message_on_a_real_pdo_queue_survives_until_the_claim_lapses(): void
    {
        // Path 1 end to end: a dead worker's claim is held while the redelivery arrives.
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $transport = new PdoTransport($pdo, 'jobs', null, 'default', 0);
        $transport->createSchema();
        $transport->publish($this->payload('msg-1'), 'orders');

        $store = new InMemoryIdempotencyStore();
        $store->claim(self::KEY, 300);
        $handler = new RecordingHandler();
        $worker = new Worker($transport, $this->dispatcher($handler, $store), new WorkerOptions(sleepWhenEmpty: 0.0));

        $worker->runOnce('orders');

        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn(), 'the row survives the redelivery');
        self::assertSame([], $handler->handled);

        $store->forget(self::KEY);
        $pdo->exec("UPDATE jobs SET available_at = '2000-01-01 00:00:00'"); // the delay has passed
        $worker->runOnce('orders');

        self::assertCount(1, $handler->handled);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
    }

    public function test_waiting_does_not_count_as_processing_or_use_up_max_jobs(): void
    {
        $transport = new ArrayTransport();
        $store = new InMemoryIdempotencyStore();
        $store->claim(self::KEY, 300);
        $handler = new RecordingHandler();
        $worker = new Worker(
            $transport,
            $this->dispatcher($handler, $store),
            new WorkerOptions(maxJobs: 1, sleepWhenEmpty: 0.0, reserveTimeout: 0.0, maxRuntime: 1.0),
        );
        $transport->publish($this->payload('msg-1'), 'orders');
        $transport->publish($this->payload('msg-2'), 'orders'); // an ordinary message behind the waiting one

        $worker->run('orders');

        self::assertCount(1, $handler->handled, 'maxJobs 1 was still available for the ordinary message');
        self::assertSame(1, $worker->processedCount(), 'only the handled message is counted');
        self::assertGreaterThan(0, count($transport->releases), 'the waiting message was deferred');
    }

    public function test_a_deferral_pauses_even_when_sleep_when_empty_is_zero(): void
    {
        $transport = new ArrayTransport();
        $store = new InMemoryIdempotencyStore();
        $store->claim(self::KEY, 300);
        $worker = new Worker(
            $transport,
            $this->dispatcher(new RecordingHandler(), $store),
            new WorkerOptions(sleepWhenEmpty: 0.0),
        );
        $transport->publish($this->payload('msg-1'), 'orders');

        $startedAt = microtime(true);
        $worker->runOnce('orders');

        self::assertGreaterThanOrEqual(0.09, microtime(true) - $startedAt, 'a transport that ignores the delay must not be spun on');
    }

    public function test_a_stale_holder_cannot_overwrite_a_retry_recorded_by_another_worker(): void
    {
        // A and B both hold the row (B took over A's lapsed reservation). B records
        // a retry; A then puts the message back unchanged.
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $a = new PdoTransport($pdo, 'jobs', null, 'default', 0);
        $a->createSchema();
        $b = new PdoTransport($pdo, 'jobs', null, 'default', 0);
        $a->publish($this->payload('msg-1'), 'orders');

        $first = $a->reserve('orders');
        $second = $b->reserve('orders');
        self::assertNotNull($first);
        self::assertNotNull($second);

        $envelope = $second->envelope();
        $envelope['attempts'] = 1;
        $b->release($second, EnvelopeCodec::encode($envelope), 60);
        $a->release($first, $first->rawBody(), 5);

        $row = $pdo->query('SELECT attempts, payload FROM jobs')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1, (int) $row['attempts'], 'the recorded retry survives');
        self::assertSame(1, EnvelopeCodec::decode($row['payload'])['attempts']);
        self::assertGreaterThan(time() + 30, strtotime((string) $pdo->query('SELECT available_at FROM jobs')->fetchColumn()));
    }

    public function test_a_release_does_not_touch_a_row_another_worker_holds_now(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $transport = new PdoTransport($pdo, 'jobs', null, 'default', 0);
        $transport->createSchema();
        $transport->publish($this->payload('msg-1'), 'orders');

        $message = $transport->reserve('orders');
        self::assertNotNull($message);

        // Another worker took the row over later: a different reservation stamp.
        $pdo->exec("UPDATE jobs SET reserved_at = '2999-01-01 00:00:00'");

        $envelope = $message->envelope();
        $envelope['attempts'] = 1;
        $transport->release($message, EnvelopeCodec::encode($envelope), 30);

        $row = $pdo->query('SELECT attempts, reserved_at FROM jobs')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(0, (int) $row['attempts'], 'the new holder keeps its row');
        self::assertSame('2999-01-01 00:00:00', $row['reserved_at']);
    }

    public function test_the_in_flight_delay_must_be_at_least_one_second(): void
    {
        $this->expectException(ConfigurationException::class);

        new IdempotencyOptions(new InMemoryIdempotencyStore(), inFlightDelaySeconds: 0);
    }
}
