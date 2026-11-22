# Transports — Overview

A **transport** is a binding to one broker. Every bundled transport implements
**both** sides of the contract, so a single object both produces and consumes:

- **Publish** — `BabelQueue\Contracts\Transport::publish(string $payload, ?string $queue)`
- **Consume** — `InitPHP\Queue\Contracts\ConsumerTransport`:
  - `reserve(string $queue, float $timeout = 5.0): ?ReceivedMessage`
  - `ack(ReceivedMessage $message): void`
  - `release(ReceivedMessage $message, string $payload, int $delaySeconds = 0): void`
  - `deadLetter(ReceivedMessage $message, string $payload): void`

## Choosing one

| | [PDO (Database)](Transport-PDO.md) | [Redis](Transport-Redis.md) | [RabbitMQ](Transport-RabbitMQ.md) |
| --- | --- | --- | --- |
| Extra infrastructure | none (reuse your DB) | a Redis server | a RabbitMQ broker |
| Client | `ext-pdo` | `predis/predis` | `php-amqplib/php-amqplib` |
| Throughput | modest | high | high |
| Native delayed retries | ✅ | ✅ | ⚠️ needs a plugin |
| Inspect/replay failures | SQL on `*_failed` table | `LRANGE <q>:failed` | consume `<q>.failed` |
| Best when | you already have a database and want zero new infra | you want speed and already run Redis | you want a real message broker / routing |

All three are wire-compatible with every BabelQueue SDK, so the choice is
operational — a Go service can produce to RabbitMQ while your PHP worker consumes,
and so on.

## Swapping transports

Only the construction changes; producer, dispatcher, worker and handlers are
identical:

```php
use InitPHP\Queue\Transport\Pdo\PdoTransport;
use InitPHP\Queue\Transport\Redis\RedisTransport;
use InitPHP\Queue\Transport\Amqp\AmqpTransport;

$transport = new PdoTransport(new PDO('mysql:host=127.0.0.1;dbname=app', 'u', 'p'));
// or
$transport = new RedisTransport(new Predis\Client('tcp://127.0.0.1:6379'));
// or
$transport = new AmqpTransport((new PhpAmqpLib\Connection\AMQPStreamConnection('127.0.0.1', 5672, 'guest', 'guest'))->channel());

$producer = new InitPHP\Queue\Producer\Producer($transport, 'emails');
$worker   = new InitPHP\Queue\Consumer\Worker($transport, $dispatcher, $options);
```

## Writing your own transport

To support another broker, implement the SDK `Transport` (publish) and this
package's `ConsumerTransport` (consume). The contract is small — reserve a message
exclusively, ack it, release it for retry (with an optional delay), and move it to
a dead-letter destination. Use the bundled transports as a reference; the [Redis
one](Transport-Redis.md) is the most compact.

## Reference

- [PDO (Database) transport](Transport-PDO.md)
- [Redis transport](Transport-Redis.md)
- [RabbitMQ (AMQP) transport](Transport-RabbitMQ.md)
