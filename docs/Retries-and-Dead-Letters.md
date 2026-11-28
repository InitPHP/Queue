# Retries & Dead-Letters

This page explains exactly when a message is retried, when it is dead-lettered,
and how to inspect or replay the failures.

## The retry policy

When a handler throws, the worker treats the message as failed:

1. It increments the envelope's `attempts`.
2. If `attempts` is still **below** `WorkerOptions::$maxAttempts`, it **re-queues**
   the message with the new `attempts` and a back-off delay.
3. Once `attempts` **reaches** `maxAttempts`, it **dead-letters** the message.

The delay between attempts comes from `WorkerOptions::$backoff`
(`delayForAttempt(n)` uses `backoff[min(n - 1, last index)]`):

| `backoff` | Delay after attempt 1 / 2 / 3 / 4 … |
| --------- | ----------------------------------- |
| `[0]` *(default)* | 0 / 0 / 0 / 0 — immediate retries |
| `[1, 5, 15]` | 1s / 5s / 15s / 15s — last value repeats |
| `[2]` | 2s / 2s / 2s — fixed delay |

> Delays are honoured by the PDO transport (`available_at`) and the Redis transport
> (a delayed sorted set). RabbitMQ retries immediately unless you install the
> delayed-message-exchange plugin — see [Transport: RabbitMQ](Transport-RabbitMQ.md).

## A handler that kills its process

A handler that is killed (out of memory, `exit`, `SIGKILL`) never throws, so the
worker never learns that it failed. The transport does: the message comes back
because its reservation lapsed, or the broker or your reaper returned it. A
delivery that ended without an ack, a release or a dead-letter counts as **one
attempt**, recorded by the transport before it hands the message out again. If that
brings `attempts` to `maxAttempts`, the worker dead-letters the message with reason
`failed` and does not run the handler again.

| Transport | How the unfinished delivery is found | Counted |
| --------- | ------------------------------------ | ------- |
| PDO | a row that is still stamped `reserved_at` when its reservation lapses (`retryAfter`) | every one, in the same `UPDATE` that takes the row |
| Redis | the body is back on the queue while its `<queue>:started` entry is still there | every one, in the body and in the processing entry |
| RabbitMQ | the broker's `redelivered` flag | **one**: classic queues keep no delivery count and the unacked body cannot be rewritten, so a handler that kills its process on every delivery is counted once, not once per crash |

With [deduplication](Idempotent-Consumption.md) on, the idempotency record is read
before the message is dead-lettered, so a message is not failed for a handler that
did finish. If the key is recorded as processed (the handler finished and only its
ack was lost) the message is acknowledged and discarded as a `duplicate`; if the key
is still claimed (another handler is running, or its lease has not lapsed) the
message is put back as `in_flight`; only a message with neither is dead-lettered.
A store that cannot tell a recorded key from a claimed one discards in both cases.

A message put back without an outcome of its own, such as one waiting for another
handler's [in-flight key](Idempotent-Consumption.md), has its reservation cleared by
`release()` and is not counted, however many times it waits. A normal failure is not
counted twice either: the unfinished delivery and the failure that follows it are two
different deliveries.

A handler that outlives `retryAfter` (PDO) or the delay of your Redis reaper while
its worker is alive looks like a dead one: the second delivery is counted. Keep both
above your slowest handler.

## When a message is dead-lettered

| Cause | Reason in the `dead_letter` block |
| ----- | --------------------------------- |
| Exhausted retries — a handler kept throwing until `attempts` reached `maxAttempts` | `failed` |
| Exhausted deliveries — a handler's process died until `attempts` reached `maxAttempts` (see [above](#a-handler-that-kills-its-process)) | `failed` |
| Malformed / poison envelope (missing URN, non-object `data`, bad `attempts`) | an `EnvelopeValidator::REASON_*` value |
| Unsupported `meta.schema_version` (a newer producer) — **quarantined, never dropped** | `unsupported_schema_version` |
| Unknown URN with the `DEAD_LETTER` strategy (or `FAIL` after retries) | `unknown_urn` |

## The `dead_letter` annotation

Before moving the message, the worker annotates the original envelope (via the
SDK's `BabelQueue\DeadLetter\DeadLetter`). The original `trace_id`, `meta.id` and
`data` are preserved verbatim:

```json
{
  "job": "urn:babel:orders:created",
  "trace_id": "7b3f9c2a-...",
  "data": { "order_id": 1042 },
  "meta": { "id": "f1e2...", "queue": "orders", "lang": "php", "schema_version": 1, "created_at": 1749132727000 },
  "attempts": 3,
  "dead_letter": {
    "reason": "failed",
    "error": "Payment gateway timeout",
    "exception": "App\\Exceptions\\GatewayTimeout",
    "failed_at": 1749132730000,
    "original_queue": "orders",
    "attempts": 3,
    "lang": "php"
  }
}
```

## Where dead-lettered messages go

Each transport has its own destination, derived from the queue name:

| Transport | Destination |
| --------- | ----------- |
| PDO | The `*_failed` table (e.g. `jobs_failed`) — one row per message with `reason`, `urn`, `attempts` and the full annotated `payload`. |
| Redis | A `<queue>:failed` list (e.g. `orders:failed`). |
| RabbitMQ | A durable `<queue>.failed` queue (e.g. `orders.failed`). |

## Inspecting failures

**PDO** — query the failed table:

```sql
SELECT id, urn, attempts, reason, failed_at, payload
FROM jobs_failed
ORDER BY id DESC;
```

**Redis** — read the list:

```bash
LRANGE orders:failed 0 -1
```

**RabbitMQ** — consume `orders.failed` with the same `AmqpTransport` pointed at
that queue name.

## Replaying a dead-lettered message

Decode the stored envelope, strip the `dead_letter` block, reset `attempts`, and
publish it again:

```php
use BabelQueue\Codec\EnvelopeCodec;

$envelope = EnvelopeCodec::decode($storedPayload);
unset($envelope['dead_letter']);
$envelope['attempts'] = 0;

$transport->publish(EnvelopeCodec::encode($envelope), $envelope['meta']['queue']);
```

For PDO you would read `payload` from a `jobs_failed` row; for Redis, `LPOP`/`LRANGE`
from `orders:failed`; for RabbitMQ, consume `orders.failed`.

## Tuning

- Raise `maxAttempts` (and lengthen `backoff`) for **transient** failures like a
  flaky downstream service.
- Keep `maxAttempts` low for failures that will **never** succeed on retry (bad
  input), so they reach the dead-letter queue quickly instead of looping.
- Use `UnknownUrnStrategy::DEAD_LETTER` to quarantine unmapped messages for review
  rather than retrying them. See [Handlers & Routing](Handlers-and-Routing.md#unknown-urn-strategies).
- Make handlers **idempotent** — at-least-once delivery means a handler can run
  again for a message that actually succeeded but whose ack was lost. The
  dispatcher's optional deduplication is not a replacement: it can still run a
  handler twice — see
  [Idempotent Consumption](Idempotent-Consumption.md#what-deduplication-does-not-guarantee).
