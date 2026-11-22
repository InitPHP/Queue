# API Reference

The complete public surface of `initphp/queue`. Types from the underlying
`babelqueue/php-sdk` are referenced where they appear in signatures.

> Namespaces: everything here is under `InitPHP\Queue\`. SDK types are under
> `BabelQueue\`.

---

## Producer

`InitPHP\Queue\Producer\Producer` — publishes canonical envelopes through a
`BabelQueue\Contracts\Transport`.

```php
public function __construct(
    BabelQueue\Contracts\Transport $transport,
    string $defaultQueue = 'default',
)

public function dispatch(BabelQueue\Contracts\PolyglotJob $job, ?string $queue = null): ?string
public function send(string $urn, array $data = [], ?string $queue = null, ?string $traceId = null): ?string
```

- `dispatch()` reads the URN and payload from the job; honours `HasTraceId`.
- `send()` builds the envelope from a URN + array.
- Both return the transport's message id, or `null`.
- Throws `BabelQueue\Exceptions\BabelQueueException` on an empty URN and
  `\JsonException` on an unencodable payload.

See [Producing Messages](Producing-Messages.md).

---

## Consumer

### Worker

`InitPHP\Queue\Consumer\Worker` — the consume loop.

```php
public function __construct(
    InitPHP\Queue\Contracts\ConsumerTransport $transport,
    InitPHP\Queue\Consumer\Dispatcher $dispatcher,
    InitPHP\Queue\Consumer\WorkerOptions $options = new WorkerOptions(),
    ?callable $logger = null,   // ($level, $event, $context)
)

public function run(string $queue): void       // loop until stop/limit (resilient)
public function runOnce(string $queue): bool    // process one; true if a message was handled
public function stop(): void                     // request graceful stop
public function processedCount(): int
```

See [The Worker](The-Worker.md).

### WorkerOptions

`InitPHP\Queue\Consumer\WorkerOptions` — immutable worker tuning.

```php
public function __construct(
    int $maxAttempts = 3,
    array $backoff = [0],          // list<int> seconds
    float $reserveTimeout = 5.0,
    float $sleepWhenEmpty = 0.5,
    int $maxJobs = 0,
    float $maxRuntime = 0.0,
    int $memoryLimitMb = 0,
    bool $stopWhenEmpty = false,
)

public function delayForAttempt(int $attempt): int
```

Public readonly properties match the constructor parameters. Out-of-range values
throw `ConfigurationException`.

### Dispatcher

`InitPHP\Queue\Consumer\Dispatcher` — validates and routes a message.

```php
public function __construct(
    InitPHP\Queue\Contracts\HandlerResolver $resolver,
    string $unknownUrnStrategy = BabelQueue\Routing\UnknownUrnStrategy::FAIL,
)

public function dispatch(InitPHP\Queue\Message\ReceivedMessage $message): InitPHP\Queue\Consumer\Outcome
```

An unknown strategy string throws `ConfigurationException`.

### Outcome / OutcomeKind

`InitPHP\Queue\Consumer\Outcome` — the dispatcher's decision (immutable).

```php
public readonly OutcomeKind $kind;
public readonly string $reason;          // '' | 'failed' | 'unknown_urn' | a validator reason
public readonly ?\Throwable $exception;

public static function ack(): self
public static function retry(?\Throwable $exception = null, string $reason = 'failed'): self
public static function deadLetter(string $reason, ?\Throwable $exception = null): self
public static function drop(string $reason): self
public static function release(string $reason): self
```

`InitPHP\Queue\Consumer\OutcomeKind` is an enum: `Ack`, `Retry`, `DeadLetter`,
`Drop`, `Release`.

---

## Routing

### HandlerMap

`InitPHP\Queue\Routing\HandlerMap implements HandlerResolver`.

```php
public function register(string $urn, InitPHP\Queue\Contracts\Handler|\Closure|string $handler): self
public function has(string $urn): bool
public function resolve(string $urn): ?InitPHP\Queue\Contracts\Handler
```

A `string` handler is a no-arg `Handler` class name; a `Closure` is wrapped in a
`CallableHandler`. Invalid registrations throw `ConfigurationException` on resolve;
an empty URN throws on `register()`.

### CallableHandler

`InitPHP\Queue\Routing\CallableHandler implements Handler` — adapts a closure into
a handler.

```php
public function __construct(callable $callback)   // callable(InboundMessage): void
public function handle(BabelQueue\Contracts\InboundMessage $message): void
```

See [Handlers & Routing](Handlers-and-Routing.md).

---

## Contracts

### Handler

```php
namespace InitPHP\Queue\Contracts;

