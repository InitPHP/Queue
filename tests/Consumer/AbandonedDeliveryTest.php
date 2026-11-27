<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Consumer;

use BabelQueue\Codec\EnvelopeCodec;
use InitPHP\Queue\Consumer\Dispatcher;
use InitPHP\Queue\Consumer\IdempotencyOptions;
use InitPHP\Queue\Consumer\InMemoryIdempotencyStore;
use InitPHP\Queue\Consumer\Worker;
use InitPHP\Queue\Consumer\WorkerOptions;
use InitPHP\Queue\Contracts\ConsumerTransport;
use InitPHP\Queue\Message\ReceivedMessage;
use InitPHP\Queue\Routing\HandlerMap;
use InitPHP\Queue\Tests\Support\InMemoryRedis;
use InitPHP\Queue\Tests\Support\OutcomeDroppingTransport;
use InitPHP\Queue\Tests\Support\RecordingHandler;
use InitPHP\Queue\Transport\Pdo\PdoTransport;
use InitPHP\Queue\Transport\Redis\RedisTransport;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * A handler that kills its process (OOM, `exit`, SIGKILL) never reports a failure.
 * The message comes back once the transport's reservation lapses; that delivery
 * must count as an attempt so `maxAttempts` still ends the message.
 */
final class AbandonedDeliveryTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const URN = 'urn:babel:orders:created';

    private function payload(int $attempts = 0): string
    {
        $envelope = EnvelopeCodec::make(self::URN, ['order_id' => 1], 'orders');
        $envelope['meta']['id'] = 'msg-1';
        $envelope['attempts'] = $attempts;

        return EnvelopeCodec::encode($envelope);
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function rows(PDO $pdo, string $table): int
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    public function test_pdo_a_message_whose_handler_keeps_dying_reaches_the_dead_letter_table(): void
    {
        $pdo = $this->pdo();
        $inner = new PdoTransport($pdo, 'jobs', null, 'default', 0); // retryAfter 0: a lapsed reservation is at once reclaimable
        $inner->createSchema();
        $inner->publish($this->payload(), 'orders');

        $transport = new OutcomeDroppingTransport($inner);
        $handler = new RecordingHandler();
        $worker = new Worker(
            $transport,
            new Dispatcher((new HandlerMap())->register(self::URN, $handler)),
            new WorkerOptions(maxAttempts: 3, sleepWhenEmpty: 0.0),
        );

        // Delivery 1 and the two after it: the handler runs, then the process dies.
        foreach ([0, 1, 2] as $expectedAttempts) {
            self::assertTrue($worker->runOnce('orders'));
            self::assertSame($expectedAttempts, (int) $pdo->query('SELECT attempts FROM jobs')->fetchColumn());
            self::assertSame($expectedAttempts, EnvelopeCodec::decode((string) $pdo->query('SELECT payload FROM jobs')->fetchColumn())['attempts']);
        }
        self::assertCount(3, $handler->handled);

        // The reaper-equivalent hands it out a fourth time: three deliveries were lost.
        $transport->dropOutcomes = false;
        self::assertTrue($worker->runOnce('orders'));

        self::assertCount(3, $handler->handled, 'the handler is not run again');
        self::assertSame(0, $this->rows($pdo, 'jobs'));
        $failed = $pdo->query('SELECT attempts, reason FROM jobs_failed')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(1, $failed);
        self::assertSame(3, (int) $failed[0]['attempts']);
        self::assertSame('failed', $failed[0]['reason']);
    }

    public function test_pdo_the_counted_attempt_is_persisted_when_the_row_is_taken_over(): void
    {
        $pdo = $this->pdo();
        $transport = new PdoTransport($pdo, 'jobs', null, 'default', 0);
        $transport->createSchema();
        $transport->publish($this->payload(), 'orders');

        $first = $transport->reserve('orders');
        self::assertNotNull($first);
        self::assertFalse($first->wasAbandoned());
        self::assertSame(0, $first->attempts());

        $second = $transport->reserve('orders'); // the first delivery never finished
        self::assertNotNull($second);
        self::assertTrue($second->wasAbandoned());
        self::assertSame(1, $second->attempts());
        self::assertSame(1, (int) $pdo->query('SELECT attempts FROM jobs')->fetchColumn(), 'stored with the take-over');
        self::assertSame(1, EnvelopeCodec::decode((string) $pdo->query('SELECT payload FROM jobs')->fetchColumn())['attempts']);
    }

    public function test_pdo_a_failure_after_a_counted_delivery_counts_once_more_and_not_twice(): void
    {
        $pdo = $this->pdo();
        $transport = new PdoTransport($pdo, 'jobs', null, 'default', 0);
        $transport->createSchema();
        $transport->publish($this->payload(), 'orders');
        $transport->reserve('orders'); // lost

        $throwing = new class implements \InitPHP\Queue\Contracts\Handler {
            public function handle(\BabelQueue\Contracts\InboundMessage $message): void
            {
                throw new \RuntimeException('boom');
            }
        };
        $worker = new Worker(
            $transport,
            new Dispatcher((new HandlerMap())->register(self::URN, $throwing)),
            new WorkerOptions(maxAttempts: 5, backoff: [0], sleepWhenEmpty: 0.0),
        );

        $worker->runOnce('orders'); // the take-over counts 1, this failure makes 2

        self::assertSame(2, (int) $pdo->query('SELECT attempts FROM jobs')->fetchColumn());
    }

    public function test_pdo_a_released_message_is_not_counted_when_it_is_taken_again(): void
    {
        $pdo = $this->pdo();
        $transport = new PdoTransport($pdo, 'jobs', null, 'default', 0);
        $transport->createSchema();
        $transport->publish($this->payload(), 'orders');

        $message = $transport->reserve('orders');
        self::assertNotNull($message);
        $transport->release($message, $message->rawBody(), 0);

        $again = $transport->reserve('orders');
        self::assertNotNull($again);
        self::assertFalse($again->wasAbandoned());
        self::assertSame(0, $again->attempts());
    }

    public function test_pdo_waiting_on_an_in_flight_key_uses_no_attempts_however_often_it_repeats(): void
    {
        $pdo = $this->pdo();
        $transport = new PdoTransport($pdo, 'jobs', null, 'default', 0);
        $transport->createSchema();
        $transport->publish($this->payload(), 'orders');

        $store = new InMemoryIdempotencyStore();
        $store->claim('bq:idemp:msg-1', 300); // another worker is inside its handler
        $handler = new RecordingHandler();
        $worker = new Worker(
            $transport,
            new Dispatcher(
                (new HandlerMap())->register(self::URN, $handler),
                idempotency: new IdempotencyOptions($store, inFlightDelaySeconds: 5),
            ),
            new WorkerOptions(maxAttempts: 2, sleepWhenEmpty: 0.0),
        );

        for ($wait = 0; $wait < 8; ++$wait) { // well past maxAttempts
            $pdo->exec("UPDATE jobs SET available_at = '2000-01-01 00:00:00'"); // the delay has passed
            self::assertTrue($worker->runOnce('orders'));
        }

        self::assertSame([], $handler->handled);
        self::assertSame(1, $this->rows($pdo, 'jobs'), 'the message is still queued');
        self::assertSame(0, $this->rows($pdo, 'jobs_failed'), 'waiting never dead-letters it');
        self::assertSame(0, (int) $pdo->query('SELECT attempts FROM jobs')->fetchColumn());
    }

    public function test_pdo_a_lost_delivery_followed_by_waiting_counts_exactly_one_attempt(): void
    {
        $pdo = $this->pdo();
        $transport = new PdoTransport($pdo, 'jobs', null, 'default', 0);
        $transport->createSchema();
        $transport->publish($this->payload(), 'orders');
        $transport->reserve('orders'); // the first worker died in the handler

        $store = new InMemoryIdempotencyStore();
        $store->claim('bq:idemp:msg-1', 300); // its claim has not lapsed yet
        $worker = new Worker(
            $transport,
            new Dispatcher(
                (new HandlerMap())->register(self::URN, new RecordingHandler()),
                idempotency: new IdempotencyOptions($store, inFlightDelaySeconds: 5),
            ),
            new WorkerOptions(maxAttempts: 3, sleepWhenEmpty: 0.0),
        );

        for ($wait = 0; $wait < 6; ++$wait) {
            $pdo->exec("UPDATE jobs SET available_at = '2000-01-01 00:00:00'");
            $worker->runOnce('orders');
        }

        self::assertSame(1, (int) $pdo->query('SELECT attempts FROM jobs')->fetchColumn());
        self::assertSame(0, $this->rows($pdo, 'jobs_failed'));
    }

    public function test_pdo_two_workers_racing_for_a_lapsed_row_count_it_once(): void
    {
        $pdo = $this->pdo();
        $a = new PdoTransport($pdo, 'jobs', null, 'default', 0);
        $a->createSchema();
        $b = new PdoTransport($pdo, 'jobs', null, 'default', 0);
        $a->publish($this->payload(), 'orders');
        $a->reserve('orders'); // lost

        // Both read the lapsed stamp; the conditional UPDATE lets only one win.
        $winner = $b->reserve('orders');

        self::assertNotNull($winner);
        self::assertSame(1, (int) $pdo->query('SELECT attempts FROM jobs')->fetchColumn());
    }

    public function test_pdo_a_row_from_before_this_version_is_counted_like_any_other(): void
    {
        // A 2.3 worker died holding a row: reserved, attempts 1 already recorded.
        $pdo = $this->pdo();
        $transport = new PdoTransport($pdo, 'jobs', null, 'default', 0);
        $transport->createSchema();
        $transport->publish($this->payload(1), 'orders');
        $pdo->exec("UPDATE jobs SET reserved_at = '2000-01-01 00:00:00'");

        $message = $transport->reserve('orders');

        self::assertNotNull($message);
        self::assertSame(2, $message->attempts());
        self::assertTrue($message->wasAbandoned());
    }

    public function test_redis_a_message_whose_handler_keeps_dying_reaches_the_failed_list(): void
    {
        $redis = new InMemoryRedis();
        $inner = new RedisTransport($redis, 'default');
        $inner->publish($this->payload(), 'orders');

        $transport = new OutcomeDroppingTransport($inner);
        $handler = new RecordingHandler();
        $worker = new Worker(
            $transport,
            new Dispatcher((new HandlerMap())->register(self::URN, $handler)),
            new WorkerOptions(maxAttempts: 3, sleepWhenEmpty: 0.0, reserveTimeout: 0.0),
        );

        foreach ([0, 1, 2] as $expectedAttempts) {
            self::assertTrue($worker->runOnce('orders'));
            self::assertCount(1, $redis->list('orders:processing'), 'the handler died, the entry is left');
            self::assertSame($expectedAttempts, EnvelopeCodec::decode($redis->list('orders:processing')[0])['attempts'], 'the processing entry carries the count');
            $this->reap($redis, 'orders');
        }
        self::assertCount(3, $handler->handled);

        $transport->dropOutcomes = false;
        self::assertTrue($worker->runOnce('orders'));

        self::assertCount(3, $handler->handled, 'the handler is not run again');
        self::assertSame([], $redis->list('orders:processing'));
        self::assertSame([], $redis->list('orders'));
        self::assertCount(1, $redis->list('orders:failed'));
        self::assertSame(3, EnvelopeCodec::decode($redis->list('orders:failed')[0])['attempts']);
        self::assertSame(0, $redis->zcard('orders:started'), 'no delivery record is left behind');
    }

    public function test_redis_a_finished_message_leaves_nothing_to_count(): void
    {
        $redis = new InMemoryRedis();
        $transport = new RedisTransport($redis, 'default');
        $transport->publish($this->payload(), 'orders');

        $message = $transport->reserve('orders', 1.0);
        self::assertNotNull($message);
        self::assertSame(1, $redis->zcard('orders:started'));
        $transport->release($message, $message->rawBody(), 0); // put back verbatim, e.g. an in-flight wait

        $again = $transport->reserve('orders', 1.0);
        self::assertNotNull($again);
        self::assertFalse($again->wasAbandoned());
        self::assertSame(0, $again->attempts());

        $transport->ack($again);
        self::assertSame(0, $redis->zcard('orders:started'));
    }

    public function test_redis_without_eval_counts_the_same_way(): void
    {
        $redis = new InMemoryRedis(scripting: false);
        $transport = new RedisTransport($redis, 'default');
        $transport->publish($this->payload(), 'orders');

        $transport->reserve('orders', 1.0);
        $this->reap($redis, 'orders');
        $second = $transport->reserve('orders', 1.0);

        self::assertNotNull($second);
        self::assertTrue($second->wasAbandoned());
        self::assertSame(1, $second->attempts());
        self::assertSame(1, EnvelopeCodec::decode($redis->list('orders:processing')[0])['attempts']);
        self::assertSame(1, $redis->zcard('orders:started'));
    }

    public function test_redis_a_client_without_the_sorted_set_commands_keeps_consuming(): void
    {
        $client = Mockery::mock(\Predis\ClientInterface::class);
        $client->shouldReceive('eval')->andReturn(0);
        $client->shouldReceive('zremrangebyscore')->andThrow(new \BadMethodCallException('no zremrangebyscore'));
        $client->shouldReceive('blmove')->andReturn($this->payload());

        $message = (new RedisTransport($client, 'default'))->reserve('orders', 1.0);

        self::assertNotNull($message);
        self::assertFalse($message->wasAbandoned());
        self::assertSame(0, $message->attempts());
    }

    public function test_the_worker_quarantines_an_abandoned_message_that_is_out_of_attempts(): void
    {
        $message = new ReceivedMessage('orders', $this->payload(3), EnvelopeCodec::decode($this->payload(3)), 'r', true);
        $transport = Mockery::mock(ConsumerTransport::class);
        $transport->shouldReceive('reserve')->once()->andReturn($message);
        $transport->shouldReceive('deadLetter')->once()->with($message, Mockery::on(
            static fn(string $payload): bool => EnvelopeCodec::decode($payload)['dead_letter']['reason'] === 'failed'
                && EnvelopeCodec::decode($payload)['attempts'] === 3,
        ));
        $handler = new RecordingHandler();
        $worker = new Worker(
            $transport,
            new Dispatcher((new HandlerMap())->register(self::URN, $handler)),
            new WorkerOptions(maxAttempts: 3, sleepWhenEmpty: 0.0),
        );

        self::assertTrue($worker->runOnce('orders'));
        self::assertSame([], $handler->handled);
    }

    public function test_a_message_that_was_not_abandoned_runs_even_with_a_high_attempt_count(): void
    {
        // e.g. a dead-letter message put back by hand with `attempts` preserved.
        $message = new ReceivedMessage('orders', $this->payload(3), EnvelopeCodec::decode($this->payload(3)), 'r');
        $transport = Mockery::mock(ConsumerTransport::class);
        $transport->shouldReceive('reserve')->once()->andReturn($message);
        $transport->shouldReceive('ack')->once()->with($message);
        $handler = new RecordingHandler();
        $worker = new Worker(
            $transport,
            new Dispatcher((new HandlerMap())->register(self::URN, $handler)),
            new WorkerOptions(maxAttempts: 3, sleepWhenEmpty: 0.0),
        );

        $worker->runOnce('orders');

        self::assertCount(1, $handler->handled);
    }

    public function test_an_abandoned_message_with_attempts_left_runs_normally(): void
    {
        $message = new ReceivedMessage('orders', $this->payload(2), EnvelopeCodec::decode($this->payload(2)), 'r', true);
        $transport = Mockery::mock(ConsumerTransport::class);
        $transport->shouldReceive('reserve')->once()->andReturn($message);
        $transport->shouldReceive('ack')->once()->with($message);
        $handler = new RecordingHandler();
        $worker = new Worker(
            $transport,
            new Dispatcher((new HandlerMap())->register(self::URN, $handler)),
            new WorkerOptions(maxAttempts: 3, sleepWhenEmpty: 0.0),
        );

        $worker->runOnce('orders');

        self::assertCount(1, $handler->handled);
    }

    /** What a user's reaper does: put every processing entry back on the queue. */
    private function reap(InMemoryRedis $redis, string $queue): void
    {
        foreach ($redis->list($queue . ':processing') as $body) {
            $redis->lrem($queue . ':processing', 1, $body);
            $redis->rpush($queue, [$body]);
        }
    }
}
