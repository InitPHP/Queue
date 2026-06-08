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
}
