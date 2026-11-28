<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Support;

use InitPHP\Queue\Consumer\InMemoryIdempotencyStore;
use InitPHP\Queue\Contracts\CompletionAwareIdempotencyStore;

/**
 * A completion-aware store whose worker can "die": while `$dead` is set, `remember()`
 * and `forget()` do nothing, so the in-flight claim stays behind exactly as a killed
 * process leaves it. `lapse()` models the lease running out.
 */
final class CrashableIdempotencyStore implements CompletionAwareIdempotencyStore
{
    public bool $dead = false;

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

    public function isCompleted(string $key): bool
    {
        return $this->inner->isCompleted($key);
    }

    public function remember(string $key, ?int $ttl = null): void
    {
        if (! $this->dead) {
            $this->inner->remember($key, $ttl);
        }
    }

    public function forget(string $key): void
    {
        if (! $this->dead) {
            $this->inner->forget($key);
        }
    }

    /** The lease of the dead worker's claim runs out. */
    public function lapse(string $key): void
    {
        $this->inner->forget($key);
    }
}
