<?php

declare(strict_types=1);

namespace InitPHP\Queue\Contracts;

/**
 * An {@see IdempotencyStore} that can tell a **completed** key from one that is
 * merely **in flight**.
 *
 * {@see IdempotencyStore::claim()} returns `false` for both: the key was recorded
 * by {@see IdempotencyStore::remember()} (the message was handled, so discarding
 * the redelivery is right), or another worker holds the claim and has not
 * finished (the message must not be discarded: that worker may still fail or
 * die, and then nothing would handle the message). With this interface the
 * {@see \InitPHP\Queue\Consumer\Dispatcher} asks which one it is and, for an
 * in-flight key, puts the message back on the queue instead of dropping it.
 *
 * A store that does **not** implement this interface keeps the 2.1/2.2 behaviour:
 * every failed claim is treated as a completed duplicate and discarded. The
 * bundled {@see \InitPHP\Queue\Consumer\InMemoryIdempotencyStore} implements it;
 * implement it in your own Redis/PDO store to get the safer behaviour.
 */
interface CompletionAwareIdempotencyStore extends IdempotencyStore
{
    /**
     * Whether `$key` was recorded as **processed** with
     * {@see IdempotencyStore::remember()} and that record is still live.
     *
     * Return `false` for a key that is only claimed (in flight), that has
     * expired, or that is unknown. A read-only check with no side effects.
     *
     * Rows written before your store implemented this interface cannot be told
     * apart if the table has no column for it; treat such a row as completed
     * (the 2.2 behaviour) rather than as in flight.
     */
    public function isCompleted(string $key): bool;
}
