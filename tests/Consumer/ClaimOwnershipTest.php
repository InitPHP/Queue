<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Consumer;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\Contracts\InboundMessage;
use InitPHP\Queue\Consumer\Dispatcher;
use InitPHP\Queue\Consumer\IdempotencyOptions;
use InitPHP\Queue\Consumer\InMemoryIdempotencyStore;
use InitPHP\Queue\Consumer\OutcomeKind;
use InitPHP\Queue\Contracts\IdempotencyStore;
use InitPHP\Queue\Contracts\OwnershipAwareIdempotencyStore;
use InitPHP\Queue\Message\ReceivedMessage;
use InitPHP\Queue\Routing\HandlerMap;
use InitPHP\Queue\Tests\Support\ClaimOnlyIdempotencyStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A handler that outlives its lease and then throws must not delete the claim, or
 * the completed record, of the worker that took the key over.
 */
final class ClaimOwnershipTest extends TestCase
{
    private const URN = 'urn:babel:orders:created';

    private const KEY = 'bq:idemp:msg-1';

    private function message(): ReceivedMessage
    {
        $envelope = EnvelopeCodec::make(self::URN, ['order_id' => 1], 'orders');
        $envelope['meta']['id'] = 'msg-1';

        return new ReceivedMessage('orders', EnvelopeCodec::encode($envelope), $envelope, 1);
    }

    /**
     * Worker 1 runs the handler, its lease lapses (the key is freed), worker 2
     * takes the key over inside the same handler call and finishes or not, and then
     * worker 1 throws.
     *
     * @param  callable(InboundMessage, int): void  $secondWorkerHandler  what worker 2's handler does
     */
    private function runOverrunningFirstWorker(IdempotencyStore $store, callable $secondWorkerHandler): OutcomeKind
    {
        $depth = 0;
        $dispatcher = null;
        $message = $this->message();

        $handlers = (new HandlerMap())->register(self::URN, function (InboundMessage $m) use (&$depth, &$dispatcher, $store, $message, $secondWorkerHandler): void {
            ++$depth;

            if ($depth === 1) {
                $store->forget(self::KEY); // worker 1's lease lapses
                $dispatcher->dispatch($message); // worker 2 takes the redelivery

                throw new RuntimeException('worker 1 fails after its lease lapsed');
            }

            $secondWorkerHandler($m, $depth);
        });

        $dispatcher = new Dispatcher($handlers, idempotency: new IdempotencyOptions($store, leaseSeconds: 300));

        return $dispatcher->dispatch($message)->kind;
    }

    public function test_a_late_failure_keeps_the_completed_record_of_the_worker_that_took_over(): void
    {
        $store = new InMemoryIdempotencyStore();

        $kind = $this->runOverrunningFirstWorker($store, static function (): void {}); // worker 2 succeeds

        self::assertSame(OutcomeKind::Retry, $kind, 'worker 1 still reports its own failure');
        self::assertTrue($store->isCompleted(self::KEY), 'worker 2 completed the message and that record stays');
    }

    public function test_a_late_failure_keeps_the_claim_of_a_worker_that_is_still_running(): void
    {
        $store = new InMemoryIdempotencyStore();

        $handlers = (new HandlerMap())->register(self::URN, static function () use ($store): void {
            $store->forget(self::KEY); // worker 1's lease lapses
            $token = $store->claimOwned(self::KEY, 300); // worker 2 claims the key and is still inside its handler
            self::assertNotNull($token);

            throw new RuntimeException('worker 1 fails after its lease lapsed');
        });
        $dispatcher = new Dispatcher($handlers, idempotency: new IdempotencyOptions($store, leaseSeconds: 300));

        $dispatcher->dispatch($this->message());

        self::assertTrue($store->seen(self::KEY), "worker 2's claim is not released by worker 1's failure");
        self::assertFalse($store->isCompleted(self::KEY));
    }

    public function test_a_failure_still_releases_its_own_claim(): void
    {
        $store = new InMemoryIdempotencyStore();
        $handlers = (new HandlerMap())->register(self::URN, static function (): void {
            throw new RuntimeException('boom');
        });
        $dispatcher = new Dispatcher($handlers, idempotency: new IdempotencyOptions($store));

        $outcome = $dispatcher->dispatch($this->message());

        self::assertSame(OutcomeKind::Retry, $outcome->kind);
        self::assertFalse($store->seen(self::KEY), 'the redelivery can run the handler again');
    }

    public function test_a_store_without_ownership_keeps_the_unconditional_forget(): void
    {
        // The contract of 2.1 to 2.3: forget() is called as before, so the late
        // failure still clears the key. Nothing changes for such a store.
        $store = new ClaimOnlyIdempotencyStore();

        $kind = $this->runOverrunningFirstWorker($store, static function (): void {}); // worker 2 succeeds

        self::assertSame(OutcomeKind::Retry, $kind);
        self::assertFalse($store->seen(self::KEY), 'the old store loses the record, exactly as it did');
    }

    public function test_a_store_without_ownership_still_runs_the_whole_lifecycle(): void
    {
        $store = new ClaimOnlyIdempotencyStore();
        $calls = 0;
        $handlers = (new HandlerMap())->register(self::URN, function () use (&$calls): void {
            ++$calls;
        });
        $dispatcher = new Dispatcher($handlers, idempotency: new IdempotencyOptions($store));

        self::assertSame(OutcomeKind::Ack, $dispatcher->dispatch($this->message())->kind);
        self::assertSame(OutcomeKind::Drop, $dispatcher->dispatch($this->message())->kind, 'the duplicate is dropped');
        self::assertSame(1, $calls);
    }

    public function test_the_in_memory_store_releases_only_a_claim_it_still_owns(): void
    {
        $store = new InMemoryIdempotencyStore();
        self::assertInstanceOf(OwnershipAwareIdempotencyStore::class, $store);

        $first = $store->claimOwned('k', 300);
        self::assertNotNull($first);
        self::assertNull($store->claimOwned('k', 300), 'a held key cannot be claimed again');

        $store->forgetOwned('k', 'not-the-token');
        self::assertTrue($store->seen('k'), 'a foreign token releases nothing');

        $store->forgetOwned('k', $first);
        self::assertFalse($store->seen('k'));

        $second = $store->claimOwned('k', 300);
        self::assertNotNull($second);
        self::assertNotSame($first, $second, 'every claim has its own token');
        $store->forgetOwned('k', $first);
        self::assertTrue($store->seen('k'), 'a stale token cannot release the newer claim');
    }

    public function test_a_completed_record_is_not_released_by_a_token(): void
    {
        $store = new InMemoryIdempotencyStore();
        $token = $store->claimOwned('k', 300);
        self::assertNotNull($token);
        $store->remember('k', 60);

        $store->forgetOwned('k', $token);

        self::assertTrue($store->isCompleted('k'));
    }

    public function test_plain_claim_and_forget_keep_working_on_an_ownership_store(): void
    {
        $store = new InMemoryIdempotencyStore();

        self::assertTrue($store->claim('k', 300));
        self::assertFalse($store->claim('k', 300));
        $store->forget('k');
        self::assertFalse($store->seen('k'));
    }
}
