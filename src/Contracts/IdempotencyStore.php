<?php

declare(strict_types=1);

namespace InitPHP\Queue\Contracts;

/**
 * Records which messages have already been processed, so an at-least-once
 * transport that redelivers the same message (after a crash, a lapsed
 * reservation, or a broker hiccup) processes it **at most once**.
 *
 * The store is the seam BabelQueue deliberately leaves to the framework: an
 * in-memory reference implementation ({@see \InitPHP\Queue\Consumer\InMemoryIdempotencyStore})
 * ships for tests and single-process workers, but anything durable across
 * worker restarts or shared between workers must be backed by a persistent
 * store — Redis (`SET key 1 NX EX <ttl>`), a database row, or the
 * `initphp/cache` PSR-16 pool. Bring your own by implementing this interface
 * over that backend; the {@see \InitPHP\Queue\Consumer\Dispatcher} only ever
 * calls {@see claim()} and {@see remember()}.
 *
 * Contract — the dedup lifecycle the dispatcher relies on:
 *
 *  1. Before running a handler the dispatcher calls {@see claim()}. If it
 *     returns `false` the key is already known (processed, or in flight on
 *     another worker) and the handler is skipped.
 *  2. The handler runs. If it **throws**, the key must remain *unremembered*
 *     so the redelivery is retryable — implementations that mark a key inside
 *     {@see claim()} must therefore give it only a short in-flight lease, not a
 *     permanent record.
 *  3. On success the dispatcher calls {@see remember()} to record the key
 *     permanently (for `$ttl` seconds, or forever when `$ttl` is null).
 *
 * Implementations must be safe to call repeatedly for the same key and should
 * treat keys as opaque, case-sensitive strings.
 */
interface IdempotencyStore
{
    /**
     * Whether `$key` has already been seen — processed, or currently claimed by
     * an in-flight handler. A read-only check with no side effects; the
     * dispatcher uses {@see claim()} for the actual guard, but a store may
     * expose this for diagnostics.
     */
    public function seen(string $key): bool;

    /**
     * Atomically claim `$key` for processing: return `true` if this caller won
     * the claim (the key was previously unseen), or `false` if it was already
     * seen, in which case the caller must skip the handler.
     *
     * This is the in-flight guard. A correct durable implementation performs an
     * atomic test-and-set (e.g. Redis `SET key 1 NX`, or an `INSERT` that fails
     * on a unique key) so that two workers handed the same redelivered message
     * cannot both win the claim. The claim should be held with a short lease
     * (`$leaseSeconds`) so that a worker which dies mid-handler does not leave
     * the key blocked forever — the lease expires and the redelivery can be
     * reclaimed and retried.
     *
     * @param  int  $leaseSeconds  How long, in seconds, an unconfirmed claim is
     *                             held before it may be reclaimed. Ignored by
     *                             stores that cannot expire entries.
     */
    public function claim(string $key, int $leaseSeconds = 0): bool;

    /**
     * Record `$key` as permanently processed, replacing any in-flight claim.
     * Called only after a handler has succeeded.
     *
     * @param  int|null  $ttl  Seconds to retain the record before it may be
     *                         forgotten (long enough to outlive a transport's
     *                         redelivery window), or null to retain it
     *                         indefinitely.
     */
    public function remember(string $key, ?int $ttl = null): void;

    /**
     * Drop any record (processed or in-flight) for `$key`, making it
     * processable again. Primarily for tests and operational reset; the
     * dispatcher never calls it.
     */
    public function forget(string $key): void;
}
