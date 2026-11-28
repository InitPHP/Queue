# Changelog

All notable changes to `initphp/queue` are documented here. The format is based
on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `Contracts\OwnershipAwareIdempotencyStore` (extends `IdempotencyStore`, optional,
  independent of `CompletionAwareIdempotencyStore`): `claimOwned()` returns a token
  for the claim it took, `forgetOwned()` releases a claim only while that token
  still owns the key. The `Dispatcher` uses them when the store implements the
  interface; a store that does not keeps `claim()` and `forget()` exactly as before.
  The bundled `InMemoryIdempotencyStore` implements it.
- `ReceivedMessage` has a new optional last constructor argument `$abandoned` and
  `wasAbandoned()`: the transport has handed the message out again after a delivery
  that never ended in an ack, release or dead-letter, and has already counted that
  delivery in `attempts()`.
- `RedisTransport` has a new optional last constructor argument `$startedSuffix`
  (default `:started`).

### Fixed

- **A handler that kills its process now counts as an attempt.** A handler killed by
  out-of-memory, `exit` or `SIGKILL` never reports a failure, so its message used to
  come back with the same `attempts` for ever. The transport now counts the
  unfinished delivery when it hands the message out again, and the `Worker`
  dead-letters it (reason `failed`) once `attempts` reaches `maxAttempts`, without
  running the handler again. PDO: a row taken over after its reservation lapsed gets
  `attempts` (column and envelope) raised in the same `UPDATE` that takes it, so one
  worker counts it; no schema change. Redis: `reserve()` records each delivery in a
  `<queue>:started` sorted set (SHA-1 of the body) that `ack()`, `release()` and
  `deadLetter()` clear, and a body that returns with its entry still there is
  counted, in the body and in the processing entry, so the count survives the next
  crash; the body format is unchanged and a client without the sorted-set commands
  switches the counting off. RabbitMQ: the broker's `redelivered` flag counts one
  attempt (a classic queue keeps no delivery count, so repeated crashes are not
  bounded there). Waiting for another handler's in-flight key is a release and is
  never counted, so `inFlightDelaySeconds` waits still use no attempts.
- **A handler that outlived its lease no longer deletes the new holder's claim.** On
  a store that implements `OwnershipAwareIdempotencyStore`, the late failure releases
  only its own claim, so the claim or completed record of the worker that took over
  stays.
- An abandoned message that is out of attempts is checked against the idempotency
  store before it is dead-lettered: a key recorded as processed drops it as a
  `duplicate` (the handler finished, only its ack was lost) and a key still claimed
  releases it as `in_flight`. `Dispatcher::dispatch()` has a new optional second
  argument, `$exhausted`, for this; the `Worker` passes it. A message that is put
  back this way is treated as exhausted when it returns: any message that arrives
  with `attempts` at or above `maxAttempts` and no `dead_letter` block is, so a
  handler that kills its process every time reaches the dead-letter queue even when
  `leaseSeconds` is longer than the transport's redelivery time. A store that cannot
  tell a recorded key from a claimed one now dead-letters such a message instead of
  discarding it.
- `RedisTransport` counting never fails a reservation: a `NOPERM`/unknown-command
  refusal of `ZREMRANGEBYSCORE`, `LINSERT` or the other commands it adds switches the
  counting off, and any other failure while counting hands the message out
  uncounted, instead of leaving it on `<queue>:processing`.
- `PdoTransport::release()` for a worker whose reservation was taken over records
  the back-off even when the stored `attempts` already equals the one it reports
  (the take-over counted it); `attempts` still never goes back.

### Notes

- Nothing changes for existing stores, transports or message bodies; see
  `docs/Idempotent-Consumption.md` (Upgrading from 2.3) for rolling deploys. A
  handler that outlives `retryAfter` (PDO) or your Redis reaper's delay while its
  worker is alive looks like a dead one and its second delivery is counted.

### Changed

- CI now also runs the test suite on PHP 8.6 (8.2, 8.3, 8.4, 8.5 and 8.6).

## [2.3.0] - 2026-11-25

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
  `LREM` removed one processing entry), so a crash cannot leave a message on no
  list. A release or dead-letter from a worker whose message a reaper already moved
  back replaces the live copy (in the delayed set, or on the queue) instead of
  adding a second one, so `attempts` still climbs and `maxAttempts` still ends the
  message. A client that cannot `EVAL` (an adapter without `eval`, or a Redis user
  without scripting permission) falls back to the same steps as separate commands
  with the same rules, without atomicity; `reserve()`, `ack()` and `publish()` need
  no scripting.
- `AmqpTransport::release()` and `deadLetter()` publish the new copy (waiting for
  the broker's confirm when the channel is in confirm mode) **before** acking the
  original, so a crash between the two leaves a duplicate instead of losing the
  message, and a failed publish leaves the original unacked. In confirm mode a
  broker `nack` or a returned copy requeues the original (`basic_nack`, requeue)
  and throws a `QueueException` instead of acking; the channel's own nack handler
  and return listener keep running.
- `PdoTransport::release()` no longer overwrites a reservation another worker took
  over, and a message put back unchanged no longer rewrites `payload` or `attempts`
  or shortens a recorded back-off. A failure reported by a worker whose reservation
  was taken over still counts (the new `attempts`, payload and back-off are
  recorded without touching the holder's reservation, and `attempts` never goes
  back), so a handler slower than `retryAfter` that always fails still reaches
  `maxAttempts` and the dead-letter table.
- The `Worker` pauses after the transport fails to act on a message
  (`process.failed`): the larger of 0.1 s, `sleepWhenEmpty` and the back-off for the
  message's next attempt, at most 5 s, in short slices that a stop signal ends at
  once. A message RabbitMQ keeps refusing (a nacked republish) no longer re-runs its
  handler in a tight loop. Its `attempts` still do not advance while the broker
  refuses the copy; see the RabbitMQ page.
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

### Documentation

- New [Idempotent Consumption](docs/Idempotent-Consumption.md) page, linked from
  the README, Core Concepts, FAQ, Retries and Dead Letters and the sidebar. It
  states that deduplication is not exactly-once delivery and does not replace an
  idempotent handler, explains the claim lease, the in-flight release and what is
  not guaranteed, and carries the upgrade notes for this release.
- The API reference documents `IdempotencyOptions`, `IdempotencyStore`,
  `CompletionAwareIdempotencyStore`, `InMemoryIdempotencyStore` and
  `Outcome::release($reason, $delaySeconds)`.
- Transport RabbitMQ explains that `release()` and `deadLetter()` publish first and
  ack the original afterwards, how publisher-confirm mode (`confirm_select()`)
  protects against a broker that refuses the copy, and what happens to the message
  then. Transport Redis documents the single-script `release()`, `deadLetter()`
  and delayed-set migration, how a worker that lost its message replaces the live
  copy, the command-by-command path used when `EVAL` is unavailable, and what a
  reaper must keep in mind.
- The README, The Worker, Transport PDO and Troubleshooting pages were updated to
  match.

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
