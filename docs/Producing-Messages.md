# Producing Messages

The `InitPHP\Queue\Producer\Producer` is a thin facade over the SDK's
`EnvelopeCodec` and a publish `Transport`. It encodes a canonical envelope and
hands the JSON to the transport, so producing a polyglot message is one line.

## Constructing a producer

```php
use InitPHP\Queue\Producer\Producer;

$producer = new Producer($transport, defaultQueue: 'emails');
```

`$transport` is any `BabelQueue\Contracts\Transport` — the bundled
[PDO](Transport-PDO.md), [Redis](Transport-Redis.md) or [RabbitMQ](Transport-RabbitMQ.md)
transports, or the SDK's own reference transports. The default queue is used
whenever you do not pass one explicitly.

## `send()` — from a URN and data

```php
$producer->send(
    'urn:babel:users:registered',   // the message URN
    ['user_id' => 42, 'email' => 'a@b.c'],   // pure-JSON payload
    'emails',                        // optional: queue (defaults to the producer's)
    null,                            // optional: trace id to continue (else a new one)
);
```

Signature:

```php
public function send(
    string $urn,
    array $data = [],
    ?string $queue = null,
    ?string $traceId = null,
): ?string
```

The return value is the transport's message id when it exposes one (the database
row id for PDO; `null` for Redis, where the envelope's own `meta.id` is the
identity).

## `dispatch()` — from a job object

Instead of a raw URN + array, implement `BabelQueue\Contracts\PolyglotJob` and let
the producer read the URN and payload from the object:

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

`toPayload()` must return **pure, JSON-encodable** data — no objects, closures or
resources.

## Trace propagation

Every envelope carries a `trace_id`. Normally the producer mints a fresh one. When
you dispatch a *downstream* message while handling another, carry the incoming
trace id forward so the whole chain shares one trace:

```php
$producer->send(
    'urn:babel:billing:invoice.requested',
    ['order_id' => $message->getData()['order_id']],
    'billing',
    $message->getTraceId(),   // inherited trace id
);
```

For job objects, additionally implement `BabelQueue\Contracts\HasTraceId` and
return the inherited id from `getBabelTraceId()` (return `null` to mint a new one);
`EnvelopeCodec::fromJob()` reuses it automatically.

```php
use BabelQueue\Contracts\HasTraceId;
use BabelQueue\Contracts\PolyglotJob;

final class InvoiceRequested implements PolyglotJob, HasTraceId
{
    public function __construct(
        private readonly int $orderId,
        private readonly ?string $traceId,
    ) {}

    public function getBabelUrn(): string { return 'urn:babel:billing:invoice.requested'; }
    public function toPayload(): array     { return ['order_id' => $this->orderId]; }
    public function getBabelTraceId(): ?string { return $this->traceId; }
}
```

## Producing without this package

A `Producer` is convenience, not a requirement. Any code that can `publish()` a
JSON envelope can produce — even another BabelQueue SDK in another language. The
PHP one-liner under the hood is just:

```php
use BabelQueue\Codec\EnvelopeCodec;

$transport->publish(
    EnvelopeCodec::encode(EnvelopeCodec::make('urn:babel:users:registered', ['user_id' => 42], 'emails')),
    'emails',
);
```

## Errors

- An **empty URN** throws `BabelQueue\Exceptions\BabelQueueException`.
- A payload that cannot be JSON-encoded throws `\JsonException`.

Both surface immediately — producing never silently swallows an error.
