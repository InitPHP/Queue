<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Transport;

use BabelQueue\Codec\EnvelopeCodec;
use InitPHP\Queue\Message\ReceivedMessage;
use InitPHP\Queue\Tests\Support\InMemoryRedis;
use InitPHP\Queue\Transport\Redis\RedisTransport;
use PHPUnit\Framework\TestCase;

final class RedisTransportTest extends TestCase
{
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

    public function test_a_release_from_a_worker_that_lost_the_message_does_not_fork_it(): void
    {
        $this->transport->publish($this->envelope(), 'orders');
        $message = $this->transport->reserve('orders', 1.0);
        self::assertNotNull($message);

        $this->reap($message);
        self::assertSame(1, $this->copies());

        // The original worker finally fails and releases a retry it no longer owns.
        $this->transport->release($message, $this->withAttempts($message, 1), 30);

        self::assertSame(1, $this->copies(), 'the reaped copy is the only live one');
        self::assertSame(0, $this->redis->zcard('orders:delayed'));
    }

    public function test_a_dead_letter_from_a_worker_that_lost_the_message_is_ignored(): void
    {
        $this->transport->publish($this->envelope(), 'orders');
        $message = $this->transport->reserve('orders', 1.0);
        self::assertNotNull($message);

        $this->reap($message);
        $this->transport->deadLetter($message, $message->rawBody());

        self::assertSame([], $this->redis->list('orders:failed'));
        self::assertSame(1, $this->copies());
    }

    public function test_an_in_flight_wait_followed_by_the_first_worker_failing_leaves_one_copy(): void
    {
        // A handler outlives the reaper, a second worker waits on the in-flight
        // key, then the first handler throws. The message must not become two.
        $this->transport->publish($this->envelope(), 'orders');
        $first = $this->transport->reserve('orders', 1.0);
        self::assertNotNull($first);

        $this->reap($first);
        $second = $this->transport->reserve('orders', 1.0);
        self::assertNotNull($second);
        $this->transport->release($second, $second->rawBody(), 5); // in-flight wait
        $this->transport->release($first, $this->withAttempts($first, 1), 30); // first handler fails

        self::assertSame(1, $this->copies());
        self::assertSame(1, $this->redis->zcard('orders:delayed'));
        self::assertSame([], $this->redis->list('orders:processing'));
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
