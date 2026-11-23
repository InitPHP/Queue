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
> idempotent handler.** It removes some redundant executions; it can also discard
> a message whose handler never completed, and it can still run a handler twice.
> Read [What deduplication does not guarantee](#what-deduplication-does-not-guarantee)
> before enabling it.

**How it dedupes.** Before running a handler the dispatcher derives a stable
dedup key from the message and *claims* it in the store, holding the claim under a
lease (`leaseSeconds`). If the claim succeeds the handler runs; when it returns,
the key is **recorded** (`remember()`, kept for `ttl` seconds); when it throws,
the claim is released, so the normal retry runs it again.

If the claim fails, the dispatcher skips the handler and acknowledges the message,
which discards it (the worker logs `job.dropped` with reason `duplicate`). A
failed claim means one of two things, and the dispatcher cannot tell them apart:

- the key was **recorded**: the message was handled before, so discarding it is
  right; or
- the key is **claimed but not recorded**: another worker is still inside the
  handler, or died inside it and its lease has not expired yet. Discarding the
  message here means the handler may never complete for it.

The second case is the one to understand before you turn this on — see
[What deduplication does not guarantee](#what-deduplication-does-not-guarantee).

## What deduplication does not guarantee

Because an in-flight claim is treated like a recorded key, three situations turn
out differently from what "dedupe" suggests. All three were reproduced against a
real `PdoTransport`, a shared store with a lease, and worker processes killed with
`SIGKILL`.

1. **A message can be lost.** A worker claims the key and dies inside the handler.
   The transport redelivers the message, but the claim is still held, so the
   redelivery is acknowledged and discarded as a duplicate. The handler never
   completes for that message, and nothing is retried or dead-lettered. With the
   defaults (`leaseSeconds: 300`) on a `PdoTransport` (`retryAfter: 90`), the
   redelivery arrives after 90 seconds, while the claim lives for 300.
2. **A handler can still run twice.** A worker finishes the handler and dies
   before `remember()`. The key is not recorded, so once the lease lapses the
   redelivery runs the handler again. The "processed at most once" reading does
   not hold; handlers must stay idempotent.
3. **A failed attempt can lose its retry.** A handler outlives the transport's
   redelivery time, so a second worker receives the same message, finds the claim
   held, and discards it. On PDO that deletes the row, so when the first handler
   then throws, its retry has no row to update and is silently lost: no retry and
   no dead letter.

### Choosing `leaseSeconds`

The lease has to be **longer than your slowest handler** (otherwise a second
worker can start the same message while the first is running) and **shorter than
the time your transport takes to redeliver a message from a dead worker** (so the
redelivery finds the claim gone and runs the handler).

| Transport | When a dead worker's message comes back | A safe `leaseSeconds` |
| --- | --- | --- |
| PDO | after `retryAfter` (default 90 s) | below `retryAfter`, above the slowest handler — for example `retryAfter: 120`, `leaseSeconds: 90` for handlers under a minute |
| Redis | when your reaper moves it from `<queue>:processing` back onto the queue (there is no automatic reclaim) | below the reaper's delay |
| RabbitMQ | as soon as the broker sees the connection or channel close, typically immediately | none — a crash inside a handler always discards the message while deduplication is on |

`leaseSeconds: 0` is not "no lease": the bundled `InMemoryIdempotencyStore` and the
Redis store below then hold the claim with no expiry, so a dead worker's claim
never lapses. `InMemoryIdempotencyStore` lives and dies with its worker, so it
has no orphaned claim, but it also remembers nothing across a restart.

If no value satisfies both bounds for your transport, leave deduplication off and
rely on an idempotent handler. An idempotent handler is the only guarantee that
holds on every transport, crash timing and store.

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
use InitPHP\Queue\Contracts\IdempotencyStore;

final class RedisIdempotencyStore implements IdempotencyStore
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

        return (bool) $this->redis->set($key, '1', $options);
    }

    public function remember(string $key, ?int $ttl = null): void
    {
        $ttl !== null && $ttl > 0
            ? $this->redis->set($key, '1', ['EX' => $ttl])
            : $this->redis->set($key, '1');
    }

    public function forget(string $key): void
    {
        $this->redis->del($key);
    }
}
```

A PDO-backed store needs one more step than the Redis one, because a unique
constraint does not expire rows by itself. Redis `EX` frees a lapsed claim on its
own; with a table, `claim()` must free it explicitly or a dead worker's key stays
blocked and every redelivery is discarded. In a transaction, first remove the row
if its lease has lapsed (`DELETE FROM idempotency WHERE dedup_key = ? AND expires_at <= ?`),
then `INSERT` it with `expires_at = now + leaseSeconds` and report `true` only
when the insert affected a row (a unique-key violation means `false`). The same
effect in one statement is `INSERT … ON CONFLICT (dedup_key) DO UPDATE SET expires_at =
excluded.expires_at WHERE idempotency.expires_at <= ?` (SQLite and PostgreSQL),
again checking the affected-row count. `remember()` upserts the row with
`expires_at = now + ttl` (or `NULL` to keep it), `forget()` deletes it, and
`seen()` ignores rows whose `expires_at` has passed.

See also [Retries & Dead-Letters](Retries-and-Dead-Letters.md) and
[Core Concepts › Delivery guarantee](Core-Concepts.md#delivery-guarantee).
