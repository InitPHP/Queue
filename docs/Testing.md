# Testing

Because the runtime is built from small, injected parts, you can test handlers and
workers without standing up a real broker.

## Test a handler directly

A handler is a plain object — call `handle()` with a `ReceivedMessage` you build
from an envelope:

```php
use BabelQueue\Codec\EnvelopeCodec;
use InitPHP\Queue\Message\ReceivedMessage;

$envelope = EnvelopeCodec::make('urn:babel:users:registered', ['user_id' => 42], 'emails');
$message  = new ReceivedMessage('emails', EnvelopeCodec::encode($envelope), $envelope, receipt: 1);

$handler = new SendWelcomeEmail();
$handler->handle($message);   // assert the side effects
```

`ReceivedMessage` implements `BabelQueue\Contracts\InboundMessage`, so it is
exactly what the handler sees in production.

## Test routing and outcomes with the `Dispatcher`

The dispatcher returns an `Outcome` you can assert on, with no broker involved:

```php
use InitPHP\Queue\Consumer\Dispatcher;
use InitPHP\Queue\Consumer\OutcomeKind;
use InitPHP\Queue\Routing\HandlerMap;

$handlers = (new HandlerMap())->register('urn:babel:users:registered', new SendWelcomeEmail());
$outcome  = (new Dispatcher($handlers))->dispatch($message);

self::assertSame(OutcomeKind::Ack, $outcome->kind);
```

`OutcomeKind` is `Ack`, `Retry`, `DeadLetter`, `Drop` or `Release`; the `Outcome`
also exposes `->reason` and `->exception`.

## Test the worker with an in-memory transport

Drive a real `Worker` against a fake `ConsumerTransport`. A minimal in-memory
implementation is enough — it queues payloads, hands them out on `reserve()`, and
records `deadLetter()`/`release()` for assertions:

```php
use InitPHP\Queue\Consumer\Dispatcher;
use InitPHP\Queue\Consumer\Worker;
use InitPHP\Queue\Consumer\WorkerOptions;

$worker = new Worker($transport, new Dispatcher($handlers), new WorkerOptions(stopWhenEmpty: true));

$transport->publish(EnvelopeCodec::encode(EnvelopeCodec::make('urn:babel:users:registered', [], 'emails')), 'emails');

$worker->run('emails');               // drains and returns (stopWhenEmpty)
self::assertSame(1, $worker->processedCount());
```

Two options make worker tests deterministic:

- **`stopWhenEmpty: true`** — `run()` returns the moment the queue is empty, so a
  test never blocks.
- **`runOnce($queue)`** — process exactly one message and return; ideal for
  step-by-step assertions of the retry → dead-letter progression.

```php
// maxAttempts: 2 — fail twice, then dead-letter:
$worker->runOnce('orders');   // attempt 1 -> re-queued
$worker->runOnce('orders');   // attempt 2 -> dead-lettered
```

## Asserting retries and dead-letters

Because the worker re-queues a failed message with an incremented `attempts`, a
fake transport that records `release()`/`deadLetter()` payloads lets you assert the
whole policy: the delays passed to `release()`, the final `attempts`, and the
`dead_letter.reason` in the annotated envelope.

## Integration tests against a real broker

The package's own integration tests connect to real Redis, RabbitMQ and MySQL and
are **skipped unless** the matching environment variables are set, so they are a
no-op locally and run in CI with service containers:

| Variable | Example |
| -------- | ------- |
| `QUEUE_TEST_REDIS_DSN` | `tcp://127.0.0.1:6379` |
| `QUEUE_TEST_AMQP_HOST` (+ `_PORT`/`_USER`/`_PASS`) | `127.0.0.1` |
| `QUEUE_TEST_MYSQL_DSN` (+ `_USER`/`_PASS`) | `mysql:host=127.0.0.1;port=3306;dbname=queue_test` |

Adopt the same pattern in your own suite when you want to exercise a real broker:
gate the test on an env var and skip otherwise.
