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
    startedSuffix:    ':started',
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
| `orders:started` | sorted set | The SHA-1 of every body a worker has taken and not yet finished, scored by the Unix time it was taken. Used to count a delivery whose handler died (see below). |

## How it works

### Reserve (reliable queue)

`reserve()` runs `BLMOVE orders orders:processing LEFT RIGHT <timeout>`: it
atomically pops the head of the ready list and pushes it onto the processing list,
blocking up to `timeout` seconds. Because the message sits on `orders:processing`
until acknowledged, a worker crash leaves it recoverable rather than lost.

The blocking timeout has a 1-second floor, so the loop returns periodically to
observe a stop signal instead of blocking forever on an empty queue.

### Ack

`ack()` removes the in-flight copy with `LREM orders:processing 1 <envelope>` and
its `orders:started` entry.

### Counting a delivery that never finished

A handler that kills its process (out of memory, `exit`, `SIGKILL`) never reports a
failure, and the receipt is the message body itself, so the body cannot say that it
was started. `reserve()` therefore does `ZADD orders:started <now> <sha1(body)>`;
`ack()`, `release()` and `deadLetter()` remove that member. When `ZADD` finds the
member already there, the same body was started before and never finished (your
reaper moved it back from `orders:processing`): that delivery is counted by one
script that replaces the processing entry with the body whose `attempts` is raised by
one, and the worker dead-letters the message once the count reaches `maxAttempts`
(see [Retries & Dead-Letters](Retries-and-Dead-Letters.md#a-handler-that-kills-its-process)).
The count lives in the body, so it survives the next crash and your reaper, which
only has to move `orders:processing` entries back unchanged. The body format is not
touched; a worker on an older version takes no part and its finished messages leave a
member that is pruned after a week.

Two limits follow. Two messages with the same body count as one; a producer from this
library mints a unique `meta.id` for every message, so only a producer that does not
can hit this. And a worker that dies between `BLMOVE` and the `ZADD` leaves no member,
so that one delivery is not counted. A client without the commands this uses
(`zadd`, `zrem`, `zremrangebyscore`, `linsert`; see
[phpredis](#using-phpredis-instead)), or a Redis user the ACL refuses them (`NOPERM`),
is not an error: the counting is switched off the first time a command is refused
and `reserve()` hands the message out as it did in 2.3. Counting never fails a
reservation, because the message is already on `orders:processing` by then. If your
ACL is the 2.3 list (`+blmove +rpush +lrem +zadd +zrangebyscore +zrem`), add
`+zremrangebyscore +linsert` (and `+eval` for the scripts) to get the counting.

### Retry with delay

`release()` runs one Lua script that removes the in-flight copy (`LREM`) and, only
if it removed one, then:

- delay `0` → `RPUSH orders <envelope>` (back onto the ready queue immediately);
- delay `> 0` → `ZADD orders:delayed <due-time> <envelope>`.

Because it is one script, a worker that dies cannot leave the message on no list at
all. A worker that has **lost the message** (a reaper already moved it back onto the
queue, so the `LREM` finds nothing) does not add a second copy either: it looks for
the live copy, first in `orders:delayed`, then on `orders`, and **replaces** it with
the new envelope. The failed attempt's `attempts` and back-off are carried onto the
one copy that exists. If no copy with the same body is found (a reaper that
rewrote the body, or the message already finished), the call changes nothing. The
same holds for `deadLetter()`: a worker that has lost the message quarantines the
live copy.

On every `reserve()`, due members of `orders:delayed` (score `<= now`, at most 1000
at a time) are migrated back onto the ready list by another script, each member
removed and pushed in the same step, so a delayed retry is neither lost nor
duplicated across workers.

### Dead-letter

`deadLetter()` removes the in-flight copy (or, failing that, the live copy, as
above) and `RPUSH`-es the annotated envelope onto `orders:failed`, in one script.

## Delivery semantics

At-least-once. A crashed worker leaves its in-flight message on
`orders:processing`. The graceful `SIGTERM` shutdown (finish the in-flight message,
then exit) avoids stranding messages in normal operation; if you run workers that
can be killed mid-job, add a small reaper that moves long-idle
`orders:processing` entries back onto `orders`.

A message is identified by the body it was reserved with. A worker that finishes
after the reaper has moved its message back is not ignored: its retry or
dead-letter replaces the live copy (see above), so `attempts` still climbs and a
message that always fails reaches `maxAttempts` and `orders:failed`, whether or not
the reaper is faster than the handler. Two things are worth knowing when you write
the reaper:

- **Keep its delay above your slowest handler.** Moving a message back while its
  handler is still running makes a second worker run it concurrently, and counts
  that delivery as an attempt.
- **If it rewrites the envelope** (to add `attempts`, say), the late worker's retry
  can no longer find the copy by its old body and is ignored; the reaper's own
  increment is then the only count. Moving the body back unchanged lets both
  count.

With the dispatcher's [deduplication](Idempotent-Consumption.md), a reclaimed copy
that finds the original handler's key still claimed is parked in `orders:delayed`
and re-checked, not discarded; the original handler's later failure then replaces
that parked copy.

### Two paths, two guarantees

The transport uses Lua scripts (`EVAL`) for `release()`, `deadLetter()` and the
delayed-set migration, and falls back to the same steps as separate commands when
the client cannot evaluate scripts:

| | With `EVAL` (Predis, scripting allowed) | Without (adapter lacks `eval`, or the Redis user has no scripting permission) |
| --- | --- | --- |
| Ownership and live-copy replacement | yes | yes, the same rules |
| A crash between the steps | cannot lose the message: each operation is one script | can lose it: the processing entry is removed before the message is re-added |
| Concurrent workers on the delayed set | each member moves exactly once | each member is moved only by the worker whose `ZREM` wins, which is also exactly once |

The fallback is chosen the first time `eval` fails as unavailable (no such method, a
different signature, `NOPERM`/unknown command); any other failure, a lost connection
for instance, is thrown as before. `reserve()`, `ack()` and `publish()` never need
scripting, so a client that implements only the commands listed below keeps
consuming. Run with scripting allowed if you can: it is what makes a crash during a
retry harmless.

## Using phpredis instead

The consume side is written against `Predis\ClientInterface`. If you use the
`ext-redis` (phpredis) extension, either:

- install `predis/predis` (pure PHP, no extension needed — the supported path), or
- wrap phpredis in a thin adapter exposing the same `blmove` / `rpush` / `lrem` /
  `zadd` / `zrangebyscore` / `zrem` methods (plus `zremrangebyscore` and `linsert`
  for the [delivery count](#counting-a-delivery-that-never-finished); without them the
  count is switched off). `eval` is optional: leave it out (let
  the call throw `BadMethodCallException`) and the transport uses the
  [command-by-command path](#two-paths-two-guarantees). If the adapter does provide
  `eval`, it must accept Predis's argument order, `eval($script, $numKeys, ...$keysAndArgs)`
  (phpredis takes `$script, $args, $numKeys`), or throw.

For **publish-only** producers, the SDK's one-method `Transport` is just an
`RPUSH` and is trivial to implement over phpredis directly.

See [The Worker](The-Worker.md) for back-off configuration and
[Retries & Dead-Letters](Retries-and-Dead-Letters.md) for the failed list.
