# FAQ

### Is this a replacement for Laravel Queue or Symfony Messenger?

No — it is for apps that **don't** have one. Laravel and Symfony already own a
worker loop and retry machinery; the BabelQueue adapters plug into those. InitPHP
Queue is the equivalent runtime for a plain PHP / Slim / Mezzio app that has no
such loop of its own.

### What is the relationship to `babelqueue/php-sdk`?

The SDK defines the **wire format** (the canonical envelope, URN routing,
validation, dead-letter annotation) and a publish-only `Transport`. It deliberately
does not own a consumer loop. InitPHP Queue **is** that consumer loop for
framework-less PHP, plus a database transport the SDK does not ship. It depends on
the SDK and reuses its codec, so the two never drift. See [Core Concepts](Core-Concepts.md).

### Why route on a URN instead of a class name?

So the queue is **polyglot** and **refactor-safe**. A URN is a stable string any
language can match on; a PHP class name is neither. See
[The Message Envelope](The-Message-Envelope.md#urns-naming-your-messages).

### Do I have to use the `Producer` / `HandlerMap`?

No. The `Producer` is a one-line convenience over `EnvelopeCodec` + a transport;
`HandlerMap` is one implementation of `HandlerResolver`. You can publish raw
envelopes yourself and back routing with your own container — see
[Producing Messages](Producing-Messages.md#producing-without-this-package) and
[Handlers & Routing](Handlers-and-Routing.md#container-based-resolution).

### Which transport should I pick?

If you already have a database and want zero new infrastructure, use
[PDO](Transport-PDO.md). For throughput, use [Redis](Transport-Redis.md). For a real
broker with routing, use [RabbitMQ](Transport-RabbitMQ.md). The
[overview](Transports.md#choosing-one) has a comparison table.

### How are retries delayed?

The worker re-queues a failed message with a back-off delay from
`WorkerOptions::$backoff`. PDO and Redis honour the delay natively; RabbitMQ
applies retries immediately unless the delayed-message-exchange plugin is
installed. See [Retries & Dead-Letters](Retries-and-Dead-Letters.md).

### Is delivery exactly-once?

No — it is **at-least-once**. A message can be delivered more than once (e.g. a
worker crashes after handling but before acking). Make handlers **idempotent**.
The dispatcher's optional deduplication can skip some redeliveries, but it is not
a substitute: it can still run a handler twice — see
[Idempotent Consumption](Idempotent-Consumption.md#what-deduplication-does-not-guarantee).

### Can a Go / Python / Node service consume what PHP produces (and vice-versa)?

Yes — that is the whole point. They share the canonical envelope and route on the
same URN. See [Interoperability](Interoperability.md).

### How do I run a worker in production?

Run `vendor/bin/queue work --bootstrap=worker.php --queue=...` under a process
supervisor (systemd, supervisord, a container restart policy), and set a
`maxJobs`/`memoryLimitMb` limit so each worker exits and restarts with clean
memory. See [The Worker](The-Worker.md#the-cli).

### How do I stop a worker cleanly during a deploy?

Send `SIGTERM` (or `SIGINT`). With `ext-pcntl` installed, the worker finishes the
in-flight message and then exits.

### Does it need an extension for Redis?

No — `predis/predis` is a pure-PHP client. `ext-redis` (phpredis) is optional; see
[Transport: Redis](Transport-Redis.md#using-phpredis-instead).

### Where do failed messages go, and can I replay them?

To a per-transport dead-letter destination (a `*_failed` table, a `:failed` list,
or a `.failed` queue). Decode the stored envelope, drop the `dead_letter` block,
reset `attempts`, and re-publish. See
[Retries & Dead-Letters](Retries-and-Dead-Letters.md#replaying-a-dead-lettered-message).

### What PHP versions are supported?

PHP **8.2, 8.3, 8.4 and 8.5** are tested in CI.
