# InitPHP Queue

[![Designed & Maintained with Tan](https://muhammetsafak.com/badges/designed-maintained-with-tan.svg)](https://muhammetsafak.com/tan/)
[![CI](https://github.com/InitPHP/Queue/actions/workflows/ci.yml/badge.svg)](https://github.com/InitPHP/Queue/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%E2%89%A5%208.2-777bb4.svg)](composer.json)

> The **framework-less [BabelQueue](https://babelqueue.com) runtime for plain PHP** —
> a polyglot queue worker for apps that have no Laravel queue or Symfony Messenger
> of their own.

InitPHP Queue gives a plain PHP / Slim / Mezzio application the piece
[`babelqueue/php-sdk`](https://github.com/BabelQueue/php-sdk) deliberately leaves
to the framework: the **consumer loop, retries with back-off and dead-letter
routing**, plus a **database (PDO) transport** the core SDK does not ship. It
reuses the SDK's canonical `{ job, trace_id, data, meta, attempts }` envelope, so
the queue you produce and consume here is the *same* queue a Go, Python, Node or
.NET service reads — messages are routed by a stable **URN**, never a PHP class
name.

## Where it sits

| Layer | `babelqueue/php-sdk` | **InitPHP Queue** |
| ----- | -------------------- | ----------------- |
| Wire format / contract | Canonical envelope, URN scheme, validation, dead-letter annotation | *(reuses it)* |
| Producer | `EnvelopeCodec` + a publish `Transport` | `Producer` facade |
| **Consumer loop** | **— (left to the framework)** | **`Worker`: reserve → route → ack / retry / dead-letter** |
| Transports | Redis & AMQP (publish only) | Redis, AMQP **and PDO** (publish **+** consume) |

## Requirements

- PHP **8.2+**
- [`babelqueue/php-sdk`](https://packagist.org/packages/babelqueue/php-sdk) `^1.0` (installed automatically)
- A broker client for the transport you choose:
  - `ext-pdo` for the database transport
  - [`predis/predis`](https://packagist.org/packages/predis/predis) for Redis (Redis **6.2+**)
  - [`php-amqplib/php-amqplib`](https://packagist.org/packages/php-amqplib/php-amqplib) for RabbitMQ

## Installation

```bash
composer require initphp/queue
# plus the client for your broker, e.g.:
composer require predis/predis
```

## Quick start

### 1. Write a handler

A handler is mapped to a message **URN**, not to a PHP class name. Return to
acknowledge; throw to fail (the worker retries, then dead-letters).

```php
use BabelQueue\Contracts\InboundMessage;
use InitPHP\Queue\Contracts\Handler;

final class SendWelcomeEmail implements Handler
{
    public function handle(InboundMessage $message): void
    {
        $data = $message->getData();      // ['user_id' => 42, 'email' => '...']
        // ... do the work. Throwing marks the message as failed.
    }
}
```

### 2. Produce a message

```php
use BabelQueue\Codec\EnvelopeCodec;
use InitPHP\Queue\Producer\Producer;
use InitPHP\Queue\Transport\Redis\RedisTransport;

$transport = new RedisTransport(new Predis\Client('tcp://127.0.0.1:6379'));
$producer  = new Producer($transport, defaultQueue: 'emails');

// From a URN + pure-JSON data:
$producer->send('urn:babel:users:registered', ['user_id' => 42, 'email' => 'a@b.c']);
```

A Go or Python consumer subscribed to the same `emails` queue reads the identical
envelope.

### 3. Run a worker

Build the worker in a small bootstrap file that the CLI loads:

```php
// worker.php
use InitPHP\Queue\Consumer\Dispatcher;
use InitPHP\Queue\Consumer\Worker;
use InitPHP\Queue\Consumer\WorkerOptions;
use InitPHP\Queue\Routing\HandlerMap;
use InitPHP\Queue\Transport\Redis\RedisTransport;

require __DIR__ . '/vendor/autoload.php';

$transport = new RedisTransport(new Predis\Client('tcp://127.0.0.1:6379'));

$handlers = (new HandlerMap())
    ->register('urn:babel:users:registered', SendWelcomeEmail::class);

$options = new WorkerOptions(maxAttempts: 3, backoff: [1, 5, 15]);

return new Worker($transport, new Dispatcher($handlers), $options);
```

```bash
php bin/queue work --bootstrap=worker.php --queue=emails
# or process exactly one message and exit:
php bin/queue work --bootstrap=worker.php --queue=emails --once
```

Prefer to drive it from your own code? Skip the CLI and call the worker directly:

```php
$worker->run('emails');       // loop until SIGINT/SIGTERM or a configured limit
$worker->runOnce('emails');   // process at most one message
```

## Transports

All three implement both the SDK's publish `Transport` and this package's
`ConsumerTransport`, so one object both produces and consumes.

```php
use InitPHP\Queue\Transport\Pdo\PdoTransport;
use InitPHP\Queue\Transport\Redis\RedisTransport;
use InitPHP\Queue\Transport\Amqp\AmqpTransport;

// Database (no extra broker to run):
$pdo = new PDO('mysql:host=127.0.0.1;dbname=app', 'user', 'pass');
$transport = new PdoTransport($pdo, table: 'jobs');
$transport->createSchema();   // dev/test convenience; see docs for production DDL

// Redis 6.2+ (reliable-queue: BLMOVE/LREM):
$transport = new RedisTransport(new Predis\Client('tcp://127.0.0.1:6379'));

// RabbitMQ:
$connection = new PhpAmqpLib\Connection\AMQPStreamConnection('127.0.0.1', 5672, 'guest', 'guest');
$transport  = new AmqpTransport($connection->channel());
```

## Retries, back-off and dead-letters

A failed message is re-queued with an incremented `attempts` and a back-off delay
until `WorkerOptions::$maxAttempts` is reached; then it is annotated with a
`dead_letter` block and moved to the dead-letter destination (`<queue>:failed`
list on Redis, `<queue>.failed` queue on RabbitMQ, the `*_failed` table on PDO).

```php
new WorkerOptions(
    maxAttempts: 5,          // total tries before dead-lettering
    backoff: [1, 5, 30],     // seconds between attempts (last value repeats)
    maxJobs: 1000,           // stop after N messages (pair with a supervisor)
    memoryLimitMb: 128,      // stop when memory grows past this
);
```

Delivery is **at-least-once** — make handlers idempotent. The dispatcher's
optional deduplication (see below) can skip some redeliveries, but it does not
replace an idempotent handler.

## Idempotent consumption

Because delivery is at-least-once, a transport may hand the same message to a
worker more than once (after a crash before the ack, a lapsed reservation, or a
broker hiccup). The dispatcher can skip such a redelivery instead of running the
handler again — opt in by giving it an `IdempotencyOptions`:

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

> **Deduplication is not exactly-once delivery and does not replace an idempotent
> handler.** It can still run a handler twice. Read "What deduplication does not
> guarantee" below.

**How it dedupes.** Before running a handler the dispatcher derives a stable
dedup key from the message and *claims* it in the store, holding the claim under a
lease (`leaseSeconds`). If the claim succeeds the handler runs; when it returns,
the key is **recorded** (`remember()`, kept for `ttl` seconds); when it throws,
the claim is released, so the normal retry runs it again.

If the claim fails, the key is either **recorded** (the message was handled before:
it is acknowledged and discarded, `job.dropped` with reason `duplicate`) or
**claimed but not recorded** (another worker is still inside the handler, or died
inside it and its lease has not lapsed). In the second case the message is **not**
discarded: it is put back on the queue (`job.released`, reason `in_flight`)
without counting an attempt, and looked at again after `inFlightDelaySeconds`
(default 5). It ends when the other handler records the key (the message is then
dropped), when that handler fails and releases the claim, or when a dead worker's
lease lapses (the handler then runs). Waiting never moves a message towards the
dead-letter queue, and it is paced rather than a tight loop: the message is held
back for `inFlightDelaySeconds` and the worker pauses for `sleepWhenEmpty` (at
least 0.1 s) after each deferral. A deferral is not counted as processing, so it
does not use up `maxJobs`; on RabbitMQ a `stopWhenEmpty` (drain) worker stays
until the other handler finishes or its lease lapses. Each transport puts the
message back without a step that could lose it: PDO in one `UPDATE`, RabbitMQ by
publishing the copy before acking the original (a crash in between leaves a
duplicate, never a gap), Redis in one Lua script.

This needs a store that implements `CompletionAwareIdempotencyStore`
(`InMemoryIdempotencyStore` does). A store that implements only `IdempotencyStore`
cannot tell the two cases apart and keeps the previous behaviour: any failed claim
is discarded.

### What deduplication does not guarantee

1. **A handler can still run twice.** A worker that dies after the handler but
   before `remember()` leaves no record; once the lease lapses the redelivery runs
   the handler again. This is not "at most once".
2. **A handler that outlives its lease can run twice**, because a second worker
   then wins the claim.
3. **A claim that never lapses keeps its message waiting.** Give every claim a
   lease; `leaseSeconds: 0` holds it until `forget()`.
4. **A handler that kills its process never counts as failed**, so its `attempts`
   does not rise and it never reaches the dead-letter queue; it is picked up again
   each time the lease lapses.
5. **On Redis, a reaper that rewrites the envelope hides the late failure.** A
   handler that outlives the reaper still has its failure counted (it replaces the
   reclaimed copy, so `maxAttempts` ends the message), but only while the copy has
   the body the handler was given. Keep the reaper's delay above your slowest
   handler and move the body back unchanged. A Redis client without `EVAL` keeps the
   same rules, but a crash in the middle of a retry can lose the message.

Set `leaseSeconds` **above your slowest handler**, and as short as that allows: it
is how long a dead worker's message waits before it is handled again. The
per-transport table and the upgrade notes for 2.1/2.2 (drain the old workers
first) are in the
[full guide](docs/Idempotent-Consumption.md#choosing-leaseseconds).

### Message keys

The key is derived, in order, from:

1. a custom `keyResolver` you supply (`fn (ReceivedMessage): ?string`), else
2. the producer-minted **`meta.id`** (the canonical message identity), else
3. the **`trace_id`**.

A resolver result counts only when it is a **non-empty string**; anything else
(`null`, `''`, an `int` order id) falls back to `meta.id`. A store is shared by
every URN and keys are not scoped by it, so scope the key yourself:

```php
use InitPHP\Queue\Message\ReceivedMessage;

new IdempotencyOptions(
    store: $store,
    keyPrefix: 'bq:idemp:',   // namespaces keys in a shared store
    ttl: 86_400,              // seconds a processed key is retained (null = forever)
    leaseSeconds: 60,         // in-flight claim lease; see the notes above
    inFlightDelaySeconds: 5,  // wait before re-checking a key another handler holds
    keyResolver: static function (ReceivedMessage $message): ?string {
        $orderId = $message->getData()['order_id'] ?? null;

        return $orderId === null ? null : $message->getUrn() . ':' . $orderId;
    },
);
```

### Bring your own store

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

Full guide: [docs/Idempotent-Consumption.md](docs/Idempotent-Consumption.md).

## Unknown-URN strategy

When a message arrives whose URN has no mapped handler, the dispatcher applies one
of the four canonical BabelQueue strategies:

```php
use BabelQueue\Routing\UnknownUrnStrategy;

new Dispatcher($handlers, UnknownUrnStrategy::DEAD_LETTER);
// FAIL (default) | DELETE | RELEASE | DEAD_LETTER
```

A malformed or unsupported-`schema_version` envelope is always **quarantined**
(dead-lettered), never silently dropped.

## Documentation

Full guides live in [`docs/`](docs/Home.md):

| Guide | What it covers |
| ----- | -------------- |
| [Getting started](docs/Quick-Start.md) | Install, the three moving parts, your first producer + worker. |
| [Envelope & URNs](docs/The-Message-Envelope.md) | The wire format and how to name message URNs. |
| [Handlers & routing](docs/Handlers-and-Routing.md) | Writing handlers, the `HandlerMap`, unknown-URN strategies. |
| [The worker & retries](docs/The-Worker.md) | The loop, `WorkerOptions`, back-off, limits, graceful shutdown, the CLI. |
| [Dead-letter handling](docs/Retries-and-Dead-Letters.md) | When messages are quarantined and how to inspect/replay them. |
| [PDO transport](docs/Transport-PDO.md) | Schema, production DDL, reservation semantics. |
| [Redis transport](docs/Transport-Redis.md) | Keys, the reliable-queue pattern, delayed retries. |
| [RabbitMQ transport](docs/Transport-RabbitMQ.md) | Properties, headers, retry/delay caveats. |
| [Interoperability](docs/Interoperability.md) | Consuming a Go/Python-produced message end to end. |
| [Migrating from 1.x](docs/Migration-from-1.x.md) | What changed and how to port `1.x` jobs. |

## Migrating from 1.x

`2.0` is a breaking rewrite. See [UPGRADE-2.0.md](UPGRADE-2.0.md) and
[docs/Migration-from-1.x.md](docs/Migration-from-1.x.md).

## Testing

```bash
composer install
composer test            # unit suite (no broker required)
composer ci              # cs-check + phpstan + tests
```

Integration tests against real Redis/RabbitMQ/MySQL run in CI (and locally when
the matching `QUEUE_TEST_*` environment variables are set).

## Contributing

Fork, branch, add tests for your change, and open a pull request. All code is
released under the MIT License.

## Credits

- [Muhammet ŞAFAK](https://muhammetsafak.com) <<info@muhammetsafak.com>>

## License

Copyright &copy; 2023–2026 InitPHP — released under the [MIT License](LICENSE).
