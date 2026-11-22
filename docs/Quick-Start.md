# Quick Start

This walkthrough takes you from nothing to a producer pushing messages and a
worker consuming them. It uses the [PDO transport](Transport-PDO.md) so you need no
broker — just a database (SQLite here).

## 1. Write a handler

A handler implements `InitPHP\Queue\Contracts\Handler` and is mapped to a message
**URN**. It receives a read-only `BabelQueue\Contracts\InboundMessage`. **Return**
to acknowledge; **throw** to fail (the worker retries, then dead-letters).

```php
use BabelQueue\Contracts\InboundMessage;
use InitPHP\Queue\Contracts\Handler;

final class SendWelcomeEmail implements Handler
{
    public function handle(InboundMessage $message): void
    {
        $data    = $message->getData();      // ['user_id' => 42, 'email' => '...']
        $traceId = $message->getTraceId();   // correlate with the producer
        $meta    = $message->getMeta();      // ['id' => ..., 'queue' => ..., 'lang' => ...]

        // Do the work. An exception here marks the message as failed.
        // mailer()->sendWelcome($data['email']);
    }
}
```

> **Idempotency.** Delivery is at-least-once: a handler may run more than once for
> the same message (after a crash, for example). Make it safe to repeat.

## 2. Build a transport

```php
use InitPHP\Queue\Transport\Pdo\PdoTransport;

$pdo = new PDO('sqlite:' . __DIR__ . '/queue.sqlite');
$transport = new PdoTransport($pdo, table: 'jobs');
$transport->createSchema();   // creates the jobs / jobs_failed tables (dev convenience)
```

## 3. Produce a message

```php
use InitPHP\Queue\Producer\Producer;

$producer = new Producer($transport, defaultQueue: 'emails');

$producer->send('urn:babel:users:registered', [
    'user_id' => 42,
    'email'   => 'jane@example.com',
]);
```

`send()` encodes the canonical envelope and publishes it. A consumer in **any**
language subscribed to the `emails` queue can read it.

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

$worker->run('emails');   // loops, processing messages until stopped
```

To process the queue once and exit (handy in tests or cron):

```php
$worker->runOnce('emails');   // returns bool: was a message processed?
```

## 5. Run it as a process

Put the wiring in a **bootstrap file** that returns a configured `Worker`, and run
it with the CLI:

```php
// worker.php
require __DIR__ . '/vendor/autoload.php';

use InitPHP\Queue\Consumer\{Dispatcher, Worker, WorkerOptions};
use InitPHP\Queue\Routing\HandlerMap;
use InitPHP\Queue\Transport\Pdo\PdoTransport;

$transport = new PdoTransport(new PDO('sqlite:' . __DIR__ . '/queue.sqlite'), 'jobs');
$handlers  = (new HandlerMap())->register('urn:babel:users:registered', SendWelcomeEmail::class);

return new Worker($transport, new Dispatcher($handlers), new WorkerOptions(maxAttempts: 3));
```

```bash
php vendor/bin/queue work --bootstrap=worker.php --queue=emails
# or process exactly one message and exit:
php vendor/bin/queue work --bootstrap=worker.php --queue=emails --once
```

Run the worker under a process supervisor (systemd, supervisord, a container
restart policy) and set a `maxJobs`/`memoryLimitMb` limit so each worker exits and
is restarted periodically.

## What next?

- [Core Concepts](Core-Concepts.md) — the three moving parts and how they interact.
- [Handlers & Routing](Handlers-and-Routing.md) — closures, container resolution, unknown URNs.
- [The Worker](The-Worker.md) — every tuning knob.
- [Transports](Transports.md) — switch from PDO to Redis or RabbitMQ.
