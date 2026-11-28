<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Transport;

use BabelQueue\Codec\EnvelopeCodec;
use InitPHP\Queue\Consumer\Dispatcher;
use InitPHP\Queue\Consumer\Worker;
use InitPHP\Queue\Consumer\WorkerOptions;
use InitPHP\Queue\Message\ReceivedMessage;
use InitPHP\Queue\Routing\HandlerMap;
use InitPHP\Queue\Tests\Support\InMemoryRedis;
use InitPHP\Queue\Transport\Redis\RedisTransport;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Predis\Response\ServerException;
use RuntimeException;

final class RedisTransportTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private InMemoryRedis $redis;

    private RedisTransport $transport;

    protected function setUp(): void
    {
        $this->redis = new InMemoryRedis();
        $this->transport = new RedisTransport($this->redis, 'default');
    }

    private function envelope(string $urn = 'urn:babel:orders:created'): string
    {
        return EnvelopeCodec::encode(EnvelopeCodec::make($urn, ['order_id' => 1], 'orders'));
    }

    public function test_publish_rpushes_onto_the_queue_list(): void
    {
        $payload = $this->envelope();
        $this->transport->publish($payload, 'orders');

        self::assertSame([$payload], $this->redis->list('orders'));
    }

    public function test_reserve_moves_the_message_to_the_processing_list(): void
    {
        $payload = $this->envelope();
        $this->transport->publish($payload, 'orders');

        $message = $this->transport->reserve('orders', 1.0);

        self::assertInstanceOf(ReceivedMessage::class, $message);
        self::assertSame('urn:babel:orders:created', $message->getUrn());
        self::assertSame($payload, $message->receipt());
        self::assertSame([], $this->redis->list('orders'));
        self::assertSame([$payload], $this->redis->list('orders:processing'));
    }

    public function test_reserve_returns_null_when_empty(): void
    {
        self::assertNull($this->transport->reserve('orders', 1.0));
    }

    public function test_ack_removes_the_message_from_processing(): void
    {
        $this->transport->publish($this->envelope(), 'orders');
        $message = $this->transport->reserve('orders', 1.0);
        self::assertNotNull($message);

        $this->transport->ack($message);

        self::assertSame([], $this->redis->list('orders:processing'));
    }

    public function test_release_without_delay_requeues_immediately(): void
    {
        $this->transport->publish($this->envelope(), 'orders');
        $message = $this->transport->reserve('orders', 1.0);
        self::assertNotNull($message);

        $this->transport->release($message, $message->rawBody(), 0);

        self::assertSame([], $this->redis->list('orders:processing'));
        self::assertSame([$message->rawBody()], $this->redis->list('orders'));
    }

    public function test_release_with_delay_parks_the_message_in_the_delayed_set(): void
    {
        $this->transport->publish($this->envelope(), 'orders');
        $message = $this->transport->reserve('orders', 1.0);
        self::assertNotNull($message);

        $this->transport->release($message, $message->rawBody(), 3600);

        self::assertSame([], $this->redis->list('orders:processing'));
        self::assertSame([], $this->redis->list('orders'));
        self::assertSame(1, $this->redis->zcard('orders:delayed'));
    }

    public function test_due_delayed_messages_are_migrated_on_reserve(): void
    {
        $payload = $this->envelope();
        // A delayed message whose time has already passed.
        $this->redis->zadd('orders:delayed', [$payload => time() - 5]);

        $message = $this->transport->reserve('orders', 1.0);

        self::assertNotNull($message);
        self::assertSame('urn:babel:orders:created', $message->getUrn());
        self::assertSame(0, $this->redis->zcard('orders:delayed'));
    }

    public function test_dead_letter_pushes_onto_the_failed_list(): void
    {
        $this->transport->publish($this->envelope(), 'orders');
        $message = $this->transport->reserve('orders', 1.0);
        self::assertNotNull($message);

        $this->transport->deadLetter($message, $message->rawBody());

        self::assertSame([], $this->redis->list('orders:processing'));
        self::assertSame([$message->rawBody()], $this->redis->list('orders:failed'));
    }

    /**
     * Messages the transport still holds anywhere, whichever list they are on.
     */
    private function copies(string $queue = 'orders'): int
    {
        return count($this->redis->list($queue))
            + count($this->redis->list($queue . ':processing'))
            + count($this->redis->list($queue . ':failed'))
            + $this->redis->zcard($queue . ':delayed');
    }

    /**
     * What a reaper does to a message whose worker looks dead: take it off the
     * processing list and put it back on the queue.
     */
    private function reap(ReceivedMessage $message): void
    {
        $this->redis->lrem('orders:processing', 1, (string) $message->receipt());
        $this->redis->rpush('orders', [$message->rawBody()]);
    }

    public function test_a_release_from_a_worker_that_lost_the_message_replaces_the_live_copy(): void
    {
        $this->transport->publish($this->envelope(), 'orders');
        $message = $this->transport->reserve('orders', 1.0);
        self::assertNotNull($message);

        $this->reap($message);
        self::assertSame(1, $this->copies());

        // The original worker finally fails and reports a retry it no longer owns.
        $this->transport->release($message, $this->withAttempts($message, 1), 30);

        self::assertSame(1, $this->copies(), 'still one copy');
        self::assertSame([], $this->redis->list('orders'), 'the reaped copy was taken off the queue');
        $parked = $this->redis->members('orders:delayed');
        self::assertCount(1, $parked);
        self::assertSame(1, EnvelopeCodec::decode($parked[0])['attempts'], 'the failed attempt is carried onto the live copy');
    }

    public function test_a_dead_letter_from_a_worker_that_lost_the_message_quarantines_the_live_copy(): void
    {
        $this->transport->publish($this->envelope(), 'orders');
        $message = $this->transport->reserve('orders', 1.0);
        self::assertNotNull($message);

        $this->reap($message);
        $this->transport->deadLetter($message, $this->withAttempts($message, 3));

        self::assertSame(1, $this->copies());
        self::assertSame([], $this->redis->list('orders'));
        self::assertCount(1, $this->redis->list('orders:failed'));
    }

    public function test_a_release_for_a_message_that_is_gone_is_ignored(): void
    {
        // A reaper that rewrote the body (say, to count the reclaim) leaves nothing
        // that matches the late worker's receipt; the reaper's copy stays as it is.
        $this->transport->publish($this->envelope(), 'orders');
        $message = $this->transport->reserve('orders', 1.0);
        self::assertNotNull($message);

        $this->redis->lrem('orders:processing', 1, $message->rawBody());
        $this->redis->rpush('orders', [$this->withAttempts($message, 1)]);

        $this->transport->release($message, $this->withAttempts($message, 1), 0);
        $this->transport->deadLetter($message, $this->withAttempts($message, 1));

        self::assertSame(1, $this->copies());
        self::assertSame([], $this->redis->list('orders:failed'));
    }

    public function test_an_in_flight_wait_followed_by_the_first_worker_failing_leaves_one_copy_with_the_attempt(): void
    {
        // A handler outlives the reaper, a second worker waits on the in-flight key,
        // then the first handler throws. One copy remains, and it has the attempt.
        $this->transport->publish($this->envelope(), 'orders');
        $first = $this->transport->reserve('orders', 1.0);
        self::assertNotNull($first);

        $this->reap($first);
        $second = $this->transport->reserve('orders', 1.0);
        self::assertNotNull($second);
        $this->transport->release($second, $second->rawBody(), 5); // in-flight wait
        $this->transport->release($first, $this->withAttempts($first, 1), 30); // first handler fails

        self::assertSame(1, $this->copies());
        self::assertSame([], $this->redis->list('orders:processing'));
        $parked = $this->redis->members('orders:delayed');
        self::assertCount(1, $parked);
        self::assertSame(1, EnvelopeCodec::decode($parked[0])['attempts']);
    }

    public function test_a_handler_slower_than_the_reaper_still_reaches_the_dead_letter_list_without_idempotency(): void
    {
        $handlers = (new HandlerMap())->register('urn:babel:orders:created', function (): void {
            // The reaper moves the message back while the handler is still running.
            $raw = $this->redis->list('orders:processing')[0];
            $this->redis->lrem('orders:processing', 1, $raw);
            $this->redis->rpush('orders', [$raw]);

            throw new RuntimeException('downstream failed');
        });
        $worker = new Worker($this->transport, new Dispatcher($handlers), new WorkerOptions(maxAttempts: 3, backoff: [0]));
        $this->transport->publish($this->envelope(), 'orders');

        $seen = [];
        for ($i = 0; $i < 6 && $worker->runOnce('orders'); ++$i) {
            $live = array_merge($this->redis->list('orders'), $this->redis->members('orders:delayed'));
            if ($live !== []) {
                $seen[] = EnvelopeCodec::decode($live[0])['attempts'];
            }
        }

        self::assertSame([1, 2], $seen, 'every failure is counted');
        self::assertSame(1, count($this->redis->list('orders:failed')), 'the message reaches the dead-letter list at maxAttempts');
        self::assertSame(1, $this->copies(), 'one copy in total');
        self::assertSame([], $this->redis->list('orders:processing'));
    }

    public function test_a_client_without_eval_gets_the_same_results_command_by_command(): void
    {
        $redis = new InMemoryRedis(scripting: false);
        $transport = new RedisTransport($redis, 'default');
        $transport->publish($this->envelope(), 'orders');
        $message = $transport->reserve('orders', 1.0);
        self::assertNotNull($message);

        // A lapsed reaper hands the message back; the late failure replaces it.
        $redis->lrem('orders:processing', 1, $message->rawBody());
        $redis->rpush('orders', [$message->rawBody()]);
        $transport->release($message, $this->withAttempts($message, 1), 30);

        self::assertSame([], $redis->list('orders'));
        self::assertCount(1, $redis->members('orders:delayed'));

        $redis->zadd('orders:delayed', [$message->rawBody() => time() - 1]);
        self::assertNotNull($transport->reserve('orders', 1.0), 'a due delayed message is migrated and reserved');
    }

    public function test_a_client_that_refuses_eval_falls_back_and_keeps_working(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('eval')->once()->andThrow(new ServerException('NOPERM this user has no permissions to run the \'eval\' command'));
        $client->shouldReceive('lrem')->with('orders:processing', 1, 'raw')->once()->andReturn(1);
        $client->shouldReceive('rpush')->with('orders', ['payload'])->once();
        $client->shouldReceive('zrem')->andReturn(0);

        $transport = new RedisTransport($client, 'default');
        $transport->release(new ReceivedMessage('orders', 'raw', [], 'raw'), 'payload', 0);

        // Later calls go straight to the commands: eval is not tried again.
        $client->shouldReceive('lrem')->with('orders:processing', 1, 'raw')->once()->andReturn(1);
        $client->shouldReceive('rpush')->with('orders', ['payload'])->once();
        $transport->release(new ReceivedMessage('orders', 'raw', [], 'raw'), 'payload', 0);

        self::addToAssertionCount(1);
    }

    public function test_a_real_failure_of_eval_is_not_swallowed(): void
    {
        $client = Mockery::mock(ClientInterface::class);
        $client->shouldReceive('eval')->once()->andThrow(new RuntimeException('connection lost'));
        $client->shouldNotReceive('lrem');

        $this->expectException(RuntimeException::class);
        (new RedisTransport($client, 'default'))->release(new ReceivedMessage('orders', 'raw', [], 'raw'), 'payload', 0);
    }

    public function test_migration_moves_each_due_member_exactly_once(): void
    {
        $due = $this->envelope('urn:babel:orders:due');
        $later = $this->envelope('urn:babel:orders:later');
        $this->redis->zadd('orders:delayed', [$due => time() - 1, $later => time() + 3600]);

        // The first reserve migrates the due one and reserves it; a second migrates nothing.
        self::assertNotNull($this->transport->reserve('orders', 1.0));
        self::assertNull($this->transport->reserve('orders', 1.0));

        self::assertSame([$due], $this->redis->list('orders:processing'));
        self::assertSame(1, $this->redis->zcard('orders:delayed'));
    }

    private function withAttempts(ReceivedMessage $message, int $attempts): string
    {
        $envelope = $message->envelope();
        $envelope['attempts'] = $attempts;

        return EnvelopeCodec::encode($envelope);
    }
}
