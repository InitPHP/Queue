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

`release()` removes the in-flight copy, then:

- delay `0` → `RPUSH orders <envelope>` (back onto the ready queue immediately);
- delay `> 0` → `ZADD orders:delayed <due-time> <envelope>`.

On every `reserve()`, due members of `orders:delayed` (score `<= now`) are migrated
back onto the ready list — each re-enqueued only by the worker that wins its
`ZREM`, so a delayed retry is never duplicated across workers.

### Dead-letter

`deadLetter()` removes the in-flight copy and `RPUSH`-es the annotated envelope
onto `orders:failed`.

## Delivery semantics

At-least-once. A crashed worker leaves its in-flight message on
`orders:processing`. The graceful `SIGTERM` shutdown (finish the in-flight message,
then exit) avoids stranding messages in normal operation; if you run workers that
can be killed mid-job, add a small reaper that moves long-idle
`orders:processing` entries back onto `orders`.

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
