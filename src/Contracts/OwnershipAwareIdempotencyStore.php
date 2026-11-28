<?php

declare(strict_types=1);

namespace InitPHP\Queue\Contracts;

/**
 * An {@see IdempotencyStore} whose claims carry an **owner token**, so releasing a
 * claim only ever releases the one the caller took.
 *
 * {@see IdempotencyStore::forget()} removes whatever record is stored under the
 * key. That is wrong for a handler that outlived its lease and then throws: its
 * claim has lapsed and a second worker has since claimed the same key (or even
 * finished and recorded it), and the late `forget()` deletes the new holder's claim
 * or the completed record, so the message runs again. With this interface the
 * {@see \InitPHP\Queue\Consumer\Dispatcher} takes the claim with
 * {@see claimOwned()} and, when the handler throws, releases it with
 * {@see forgetOwned()}, which does nothing unless the token still owns the key.
 *
 * It is optional and independent of {@see CompletionAwareIdempotencyStore}; a store
 * can implement either or both. A store that does **not** implement it keeps the
 * unconditional {@see IdempotencyStore::forget()}; no existing store changes
 * behaviour. {@see IdempotencyStore::claim()}, {@see IdempotencyStore::remember()}
 * and `forget()` must keep working as before on a store that implements this.
 * The bundled {@see \InitPHP\Queue\Consumer\InMemoryIdempotencyStore} implements it.
 */
interface OwnershipAwareIdempotencyStore extends IdempotencyStore
{
    /**
     * Atomically claim `$key`, as {@see IdempotencyStore::claim()} does, and return
     * an opaque token that identifies this claim, or `null` when the claim was not
     * won (the key is recorded, or another worker holds it).
     *
     * The token must be unique per successful claim (a random string is enough) and
     * must be stored with the claim, so that {@see forgetOwned()} can compare it.
     *
     * @param  int  $leaseSeconds  As for {@see IdempotencyStore::claim()}.
     */
    public function claimOwned(string $key, int $leaseSeconds = 0): ?string;

    /**
     * Release the in-flight claim on `$key` **only if** it is still the one that
     * `$token` identifies; otherwise do nothing. A claim that lapsed and was taken
     * by someone else, or a key that has since been recorded as processed, must be
     * left untouched. The comparison and the removal must be one atomic step.
     */
    public function forgetOwned(string $key, string $token): void;
}
