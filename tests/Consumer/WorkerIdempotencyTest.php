<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Consumer;

use BabelQueue\Codec\EnvelopeCodec;
use InitPHP\Queue\Consumer\Dispatcher;
use InitPHP\Queue\Consumer\IdempotencyOptions;
use InitPHP\Queue\Consumer\InMemoryIdempotencyStore;
use InitPHP\Queue\Consumer\Worker;
use InitPHP\Queue\Consumer\WorkerOptions;
use InitPHP\Queue\Routing\HandlerMap;
use InitPHP\Queue\Tests\Support\ArrayTransport;
use InitPHP\Queue\Tests\Support\RecordingHandler;
use InitPHP\Queue\Tests\Support\ThrowingHandler;
use PHPUnit\Framework\TestCase;

final class WorkerIdempotencyTest extends TestCase
{
    private const URN = 'urn:babel:orders:created';

    /**
     * Build a fixed envelope with a known meta.id so the *same* message can be
     * enqueued twice — modelling an at-least-once redelivery.
     */
    private function envelope(string $id): string
    {
        $envelope = EnvelopeCodec::make(self::URN, ['order_id' => 1], 'orders');
        $envelope['meta']['id'] = $id;

        return EnvelopeCodec::encode($envelope);
    }

    public function test_a_redelivered_message_is_handled_once_and_acked_not_dead_lettered(): void
    {
        $transport = new ArrayTransport();
        $handler = new RecordingHandler();
        $dispatcher = new Dispatcher(
            (new HandlerMap())->register(self::URN, $handler),
            idempotency: new IdempotencyOptions(new InMemoryIdempotencyStore()),
        );
        $worker = new Worker($transport, $dispatcher);

        // Same message delivered twice (e.g. a crash before the first ack).
        $transport->publish($this->envelope('msg-1'), 'orders');
        $transport->publish($this->envelope('msg-1'), 'orders');

        $worker->runOnce('orders');
        $worker->runOnce('orders');

        self::assertCount(1, $handler->handled, 'the handler ran exactly once');
        self::assertSame(0, $transport->readyCount('orders'), 'both copies were acked');
        self::assertSame(0, $transport->reservedCount());
        self::assertSame(0, $transport->deadCount('orders'), 'a duplicate is dropped, not dead-lettered');
    }

    public function test_a_failed_message_is_not_recorded_and_is_retried(): void
    {
        $transport = new ArrayTransport();
        $store = new InMemoryIdempotencyStore();
        $dispatcher = new Dispatcher(
            (new HandlerMap())->register(self::URN, new ThrowingHandler('boom')),
            idempotency: new IdempotencyOptions($store),
        );
        $worker = new Worker($transport, $dispatcher, new WorkerOptions(maxAttempts: 3));

        $transport->publish($this->envelope('msg-1'), 'orders');
        $worker->runOnce('orders'); // attempt 1 fails -> released for retry

        self::assertSame(1, $transport->readyCount('orders'), 'the failed message is requeued');
        self::assertSame(0, $transport->deadCount('orders'));
        self::assertFalse($store->seen('bq:idemp:msg-1'), 'a failed message is never marked processed');
    }

    public function test_idempotency_disabled_runs_the_duplicate(): void
    {
        $transport = new ArrayTransport();
        $handler = new RecordingHandler();
        // No IdempotencyOptions -> default behaviour, duplicates run.
        $worker = new Worker($transport, new Dispatcher((new HandlerMap())->register(self::URN, $handler)));

        $transport->publish($this->envelope('msg-1'), 'orders');
        $transport->publish($this->envelope('msg-1'), 'orders');

        $worker->runOnce('orders');
        $worker->runOnce('orders');

        self::assertCount(2, $handler->handled, 'without idempotency the redelivery runs again');
        self::assertSame(0, $transport->deadCount('orders'));
    }

    public function test_a_duplicate_emits_the_dropped_lifecycle_event(): void
    {
        $transport = new ArrayTransport();
        $events = [];
        $dispatcher = new Dispatcher(
            (new HandlerMap())->register(self::URN, new RecordingHandler()),
            idempotency: new IdempotencyOptions(new InMemoryIdempotencyStore()),
        );
        $worker = new Worker(
            $transport,
            $dispatcher,
            new WorkerOptions(),
            function (string $level, string $event, array $context) use (&$events): void {
                $events[] = ['event' => $event, 'reason' => $context['reason'] ?? null];
            },
        );

        $transport->publish($this->envelope('msg-1'), 'orders');
        $transport->publish($this->envelope('msg-1'), 'orders');
        $worker->runOnce('orders');
        $worker->runOnce('orders');

        self::assertContains(['event' => 'job.dropped', 'reason' => 'duplicate'], $events);
    }
}
