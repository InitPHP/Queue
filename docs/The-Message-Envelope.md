# The Message Envelope

InitPHP Queue does not invent its own message format — it produces and consumes
the **canonical BabelQueue envelope** defined by `babelqueue/php-sdk`. That shared
format is what lets a message be read by a consumer in any language.

## The shape

Every message on the wire is this JSON (`schema_version` 1):

```json
{
  "job": "urn:babel:users:registered",
  "trace_id": "7b3f9c2a-e41d-4f88-9b2a-1c0d5e6f7a8b",
  "data": { "user_id": 42 },
  "meta": {
    "id": "f1e2d3c4-b5a6-4789-90ab-cdef01234567",
    "queue": "emails",
    "lang": "php",
    "schema_version": 1,
    "created_at": 1749132727000
  },
  "attempts": 0
}
```

| Field | Meaning |
| ----- | ------- |
| `job` | The message **URN** — its language-independent identity. Consumers also accept the inbound alias `urn`. |
| `trace_id` | A cross-service correlation id, preserved unchanged across every hop and language. |
| `data` | The business payload. Pure JSON only — no PHP objects, closures or resources. |
| `meta.id` | A unique message id (UUID v4). |
| `meta.queue` | The logical queue name. |
| `meta.lang` | The producing language (`php` here; `go`, `python`, … from other SDKs). |
| `meta.schema_version` | Frozen at 1; a consumer refuses versions it does not understand. |
| `attempts` | A transport-level retry counter, incremented by the worker on each failure. |

You rarely build this by hand — `Producer` and `EnvelopeCodec` do it. The codec is
the SDK's `BabelQueue\Codec\EnvelopeCodec`:

```php
use BabelQueue\Codec\EnvelopeCodec;

$envelope = EnvelopeCodec::make('urn:babel:users:registered', ['user_id' => 42], 'emails');
$json     = EnvelopeCodec::encode($envelope);   // UTF-8 JSON string
$back     = EnvelopeCodec::decode($json);        // array, or [] if malformed
$urn      = EnvelopeCodec::urn($back);           // 'urn:babel:users:registered'
$ok       = EnvelopeCodec::accepts($back);       // bool: passes consumer validation?
```

## Reading a consumed message

Inside a handler you receive a read-only `BabelQueue\Contracts\InboundMessage`:

```php
$message->getUrn();      // 'urn:babel:users:registered'
$message->getTraceId();  // correlation id, or '' if absent
$message->getData();     // the 'data' block as an array
$message->getMeta();     // the 'meta' block as an array
```

The worker's own `ReceivedMessage` (which implements `InboundMessage`) adds
`queue()`, `rawBody()`, `envelope()`, `attempts()` and `receipt()` — but a handler
only ever sees the read-only view above.

## URNs: naming your messages

A URN is a stable, application-controlled string identifying *what a message is*.
Because it is never a PHP class name, the producing class can be renamed, moved or
refactored without breaking any consumer, and a consumer in another language can
route on it without sharing a type.

Recommended (not enforced) convention:

```
urn:babel:<bounded-context>:<event-or-command>
```

Examples:

```
urn:babel:orders:created
urn:babel:orders:invoice.requested
urn:babel:users:registered
urn:babel:catalog:item.indexed
```

Guidelines:

- **Keep it stable.** A URN is a contract; treat a change like an API break.
- **One URN per message type.** Routing maps a URN to exactly one handler.
- **Lowercase, dot-separated** for the event part; descriptive over short.

## Validation (consume side)

Before dispatching, the worker validates every envelope through
`BabelQueue\Validation\EnvelopeValidator`. `check()` returns `null` when the
envelope is acceptable, or a machine-readable reason otherwise:

| Reason constant | Meaning |
| --------------- | ------- |
| `REASON_MISSING_URN` | No `job`/`urn` — the message has no identity. |
| `REASON_MISSING_META` | No `meta` block. |
| `REASON_UNSUPPORTED_SCHEMA_VERSION` | `meta.schema_version` is not understood. |
| `REASON_INVALID_DATA` | `data` is not an object. |
| `REASON_MISSING_TRACE_ID` | No `trace_id`. |
| `REASON_INVALID_ATTEMPTS` | `attempts` is not an integer. |

A message that fails validation is **quarantined** (dead-lettered), never silently
dropped — so a newer producer's messages are never lost. See
[Retries & Dead-Letters](Retries-and-Dead-Letters.md).

## The `dead_letter` block

When a message is dead-lettered, the worker adds an **additive** `dead_letter`
block via `BabelQueue\DeadLetter\DeadLetter::annotate()`, preserving the original
`trace_id`, `meta.id` and `data`:

```json
"dead_letter": {
  "reason": "failed",
  "error": "Payment gateway timeout",
  "exception": "App\\Exceptions\\GatewayTimeout",
  "failed_at": 1749132730000,
  "original_queue": "orders",
  "attempts": 3,
  "lang": "php"
}
```

Because it is additive, the envelope stays at `schema_version` 1 and consumers of
normal queues ignore it.
