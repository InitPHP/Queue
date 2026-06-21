<?php

declare(strict_types=1);

namespace InitPHP\Queue\Consumer;

use InitPHP\Queue\Contracts\IdempotencyStore;

/**
 * The bundled reference {@see IdempotencyStore}: an in-process array of seen
 * keys with monotonic-clock expiry.
 *
 * It is correct for a **single-process** worker — it makes a redelivered
 * message processed at most once for as long as the process lives — and is the
 * store the test-suite drives. It is **not** shared across processes and does
 * not survive a restart, so it cannot dedupe across the whole fleet: for that,
 * back {@see IdempotencyStore} with Redis, a database table or `initphp/cache`.
 * See the README "Idempotent consumption" section for the BYO pattern.
 *
 * Expiry uses {@see hrtime()} (a monotonic clock) so it is immune to wall-clock
 * jumps; a null expiry means "remembered forever".
 */
final class InMemoryIdempotencyStore implements IdempotencyStore
{
    /**
     * key => expiry in nanoseconds on the monotonic clock, or null for never.
     *
     * @var array<string, int|null>
     */
    private array $entries = [];

    public function seen(string $key): bool
    {
        // array_key_exists, not isset: a permanent record is stored as null,
        // which isset() would wrongly report as absent.
        if (! array_key_exists($key, $this->entries)) {
            return false;
        }

        $expiry = $this->entries[$key];
        if ($expiry !== null && hrtime(true) >= $expiry) {
            unset($this->entries[$key]);

            return false;
        }

        return true;
    }

    public function claim(string $key, int $leaseSeconds = 0): bool
    {
        if ($this->seen($key)) {
            return false;
        }

        // Hold the claim under a short lease so a crash mid-handler frees it for
        // the redelivery, rather than blocking the key forever.
        $this->entries[$key] = $this->expiryFor($leaseSeconds > 0 ? $leaseSeconds : null);

        return true;
    }

    public function remember(string $key, ?int $ttl = null): void
    {
        $this->entries[$key] = $this->expiryFor($ttl);
    }

    public function forget(string $key): void
    {
        unset($this->entries[$key]);
    }

    /**
     * Number of currently-live (unexpired) entries — for tests and diagnostics.
     */
    public function count(): int
    {
        $live = 0;
        foreach (array_keys($this->entries) as $key) {
            if ($this->seen($key)) {
                ++$live;
            }
        }

        return $live;
    }

    /**
     * Convert a TTL in seconds (or null) into an absolute monotonic-clock
     * expiry in nanoseconds (or null for "never expires").
     */
    private function expiryFor(?int $seconds): ?int
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        return hrtime(true) + $seconds * 1_000_000_000;
    }
}
