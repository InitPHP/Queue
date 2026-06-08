# InitPHP Queue — Documentation

InitPHP Queue is the **framework-less [BabelQueue](https://babelqueue.com) runtime
for plain PHP**: it owns the consumer loop, retries and dead-letter routing that
[`babelqueue/php-sdk`](https://github.com/BabelQueue/php-sdk) leaves to the
framework, and reuses the SDK's canonical envelope so your queue interoperates
with Go, Python, Node and any other BabelQueue SDK.

## Contents

| Guide | What it covers |
| ----- | -------------- |
| [Getting started](getting-started.md) | Install, the three moving parts, your first producer and worker. |
| [Envelope & URNs](envelope-and-urns.md) | The wire format you produce/consume and how to name message URNs. |
| [Handlers & routing](handlers-and-routing.md) | Writing handlers, registering them, and unknown-URN strategies. |
| [The worker & retries](worker-and-retries.md) | The loop, `WorkerOptions`, back-off, limits, graceful shutdown, and the CLI. |
| [Dead-letter handling](dead-letter.md) | When and why messages are quarantined, and how to inspect or replay them. |
| [PDO transport](transports/pdo.md) | Tables, production DDL, the optimistic reservation, and visibility timeout. |
| [Redis transport](transports/redis.md) | Keys, the reliable-queue pattern, and delayed retries. |
| [RabbitMQ transport](transports/amqp.md) | AMQP properties/headers and the retry/delay caveat. |
| [Interoperability](interoperability.md) | Consuming a message produced by another language, end to end. |
| [Migrating from 1.x](migrating-from-v1.md) | What changed in 2.0 and how to port your jobs. |

## At a glance

```php
use BabelQueue\Routing\UnknownUrnStrategy;
use InitPHP\Queue\Consumer\{Dispatcher, Worker, WorkerOptions};
use InitPHP\Queue\Producer\Producer;
use InitPHP\Queue\Routing\HandlerMap;
use InitPHP\Queue\Transport\Redis\RedisTransport;

$transport = new RedisTransport(new Predis\Client('tcp://127.0.0.1:6379'));

// Produce
(new Producer($transport, 'emails'))->send('urn:babel:users:registered', ['user_id' => 42]);

// Consume
$handlers = (new HandlerMap())->register('urn:babel:users:registered', SendWelcomeEmail::class);
$worker   = new Worker($transport, new Dispatcher($handlers, UnknownUrnStrategy::FAIL), new WorkerOptions(maxAttempts: 3));
$worker->run('emails');
```

## The three moving parts

1. **Transport** — talks to a broker (PDO / Redis / RabbitMQ). It both publishes
   (`Transport::publish`) and consumes (`ConsumerTransport::reserve/ack/release/deadLetter`).
2. **Dispatcher + HandlerMap** — decode and validate each message, then route it
   by URN to a `Handler`.
3. **Worker** — the loop that ties them together and applies the retry / back-off
   / dead-letter policy from `WorkerOptions`.

## Requirements

- PHP 8.2 or newer
- `babelqueue/php-sdk ^1.0`
- The client for your broker: `ext-pdo`, `predis/predis`, or `php-amqplib/php-amqplib`
