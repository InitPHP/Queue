<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Integration;

use BabelQueue\Codec\EnvelopeCodec;
use InitPHP\Queue\Transport\Amqp\AmqpTransport;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Exercises {@see AmqpTransport} against a real RabbitMQ broker. Skipped unless
 * QUEUE_TEST_AMQP_HOST is set (the CI integration job provides it via a RabbitMQ
 * service container); locally it is a no-op.
 */
#[Group('integration')]
final class AmqpIntegrationTest extends TestCase
{
    private const QUEUE = 'it_orders';

    private AMQPStreamConnection $connection;

    private AMQPChannel $channel;

    private AmqpTransport $transport;

    protected function setUp(): void
    {
        $host = getenv('QUEUE_TEST_AMQP_HOST');
        if (! is_string($host) || $host === '') {
            self::markTestSkipped('Set QUEUE_TEST_AMQP_HOST to run the RabbitMQ integration test.');
        }

        $this->connection = new AMQPStreamConnection(
            $host,
            (int) (getenv('QUEUE_TEST_AMQP_PORT') ?: 5672),
            getenv('QUEUE_TEST_AMQP_USER') ?: 'guest',
            getenv('QUEUE_TEST_AMQP_PASS') ?: 'guest',
        );
        $this->channel = $this->connection->channel();
        $this->channel->queue_declare(self::QUEUE, false, true, false, false);
        $this->channel->queue_purge(self::QUEUE);

        $this->transport = new AmqpTransport($this->channel, 'default');
    }

    protected function tearDown(): void
    {
        if (isset($this->channel)) {
            $this->channel->close();
        }
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    public function test_full_publish_reserve_ack_cycle(): void
    {
        $payload = EnvelopeCodec::encode(EnvelopeCodec::make('urn:babel:orders:created', ['order_id' => 9], self::QUEUE));
        $this->transport->publish($payload, self::QUEUE);

        $message = $this->transport->reserve(self::QUEUE, 2.0);

        self::assertNotNull($message);
        self::assertSame('urn:babel:orders:created', $message->getUrn());
        self::assertSame(['order_id' => 9], $message->getData());

        $this->transport->ack($message);

        // Nothing left to consume after the ack.
        self::assertNull($this->transport->reserve(self::QUEUE, 1.0));
    }
}
