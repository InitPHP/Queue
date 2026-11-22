# InitPHP Queue — Wiki

Welcome to the official documentation for **`initphp/queue`** — the
**framework-less [BabelQueue](https://babelqueue.com) runtime for plain PHP**.

It gives an application that has no Laravel queue or Symfony Messenger of its own
the piece [`babelqueue/php-sdk`](https://github.com/BabelQueue/php-sdk)
deliberately leaves to the framework: the **consumer loop, retries with back-off
and dead-letter routing** — plus a **database (PDO) transport** the core SDK does
not ship.

Because it reuses the SDK's canonical `{ job, trace_id, data, meta, attempts }`
envelope, the queue you produce and consume here is the **same** queue a Go,
Python, Node or .NET service reads. Messages are routed by a stable **URN**
(`urn:babel:orders:created`), never a PHP class name.

```bash
composer require initphp/queue predis/predis
```

```php
use BabelQueue\Contracts\InboundMessage;
use InitPHP\Queue\Consumer\{Dispatcher, Worker, WorkerOptions};
use InitPHP\Queue\Contracts\Handler;
use InitPHP\Queue\Producer\Producer;
use InitPHP\Queue\Routing\HandlerMap;
use InitPHP\Queue\Transport\Redis\RedisTransport;

// A handler is mapped to a URN, not a class name.
final class SendWelcomeEmail implements Handler
{
    public function handle(InboundMessage $message): void
    {
        $email = $message->getData()['email'];
        // ... send it. Throwing marks the message as failed (retry, then dead-letter).
    }
}

$transport = new RedisTransport(new Predis\Client('tcp://127.0.0.1:6379'));

// Produce
(new Producer($transport, 'emails'))
    ->send('urn:babel:users:registered', ['user_id' => 42, 'email' => 'jane@example.com']);

// Consume
$handlers = (new HandlerMap())->register('urn:babel:users:registered', SendWelcomeEmail::class);
$worker   = new Worker($transport, new Dispatcher($handlers), new WorkerOptions(maxAttempts: 3));
$worker->run('emails');
```

## Where this package sits

| Layer | `babelqueue/php-sdk` | **InitPHP Queue** |
| ----- | -------------------- | ----------------- |
| Wire format / contract | Canonical envelope, URN scheme, validation, dead-letter annotation | *reuses it* |
| Producer | `EnvelopeCodec` + a publish `Transport` | `Producer` facade |
| **Consumer loop** | **— (left to the framework)** | **`Worker`: reserve → route → ack / retry / dead-letter** |
| Transports | Redis & AMQP (publish only) | Redis, AMQP **and PDO** (publish **+** consume) |

## Start here

- **New to the package?** Read [Installation](Installation.md) then [Quick Start](Quick-Start.md).
- **Want the big picture?** Read [Core Concepts](Core-Concepts.md).
- **Coming from `1.x`?** Read [Migration from 1.x](Migration-from-1.x.md).

Use the sidebar to navigate. Every code example on this wiki reflects the real
public API of the current release.

## Requirements

- PHP **8.2+**
- `babelqueue/php-sdk ^1.0` (installed automatically)
- A client for your broker: `ext-pdo`, `predis/predis` (Redis 6.2+), or `php-amqplib/php-amqplib`
