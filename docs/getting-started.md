# Getting started

This guide takes you from an empty project to a producer pushing messages and a
worker consuming them.

## Install

```bash
composer require initphp/queue
```

Then add the client for the broker you want to use:

```bash
composer require predis/predis            # Redis 6.2+
# or
composer require php-amqplib/php-amqplib  # RabbitMQ
# or nothing extra — the PDO transport only needs ext-pdo
```

## The three moving parts

| Part | Class | Role |
| ---- | ----- | ---- |
| Transport | `InitPHP\Queue\Transport\*` | Publishes and consumes against a broker. |
| Routing | `InitPHP\Queue\Routing\HandlerMap` + `InitPHP\Queue\Consumer\Dispatcher` | Maps a message URN to a handler. |
| Worker | `InitPHP\Queue\Consumer\Worker` | The consume loop with retry/dead-letter policy. |

## 1. Write a handler

A handler implements `InitPHP\Queue\Contracts\Handler`. It receives a read-only
`BabelQueue\Contracts\InboundMessage`. **Return** to acknowledge the message;
**throw** to fail it (the worker will retry, then dead-letter).

```php
use BabelQueue\Contracts\InboundMessage;
use InitPHP\Queue\Contracts\Handler;

final class SendWelcomeEmail implements Handler
{
    public function handle(InboundMessage $message): void
    {
        $data    = $message->getData();     // ['user_id' => 42, 'email' => '...']
        $traceId = $message->getTraceId();  // correlate with the producer's trace
        $meta    = $message->getMeta();     // ['id' => ..., 'queue' => ..., 'lang' => ...]

        // Do the work. An exception here marks the message as failed.
        mailer()->sendWelcome($data['email']);
    }
}
```

> Delivery is **at-least-once**: a handler may run more than once for the same
> message (after a worker crash, for example), so make it idempotent.

## 2. Choose a transport

```php
use InitPHP\Queue\Transport\Redis\RedisTransport;

$transport = new RedisTransport(new Predis\Client('tcp://127.0.0.1:6379'));
```

See [PDO](transports/pdo.md), [Redis](transports/redis.md) and
[RabbitMQ](transports/amqp.md) for the other options. The PDO transport also needs
its tables — call `$transport->createSchema()` once (or run the DDL yourself).

## 3. Produce messages

```php
use InitPHP\Queue\Producer\Producer;

$producer = new Producer($transport, defaultQueue: 'emails');

$producer->send('urn:babel:users:registered', [
    'user_id' => 42,
    'email'   => 'jane@example.com',
]);
```

`send()` encodes the canonical envelope and publishes it. The return value is the
transport's message id when it exposes one (the DB row id for PDO), or `null`.

## 4. Consume messages

Map URNs to handlers, wrap them in a `Dispatcher`, and run a `Worker`.

```php
use InitPHP\Queue\Consumer\Dispatcher;
use InitPHP\Queue\Consumer\Worker;
use InitPHP\Queue\Consumer\WorkerOptions;
use InitPHP\Queue\Routing\HandlerMap;

$handlers = (new HandlerMap())
    ->register('urn:babel:users:registered', SendWelcomeEmail::class);

$worker = new Worker(
    $transport,
    new Dispatcher($handlers),
    new WorkerOptions(maxAttempts: 3, backoff: [1, 5, 15]),
);

$worker->run('emails');   // blocks, processing messages until stopped
```

## 5. Run it as a process

The bundled CLI loads a **bootstrap file** that returns a configured `Worker`:

```php
// worker.php
require __DIR__ . '/vendor/autoload.php';

use InitPHP\Queue\Consumer\{Dispatcher, Worker, WorkerOptions};
use InitPHP\Queue\Routing\HandlerMap;
use InitPHP\Queue\Transport\Redis\RedisTransport;

$transport = new RedisTransport(new Predis\Client('tcp://127.0.0.1:6379'));
$handlers  = (new HandlerMap())->register('urn:babel:users:registered', SendWelcomeEmail::class);

return new Worker($transport, new Dispatcher($handlers), new WorkerOptions(maxAttempts: 3));
```

```bash
php bin/queue work --bootstrap=worker.php --queue=emails
```

Run it under a process supervisor (systemd, supervisord, a container restart
policy) and set a `maxJobs`/`memoryLimitMb` limit so each worker exits and is
restarted periodically — the standard way to bound memory in long-running PHP.

## Next steps

- [Handlers & routing](handlers-and-routing.md) — closures, container resolution, unknown URNs.
- [The worker & retries](worker-and-retries.md) — every `WorkerOptions` knob.
- [Interoperability](interoperability.md) — consume a message produced by Go/Python.
