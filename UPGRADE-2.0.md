# Upgrading from 1.x to 2.0

`2.0` is a breaking rewrite. The package moved from a bespoke, PHP-only queue
to **the framework-less runtime for the BabelQueue polyglot envelope**. This
guide maps every `1.x` concept to its `2.0` replacement.

## Why it changed

In `1.x` a job was serialised as `{"jobClass": "App\\Jobs\\MailJob", "payload": …}`
— the consumer did `new $jobClass(...)`. That tied every message to a PHP class
name: a Go or Python service could not consume it, and renaming the class
orphaned any failed job already on the queue. `2.0` serialises the
[BabelQueue envelope](https://babelqueue.com) instead, routing on a stable
**URN** string so the same queue is readable by every language.

## Requirements

| | 1.x | 2.0 |
| --- | --- | --- |
| PHP | `>=7.4` | `^8.2` |
| Core dependency | none | `babelqueue/php-sdk ^1.0` |

## Concept mapping

| 1.x | 2.0 |
| --- | --- |
| `class MailJob extends InitPHP\Queue\Job` with `handle(): bool` | A handler class implementing `InitPHP\Queue\Contracts\Handler` (`handle(InboundMessage $message): void`) |
| `$job->push([...])` | `Producer::dispatch($urn, $data)` (or `dispatch($polyglotJob)`) |
| `$adapter->handle($channel, $queue)` | `Worker::run($queue)` |
| `protected string $channel` / `$queue` | The logical queue name passed to the producer/worker; routing identity is the URN |
| `PDOAdapter` | `InitPHP\Queue\Transport\Pdo\PdoTransport` |
| `RabbitMQAdapter` | `InitPHP\Queue\Transport\Amqp\AmqpConsumerTransport` (+ the SDK's `AmqpTransport` to publish) |
| `ack()` / `nack()` inside the job | The worker acks on success and applies the retry / dead-letter policy on failure |

> The concrete `2.0` class names are finalised alongside the implementation; this
> table reflects the intended shape and will be kept in sync with the code.

## Database (PDO) schema

The `2.0` PDO transport stores the full canonical envelope as JSON rather than a
column per field, so it no longer depends on column order. The new schema and a
migration note ship in [`docs/transports/pdo.md`](docs/transports/pdo.md).
