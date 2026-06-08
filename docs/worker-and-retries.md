# The worker & retries

The `Worker` is the consume loop. It reserves a message from a transport, asks the
`Dispatcher` what to do with it, and performs the resulting broker action —
acknowledge, retry with back-off, or dead-letter.

## Constructing a worker

```php
use InitPHP\Queue\Consumer\Dispatcher;
use InitPHP\Queue\Consumer\Worker;
use InitPHP\Queue\Consumer\WorkerOptions;

$worker = new Worker(
    $transport,                       // a ConsumerTransport (PDO / Redis / AMQP)
    new Dispatcher($handlers),        // routing
    new WorkerOptions(maxAttempts: 3),// tuning (optional)
    $logger,                          // optional callable (see below)
);
```

## Running it

```php
$worker->run('emails');        // loop until a stop signal or a configured limit
$worker->runOnce('emails');    // process at most one message; returns bool
$worker->stop();               // request a graceful stop (e.g. from a handler)
$worker->processedCount();     // messages processed so far
```

`run()` is resilient: a transport error on a single iteration is logged and the
loop continues after a short sleep, instead of crashing the worker. `runOnce()`
does **not** swallow errors — it is the strict entry point for tests and one-shot
draining.

## `WorkerOptions`

Every knob, with its default:

```php
new WorkerOptions(
    maxAttempts: 3,        // total delivery attempts before dead-lettering (>= 1)
    backoff: [0],          // per-attempt delay in seconds; last value repeats
    reserveTimeout: 5.0,   // seconds a blocking transport waits for a message
    sleepWhenEmpty: 0.5,   // seconds to sleep when the queue is empty
    maxJobs: 0,            // stop after N messages (0 = unlimited)
    maxRuntime: 0.0,       // stop after N seconds (0 = unlimited)
    memoryLimitMb: 0,      // stop once memory reaches N MB (0 = unlimited)
    stopWhenEmpty: false,  // stop as soon as the queue drains (drain/batch mode)
);
```

### Retries and back-off

When a handler throws, the worker increments the envelope's `attempts` and
re-queues it with a delay from `backoff`:

- `delayForAttempt(n)` uses `backoff[min(n - 1, last index)]`.
- `[0]` retries immediately; `[1, 5, 15]` waits 1s, then 5s, then 15s, then 15s for
  every further attempt.

Once `attempts` reaches `maxAttempts`, the message is annotated with a
`dead_letter` block and moved to the dead-letter destination. With
`maxAttempts: 3` and `backoff: [1, 5, 15]`:

| Delivery | On failure |
| -------- | ---------- |
| 1st (`attempts` 0) | re-queued with `attempts` 1, after 1s |
| 2nd (`attempts` 1) | re-queued with `attempts` 2, after 5s |
| 3rd (`attempts` 2) | dead-lettered with `attempts` 3 |

> Back-off delays are honoured by the PDO and Redis transports. RabbitMQ applies
> retries immediately unless you configure the delayed-message exchange plugin —
> see [the AMQP transport guide](transports/amqp.md).

### Stopping cleanly

Set one or more limits and run each worker under a supervisor that restarts it:

```php
new WorkerOptions(maxJobs: 1000, memoryLimitMb: 128);
```

This is the standard pattern for long-running PHP — the process exits on its own
terms and a fresh one starts with clean memory.

On platforms with `ext-pcntl` the worker installs `SIGINT`/`SIGTERM` handlers and
stops **after finishing the in-flight message**, so a deploy or `Ctrl-C` never
tears a job in half.

## Observability

Pass a callable as the fourth constructor argument to receive lifecycle events.
It is invoked as `($level, $event, $context)`:

```php
$worker = new Worker($transport, new Dispatcher($handlers), new WorkerOptions(), function (
    string $level,          // 'info' | 'notice' | 'warning' | 'error'
    string $event,          // 'job.ack' | 'job.retry' | 'job.dead_letter' | ...
    array  $context,        // ['queue' => ..., 'urn' => ..., 'trace_id' => ..., ...]
): void {
    $myPsrLogger->log($level, $event, $context);
});
```

Events: `job.ack`, `job.retry`, `job.dead_letter`, `job.dropped`, `job.released`,
`reserve.failed`, `process.failed`.

## The CLI

`bin/queue work` loads a bootstrap file that returns a configured `Worker`:

```bash
php bin/queue work --bootstrap=worker.php --queue=emails        # loop
php bin/queue work --bootstrap=worker.php --queue=emails --once # one message
```

| Option | Meaning |
| ------ | ------- |
| `--bootstrap` | A PHP file that wires and `return`s a `Worker`. Required. |
| `--queue` | The queue to consume (default: `default`). |
| `--once` | Process at most one message, then exit. |

The bootstrap pattern keeps credentials and the handler map in your application
code, not on the command line. See [Getting started](getting-started.md#5-run-it-as-a-process)
for a complete `worker.php`.
