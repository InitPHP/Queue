# The Worker

The `InitPHP\Queue\Consumer\Worker` is the consume loop. It reserves a message
from a transport, asks the `Dispatcher` what to do with it, and performs the
resulting broker action — acknowledge, retry with back-off, or dead-letter.

## Constructing a worker

```php
use InitPHP\Queue\Consumer\Dispatcher;
use InitPHP\Queue\Consumer\Worker;
use InitPHP\Queue\Consumer\WorkerOptions;

$worker = new Worker(
    $transport,                        // a ConsumerTransport (PDO / Redis / AMQP)
    new Dispatcher($handlers),         // routing
    new WorkerOptions(maxAttempts: 3), // tuning (optional; defaults shown below)
    $logger,                           // optional callable for observability
);
```

## Running it

```php
$worker->run('emails');        // loop until a stop signal or a configured limit
$worker->runOnce('emails');    // process at most one message; returns bool
$worker->stop();               // request a graceful stop (e.g. from a handler)
$worker->processedCount();     // messages processed so far (int)
```

- **`run()`** is resilient: a transport error on a single iteration is logged and
  the loop continues after a short sleep, rather than crashing the worker.
- **`runOnce()`** does **not** swallow errors — it is the strict entry point for
  tests and one-shot draining. It returns `true` if a message was processed,
  `false` if the queue was empty.

## `WorkerOptions`

Every knob, with its default:

```php
new WorkerOptions(
    maxAttempts: 3,        // total delivery attempts before dead-lettering (>= 1)
    backoff: [0],          // per-attempt delay in seconds; the last value repeats
    reserveTimeout: 5.0,   // seconds a blocking transport waits for a message
    sleepWhenEmpty: 0.5,   // seconds to sleep when the queue is empty
    maxJobs: 0,            // stop after N messages (0 = unlimited)
    maxRuntime: 0.0,       // stop after N seconds (0 = unlimited)
    memoryLimitMb: 0,      // stop once memory reaches N MB (0 = unlimited)
    stopWhenEmpty: false,  // stop as soon as the queue drains (drain/batch mode)
);
```

`WorkerOptions` is immutable and validates its input: `maxAttempts < 1`, a
negative back-off, or a negative limit throws `ConfigurationException`.

### Reservation and idling

- **Blocking transports** (Redis `BLMOVE`) wait up to `reserveTimeout` for a
  message before returning.
- **Polling transports** (PDO, RabbitMQ `basic_get`) return immediately when the
  queue is empty; the worker then sleeps for `sleepWhenEmpty`. Tune that for your
  latency/load trade-off.

### Stopping cleanly

Set one or more limits and run each worker under a supervisor that restarts it:

```php
new WorkerOptions(maxJobs: 1000, memoryLimitMb: 128);
```

This is the standard pattern for long-running PHP — the process exits on its own
terms and a fresh one starts with clean memory. `stopWhenEmpty: true` instead
drains the queue and returns (useful for batch jobs and tests).

### Graceful shutdown

On platforms with `ext-pcntl` the worker installs `SIGINT`/`SIGTERM` handlers and
stops **after finishing the in-flight message**, so a deploy or `Ctrl-C` never
tears a job in half. Without `ext-pcntl` the loop still stops on a limit or
`stop()`, just not on a signal.

## Retries and back-off

When a handler throws, the worker increments the envelope's `attempts` and
re-queues it with a delay from `backoff`:

- `delayForAttempt(n)` uses `backoff[min(n - 1, last index)]`.
- `[0]` retries immediately; `[1, 5, 15]` waits 1s, then 5s, then 15s, then 15s for
  every further attempt.

Once `attempts` reaches `maxAttempts`, the message is annotated and dead-lettered.
With `maxAttempts: 3, backoff: [1, 5, 15]`:

| Delivery | `attempts` seen | On failure |
| -------- | --------------- | ---------- |
| 1st | 0 | re-queued with `attempts` 1, after 1s |
| 2nd | 1 | re-queued with `attempts` 2, after 5s |
| 3rd | 2 | dead-lettered with `attempts` 3 |

> Back-off delays are honoured natively by the PDO and Redis transports. RabbitMQ
> applies retries immediately unless the delayed-message-exchange plugin is
> configured — see [Transport: RabbitMQ](Transport-RabbitMQ.md).

Full details on [Retries & Dead-Letters](Retries-and-Dead-Letters.md).

## Observability

Pass a callable as the fourth constructor argument to receive lifecycle events,
invoked as `($level, $event, $context)`:

```php
$worker = new Worker($transport, new Dispatcher($handlers), new WorkerOptions(), function (
    string $level,    // 'info' | 'notice' | 'warning' | 'error'
    string $event,    // see below
    array  $context,  // ['queue' => ..., 'urn' => ..., 'trace_id' => ..., ...]
): void {
    $myPsrLogger->log($level, $event, $context);
});
```

| Event | When |
| ----- | ---- |
| `job.ack` | A handler succeeded. |
| `job.retry` | A failure was re-queued (context has `attempt`, `delay`). |
| `job.dead_letter` | A message was quarantined (context has `reason`, `attempts`, `error`). |
| `job.dropped` | The message was acked-and-discarded: an unknown URN (`DELETE` strategy) or, with deduplication on, a duplicate whose handler already finished (`reason` `duplicate`). |
| `job.released` | A message was put back without counting an attempt: an unknown URN (`RELEASE` strategy), or, with deduplication on, a key another handler still holds (`reason` `in_flight`, context has `delay`). |
| `reserve.failed` | The transport threw while reserving (loop continues). |
| `process.failed` | The transport threw while acting on a message (loop continues). |

## The CLI

`vendor/bin/queue work` loads a **bootstrap file** that returns a configured
`Worker`:

```bash
php vendor/bin/queue work --bootstrap=worker.php --queue=emails        # loop
php vendor/bin/queue work --bootstrap=worker.php --queue=emails --once # one message
```

| Option | Meaning |
| ------ | ------- |
| `--bootstrap` | A PHP file that wires and `return`s a `Worker`. Required. |
| `--queue` | The queue to consume (default: `default`). |
| `--once` | Process at most one message, then exit. |

The bootstrap keeps credentials and the handler map in your application code, not
on the command line:

```php
// worker.php
require __DIR__ . '/vendor/autoload.php';

use InitPHP\Queue\Consumer\{Dispatcher, Worker, WorkerOptions};
use InitPHP\Queue\Routing\HandlerMap;
use InitPHP\Queue\Transport\Redis\RedisTransport;

$transport = new RedisTransport(new Predis\Client('tcp://127.0.0.1:6379'));
$handlers  = (new HandlerMap())->register('urn:babel:users:registered', SendWelcomeEmail::class);

return new Worker($transport, new Dispatcher($handlers), new WorkerOptions(maxAttempts: 3, maxJobs: 1000));
```

### Running under systemd (example)

```ini
[Service]
ExecStart=/usr/bin/php /srv/app/vendor/bin/queue work --bootstrap=/srv/app/worker.php --queue=emails
Restart=always
# The worker exits after maxJobs; systemd restarts it with clean memory.
```
