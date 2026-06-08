# Migrating from 1.x

`2.0` is a breaking rewrite. `1.x` was a self-contained, **PHP-only** queue that
serialised the producing class name into the message (`{"jobClass": "App\\Jobs\\MailJob", …}`)
and did `new $jobClass(...)` on the other end. `2.0` is the framework-less runtime
for the **polyglot BabelQueue envelope**, routing on a stable URN instead — so the
same queue is readable by Go, Python, Node and any other BabelQueue SDK, and
renaming a class no longer orphans messages already on the queue.

## Requirements

| | 1.x | 2.0 |
| --- | --- | --- |
| PHP | `>= 7.4` | `^8.2` |
| Core dependency | none | `babelqueue/php-sdk ^1.0` |

## What was removed

The entire `1.x` surface is gone:

- `InitPHP\Queue\Job` (abstract base) and `InitPHP\Queue\Interfaces\JobInterface`
- `InitPHP\Queue\Interfaces\AdapterInterface`
- `InitPHP\Queue\Adapters\PDOAdapter` and `RabbitMQAdapter`
- `InitPHP\Queue\Exceptions\JobException`, `JobInvalidArgumentException`

## Concept mapping

| 1.x | 2.0 |
| --- | --- |
| `class MailJob extends Job { handle(): bool }` | A class implementing `InitPHP\Queue\Contracts\Handler` — `handle(InboundMessage $message): void` |
| The job class **is** the routing identity | A stable **URN** (`urn:babel:mail:requested`) is the identity; the handler class is free to change |
| `$job->push(['to' => ...])` | `$producer->send('urn:babel:mail:requested', ['to' => ...])` |
| `$adapter->handle($channel, $queue)` | `$worker->run($queue)` |
| `protected string $channel` / `$queue` | The queue name passed to the producer/worker |
| `new PDOAdapter($pdo, 'queue')` | `new PdoTransport($pdo, 'jobs')` |
| `new RabbitMQAdapter($host, $port, $user, $pass)` | `new AmqpTransport((new AMQPStreamConnection(...))->channel())` |
| `ack()` / `nack()` called inside the job | The worker acks on success and applies the retry/dead-letter policy on failure |
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

// produce
$job = new MailJob($adapter);
$job->push(['to' => 'a@b.c', 'subject' => 'Hi']);

// consume
$adapter->handle('mailChannel', 'mailQueue');
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
            throw new \RuntimeException('mail() failed');  // -> retried, then dead-lettered
        }
    }
}

// produce
$producer->send('urn:babel:mail:requested', ['to' => 'a@b.c', 'subject' => 'Hi'], 'mail');

// consume
$handlers = (new HandlerMap())->register('urn:babel:mail:requested', SendMail::class);
(new Worker($transport, new Dispatcher($handlers), new WorkerOptions()))->run('mail');
```

## Database (PDO) schema changed

`1.x` stored a column per field and moved failed rows with a column-position
`INSERT … SELECT *`. `2.0` stores the full envelope as JSON and uses a portable,
abandonment-safe reservation. The old `queue` / `queue_failed` tables are not
compatible — create the new `jobs` / `jobs_failed` tables (see
[the PDO transport guide](transports/pdo.md)). Drain the old queue with a `1.x`
worker before switching, or write a one-off script that reads old rows and
re-publishes them through a `2.0` `Producer`.

## In-flight messages

Because the wire format changed (class name → URN envelope), `1.x` and `2.0`
messages are not interchangeable on the same queue. Drain `1.x` queues with the
old code before deploying `2.0`, or migrate them with a short script that maps the
old `jobClass`/`payload` to a URN + `data` and re-publishes via the new `Producer`.