interface Handler
{
    public function handle(BabelQueue\Contracts\InboundMessage $message): void;
}
```

### HandlerResolver

```php
interface HandlerResolver
{
    public function resolve(string $urn): ?Handler;
}
```

### ConsumerTransport

```php
interface ConsumerTransport
{
    public function reserve(string $queue, float $timeout = 5.0): ?InitPHP\Queue\Message\ReceivedMessage;
    public function ack(InitPHP\Queue\Message\ReceivedMessage $message): void;
    public function release(InitPHP\Queue\Message\ReceivedMessage $message, string $payload, int $delaySeconds = 0): void;
    public function deadLetter(InitPHP\Queue\Message\ReceivedMessage $message, string $payload): void;
}
```

---

## Message

### ReceivedMessage

`InitPHP\Queue\Message\ReceivedMessage implements BabelQueue\Contracts\InboundMessage`.

```php
public function __construct(string $queue, string $rawBody, array $envelope, mixed $receipt)

// InboundMessage:
public function getUrn(): string
public function getTraceId(): string
public function getData(): array
public function getMeta(): array

// Plus worker-facing accessors:
public function queue(): string
public function rawBody(): string
public function envelope(): array
public function attempts(): int
public function receipt(): mixed
```

---

## Transports

All implement `BabelQueue\Contracts\Transport` (publish) **and**
`InitPHP\Queue\Contracts\ConsumerTransport` (consume).

### PdoTransport

```php
namespace InitPHP\Queue\Transport\Pdo;

public function __construct(
    PDO $pdo,
    string $table = 'jobs',
    ?string $failedTable = null,     // defaults to "<table>_failed"
    string $defaultQueue = 'default',
    int $retryAfter = 90,            // visibility timeout, seconds
)

public function createSchema(): void
```

See [Transport: PDO](Transport-PDO.md).

### RedisTransport

```php
namespace InitPHP\Queue\Transport\Redis;

public function __construct(
    Predis\ClientInterface $client,
    string $defaultQueue = 'default',
    string $processingSuffix = ':processing',
    string $failedSuffix = ':failed',
    string $delayedSuffix = ':delayed',
)
```

See [Transport: Redis](Transport-Redis.md).

### AmqpTransport

```php
namespace InitPHP\Queue\Transport\Amqp;

public function __construct(
    PhpAmqpLib\Channel\AMQPChannel $channel,
    string $defaultQueue = 'default',
    string $failedSuffix = '.failed',
)
```

See [Transport: RabbitMQ](Transport-RabbitMQ.md).

---

## Console

`InitPHP\Queue\Console\WorkerCommand` — backs `bin/queue`.

```php
public function __construct(?callable $writer = null)   // output sink; defaults to STDERR
public function run(array $argv): int                    // exit code (0 ok, 1 usage/error)
```

CLI: `php vendor/bin/queue work --bootstrap=<file.php> [--queue=<name>] [--once]`.

---

## Exceptions

```php
InitPHP\Queue\Exceptions\QueueException
    extends BabelQueue\Exceptions\BabelQueueException   // extends \RuntimeException

InitPHP\Queue\Exceptions\ConfigurationException
    extends InitPHP\Queue\Exceptions\QueueException
```

- `QueueException` — base for runtime errors raised by this package.
- `ConfigurationException` — programmer/config mistakes detectable before any
  message flows (empty URN, unknown strategy, bad handler class, out-of-range
  options, invalid table name). Never retried.

Catching `BabelQueue\Exceptions\BabelQueueException` captures both these and the
SDK's own envelope/codec errors.

---

## SDK types you will touch

From `babelqueue/php-sdk` (installed automatically):

| Type | Use |
| ---- | --- |
| `BabelQueue\Codec\EnvelopeCodec` | `make` / `fromJob` / `encode` / `decode` / `urn` / `accepts`. |
| `BabelQueue\Contracts\InboundMessage` | The read-only message a handler receives. |
| `BabelQueue\Contracts\PolyglotJob` | `getBabelUrn()` + `toPayload()` — a producible job. |
| `BabelQueue\Contracts\HasTraceId` | `getBabelTraceId()` — opt-in trace propagation. |
| `BabelQueue\Contracts\Transport` | The publish seam a `Producer` needs. |
| `BabelQueue\Routing\UnknownUrnStrategy` | `FAIL` / `DELETE` / `RELEASE` / `DEAD_LETTER`. |
| `BabelQueue\Validation\EnvelopeValidator` | `check()` + `REASON_*` constants. |
| `BabelQueue\DeadLetter\DeadLetter` | `annotate()` — the dead-letter block. |
| `BabelQueue\Exceptions\BabelQueueException` | Base of the exception hierarchy. |
