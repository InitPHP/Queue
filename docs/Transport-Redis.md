# Transport: Redis

`InitPHP\Queue\Transport\Redis\RedisTransport` runs a durable, reliable queue on
Redis. Publishing reuses the SDK's `BabelQueue\Transport\RedisTransport` (`RPUSH`),
so the wire convention every BabelQueue SDK shares is never duplicated; consuming
adds the reliable-queue mechanics.

**Requires Redis 6.2+** (for `BLMOVE`) and the pure-PHP
[`predis/predis`](https://packagist.org/packages/predis/predis) client.

## Construction

```php
use InitPHP\Queue\Transport\Redis\RedisTransport;

$transport = new RedisTransport(
    client:           new Predis\Client('tcp://127.0.0.1:6379'),
    defaultQueue:     'default',
    processingSuffix: ':processing',
    failedSuffix:     ':failed',
    delayedSuffix:    ':delayed',
);
```

## Keys

For a queue named `orders`:

| Key | Type | Role |
| --- | ---- | ---- |
| `orders` | list | The ready queue. `publish()` does `RPUSH orders <envelope>`. |
| `orders:processing` | list | In-flight messages reserved by a worker. |
| `orders:delayed` | sorted set | Messages waiting out a back-off delay, scored by their due Unix time. |
| `orders:failed` | list | Dead-lettered messages. |

## How it works

### Reserve (reliable queue)

`reserve()` runs `BLMOVE orders orders:processing LEFT RIGHT <timeout>`: it
atomically pops the head of the ready list and pushes it onto the processing list,
blocking up to `timeout` seconds. Because the message sits on `orders:processing`
until acknowledged, a worker crash leaves it recoverable rather than lost.

The blocking timeout has a 1-second floor, so the loop returns periodically to
observe a stop signal instead of blocking forever on an empty queue.

### Ack

`ack()` removes the in-flight copy with `LREM orders:processing 1 <envelope>`.

### Retry with delay

`release()` runs one Lua script that removes the in-flight copy (`LREM`) and, only
if it removed one, then:

- delay `0` → `RPUSH orders <envelope>` (back onto the ready queue immediately);
- delay `> 0` → `ZADD orders:delayed <due-time> <envelope>`.

Because it is one script, a worker that dies cannot leave the message on no list at
all. And because the write happens only when the `LREM` removed the worker's own
entry, a worker that has **lost the message** (a reaper already moved it back onto
the queue) does not add a second copy: the live copy belongs to whoever reserves it
next, and the stale release is ignored. The same holds for `deadLetter()`.

On every `reserve()`, due members of `orders:delayed` (score `<= now`, at most 1000
at a time) are migrated back onto the ready list by another script, each member
removed and pushed in the same step, so a delayed retry is neither lost nor
duplicated across workers.

### Dead-letter

`deadLetter()` removes the in-flight copy and `RPUSH`-es the annotated envelope
onto `orders:failed`, in one script, and only if the worker still owns the message.

## Delivery semantics

At-least-once. A crashed worker leaves its in-flight message on
`orders:processing`. The graceful `SIGTERM` shutdown (finish the in-flight message,
then exit) avoids stranding messages in normal operation; if you run workers that
can be killed mid-job, add a small reaper that moves long-idle
`orders:processing` entries back onto `orders`.

A message is identified by its processing entry, so a worker acts on a message
only while that entry is still there. This is what stops a slow handler and a
reaper from splitting one message into two, and it has two consequences for the
reaper you write:

- **Keep its delay above your slowest handler.** If a handler is still running when
  the reaper moves its message back, the handler's later ack, retry or dead-letter
  is ignored; the reclaimed copy is the one that carries on.
- **Count each reclaim as an attempt**: increment `attempts` in the envelope it
  puts back. A reclaim means one attempt did not finish, and without the count a
  handler that always outlives the reaper and always fails would never reach
  `maxAttempts`.

With the dispatcher's [deduplication](Idempotent-Consumption.md), a reclaimed copy
that finds the original handler's key still claimed is parked in `orders:delayed`
and re-checked, not discarded.

## Using phpredis instead

The consume side is written against `Predis\ClientInterface`. If you use the
`ext-redis` (phpredis) extension, either:

- install `predis/predis` (pure PHP, no extension needed — the supported path), or
- wrap phpredis in a thin adapter exposing the same `blmove` / `rpush` / `lrem` /
  `zadd` / `zrangebyscore` / `zrem` methods.

For **publish-only** producers, the SDK's one-method `Transport` is just an
`RPUSH` and is trivial to implement over phpredis directly.

See [The Worker](The-Worker.md) for back-off configuration and
[Retries & Dead-Letters](Retries-and-Dead-Letters.md) for the failed list.
