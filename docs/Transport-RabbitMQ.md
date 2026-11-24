# Transport: RabbitMQ (AMQP)

`InitPHP\Queue\Transport\Amqp\AmqpTransport` runs the queue on RabbitMQ.
Publishing reuses the SDK's `BabelQueue\Transport\AmqpTransport` (durable queue,
persistent message, the contract AMQP properties and headers); consuming adds a
pull-based loop.

**Requires** [`php-amqplib/php-amqplib`](https://packagist.org/packages/php-amqplib/php-amqplib)
and `ext-sockets`.

## Construction

```php
use InitPHP\Queue\Transport\Amqp\AmqpTransport;
use PhpAmqpLib\Connection\AMQPStreamConnection;

$connection = new AMQPStreamConnection('127.0.0.1', 5672, 'guest', 'guest');
$channel    = $connection->channel();

$transport = new AmqpTransport(
    channel:      $channel,
    defaultQueue: 'default',
    failedSuffix: '.failed',
);
```

Close the channel and connection when your producer or worker shuts down.

## Wire mapping

Published messages carry the contract AMQP properties (set by the SDK publisher),
so a non-PHP consumer can route and trace **without decoding the body first**:

| AMQP property / header | Source |
| ---------------------- | ------ |
| `type` | the job URN |
| `correlation_id` | `trace_id` |
| `message_id` | `meta.id` |
| header `x-schema-version` | `meta.schema_version` |
| header `x-source-lang` | `meta.lang` |
| header `x-attempts` | `attempts` |
| `delivery_mode` | persistent (2) |
| `content_type` | `application/json` |

Queues are declared durable (`passive=false, durable=true, exclusive=false,
auto_delete=false`); the body is the canonical envelope JSON.

## How it works

| Operation | AMQP action |
| --------- | ----------- |
| `publish()` | Declare the queue, `basic_publish` the persistent envelope. |
| `reserve()` | Declare the queue, then `basic_get` one message (pull). Returns `null` when empty. |
| `ack()` | `basic_ack` on the delivery tag. |
| `release()` | **Republish** the updated envelope, wait for the broker's confirm if the channel is in confirm mode, then `basic_ack` the original. |
| `deadLetter()` | Publish the annotated envelope to `<queue>.failed`, wait for the confirm likewise, then `basic_ack` the original. |

### Why retries republish

RabbitMQ does not let you mutate a message body in place, and a plain
`basic_nack(requeue=true)` would not persist the incremented `attempts` or the
`dead_letter` block. So a retry/dead-letter **publishes a fresh message**
carrying the updated envelope **and only then acks the original delivery**. This
keeps `attempts` and dead-letter annotations accurate across redeliveries.

The order is deliberate. A worker that dies between the publish and the ack leaves
a duplicate, which RabbitMQ redelivers and the dispatcher's
[deduplication](Idempotent-Consumption.md) recognises; acking first would leave no
message at all. If the publish throws, the original is not acked and RabbitMQ
redelivers it once the channel closes. To also be safe against the broker not
accepting the publish, put the channel in publisher-confirm mode
(`$channel->confirm_select()`). The transport then waits (up to 30 s) for the
confirm before it acks:

- a broker **`nack`** or a **returned** message means the new copy is not on the
  queue (a full queue with `x-overflow: reject-publish`, for example). The original
  is requeued with `basic_nack(requeue: true)`, not acked, and a `QueueException`
  is thrown; the worker logs `process.failed` and the message is tried again. Your
  own `set_nack_handler()` and `set_return_listener()` callbacks still run: the
  transport wraps them for the wait and puts them back.
- a **timeout** leaves the original unacked (it comes back when the channel
  closes) and throws. The channel's pending confirms are then in an unknown state,
  so restart the worker; until then every release waits for the lost confirm again.

Without confirm mode that wait returns at once and a refused publish goes
unnoticed, so enable confirms if the queue can refuse messages.

The same path puts back a message that is **waiting for another handler's
in-flight key** (a `release` with a delay). It lands at the tail of the queue, so
other messages are not blocked behind it, and the worker pauses briefly after each
such release because RabbitMQ ignores the delay.

### Delay caveat

`reserve()` is a pull (`basic_get`), so the worker sleeps for
`WorkerOptions::$sleepWhenEmpty` when the queue is empty. **Per-message back-off
delay is not applied on RabbitMQ** — retries are republished immediately — because
native delayed delivery requires the
[delayed-message-exchange plugin](https://github.com/rabbitmq/rabbitmq-delayed-message-exchange).
If you need back-off windows, install that plugin, or use the
[PDO](Transport-PDO.md)/[Redis](Transport-Redis.md) transport where delays are honoured
natively.

## Operational notes

- **One message at a time.** The worker pulls a single message per iteration, so
  there is no unbounded prefetch to manage.
- **Dead-letter queue.** `<queue>.failed` is a normal durable queue. Consume it
  with the same `AmqpTransport` pointed at that name to inspect or replay messages.
- **Connections are not thread-safe.** Use one connection/channel per worker
  process.

See [The Worker](The-Worker.md) and [Retries & Dead-Letters](Retries-and-Dead-Letters.md)
for the policy that drives these calls.
