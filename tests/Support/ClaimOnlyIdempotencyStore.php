<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Support;

use InitPHP\Queue\Consumer\InMemoryIdempotencyStore;
use InitPHP\Queue\Contracts\IdempotencyStore;

/**
 * A store written against the original (2.1/2.2) contract: it implements only
 * {@see IdempotencyStore} and cannot tell a completed key from an in-flight one.
 */
final class ClaimOnlyIdempotencyStore implements IdempotencyStore
{
    private readonly InMemoryIdempotencyStore $inner;

    public function __construct()
    {
        $this->inner = new InMemoryIdempotencyStore();
    }

    public function seen(string $key): bool
    {
        return $this->inner->seen($key);
    }

    public function claim(string $key, int $leaseSeconds = 0): bool
    {
        return $this->inner->claim($key, $leaseSeconds);
    }

    public function remember(string $key, ?int $ttl = null): void
    {
        $this->inner->remember($key, $ttl);
    }

    public function forget(string $key): void
    {
        $this->inner->forget($key);
    }
}
