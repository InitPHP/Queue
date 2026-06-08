<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Transport;

use BabelQueue\Codec\EnvelopeCodec;
use InitPHP\Queue\Message\ReceivedMessage;
use InitPHP\Queue\Transport\Amqp\AmqpTransport;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;

final class AmqpTransportTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private function envelope(string $urn = 'urn:babel:orders:created'): string
    {
        return EnvelopeCodec::encode(EnvelopeCodec::make($urn, ['order_id' => 1], 'orders'));
    }

    private function reserved(string $raw, int $tag = 42): ReceivedMessage
    {
        return new ReceivedMessage('orders', $raw, EnvelopeCodec::decode($raw), $tag);
    }

    public function test_reserve_declares_the_queue_and_returns_the_message(): void
    {
        $raw = $this->envelope();

        $amqpMessage = Mockery::mock(AMQPMessage::class);
        $amqpMessage->shouldReceive('getBody')->andReturn($raw);
        $amqpMessage->shouldReceive('getDeliveryTag')->andReturn(42);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('queue_declare')->once()->with('orders', false, true, false, false);
        $channel->shouldReceive('basic_get')->once()->with('orders')->andReturn($amqpMessage);

        $message = (new AmqpTransport($channel, 'default'))->reserve('orders');

        self::assertInstanceOf(ReceivedMessage::class, $message);
        self::assertSame('urn:babel:orders:created', $message->getUrn());
        self::assertSame(42, $message->receipt());
    }

    public function test_reserve_returns_null_when_no_message_is_waiting(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('queue_declare');
        $channel->shouldReceive('basic_get')->andReturnNull();

        self::assertNull((new AmqpTransport($channel))->reserve('orders'));
    }

    public function test_ack_acknowledges_the_delivery_tag(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_ack')->once()->with(42);

        (new AmqpTransport($channel))->ack($this->reserved($this->envelope()));
    }

    public function test_release_acks_the_original_and_republishes(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_ack')->once()->with(42);
        $channel->shouldReceive('queue_declare');
        $channel->shouldReceive('basic_publish')->once();

        $raw = $this->envelope();
        (new AmqpTransport($channel))->release($this->reserved($raw), $raw, 0);
    }

    public function test_dead_letter_acks_and_publishes_to_the_failed_queue(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_ack')->once()->with(42);
        $channel->shouldReceive('queue_declare')->once()->with('orders.failed', false, true, false, false);
        $channel->shouldReceive('basic_publish')->once();

        $raw = $this->envelope();
        (new AmqpTransport($channel))->deadLetter($this->reserved($raw), $raw);
    }
}
