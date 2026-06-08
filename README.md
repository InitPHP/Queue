# InitPHP Queue

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

Delivery is **at-least-once** — make handlers idempotent.

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

Full guides live in [`docs/`](docs/):

| Guide | What it covers |
| ----- | -------------- |
| [Getting started](docs/getting-started.md) | Install, the three moving parts, your first producer + worker. |
| [Envelope & URNs](docs/envelope-and-urns.md) | The wire format and how to name message URNs. |
| [Handlers & routing](docs/handlers-and-routing.md) | Writing handlers, the `HandlerMap`, unknown-URN strategies. |
| [The worker & retries](docs/worker-and-retries.md) | The loop, `WorkerOptions`, back-off, limits, graceful shutdown, the CLI. |
| [Dead-letter handling](docs/dead-letter.md) | When messages are quarantined and how to inspect/replay them. |
| [PDO transport](docs/transports/pdo.md) | Schema, production DDL, reservation semantics. |
| [Redis transport](docs/transports/redis.md) | Keys, the reliable-queue pattern, delayed retries. |
| [RabbitMQ transport](docs/transports/amqp.md) | Properties, headers, retry/delay caveats. |
| [Interoperability](docs/interoperability.md) | Consuming a Go/Python-produced message end to end. |
| [Migrating from 1.x](docs/migrating-from-v1.md) | What changed and how to port `1.x` jobs. |

## Migrating from 1.x

`2.0` is a breaking rewrite. See [UPGRADE-2.0.md](UPGRADE-2.0.md) and
[docs/migrating-from-v1.md](docs/migrating-from-v1.md).

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

- [Muhammet ŞAFAK](https://www.muhammetsafak.com.tr) <<info@muhammetsafak.com.tr>>

## License

Copyright &copy; 2023–2026 InitPHP — released under the [MIT License](LICENSE).
