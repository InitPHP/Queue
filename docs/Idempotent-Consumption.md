# Idempotent Consumption

Because delivery is at-least-once, a transport may hand the same message to a
worker more than once (after a crash before the ack, a lapsed reservation, or a
broker hiccup). The dispatcher can dedupe these redeliveries so a message is
processed **at most once** — opt in by giving it an `IdempotencyOptions`:

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

**How it dedupes.** Before running a handler the dispatcher derives a stable
dedup key from the message and *claims* it; a key that is already recorded means
the message was processed before, so the handler is skipped and the message is
acknowledged and discarded. The key is **only recorded after the handler
succeeds** — a handler that throws leaves the key free, so the at-least-once
redelivery is retried as normal.

The key is derived, in order, from:

1. a custom `keyResolver` you supply (`fn (ReceivedMessage): ?string`), else
2. the producer-minted **`meta.id`** (the canonical message identity), else
3. the **`trace_id`**.

A message with none of these has no stable identity and is processed without
deduplication.

```php
new IdempotencyOptions(
    store: $store,
    keyPrefix: 'bq:idemp:',   // namespaces keys in a shared store
    ttl: 86_400,              // seconds a processed key is retained (null = forever)
    leaseSeconds: 300,        // in-flight claim lease (frees a crashed worker's claim)
    keyResolver: fn ($m) => $m->getData()['order_id'] ?? null, // optional
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
        // Atomic test-and-set: only the first caller wins the claim.
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

A PDO-backed store works the same way: `claim()` is an `INSERT` that fails on a
unique `key` column (caught and reported as `false`), `remember()` upserts the
row, and a `processed_at`/`expires_at` column drives the TTL.

See also [Retries & Dead-Letters](Retries-and-Dead-Letters.md) and
[Core Concepts › Delivery guarantee](Core-Concepts.md#delivery-guarantee).
