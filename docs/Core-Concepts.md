# Core Concepts

InitPHP Queue is a small set of parts that snap together. Understanding the three
of them — and the one dependency they sit on — is enough to use the whole package.

## The dependency: BabelQueue

[`babelqueue/php-sdk`](https://github.com/BabelQueue/php-sdk) defines the **wire
format**: a canonical JSON envelope (`{ job, trace_id, data, meta, attempts }`)
and the rules for routing on a **URN**, validating an envelope, and annotating a
dead-letter. It is a *contract runtime, not a worker* — it publishes, but it
deliberately does not own a consume loop or retry policy.

InitPHP Queue is exactly that missing consume side, for plain PHP. See
[The Message Envelope](The-Message-Envelope.md) for the format itself.

## The three moving parts

```
                 ┌────────────┐   publish    ┌─────────┐
   Producer ────►│  Transport │ ───────────► │ Broker  │
                 └────────────┘              │ (Redis, │
                 ┌────────────┐   reserve    │ Rabbit, │
   Worker ──────►│  Transport │ ◄─────────── │  DB)    │
      │          └────────────┘              └─────────┘
      │ dispatch
      ▼
 ┌────────────┐   resolve(urn)   ┌─────────────┐
 │ Dispatcher │ ───────────────► │ HandlerMap  │ ──► your Handler
 └────────────┘                  └─────────────┘
```

### 1. Transport

A transport talks to one broker. It implements **both** sides:

- **Publish** (the SDK's `BabelQueue\Contracts\Transport`): `publish(string $payload, ?string $queue)`.
- **Consume** (`InitPHP\Queue\Contracts\ConsumerTransport`): `reserve()`, `ack()`,
  `release()`, `deadLetter()`.

Three are bundled — [PDO](Transport-PDO.md), [Redis](Transport-Redis.md),
[RabbitMQ](Transport-RabbitMQ.md) — and a single transport object both produces and
consumes.

### 2. Dispatcher + HandlerMap (routing)

The **`Dispatcher`** decodes and validates each reserved message, resolves its
handler by URN via a **`HandlerResolver`** (the bundled one is `HandlerMap`), runs
it, and returns an **`Outcome`** describing what should happen (ack / retry /
dead-letter / drop / release). It contains no broker code. See
[Handlers & Routing](Handlers-and-Routing.md).

### 3. Worker

The **`Worker`** is the loop. It reserves a message from the transport, asks the
dispatcher for an outcome, and performs the matching broker action — applying the
retry / back-off / dead-letter policy from **`WorkerOptions`**. It also handles
limits and graceful shutdown. See [The Worker](The-Worker.md).

## A message's journey

**Producing:**

1. `Producer::send($urn, $data)` calls `EnvelopeCodec::make()` + `encode()`.
2. The JSON envelope is handed to `Transport::publish()`.
3. The broker stores it on the queue.

**Consuming:**

1. `Worker` calls `Transport::reserve()` → a `ReceivedMessage` (or `null`).
2. `Dispatcher::dispatch()` validates the envelope, resolves the handler by URN,
   and runs it.
3. The handler returning → **ack**; throwing → **retry** (re-queue with an
   incremented `attempts` and a back-off delay), until `maxAttempts` →
   **dead-letter**.
4. The `Worker` performs that action through the transport.

## Why route on a URN?

In `1.x` the producing PHP **class name** was serialised into the message and the
consumer did `new $class(...)`. That made every message PHP-only and brittle: a
Go service couldn't consume it, and renaming the class orphaned messages already
queued.

A **URN** (`urn:babel:orders:created`) is a plain, stable string under your
control. The producing class can be renamed or moved freely, and a consumer in any
language routes on the same string without sharing a type. This is what makes the
queue **polyglot** — see [Interoperability](Interoperability.md).

## Delivery guarantee

Delivery is **at-least-once**. A message reserved by a worker that then crashes
becomes reservable again (the PDO visibility timeout; the Redis processing list).
A handler may therefore run more than once for the same message, so **handlers
must be idempotent** — or let the dispatcher dedupe redeliveries, see
[Idempotent Consumption](Idempotent-Consumption.md).

## Error handling

All runtime errors extend `InitPHP\Queue\Exceptions\QueueException`, which extends
the SDK's `BabelQueue\Exceptions\BabelQueueException` — so a single
`catch (BabelQueueException)` captures both envelope/codec errors and runtime
errors. Configuration mistakes (an empty URN, an unknown strategy, a handler class
that does not implement `Handler`) raise `ConfigurationException` immediately and
are never retried.
