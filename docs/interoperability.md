# Interoperability

The whole point of building on BabelQueue is that the queue you produce and
consume in PHP is the *same* queue other languages use. The wire format is the
canonical envelope; routing is by URN, never a PHP class name. This guide shows a
message crossing a language boundary in each direction.

## A PHP producer, a Go/Python consumer

Publish from PHP exactly as usual:

```php
use InitPHP\Queue\Producer\Producer;
use InitPHP\Queue\Transport\Redis\RedisTransport;

$producer = new Producer(new RedisTransport(new Predis\Client('tcp://127.0.0.1:6379')), 'orders');
$producer->send('urn:babel:orders:created', ['order_id' => 1042, 'amount' => 99.90]);
```

The bytes on the `orders` list are the canonical envelope:

```json
{
  "job": "urn:babel:orders:created",
  "trace_id": "…",
  "data": { "order_id": 1042, "amount": 99.90 },
  "meta": { "id": "…", "queue": "orders", "lang": "php", "schema_version": 1, "created_at": 1749132727000 },
  "attempts": 0
}
```

A consumer in any BabelQueue SDK reserves from the same `orders` queue, matches on
`job == "urn:babel:orders:created"`, and reads `data.order_id`. No shared PHP type,
no `serialize()`, no translation layer.

## A non-PHP producer, a PHP consumer

When another service produces onto a queue your PHP worker consumes, nothing
changes on your side — the worker decodes and routes by URN:

```php
$handlers = (new HandlerMap())
    ->register('urn:babel:orders:created', RecordOrder::class);

$worker = new Worker($transport, new Dispatcher($handlers), new WorkerOptions());
$worker->run('orders');
```

Two cross-language details the runtime handles for you:

- **The `urn` alias.** Some SDKs emit the field as `urn` instead of `job`; the
  worker accepts both (`EnvelopeCodec::urn()` reads either). A Go-produced message
  using `urn` routes to your handler identically.
- **Producer language.** `meta.lang` tells you where a message came from
  (`go`, `python`, …) via `$message->getMeta()['lang']`, which is handy for
  logging but never affects routing.

## Trace propagation across services

`trace_id` is preserved unchanged across every hop and language. When a handler
dispatches a *downstream* job as a result of the one it is processing, carry the
incoming trace id forward so the whole chain shares one trace:

```php
use BabelQueue\Contracts\InboundMessage;
use InitPHP\Queue\Contracts\Handler;

final class RecordOrder implements Handler
{
    public function __construct(private readonly Producer $producer) {}

    public function handle(InboundMessage $message): void
    {
        // ... record the order ...

        // Continue the same trace into the next message:
        $this->producer->send(
            'urn:babel:billing:invoice.requested',
            ['order_id' => $message->getData()['order_id']],
            'billing',
            $message->getTraceId(),   // inherited trace id
        );
    }
}
```

A Go or Python service further down the chain sees the same `trace_id`, so a
single distributed trace spans all of them.

## Conformance

The runtime is tested against the SDK's cross-SDK **conformance fixtures** — the
same golden files every BabelQueue SDK must satisfy (`order-created`, the Go
`urn-alias`, `dead-lettered`, a `unicode-and-numbers` round-trip, and the invalid
cases). See `tests/Conformance/EnvelopeConformanceTest.php`. If those pass, a
message produced by any conforming SDK is consumable here.

For the full cross-language standard, see [babelqueue.com](https://babelqueue.com).
