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

## When a message is dead-lettered

| Cause | Reason in the `dead_letter` block |
| ----- | --------------------------------- |
| Exhausted retries — a handler kept throwing until `attempts` reached `maxAttempts` | `failed` |
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
  again for a message that actually succeeded but whose ack was lost.
