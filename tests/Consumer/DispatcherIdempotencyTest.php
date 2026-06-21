<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Consumer;

use BabelQueue\Codec\EnvelopeCodec;
use InitPHP\Queue\Consumer\Dispatcher;
use InitPHP\Queue\Consumer\IdempotencyOptions;
use InitPHP\Queue\Consumer\InMemoryIdempotencyStore;
use InitPHP\Queue\Consumer\OutcomeKind;
use InitPHP\Queue\Message\ReceivedMessage;
use InitPHP\Queue\Routing\HandlerMap;
use InitPHP\Queue\Tests\Support\RecordingHandler;
use InitPHP\Queue\Tests\Support\ThrowingHandler;
use PHPUnit\Framework\TestCase;

final class DispatcherIdempotencyTest extends TestCase
{
    private const URN = 'urn:babel:orders:created';

    /**
     * Build a message whose canonical identity (meta.id) is `$id`, so two calls
     * with the same id represent the *same* message redelivered.
     */
    private function messageWithId(string $id, string $urn = self::URN): ReceivedMessage
    {
        $envelope = EnvelopeCodec::make($urn, ['order_id' => 1], 'orders');
        $envelope['meta']['id'] = $id;

        return new ReceivedMessage('orders', EnvelopeCodec::encode($envelope), $envelope, 1);
    }

    private function dispatcher(RecordingHandler|ThrowingHandler $handler, InMemoryIdempotencyStore $store): Dispatcher
    {
        $map = (new HandlerMap())->register(self::URN, $handler);

        return new Dispatcher($map, idempotency: new IdempotencyOptions($store));
    }

    public function test_a_duplicate_message_is_processed_once_and_then_dropped(): void
    {
        $handler = new RecordingHandler();
        $store = new InMemoryIdempotencyStore();
        $dispatcher = $this->dispatcher($handler, $store);

        $first = $dispatcher->dispatch($this->messageWithId('msg-1'));
        $second = $dispatcher->dispatch($this->messageWithId('msg-1'));

        self::assertSame(OutcomeKind::Ack, $first->kind, 'first delivery runs the handler');
        self::assertSame(OutcomeKind::Drop, $second->kind, 'the redelivery is deduped');
        self::assertSame('duplicate', $second->reason);
        self::assertCount(1, $handler->handled, 'the handler ran exactly once');
    }

    public function test_a_distinct_key_runs_the_handler(): void
    {
        $handler = new RecordingHandler();
        $store = new InMemoryIdempotencyStore();
        $dispatcher = $this->dispatcher($handler, $store);

        $dispatcher->dispatch($this->messageWithId('msg-1'));
        $outcome = $dispatcher->dispatch($this->messageWithId('msg-2'));

        self::assertSame(OutcomeKind::Ack, $outcome->kind);
        self::assertCount(2, $handler->handled, 'distinct ids each run');
    }

    public function test_a_throwing_handler_leaves_the_key_unmarked_so_it_is_retryable(): void
    {
        $handler = new ThrowingHandler('boom');
        $store = new InMemoryIdempotencyStore();
        $dispatcher = $this->dispatcher($handler, $store);

        $first = $dispatcher->dispatch($this->messageWithId('msg-1'));

        self::assertSame(OutcomeKind::Retry, $first->kind);
        self::assertSame('failed', $first->reason);
        self::assertFalse($store->seen('bq:idemp:msg-1'), 'a failed message must not be recorded as processed');

        // The redelivery must reach the handler again (the key is free).
        $second = $dispatcher->dispatch($this->messageWithId('msg-1'));
        self::assertSame(OutcomeKind::Retry, $second->kind);
        self::assertSame(2, $handler->calls, 'the redelivery re-ran the handler');
    }

    public function test_disabled_idempotency_is_the_default_and_runs_duplicates(): void
    {
        $handler = new RecordingHandler();
        // No IdempotencyOptions: the dispatcher behaves exactly as before.
        $dispatcher = new Dispatcher((new HandlerMap())->register(self::URN, $handler));

        $dispatcher->dispatch($this->messageWithId('msg-1'));
        $outcome = $dispatcher->dispatch($this->messageWithId('msg-1'));

        self::assertSame(OutcomeKind::Ack, $outcome->kind, 'no dedup when disabled');
        self::assertCount(2, $handler->handled, 'the same message runs twice when idempotency is off');
    }

    public function test_key_for_returns_null_when_no_stable_identity_exists(): void
    {
        // keyFor() is the seam that decides whether a message can be deduped.
        // With no meta.id and no trace_id there is no stable identity, so it
        // returns null and the dispatcher will run the handler every time.
        $options = new IdempotencyOptions(new InMemoryIdempotencyStore());

        $bare = ['job' => self::URN, 'data' => [], 'meta' => [], 'attempts' => 0];
        $bareMessage = new ReceivedMessage('orders', EnvelopeCodec::encode($bare), $bare, 1);
        self::assertNull($options->keyFor($bareMessage));

        // A meta.id is the preferred identity...
        self::assertSame('bq:idemp:msg-1', $options->keyFor($this->messageWithId('msg-1')));
    }

    public function test_trace_id_is_the_fallback_identity_when_meta_id_is_absent(): void
    {
        // No meta.id, but a stable trace_id continued across the redelivery:
        // the dispatcher dedupes on the trace_id fallback.
        $handler = new RecordingHandler();
        $store = new InMemoryIdempotencyStore();
        $map = (new HandlerMap())->register(self::URN, $handler);
        $dispatcher = new Dispatcher($map, idempotency: new IdempotencyOptions($store));

        $build = static function (): ReceivedMessage {
            $envelope = EnvelopeCodec::make(self::URN, ['order_id' => 1], 'orders', 'trace-xyz');
            unset($envelope['meta']['id']);

            return new ReceivedMessage('orders', EnvelopeCodec::encode($envelope), $envelope, 1);
        };

        self::assertSame(OutcomeKind::Ack, $dispatcher->dispatch($build())->kind);
        $second = $dispatcher->dispatch($build());

        self::assertSame(OutcomeKind::Drop, $second->kind, 'deduped on trace_id');
        self::assertSame('duplicate', $second->reason);
        self::assertCount(1, $handler->handled);
    }

    public function test_a_custom_key_resolver_overrides_the_default_identity(): void
    {
        $handler = new RecordingHandler();
        $store = new InMemoryIdempotencyStore();
        $map = (new HandlerMap())->register(self::URN, $handler);
        $options = new IdempotencyOptions(
            $store,
            keyResolver: static fn (ReceivedMessage $m): string => (string) ($m->getData()['order_id'] ?? ''),
        );
        $dispatcher = new Dispatcher($map, idempotency: $options);

        // Different meta.id, same business order_id -> deduped by the resolver.
        $dispatcher->dispatch($this->messageWithId('msg-1'));
        $outcome = $dispatcher->dispatch($this->messageWithId('msg-2'));

        self::assertSame(OutcomeKind::Drop, $outcome->kind);
        self::assertCount(1, $handler->handled);
    }
}
