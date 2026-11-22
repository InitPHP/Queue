# Handlers & Routing

A consumed message is routed to a **handler** by its **URN**. This page covers
writing handlers, registering them, container integration, and what happens when a
URN has no handler.

## The `Handler` contract

```php
namespace InitPHP\Queue\Contracts;

use BabelQueue\Contracts\InboundMessage;

interface Handler
{
    public function handle(InboundMessage $message): void;
}
```

- **Return** to acknowledge — the worker removes the message.
- **Throw** to fail — the worker retries with back-off, then dead-letters.

```php
use BabelQueue\Contracts\InboundMessage;
use InitPHP\Queue\Contracts\Handler;

final class RecordOrder implements Handler
{
    public function __construct(private readonly PDO $db) {}

    public function handle(InboundMessage $message): void
    {
        $data = $message->getData();

        $stmt = $this->db->prepare('INSERT INTO orders (id, amount) VALUES (?, ?)');
        $stmt->execute([$data['order_id'], $data['amount']]);
        // Return = success. An exception here = failure (retry, then dead-letter).
    }
}
```

## Registering handlers with `HandlerMap`

`HandlerMap` is the bundled URN → handler registry. A handler may be registered
three ways:

```php
use InitPHP\Queue\Routing\HandlerMap;

$handlers = new HandlerMap();

// 1. A class name — lazily instantiated, must have a no-arg constructor:
$handlers->register('urn:babel:users:registered', SendWelcomeEmail::class);

// 2. A ready-built instance — use this to inject dependencies yourself:
$handlers->register('urn:babel:orders:created', new RecordOrder($pdo));

// 3. A closure — for small inline handlers:
$handlers->register('urn:babel:audit:logged', function (InboundMessage $m): void {
    error_log('audit: ' . $m->getUrn());
});
```

API:

| Method | Returns | Notes |
| ------ | ------- | ----- |
| `register(string $urn, Handler\|Closure\|string $handler)` | `self` | Chainable. Re-registering a URN replaces the previous entry. |
| `has(string $urn)` | `bool` | Whether a URN is mapped. |
| `resolve(string $urn)` | `?Handler` | The handler, or `null` if unmapped. Caches instances. |

`register()` returns `$this`, so calls chain:

```php
$handlers = (new HandlerMap())
    ->register('urn:babel:users:registered', SendWelcomeEmail::class)
    ->register('urn:babel:orders:created', new RecordOrder($pdo))
    ->register('urn:babel:orders:shipped', OnOrderShipped::class);
```

A class name that does not exist, or does not implement `Handler`, raises a
`ConfigurationException` the first time it is resolved — a bug you fix in code,
never a runtime failure that gets retried. Registering an **empty URN** throws
immediately.

## Container-based resolution

If your handlers need constructor injection, back the routing with your own
PSR-11 container by implementing `HandlerResolver` instead of using `HandlerMap`:

```php
use InitPHP\Queue\Contracts\Handler;
use InitPHP\Queue\Contracts\HandlerResolver;
use Psr\Container\ContainerInterface;

final class ContainerHandlerResolver implements HandlerResolver
{
    /** @param array<string, class-string<Handler>> $map URN => handler class */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly array $map,
    ) {}

    public function resolve(string $urn): ?Handler
    {
        $class = $this->map[$urn] ?? null;

        return $class !== null ? $this->container->get($class) : null;
    }
}
```

Any `HandlerResolver` can be passed to the `Dispatcher`.

## The `Dispatcher`

The dispatcher ties routing together. You build it with a resolver and an
unknown-URN strategy, then hand it to a `Worker` — you rarely call it yourself.

```php
use BabelQueue\Routing\UnknownUrnStrategy;
use InitPHP\Queue\Consumer\Dispatcher;

$dispatcher = new Dispatcher($handlers, UnknownUrnStrategy::FAIL);
```

For each message it: validates the envelope, resolves the handler by URN, runs it,
and returns an `Outcome` (ack / retry / dead-letter / drop / release) the worker
acts on.

## Unknown-URN strategies

When a message's URN has no mapped handler, the dispatcher applies the strategy
passed to its constructor — the four canonical BabelQueue options from
`BabelQueue\Routing\UnknownUrnStrategy`:

| Strategy | Constant | Behaviour |
| -------- | -------- | --------- |
| Fail *(default)* | `UnknownUrnStrategy::FAIL` | Treat as a failure: retry, then dead-letter. Safest while a fleet is mid-deploy and a consumer hasn't learned a new URN yet. |
| Delete | `UnknownUrnStrategy::DELETE` | Acknowledge and silently discard. |
| Release | `UnknownUrnStrategy::RELEASE` | Put it back on the queue verbatim for another worker/version. |
| Dead-letter | `UnknownUrnStrategy::DEAD_LETTER` | Quarantine immediately, then acknowledge. |

```php
new Dispatcher($handlers, UnknownUrnStrategy::DEAD_LETTER);
```

Passing an unrecognised strategy string throws `ConfigurationException`.

Whatever the strategy for *unknown* URNs, a **malformed or unsupported-schema**
envelope is always dead-lettered — see [Retries & Dead-Letters](Retries-and-Dead-Letters.md).
