# Dead-letter handling

A message is **dead-lettered** when it cannot be processed and should be set aside
for inspection rather than retried forever or silently lost.

## When it happens

| Cause | Trigger |
| ----- | ------- |
| Exhausted retries | A handler kept throwing until `attempts` reached `WorkerOptions::$maxAttempts`. Reason: `failed`. |
| Poison / malformed | The envelope failed consumer-side validation (missing URN, non-object `data`, bad `attempts`). Reason: one of the `EnvelopeValidator::REASON_*` values. |
| Unsupported schema | `meta.schema_version` is newer than this SDK understands — quarantined, **never dropped**, so a newer producer's messages are not lost. Reason: `unsupported_schema_version`. |
| Unknown URN | No handler is mapped and the strategy is `DEAD_LETTER` (or `FAIL` after retries). Reason: `unknown_urn`. |

## The `dead_letter` block

Before moving the message, the worker annotates the original envelope with an
additive `dead_letter` block (via the SDK's `BabelQueue\DeadLetter\DeadLetter`).
The original `trace_id`, `meta.id` and `data` are preserved verbatim:

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

Because the block is additive, the envelope stays at `schema_version` 1 and any
consumer that does not care about it simply ignores it.

## Where it goes

Each transport has its own dead-letter destination, derived from the queue name:

| Transport | Dead-letter destination |
| --------- | ----------------------- |
| PDO | The `*_failed` table (e.g. `jobs_failed`), one row per dead-lettered message with `reason`, `urn`, `attempts` and the full annotated `payload`. |
| Redis | A `<queue>:failed` list (e.g. `orders:failed`). |
| RabbitMQ | A durable `<queue>.failed` queue (e.g. `orders.failed`). |

## Inspecting and replaying

**PDO** — query the failed table directly:

```sql
SELECT id, urn, attempts, reason, failed_at, payload
FROM jobs_failed
ORDER BY id DESC;
```

To replay, decode the stored `payload`, strip the `dead_letter` block and reset
`attempts` to 0, then publish it again:

```php
use BabelQueue\Codec\EnvelopeCodec;

$envelope = EnvelopeCodec::decode($payloadFromFailedRow);
unset($envelope['dead_letter']);
$envelope['attempts'] = 0;

$transport->publish(EnvelopeCodec::encode($envelope), $envelope['meta']['queue']);
```

**Redis** — read with `LRANGE orders:failed 0 -1`; replay by `RPUSH`-ing a cleaned
envelope back onto the main list.

**RabbitMQ** — consume the `orders.failed` queue (the same `AmqpTransport`,
pointed at the failed queue name) and republish.

## Tuning what gets dead-lettered

- Raise `maxAttempts` (and lengthen `backoff`) for transient failures like a flaky
  downstream service.
- Keep `maxAttempts` low for failures that will never succeed on retry (bad input),
  so they reach the dead-letter queue quickly.
- Use `UnknownUrnStrategy::DEAD_LETTER` if you want unmapped messages quarantined
  for review rather than retried.

See [The worker & retries](worker-and-retries.md) for the retry policy itself.
