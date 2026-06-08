# Handlers & routing

A consumed message is routed to a **handler** by its **URN**. This guide covers
writing handlers, registering them, and what happens when a URN has no handler.

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

The `InboundMessage` is a read-only view of the envelope:

```php
$message->getUrn();      // 'urn:babel:users:registered'
$message->getTraceId();  // correlation id, or '' if absent
$message->getData();     // the 'data' block as an array
$message->getMeta();     // the 'meta' block as an array
```

## Registering handlers with `HandlerMap`

`HandlerMap` is the bundled URN → handler registry. A handler may be registered
three ways:

```php
use InitPHP\Queue\Routing\HandlerMap;

$handlers = new HandlerMap();

// 1. A class name (lazily instantiated, must have a no-arg constructor):
$handlers->register('urn:babel:users:registered', SendWelcomeEmail::class);

// 2. A ready-built instance (use this to inject dependencies yourself):
$handlers->register('urn:babel:orders:created', new RecordOrder($database));

// 3. A closure, for small inline handlers:
$handlers->register('urn:babel:audit:logged', function (InboundMessage $m): void {
    error_log('audit: ' . $m->getUrn());
});
```

`register()` returns `$this`, so calls chain. Re-registering a URN replaces the
previous entry. `has(string $urn): bool` reports whether a URN is mapped.

A class name that does not exist, or that does not implement `Handler`, raises an
`InitPHP\Queue\Exceptions\ConfigurationException` the first time it is resolved —
a configuration bug you fix in code, never a runtime failure that gets retried.

## Resolving handlers from a container

If your handlers need constructor injection, back the routing with your own
PSR-11 container by implementing `HandlerResolver` instead of using `HandlerMap`:

```php
use InitPHP\Queue\Contracts\Handler;
use InitPHP\Queue\Contracts\HandlerResolver;
use Psr\Container\ContainerInterface;

final class ContainerHandlerResolver implements HandlerResolver
{
    /** @param array<string, class-string<Handler>> $map  URN => handler class */
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

Pass any `HandlerResolver` to the `Dispatcher`.

## The `Dispatcher`

The dispatcher decodes/validates a message, resolves its handler, runs it, and
returns an `Outcome` the worker acts on:

```php
use BabelQueue\Routing\UnknownUrnStrategy;
use InitPHP\Queue\Consumer\Dispatcher;

$dispatcher = new Dispatcher($handlers, UnknownUrnStrategy::FAIL);
```

You normally never call the dispatcher yourself — you hand it to a `Worker`.

## Unknown-URN strategies

When a message's URN has no mapped handler, the dispatcher applies the strategy
passed to its constructor — the four canonical BabelQueue options:

| Strategy | Constant | Behaviour |
| -------- | -------- | --------- |
| Fail | `UnknownUrnStrategy::FAIL` *(default)* | Treat as a failure: retry, then dead-letter. Safest while a fleet is mid-deploy and a consumer hasn't learned the URN yet. |
| Delete | `UnknownUrnStrategy::DELETE` | Acknowledge and silently discard. |
| Release | `UnknownUrnStrategy::RELEASE` | Put it back on the queue verbatim for another worker/version. |
| Dead-letter | `UnknownUrnStrategy::DEAD_LETTER` | Quarantine immediately, then acknowledge. |

```php
new Dispatcher($handlers, UnknownUrnStrategy::DEAD_LETTER);
```

Whatever the strategy for *unknown* URNs, a **malformed or unsupported-schema**
envelope is always dead-lettered — see [Dead-letter handling](dead-letter.md).
