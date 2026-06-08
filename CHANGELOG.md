# Changelog

All notable changes to `initphp/queue` are documented here. The format is based
on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased] — 2.0

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
