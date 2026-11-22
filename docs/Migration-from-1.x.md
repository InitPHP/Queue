# Migration from 1.x

`2.0` is a breaking rewrite. `1.x` was a self-contained, **PHP-only** queue that
serialised the producing class name into the message
(`{"jobClass": "App\\Jobs\\MailJob", …}`) and did `new $jobClass(...)` on the other
end. `2.0` is the framework-less runtime for the **polyglot BabelQueue envelope**,
routing on a stable URN instead — so the same queue is readable by Go, Python,
Node and any other BabelQueue SDK, and renaming a class no longer orphans messages
already queued.

## Requirements

| | 1.x | 2.0 |
| --- | --- | --- |
| PHP | `>= 7.4` | `^8.2` |
| Core dependency | none | `babelqueue/php-sdk ^1.0` |

## What was removed

| 1.x symbol | Replacement |
| ---------- | ----------- |
| `InitPHP\Queue\Job` (abstract base) | A class implementing `InitPHP\Queue\Contracts\Handler` |
| `InitPHP\Queue\Interfaces\JobInterface` | `InitPHP\Queue\Contracts\Handler` |
| `InitPHP\Queue\Interfaces\AdapterInterface` | `InitPHP\Queue\Contracts\ConsumerTransport` (+ the SDK's `Transport`) |
| `InitPHP\Queue\Adapters\PDOAdapter` | `InitPHP\Queue\Transport\Pdo\PdoTransport` |
| `InitPHP\Queue\Adapters\RabbitMQAdapter` | `InitPHP\Queue\Transport\Amqp\AmqpTransport` |
| `InitPHP\Queue\Exceptions\JobException`, `JobInvalidArgumentException` | `InitPHP\Queue\Exceptions\QueueException` / `ConfigurationException` |

## Concept mapping

| 1.x | 2.0 |
| --- | --- |
| `class MailJob extends Job { handle(): bool }` | `final class SendMail implements Handler { handle(InboundMessage $m): void }` |
| The job class **is** the routing identity | A stable **URN** is the identity; the class is free to change |
| `$job->push(['to' => ...])` | `$producer->send('urn:babel:mail:requested', ['to' => ...])` |
| `$adapter->handle($channel, $queue)` | `$worker->run($queue)` |
| `protected string $channel` / `$queue` | The queue name passed to the producer/worker |
| `ack()` / `nack()` inside the job | The worker acks on success and applies the retry/dead-letter policy on failure |
| Return `true`/`false` from `handle()` | **Return** to ack; **throw** to fail |

## Porting a job

**1.x:**

```php
use InitPHP\Queue\Job;

class MailJob extends Job
{
    protected string $channel = 'mailChannel';
    protected string $queue   = 'mailQueue';

    public function handle(): bool
    {
        $p = $this->getPayload();
        return mail($p['to'], $p['subject']);
    }
}

$job = new MailJob($adapter);
$job->push(['to' => 'a@b.c', 'subject' => 'Hi']);   // produce
$adapter->handle('mailChannel', 'mailQueue');        // consume
```

**2.0:**

```php
use BabelQueue\Contracts\InboundMessage;
use InitPHP\Queue\Contracts\Handler;

final class SendMail implements Handler
{
    public function handle(InboundMessage $message): void
    {
        $p = $message->getData();
        if (! mail($p['to'], $p['subject'])) {
            throw new \RuntimeException('mail() failed');   // -> retried, then dead-lettered
        }
    }
}

// produce
$producer->send('urn:babel:mail:requested', ['to' => 'a@b.c', 'subject' => 'Hi'], 'mail');

// consume
$handlers = (new HandlerMap())->register('urn:babel:mail:requested', SendMail::class);
(new Worker($transport, new Dispatcher($handlers), new WorkerOptions()))->run('mail');
```

## Database schema changed

`1.x` stored a column per field and moved failed rows with a column-position
`INSERT … SELECT *`. `2.0` stores the full envelope as JSON and uses a portable,
abandonment-safe reservation. The old `queue` / `queue_failed` tables are **not**
compatible — create the new `jobs` / `jobs_failed` tables (see
[Transport: PDO](Transport-PDO.md)).

## In-flight messages

Because the wire format changed (class name → URN envelope), `1.x` and `2.0`
messages are not interchangeable on the same queue. Either:

- **Drain** `1.x` queues with the old code before deploying `2.0`, or
- **Migrate** with a short script that maps the old `jobClass` + `payload` to a URN
  + `data` and re-publishes via the new `Producer`.

```php
// one-off migration sketch
foreach ($oldRows as $row) {
    $old = json_decode($row->payload, true);          // {'jobClass': ..., 'payload': ...}
    $urn = $classToUrn[$old['jobClass']];              // your mapping
    $producer->send($urn, $old['payload'], $row->queue);
}
```

See also the repository's `UPGRADE-2.0.md`.
