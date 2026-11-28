<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Transport;

use BabelQueue\Codec\EnvelopeCodec;
use InitPHP\Queue\Exceptions\QueueException;
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
        $amqpMessage->shouldReceive('isRedelivered')->andReturn(false);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('queue_declare')->once()->with('orders', false, true, false, false);
        $channel->shouldReceive('basic_get')->once()->with('orders')->andReturn($amqpMessage);

        $message = (new AmqpTransport($channel, 'default'))->reserve('orders');

        self::assertInstanceOf(ReceivedMessage::class, $message);
        self::assertSame('urn:babel:orders:created', $message->getUrn());
        self::assertSame(42, $message->receipt());
    }

    public function test_a_redelivered_message_counts_the_unfinished_delivery_as_an_attempt(): void
    {
        $raw = $this->envelope();

        $amqpMessage = Mockery::mock(AMQPMessage::class);
        $amqpMessage->shouldReceive('getBody')->andReturn($raw);
        $amqpMessage->shouldReceive('getDeliveryTag')->andReturn(43);
        $amqpMessage->shouldReceive('isRedelivered')->andReturn(true);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('queue_declare');
        $channel->shouldReceive('basic_get')->once()->andReturn($amqpMessage);

        $message = (new AmqpTransport($channel, 'default'))->reserve('orders');

        self::assertNotNull($message);
        self::assertTrue($message->wasAbandoned());
        self::assertSame(1, $message->attempts());
        self::assertSame(1, EnvelopeCodec::decode($message->rawBody())['attempts']);
        self::assertSame(43, $message->receipt(), 'the delivery tag still identifies the broker message');
    }

    public function test_a_first_delivery_is_not_counted(): void
    {
        $amqpMessage = Mockery::mock(AMQPMessage::class);
        $amqpMessage->shouldReceive('getBody')->andReturn($this->envelope());
        $amqpMessage->shouldReceive('getDeliveryTag')->andReturn(44);
        $amqpMessage->shouldReceive('isRedelivered')->andReturn(false);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('queue_declare');
        $channel->shouldReceive('basic_get')->once()->andReturn($amqpMessage);

        $message = (new AmqpTransport($channel, 'default'))->reserve('orders');

        self::assertNotNull($message);
        self::assertFalse($message->wasAbandoned());
        self::assertSame(0, $message->attempts());
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

    public function test_release_publishes_the_new_copy_before_it_acks_the_original(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('queue_declare');
        $channel->shouldReceive('basic_publish')->once()->ordered();
        $channel->shouldReceive('set_nack_handler');
        $channel->shouldReceive('set_return_listener');
        $channel->shouldReceive('wait_for_pending_acks_returns')->once()->ordered();
        $channel->shouldReceive('basic_ack')->once()->with(42)->ordered();

        $raw = $this->envelope();
        (new AmqpTransport($channel))->release($this->reserved($raw), $raw, 0);
    }

    public function test_a_failed_publish_leaves_the_original_unacked(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('queue_declare');
        $channel->shouldReceive('basic_publish')->once()->andThrow(new \RuntimeException('connection lost'));
        $channel->shouldNotReceive('basic_ack');

        $raw = $this->envelope();

        $this->expectException(\RuntimeException::class);
        (new AmqpTransport($channel))->release($this->reserved($raw), $raw, 0);
    }

    public function test_an_unconfirmed_publish_leaves_the_original_unacked(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('queue_declare');
        $channel->shouldReceive('basic_publish')->once();
        $channel->shouldReceive('set_nack_handler');
        $channel->shouldReceive('set_return_listener');
        $channel->shouldReceive('wait_for_pending_acks_returns')->once()->andThrow(new \RuntimeException('confirm timeout'));
        $channel->shouldNotReceive('basic_ack');

        $raw = $this->envelope();

        $this->expectException(\RuntimeException::class);
        (new AmqpTransport($channel))->deadLetter($this->reserved($raw), $raw);
    }

    public function test_dead_letter_publishes_to_the_failed_queue_before_it_acks(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('queue_declare')->once()->with('orders.failed', false, true, false, false);
        $channel->shouldReceive('basic_publish')->once()->ordered();
        $channel->shouldReceive('set_nack_handler');
        $channel->shouldReceive('set_return_listener');
        $channel->shouldReceive('wait_for_pending_acks_returns')->once()->ordered();
        $channel->shouldReceive('basic_ack')->once()->with(42)->ordered();

        $raw = $this->envelope();
        (new AmqpTransport($channel))->deadLetter($this->reserved($raw), $raw);
    }

    public function test_a_broker_nack_requeues_the_original_instead_of_acking_it(): void
    {
        $nackHandler = null;
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('queue_declare');
        $channel->shouldReceive('basic_publish')->once();
        $channel->shouldReceive('set_nack_handler')->andReturnUsing(function (callable $handler) use (&$nackHandler): void {
            $nackHandler ??= $handler; // the first one is the transport's wrapper
        });
        $channel->shouldReceive('set_return_listener');
        // The broker refuses the publish while the transport waits for confirms.
        $channel->shouldReceive('wait_for_pending_acks_returns')->once()->andReturnUsing(function () use (&$nackHandler): void {
            $nackHandler(new AMQPMessage('body'));
        });
        $channel->shouldReceive('basic_nack')->once()->with(42, false, true);
        $channel->shouldNotReceive('basic_ack');

        $raw = $this->envelope();

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('nacked');
        (new AmqpTransport($channel))->release($this->reserved($raw), $raw, 0);
    }

    public function test_a_returned_message_requeues_the_original_instead_of_acking_it(): void
    {
        $returnListener = null;
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('queue_declare');
        $channel->shouldReceive('basic_publish')->once();
        $channel->shouldReceive('set_nack_handler');
        $channel->shouldReceive('set_return_listener')->andReturnUsing(function (callable $listener) use (&$returnListener): void {
            $returnListener ??= $listener;
        });
        $channel->shouldReceive('wait_for_pending_acks_returns')->once()->andReturnUsing(function () use (&$returnListener): void {
            $returnListener(312, 'NO_ROUTE', '', 'orders.failed', new AMQPMessage('body'));
        });
        $channel->shouldReceive('basic_nack')->once()->with(42, false, true);
        $channel->shouldNotReceive('basic_ack');

        $raw = $this->envelope();

        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('returned');
        (new AmqpTransport($channel))->deadLetter($this->reserved($raw), $raw);
    }

    public function test_the_callers_own_nack_handler_still_runs_and_is_put_back(): void
    {
        $seen = [];
        $own = static function (AMQPMessage $message) use (&$seen): void {
            $seen[] = $message->getBody();
        };

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('queue_declare');
        $channel->shouldReceive('basic_publish')->once();

        // Give the (mock) channel the handler the caller installed earlier.
        $property = new \ReflectionProperty(AMQPChannel::class, 'nack_handler');
        $property->setValue($channel, $own);

        $installed = [];
        $channel->shouldReceive('set_nack_handler')->andReturnUsing(function (callable $handler) use (&$installed): void {
            $installed[] = $handler;
        });
        $channel->shouldReceive('set_return_listener');
        $channel->shouldReceive('wait_for_pending_acks_returns')->once()->andReturnUsing(function () use (&$installed): void {
            $installed[0](new AMQPMessage('nacked-body'));
        });
        $channel->shouldReceive('basic_nack')->once();

        $raw = $this->envelope();
        try {
            (new AmqpTransport($channel))->release($this->reserved($raw), $raw, 0);
            self::fail('a nack must fail the release');
        } catch (QueueException) {
        }

        self::assertSame(['nacked-body'], $seen, "the caller's handler was still called");
        self::assertSame($own, $installed[1], "the caller's handler is put back afterwards");
    }
}
