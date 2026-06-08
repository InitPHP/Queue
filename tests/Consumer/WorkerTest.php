<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Consumer;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\Routing\UnknownUrnStrategy;
use InitPHP\Queue\Consumer\Dispatcher;
use InitPHP\Queue\Consumer\Worker;
use InitPHP\Queue\Consumer\WorkerOptions;
use InitPHP\Queue\Routing\HandlerMap;
use InitPHP\Queue\Tests\Support\ArrayTransport;
use InitPHP\Queue\Tests\Support\RecordingHandler;
use InitPHP\Queue\Tests\Support\ThrowingHandler;
use PHPUnit\Framework\TestCase;

final class WorkerTest extends TestCase
{
    private const URN = 'urn:babel:orders:created';

    /**
     * @param  array<string, mixed>  $data
     */
    private function enqueue(ArrayTransport $transport, string $queue = 'orders', array $data = ['order_id' => 1]): void
    {
        $transport->publish(EnvelopeCodec::encode(EnvelopeCodec::make(self::URN, $data, $queue)), $queue);
    }

    public function test_successful_message_is_acked_and_removed(): void
    {
        $transport = new ArrayTransport();
        $handler = new RecordingHandler();
        $worker = new Worker($transport, new Dispatcher((new HandlerMap())->register(self::URN, $handler)));

        $this->enqueue($transport);
        $processed = $worker->runOnce('orders');

        self::assertTrue($processed);
        self::assertCount(1, $handler->handled);
        self::assertSame(0, $transport->readyCount('orders'));
        self::assertSame(0, $transport->reservedCount());
        self::assertSame(0, $transport->deadCount('orders'));
        self::assertSame(1, $worker->processedCount());
    }

    public function test_run_once_on_empty_queue_returns_false(): void
    {
        $worker = new Worker(new ArrayTransport(), new Dispatcher(new HandlerMap()));

        self::assertFalse($worker->runOnce('orders'));
    }

    public function test_failing_message_is_retried_with_back_off_then_dead_lettered(): void
    {
        $transport = new ArrayTransport();
        $map = (new HandlerMap())->register(self::URN, new ThrowingHandler('boom'));
        $options = new WorkerOptions(maxAttempts: 3, backoff: [2, 4]);
        $worker = new Worker($transport, new Dispatcher($map), $options);

        $this->enqueue($transport);

        $worker->runOnce('orders'); // attempt 1 -> release (delay 2)
        self::assertSame(1, $transport->readyCount('orders'));
        self::assertSame(0, $transport->deadCount('orders'));

        $worker->runOnce('orders'); // attempt 2 -> release (delay 4)
        self::assertSame(1, $transport->readyCount('orders'));
        self::assertSame(0, $transport->deadCount('orders'));

        $worker->runOnce('orders'); // attempt 3 -> dead-letter
        self::assertSame(0, $transport->readyCount('orders'));
        self::assertSame(1, $transport->deadCount('orders'));

        self::assertSame([2, 4], array_column($transport->releases, 'delay'));

        $dead = EnvelopeCodec::decode($transport->deadLetters('orders')[0]);
        self::assertSame(3, $dead['attempts']);
        self::assertSame('failed', $dead['dead_letter']['reason']);
        self::assertSame('boom', $dead['dead_letter']['error']);
        self::assertSame('orders', $dead['dead_letter']['original_queue']);
    }

    public function test_attempts_increment_is_persisted_between_retries(): void
    {
        $transport = new ArrayTransport();
        $map = (new HandlerMap())->register(self::URN, new ThrowingHandler());
        $worker = new Worker($transport, new Dispatcher($map), new WorkerOptions(maxAttempts: 5));

        $this->enqueue($transport);
        $worker->runOnce('orders');
        $worker->runOnce('orders');

        // After two failures the re-queued envelope must carry attempts = 2.
        $requeued = EnvelopeCodec::decode($transport->releases[1]['payload']);
        self::assertSame(2, $requeued['attempts']);
    }

    public function test_unknown_urn_delete_strategy_acks_without_dead_lettering(): void
    {
        $transport = new ArrayTransport();
        $worker = new Worker($transport, new Dispatcher(new HandlerMap(), UnknownUrnStrategy::DELETE));

        $this->enqueue($transport);
        $worker->runOnce('orders');

        self::assertSame(0, $transport->readyCount('orders'));
        self::assertSame(0, $transport->deadCount('orders'));
        self::assertSame(0, $transport->reservedCount());
    }

    public function test_unknown_urn_release_strategy_requeues_verbatim(): void
    {
        $transport = new ArrayTransport();
        $worker = new Worker($transport, new Dispatcher(new HandlerMap(), UnknownUrnStrategy::RELEASE));

        $this->enqueue($transport);
        $worker->runOnce('orders');

        self::assertSame(1, $transport->readyCount('orders'));
        self::assertSame(0, $transport->releases[0]['delay']);
    }

    public function test_unknown_urn_dead_letter_strategy_quarantines_immediately(): void
    {
        $transport = new ArrayTransport();
        $worker = new Worker($transport, new Dispatcher(new HandlerMap(), UnknownUrnStrategy::DEAD_LETTER));

        $this->enqueue($transport);
        $worker->runOnce('orders');

        self::assertSame(1, $transport->deadCount('orders'));
        $dead = EnvelopeCodec::decode($transport->deadLetters('orders')[0]);
        self::assertSame('unknown_urn', $dead['dead_letter']['reason']);
    }

    public function test_run_drains_until_empty_with_stop_when_empty(): void
    {
        $transport = new ArrayTransport();
        $handler = new RecordingHandler();
        $worker = new Worker(
            $transport,
            new Dispatcher((new HandlerMap())->register(self::URN, $handler)),
            new WorkerOptions(stopWhenEmpty: true, reserveTimeout: 0.0),
        );

        $this->enqueue($transport, data: ['order_id' => 1]);
        $this->enqueue($transport, data: ['order_id' => 2]);
        $worker->run('orders');

        self::assertSame(2, $worker->processedCount());
        self::assertSame(0, $transport->readyCount('orders'));
        self::assertSame([['order_id' => 1], ['order_id' => 2]], $handler->payloads());
    }

    public function test_max_jobs_limit_stops_the_loop(): void
    {
        $transport = new ArrayTransport();
        $handler = new RecordingHandler();
        $worker = new Worker(
            $transport,
            new Dispatcher((new HandlerMap())->register(self::URN, $handler)),
            new WorkerOptions(maxJobs: 2, stopWhenEmpty: true),
        );

        for ($i = 0; $i < 5; ++$i) {
            $this->enqueue($transport);
        }
        $worker->run('orders');

        self::assertSame(2, $worker->processedCount());
        self::assertSame(3, $transport->readyCount('orders'), 'remaining messages are untouched');
    }

    public function test_logger_receives_lifecycle_events(): void
    {
        $transport = new ArrayTransport();
        $events = [];
        $worker = new Worker(
            $transport,
            new Dispatcher((new HandlerMap())->register(self::URN, new RecordingHandler())),
            new WorkerOptions(),
            function (string $level, string $event, array $context) use (&$events): void {
                $events[] = $event;
            },
        );

        $this->enqueue($transport);
        $worker->runOnce('orders');

        self::assertContains('job.ack', $events);
    }
}
