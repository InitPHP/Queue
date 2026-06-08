# Envelope & URNs

InitPHP Queue does not invent its own message format — it produces and consumes
the **canonical BabelQueue envelope** defined by `babelqueue/php-sdk`. That is
what makes a message readable by a consumer written in any language.

## The envelope

Every message on the wire is this JSON shape (`schema_version` 1):

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
| `meta.id` | A unique message id. |
| `meta.queue` | The logical queue name. |
| `meta.lang` | The producer's language (`php` here). |
| `meta.schema_version` | Frozen at 1; a consumer refuses versions it does not understand. |
| `attempts` | A transport-level retry counter, incremented by the worker on each failure. |

You rarely build this by hand — the `Producer` and `EnvelopeCodec` do it for you.
The codec is the SDK's `BabelQueue\Codec\EnvelopeCodec`:

```php
use BabelQueue\Codec\EnvelopeCodec;

$envelope = EnvelopeCodec::make('urn:babel:users:registered', ['user_id' => 42], 'emails');
$json     = EnvelopeCodec::encode($envelope);   // UTF-8 JSON string
$back     = EnvelopeCodec::decode($json);        // array, or [] if malformed
$urn      = EnvelopeCodec::urn($back);           // 'urn:babel:users:registered'
```

## URNs: how to name messages

A URN is a stable, application-controlled string that identifies *what a message
is*. Because it is never a PHP class name, the producing class can be renamed,
moved or refactored without breaking any consumer, and a consumer in another
language can route on it without sharing any type.

The recommended (not enforced) convention is:

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

Rules of thumb:

- **Keep it stable.** A URN is a contract. Once consumers depend on it, treat a
  change like an API break.
- **One URN per message type.** Routing maps a URN to exactly one handler.
- **Make it descriptive, lowercase, dot-separated** for the event part.

## Producing from a job object

Instead of a raw URN + array, you can implement `BabelQueue\Contracts\PolyglotJob`
and let the producer read the URN and payload from it:

```php
use BabelQueue\Contracts\PolyglotJob;

final class UserRegistered implements PolyglotJob
{
    public function __construct(private readonly int $userId) {}

    public function getBabelUrn(): string
    {
        return 'urn:babel:users:registered';
    }

    public function toPayload(): array
    {
        return ['user_id' => $this->userId];
    }
}

$producer->dispatch(new UserRegistered(42), 'emails');
```

To continue an existing distributed trace (e.g. a downstream job dispatched while
handling another message), additionally implement `BabelQueue\Contracts\HasTraceId`
and return the inherited `trace_id` from `getBabelTraceId()`; the codec reuses it
instead of minting a new one.

## Validation

On the consume side, the worker validates every envelope through
`BabelQueue\Validation\EnvelopeValidator` before dispatching. A message that is
malformed, missing its URN, or carries an unsupported `schema_version` is
**quarantined** (dead-lettered), never silently dropped. See
[Dead-letter handling](dead-letter.md).
