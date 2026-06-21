<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Consumer;

use InitPHP\Queue\Consumer\InMemoryIdempotencyStore;
use PHPUnit\Framework\TestCase;

final class InMemoryIdempotencyStoreTest extends TestCase
{
    public function test_an_unseen_key_can_be_claimed_and_is_then_seen(): void
    {
        $store = new InMemoryIdempotencyStore();

        self::assertFalse($store->seen('k'));
        self::assertTrue($store->claim('k'), 'first claim wins');
        self::assertTrue($store->seen('k'));
    }

    public function test_a_second_claim_of_the_same_key_loses(): void
    {
        $store = new InMemoryIdempotencyStore();

        self::assertTrue($store->claim('k'));
        self::assertFalse($store->claim('k'), 'the key is already claimed');
    }

    public function test_distinct_keys_are_independent(): void
    {
        $store = new InMemoryIdempotencyStore();

        self::assertTrue($store->claim('a'));
        self::assertTrue($store->claim('b'), 'a different key is unaffected');
        self::assertSame(2, $store->count());
    }

    public function test_remember_records_the_key_permanently(): void
    {
        $store = new InMemoryIdempotencyStore();

        $store->remember('k');

        self::assertTrue($store->seen('k'));
        self::assertFalse($store->claim('k'), 'a remembered key cannot be reclaimed');
    }

    public function test_forget_makes_a_key_processable_again(): void
    {
        $store = new InMemoryIdempotencyStore();
        $store->remember('k');

        $store->forget('k');

        self::assertFalse($store->seen('k'));
        self::assertTrue($store->claim('k'), 'a forgotten key can be reclaimed');
    }

    public function test_a_zero_ttl_claim_is_held_until_remembered_or_forgotten(): void
    {
        // leaseSeconds = 0 means "no lease expiry" for the in-memory reference
        // store; the claim is held for the life of the process.
        $store = new InMemoryIdempotencyStore();

        self::assertTrue($store->claim('k', 0));
        self::assertTrue($store->seen('k'));
    }
}
