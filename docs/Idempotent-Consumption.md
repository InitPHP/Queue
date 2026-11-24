# Idempotent Consumption

Delivery is at-least-once, so a transport may hand the same message to a worker
more than once (after a crash before the ack, a lapsed reservation, or a broker
hiccup). The dispatcher can skip such a redelivery instead of running the handler
again — opt in by giving it an `IdempotencyOptions`:

```php
use InitPHP\Queue\Consumer\Dispatcher;
use InitPHP\Queue\Consumer\IdempotencyOptions;
use InitPHP\Queue\Consumer\InMemoryIdempotencyStore;

$dispatcher = new Dispatcher(
    $handlers,
    idempotency: new IdempotencyOptions(new InMemoryIdempotencyStore()),
);
```

With no options (the default) deduplication is off and the dispatcher behaves
exactly as before — this is fully backward-compatible.

> **Deduplication is not exactly-once delivery, and it is not a replacement for an
> idempotent handler.** It removes some redundant executions, but it can still run
> a handler twice. Read [What deduplication does not guarantee](#what-deduplication-does-not-guarantee)
> before enabling it.

**How it dedupes.** Before running a handler the dispatcher derives a stable
dedup key from the message and *claims* it in the store, holding the claim under a
lease (`leaseSeconds`). If the claim succeeds the handler runs; when it returns,
the key is **recorded** (`remember()`, kept for `ttl` seconds); when it throws,
the claim is released (`forget()`), so the normal retry runs it again.

If the claim fails, one of two things is true:

- the key was **recorded**: the message was handled before, so the dispatcher
  acknowledges and discards it (the worker logs `job.dropped` with reason
  `duplicate`); or
- the key is **claimed but not recorded**: another worker is still inside the
  handler, or died inside it and its lease has not expired yet. The dispatcher
  does **not** discard the message, because that handler may still fail and then
  nothing would be left to retry. It puts the message back on the queue
  (`job.released`, reason `in_flight`, with the `delay` in the log context),
  without counting an attempt, and looks again after `inFlightDelaySeconds`
  (default 5).

A waiting message therefore ends one of three ways: the other handler records the
key and the next look drops the message as a duplicate; the other handler throws
and releases its claim, so the next look runs the handler; or the other worker
died, its lease lapses, and the next look runs the handler.

A waiting message consumes **no retry attempt**: it is released verbatim, with
`attempts` unchanged, so it is never moved to the dead-letter queue merely for
waiting, and the worker's `maxAttempts` and `backoff` apply only to real failures.
It is not a tight loop either: the message is paced by `inFlightDelaySeconds`
(honoured natively by PDO and Redis), and after each deferral the worker pauses
for `sleepWhenEmpty`, but never less than 0.1 s, so RabbitMQ (which has no native
delay and hands the message straight back) is not spun on even with
`sleepWhenEmpty: 0`. On PDO and Redis that pause is the price of a deferral for
the messages queued behind it.

A deferral is not processing: the worker does not count it towards `maxJobs` or
`processedCount()`. Two consequences follow. A worker run with `stopWhenEmpty: true`
(drain mode) keeps going while a waiting message is still handed back at once, which
on RabbitMQ is until the other handler finishes or its lease lapses, and for good
if the claim has no lease; on PDO and Redis the waiting message is parked with its
delay, so the queue counts as empty and the worker exits. And `maxJobs` is not used
up by waiting, so a worker whose only message is waiting does not restart over and
over.

How the message is put back is the transport's job, and it is done so that no
step can lose it: PDO is one `UPDATE` of the row, RabbitMQ **publishes the copy
before it acks the original** (a crash in between leaves a duplicate, which this
layer recognises, never a missing message), and Redis does the move in one Lua
script. See [Transport: RabbitMQ](Transport-RabbitMQ.md#why-retries-republish) and
[Transport: Redis](Transport-Redis.md#retry-with-delay).

This needs a store that can tell a recorded key from a claimed one: one that
implements `InitPHP\Queue\Contracts\CompletionAwareIdempotencyStore`
(`InMemoryIdempotencyStore` does; see [Bring your own store](#bring-your-own-store)).
A store that implements only `IdempotencyStore` cannot, and keeps the previous
behaviour: every failed claim is treated as a recorded duplicate and discarded.

## What deduplication does not guarantee

A message is no longer lost because its first handler failed or its worker died
(the two loss paths were reproduced against a real `PdoTransport`, a shared store
with a lease, and worker processes killed with `SIGKILL`, and no longer occur);
these remain. Items 3 to 6 follow from how the lease and the transports work and
were not run as separate scenarios.

1. **A handler can still run twice.** A worker finishes the handler and dies
   before `remember()`. The key is not recorded, so once the lease lapses the redelivery runs the
   handler again. The "processed at most once" reading does not hold; handlers
   must stay idempotent.
2. **A handler that outlives its lease can run twice.** If the lease lapses while
   the first handler is still running, the next redelivery wins the claim and runs
   the same message concurrently. When the first handler then throws, its
   `forget()` also frees the newer claim, because a claim carries no owner.
3. **A key stuck in flight keeps its message waiting.** With a lease that never
   lapses (`leaseSeconds: 0` on a store that then holds the claim forever) a dead
   worker's claim is never released, and the message is deferred again and again
   without being handled. The message is not lost and not dead-lettered, but only
   `forget()` on the key (or a lease) frees it, so give every claim a lease.
4. **A store without `isCompleted()` still discards.** See above: such a store
   keeps the pre-2.3 behaviour, including the message loss it had.
5. **A handler that kills its process never counts as failed.** A message whose
   handler is killed each time (out of memory, a fatal error) never reaches the
   retry path, so its `attempts` stays the same and it never reaches the
   dead-letter queue. Without deduplication it simply crashed the worker again on
   every redelivery; with it, the message is picked up again each time the lease
   lapses. Watch for a message that keeps appearing.
6. **On Redis, a reaper that rewrites the envelope hides the late failure.** Redis
   never redelivers a message by itself; a reaper moves it from
   `<queue>:processing` back onto the queue. If the original handler is still
   running, its eventual failure replaces the reclaimed copy, so `attempts` keeps
   climbing and `maxAttempts` still ends the message (see
   [Transport: Redis](Transport-Redis.md#delivery-semantics)). That only works while
   the copy still has the body the handler was given: a reaper that rewrites it
   (to increment `attempts`, say) makes the late failure unmatchable, and only the
   reaper's own count applies. A client without `EVAL` keeps the same rules but can
   lose a message to a crash in the middle of a retry.

### Choosing `leaseSeconds`

The lease has to be **longer than your slowest handler**, otherwise a second worker
can start the same message while the first is running. It also sets how long a
dead worker's message waits before it is handled again, so keep it as short as the
first rule allows. It no longer has to be shorter than the transport's redelivery
time: a redelivery that arrives while the lease is still held now waits instead of
being discarded.

| Transport | When a dead worker's message comes back | Effect of the lease |
| --- | --- | --- |
| PDO | after `retryAfter` (default 90 s) | the redelivery is released and re-checked every `inFlightDelaySeconds` until the lease lapses, then handled |
| Redis | when your reaper moves it from `<queue>:processing` back onto the queue (there is no automatic reclaim) | the same: it waits until the lease lapses; keep the reaper's delay above your slowest handler |
| RabbitMQ | as soon as the broker sees the connection or channel close, typically immediately | the same, re-checked about every `sleepWhenEmpty` seconds (at least 0.1 s), because RabbitMQ has no native delay |

`leaseSeconds: 0` is not "no lease": the bundled `InMemoryIdempotencyStore` and the
Redis store below then hold the claim with no expiry, so a dead worker's claim
never lapses. `InMemoryIdempotencyStore` lives and dies with its worker, so it
has no orphaned claim, but it also remembers nothing across a restart.

## Upgrading from 2.1 and 2.2

Nothing has to change: `IdempotencyStore`, its method signatures and the
`Dispatcher` and `IdempotencyOptions` constructors are unchanged, and
`inFlightDelaySeconds` is a new optional last argument.

- **A custom store written for 2.1/2.2** keeps working with its old behaviour (a
  failed claim is discarded as a duplicate). To get the fix, also implement
  `CompletionAwareIdempotencyStore` and its single method
  `isCompleted(string $key): bool`.
- **Records written by 2.1/2.2 in a durable store** do not distinguish a claim from
  a recorded key (both were a plain row or a `1`). When you add `isCompleted()`,
  treat such a legacy record as **completed**. That is what 2.2 did, so every
  message deduped before the upgrade is still deduped.
- **Roll the upgrade out by draining first.** Stop every worker that runs 2.1/2.2
  before the first 2.3 worker starts, and deploy the new store schema or value
  format before it. While both versions run, the old workers still discard any
  message whose claim they cannot get, including a claim the new workers hold
  with the new in-flight marker, so a new worker that dies mid-handler can lose
  its message to an old one. They also keep writing legacy claim records, which
  the new `isCompleted()` reads as completed, so a redelivery meant to wait is
  discarded instead. The exposure lasts until the last 2.2 worker has stopped, and
  then for as long as the longest lease still on record: `leaseSeconds` (default
  300 s), or forever for a claim written with `leaseSeconds: 0`, which you clear
  with `forget()`.
- **`InMemoryIdempotencyStore`** keeps nothing across a restart, so it has no
  legacy records.
- **`Outcome::release()`** takes an optional second argument, `$delaySeconds`, and
  `Outcome` has a new `delaySeconds` property (default `0`); a `release` without
  it behaves as before.

## Message keys

The key is derived, in order, from:

1. a custom `keyResolver` you supply (`fn (ReceivedMessage): ?string`), else
2. the producer-minted **`meta.id`** (the canonical message identity), else
3. the **`trace_id`**.

A resolver result is used only when it is a **non-empty string**; `null`, `''` or
any other type (an `int` order id, for instance) is ignored and the key falls
back to `meta.id`. The producer in this library mints a `meta.id` for every
message, so the `trace_id` fallback matters only for messages from other
producers; a `trace_id` can be inherited by several messages of one trace, so a
producer without `meta.id` should supply a `keyResolver`.

The dispatcher validates the envelope before it derives a key, and a message
without a `trace_id` is dead-lettered (`missing_trace_id`) before deduplication
is reached, so every message the handler sees has a key.

A store is shared by every URN, and the key is not scoped by URN. A resolver
that returns only a business id would treat two different message types for the
same order as duplicates of each other — the second handler would never run. Put
the URN into the key and return a string:

```php
use InitPHP\Queue\Message\ReceivedMessage;

new IdempotencyOptions(
    store: $store,
    keyPrefix: 'bq:idemp:',   // namespaces keys in a shared store
    ttl: 86_400,              // seconds a processed key is retained (null = forever)
    leaseSeconds: 60,         // in-flight claim lease: see "Choosing leaseSeconds"
    inFlightDelaySeconds: 5,  // wait before re-checking a key another handler holds
    keyResolver: static function (ReceivedMessage $message): ?string {
        $orderId = $message->getData()['order_id'] ?? null;

        // A string scoped by URN; null falls back to meta.id.
        return $orderId === null ? null : $message->getUrn() . ':' . $orderId;
    },
);
```

## Bring your own store

`InMemoryIdempotencyStore` is correct for a **single long-running worker** but it
is not shared between processes and does not survive a restart, so it cannot
dedupe across the whole fleet. For that, back the
`InitPHP\Queue\Contracts\IdempotencyStore` contract with a durable, atomic store —
exactly how the InitPHP ecosystem treats Cache and Database backends as
pluggable seams:

```php
use InitPHP\Queue\Contracts\CompletionAwareIdempotencyStore;

final class RedisIdempotencyStore implements CompletionAwareIdempotencyStore
{
    public function __construct(private \Redis $redis) {}

    public function seen(string $key): bool
    {
        return (bool) $this->redis->exists($key);
    }

    public function claim(string $key, int $leaseSeconds = 0): bool
    {
        // Atomic test-and-set: only the first caller wins the claim. With EX the
        // claim lapses on its own; without it (leaseSeconds 0) it never does.
        $options = ['NX'];
        if ($leaseSeconds > 0) {
            $options['EX'] = $leaseSeconds;
        }

        return (bool) $this->redis->set($key, 'claimed', $options);
    }

    public function isCompleted(string $key): bool
    {
        // 'claimed' is an in-flight claim. Anything else that exists is a record,
        // including the '1' that 2.1/2.2 stored for both claims and records.
        $value = $this->redis->get($key);

        return $value !== false && $value !== 'claimed';
    }

    public function remember(string $key, ?int $ttl = null): void
    {
        $ttl !== null && $ttl > 0
            ? $this->redis->set($key, 'done', ['EX' => $ttl])
            : $this->redis->set($key, 'done');
    }

    public function forget(string $key): void
    {
        $this->redis->del($key);
    }
}
```

`CompletionAwareIdempotencyStore` extends `IdempotencyStore`; the one method it adds,
`isCompleted()`, is what lets the dispatcher keep an in-flight message instead of
discarding it. Without it a store still works, with the 2.2 behaviour.

A PDO-backed store needs one more step than the Redis one, because a unique
constraint does not expire rows by itself. Redis `EX` frees a lapsed claim on its
own; with a table, `claim()` must free it explicitly or a dead worker's key stays
blocked and every redelivery is discarded. In a transaction, first remove the row
if its lease has lapsed (`DELETE FROM idempotency WHERE dedup_key = ? AND expires_at <= ?`),
then `INSERT` it with `expires_at = now + leaseSeconds` and report `true` only
when the insert affected a row (a unique-key violation means `false`). The same
effect in one statement is `INSERT … ON CONFLICT (dedup_key) DO UPDATE SET expires_at =
excluded.expires_at, completed = 0 WHERE idempotency.expires_at <= ?` (SQLite and PostgreSQL),
again checking the affected-row count. `remember()` upserts the row with
`expires_at = now + ttl` (or `NULL` to keep it) and marks it completed, `forget()`
deletes it, and `seen()` ignores rows whose `expires_at` has passed. For
`isCompleted()` add a `completed` flag column: `claim()` sets it to `0`, **also when
it takes over a lapsed row** (the one-statement form needs `completed = 0` in its
`DO UPDATE SET` list: a takeover that keeps the old `1` makes the next redelivery
look completed and drops the message), `remember()` sets it to
`1`, and `isCompleted()` returns true for a live row whose flag is `1`. Give the
column a default of `1`, so rows written before you added it read as completed.

See also [Retries & Dead-Letters](Retries-and-Dead-Letters.md) and
[Core Concepts › Delivery guarantee](Core-Concepts.md#delivery-guarantee).
