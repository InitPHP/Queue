# RabbitMQ (AMQP) transport

`InitPHP\Queue\Transport\Amqp\AmqpTransport` runs the queue on RabbitMQ.
Publishing reuses the SDK's `BabelQueue\Transport\AmqpTransport` (durable queue,
persistent message, the contract AMQP properties and headers); consuming adds a
pull-based loop.

Requires [`php-amqplib/php-amqplib`](https://packagist.org/packages/php-amqplib/php-amqplib)
(and `ext-sockets`).

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

Close the connection and channel when your producer or worker shuts down.

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
| `reserve()` | Declare the queue, then `basic_get` one message (pull). Returns `null` when the queue is empty. |
| `ack()` | `basic_ack` on the delivery tag. |
| `release()` | `basic_ack` the original, then **republish** the updated envelope. |
| `deadLetter()` | `basic_ack` the original, then publish the annotated envelope to `<queue>.failed`. |

### Why retries republish

RabbitMQ does not let you mutate a message body in place, and a plain
`basic_nack(requeue=true)` would not persist the incremented `attempts` or the
`dead_letter` block. So a retry/dead-letter **acks the original delivery and
publishes a fresh message** carrying the updated envelope. This keeps `attempts`
and dead-letter annotations accurate across redeliveries.

### Delay caveat

`reserve()` is a pull (`basic_get`), so the worker sleeps for
`WorkerOptions::$sleepWhenEmpty` when the queue is empty. **Per-message back-off
delay is not applied on RabbitMQ** — retries are republished immediately — because
native delayed delivery requires the
[delayed-message-exchange plugin](https://github.com/rabbitmq/rabbitmq-delayed-message-exchange).
If you need back-off windows on RabbitMQ, install that plugin and route the failed
exchange accordingly, or use the PDO/Redis transport where delays are honoured
natively.

## Operational notes

- **One message at a time.** The worker pulls a single message per iteration, so
  there is no unbounded prefetch to manage.
- **Dead-letter queue.** `<queue>.failed` is a normal durable queue. Consume it
  with the same `AmqpTransport` pointed at that name to inspect or replay messages.
- **Connections are not thread-safe.** Use one connection/channel per worker
  process.

See [The worker & retries](../worker-and-retries.md) and
[Dead-letter handling](../dead-letter.md) for the policy that drives these calls.
