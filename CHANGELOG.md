# Changelog

All notable changes to `initphp/queue` are documented here. The format is based
on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `Contracts\CompletionAwareIdempotencyStore` (extends `IdempotencyStore`) with
  `isCompleted(string $key): bool`, which lets the `Dispatcher` tell a key that
  was recorded as processed from one that another handler still holds. The bundled
  `InMemoryIdempotencyStore` implements it.
- `IdempotencyOptions::$inFlightDelaySeconds` (new optional last argument,
  default 5): how long a message waits before it re-checks a key held by another
  handler.
- `Outcome::release()` takes an optional `$delaySeconds` and `Outcome` exposes it
  as `delaySeconds` (default 0, so existing releases are unchanged). The `Worker`
  passes it to the transport and logs it as `delay` on `job.released`.

### Fixed

- **A message could be lost with idempotency enabled.** A redelivery that found its
  key claimed by a handler that had not finished was acknowledged and discarded as
  a `duplicate`. If that handler's worker had died, the message was gone; if the
  handler outlived the transport's redelivery time and then threw, the discarded
  duplicate had deleted the PDO row, so its retry updated nothing and was lost with
  no dead letter. With a `CompletionAwareIdempotencyStore` such a redelivery is now
  released back to the queue (`job.released`, reason `in_flight`) without counting
  an attempt, so it never moves towards the dead-letter queue by waiting, and it is
  re-checked after `inFlightDelaySeconds`. A recorded (completed) duplicate is
  still dropped. After a delayed release the `Worker` pauses for `sleepWhenEmpty`
  (at least 0.1 s) so that RabbitMQ, which has no native delay, does not reserve
  the message again in a tight loop.
- `RedisTransport::release()`, `deadLetter()` and the delayed-set migration are
  single Lua scripts. They act only while the worker still owns the message (its
  `LREM` removed one processing entry), so a release or dead-letter from a worker
  whose message a reaper already moved back no longer forks it into two copies, and
  a crash cannot leave a message on no list. Redis 6.2+ already required Lua.
- `AmqpTransport::release()` and `deadLetter()` publish the new copy (waiting for
  the broker's confirm when the channel is in confirm mode) **before** acking the
  original, so a crash between the two leaves a duplicate instead of losing the
  message, and a failed publish leaves the original unacked.
- `PdoTransport::release()` updates only a row still held with the reservation
  stamp it wrote, and a message put back unchanged no longer rewrites `payload`
  or `attempts`, so a worker whose reservation was taken over cannot overwrite
  another worker's recorded retry or back-off.
- A message deferred to wait for an in-flight key no longer counts towards
  `maxJobs` or `processedCount()`, and the worker pauses at least 0.1 s after a
  deferral even with `sleepWhenEmpty: 0`.
- Wrong docblocks: `IdempotencyStore::forget()` is called by the dispatcher when a
  handler throws, and the contract's description now lists `forget()`.

### Compatibility

- `IdempotencyStore` and its signatures are unchanged. A custom store that
  implements only `IdempotencyStore` keeps the previous behaviour (any failed claim
  is discarded as a duplicate); implement `CompletionAwareIdempotencyStore` to get
  the fix. Records written by 2.1/2.2 should be treated as completed by that
  method; see the upgrade notes in `docs/Idempotent-Consumption.md`.

## [2.2.0] - 2026-11-22

### Changed

- Code style moved to PER-CS (php-cs-fixer, shared `.php-cs-fixer.dist.php`,
  replacing the previous PSR-12 configuration); the codebase has been
  reformatted accordingly.
- Requires PHP `^8.2` (unchanged); CI now covers PHP 8.2, 8.3, 8.4 and 8.5.

## [2.1.0] - 2026-06-21

### Added

- **Opt-in idempotent consumption.** A new `IdempotencyStore` contract
  (`Contracts/IdempotencyStore.php`) with an in-memory reference implementation
  (`InMemoryIdempotencyStore`) lets the `Dispatcher` dedupe at-least-once
  redeliveries, processing a message **at most once**. Enable it by passing an
  `IdempotencyOptions` to the `Dispatcher`; the dedup key derives from a custom
  resolver, the envelope's `meta.id`, or its `trace_id`, and is recorded only
  after a handler succeeds (a throwing handler stays retryable). Disabled by
  default — existing behaviour is unchanged. Bring a durable, atomic store
  (Redis/PDO) for fleet-wide deduplication; see the README.

## [2.0.0] - 2026-06-08

### Changed (breaking)

`2.0` is a full rewrite. InitPHP Queue is now **the framework-less BabelQueue
runtime for plain PHP**: it owns the consumer/worker loop, retries and
dead-letter routing that [`babelqueue/php-sdk`](https://github.com/BabelQueue/php-sdk)
deliberately leaves to the framework, while reusing the SDK's canonical
`{ job, trace_id, data, meta, attempts }` envelope so messages interoperate with
Go, Python, Node and any other BabelQueue SDK.

- Requires PHP `^8.2` (was `>=7.4`) and depends on `babelqueue/php-sdk ^1.0`.
- Messages are routed by **URN** (`urn:babel:<context>:<event>`) to mapped
  handlers, instead of serialising the producing PHP class name into the
  payload. This removes the PHP-only `new $classFromPayload()` coupling.
- The abstract `Job` base class, `JobInterface`, `AdapterInterface`,
  `PDOAdapter` and `RabbitMQAdapter` are removed. See [UPGRADE-2.0.md](UPGRADE-2.0.md).

### Fixed

Bugs from `1.x` that no longer exist in the new design:

- The PDO worker never instantiated jobs (`$jobClass($this)` called a function
  instead of `new $jobClass(...)`), so the database adapter never ran any job.
- `RabbitMQAdapter::close()` dereferenced a wrong (`_failed`) array key and threw.
- `Job::push()` reported success even when the adapter's `push()` returned false.
- The PDO consumer used a non-atomic `SELECT … status = 0` then `UPDATE`,
  letting two workers claim the same job, and busy-looped with no back-off.
- `nack()` moved rows to the failed table via a column-position-dependent
  `INSERT … SELECT *`.

### Added

- `Worker` consumer loop with max-attempts, exponential back-off, max-jobs /
  max-runtime / memory limits and graceful `SIGINT`/`SIGTERM` shutdown.
- URN → handler routing (`HandlerMap`, `Dispatcher`) with the four canonical
  unknown-URN strategies (`fail` / `delete` / `release` / `dead_letter`).
- Consume-side transports for **PDO** (database), **Redis** (`BLMOVE`/`LREM`)
  and **RabbitMQ** (AMQP), each paired with the SDK's publish side.
- A thin `Producer` facade over `EnvelopeCodec` + a `Transport`.
- A `bin/queue` CLI worker entry point.
