# Troubleshooting

Common problems and how to resolve them.

## Messages are published but the worker never processes them

- **Wrong queue name.** The worker consumes the queue you pass to `run($queue)`;
  the producer publishes to its `defaultQueue` (or the `$queue` argument). They
  must match.
- **No handler mapped.** With the default `UnknownUrnStrategy::FAIL`, an unmapped
  URN is retried and eventually dead-lettered — check the dead-letter destination
  and confirm the URN you registered matches the URN you produced exactly.
- **A blocking reserve.** On Redis the worker blocks up to `reserveTimeout`; that
  is normal. Confirm the message is actually on the list (`LLEN <queue>`).

## A message keeps getting retried / lands in the dead-letter queue

- The handler is **throwing**. Inspect the `dead_letter.error` and
  `dead_letter.exception` in the annotated envelope.
- Lower `maxAttempts` for non-transient failures so they stop looping; raise it
  (with a longer `backoff`) for transient ones. See
  [Retries & Dead-Letters](Retries-and-Dead-Letters.md).

## `ConfigurationException` on startup

Raised for mistakes detectable before any message flows:

| Message contains | Cause |
| ---------------- | ----- |
| "empty URN" | `HandlerMap::register('')` or an empty-URN job. |
| "Unknown unknown-URN strategy" | A bad string passed to `Dispatcher`. Use a `UnknownUrnStrategy::*` constant. |
| "is not a class that exists" / "must implement" | A handler class name that is missing or not a `Handler`. |
| "not a valid SQL identifier" | A `PdoTransport` table name with illegal characters. |
| "maxAttempts must be at least 1" / "must be zero or positive" | An out-of-range `WorkerOptions` value. |

## The worker exits immediately

You probably set `stopWhenEmpty: true` (it returns as soon as the queue drains) or
a `maxJobs`/`maxRuntime`/`memoryLimitMb` limit that was already reached. For a
long-running worker, leave `stopWhenEmpty` false and set limits sized for a
supervised restart.

## `SIGINT`/`SIGTERM` does not stop the worker gracefully

Graceful shutdown needs **`ext-pcntl`**. Without it the worker still stops on a
limit or a programmatic `stop()`, but not on a signal. Install/enable `ext-pcntl`,
or rely on a `maxJobs` limit plus supervisor restart.

## Redis: `BLMOVE` errors or "unknown command"

`RedisTransport` requires **Redis 6.2+** (when `BLMOVE` was added). Upgrade the
server, or use the [PDO](Transport-PDO.md) transport.

## RabbitMQ: `composer install` fails on platform requirements

`php-amqplib/php-amqplib` requires **`ext-sockets`** and **`ext-mbstring`**. Enable
them in the PHP that runs Composer and the worker.

## RabbitMQ: retries fire immediately, ignoring `backoff`

This is expected — native delayed delivery needs the
[delayed-message-exchange plugin](https://github.com/rabbitmq/rabbitmq-delayed-message-exchange).
Install it, or use the [PDO](Transport-PDO.md)/[Redis](Transport-Redis.md) transport for
native delays. See [Transport: RabbitMQ](Transport-RabbitMQ.md#delay-caveat).

## RabbitMQ: `guest` user is refused

By default RabbitMQ's `guest` account can only connect from `localhost`. From a
container or remote host, create a dedicated user (e.g. via `RABBITMQ_DEFAULT_USER`
/ `RABBITMQ_DEFAULT_PASS`) and connect with that instead.

## PDO: two workers seem to grab the same job

They cannot — reservation is an atomic optimistic claim (a conditional `UPDATE`
whose affected-row count confirms the claim). If you see duplicate **processing**,
the cause is at-least-once redelivery (a reservation that lapsed after
`retryAfter`), which is expected — make handlers idempotent. Raise `retryAfter`
above your slowest handler's runtime if jobs are being reclaimed mid-flight.

## A message keeps being released with reason `in_flight`

With deduplication on, a redelivery that finds its key claimed by a handler that
has not finished is put back on the queue (`job.released`, reason `in_flight`)
and re-checked after `inFlightDelaySeconds`; it costs no retry attempt. It stops
when the other handler records the key (the message is dropped as a duplicate),
releases its claim after a failure, or when the claim's lease lapses. If it goes on
for much longer than `leaseSeconds`, the claim never lapses: the store holds it
without an expiry (`leaseSeconds: 0`) or does not honour the lease, so call
`forget()` on the key. A store that implements only `IdempotencyStore` never
releases a message this way; it discards it. See
[Idempotent Consumption](Idempotent-Consumption.md#what-deduplication-does-not-guarantee).

## A message produced by another language is rejected

- Check `meta.schema_version` — a version newer than this SDK understands is
  quarantined (`unsupported_schema_version`), by design.
- The runtime accepts both `job` and the `urn` alias; other fields must still form
  a valid envelope (`data` an object, an integer `attempts`, a non-empty
  `trace_id`). See [The Message Envelope](The-Message-Envelope.md#validation-consume-side).

## Still stuck?

Open an issue at <https://github.com/InitPHP/Queue/issues> with the failing
envelope (redact secrets), the transport, and the worker options you used.
