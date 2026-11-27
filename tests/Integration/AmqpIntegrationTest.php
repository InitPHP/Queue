<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Integration;

use BabelQueue\Codec\EnvelopeCodec;
use InitPHP\Queue\Consumer\Dispatcher;
use InitPHP\Queue\Consumer\IdempotencyOptions;
use InitPHP\Queue\Consumer\InMemoryIdempotencyStore;
use InitPHP\Queue\Consumer\Worker;
use InitPHP\Queue\Consumer\WorkerOptions;
use InitPHP\Queue\Routing\HandlerMap;
use InitPHP\Queue\Tests\Support\RecordingHandler;
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

    public function test_release_republishes_the_updated_envelope_and_acks_the_original(): void
    {
        $this->transport->publish(
            EnvelopeCodec::encode(EnvelopeCodec::make('urn:babel:orders:created', ['order_id' => 1], self::QUEUE)),
            self::QUEUE,
        );

        $message = $this->transport->reserve(self::QUEUE, 2.0);
        self::assertNotNull($message);

        $envelope = $message->envelope();
        $envelope['attempts'] = 1;
        $this->transport->release($message, EnvelopeCodec::encode($envelope), 0);

        $again = $this->transport->reserve(self::QUEUE, 2.0);
        self::assertNotNull($again, 'the republished copy is there');
        self::assertSame(1, $again->attempts());
        $this->transport->ack($again);

        self::assertNull($this->transport->reserve(self::QUEUE, 1.0), 'exactly one copy existed: the original was acked');
    }

    public function test_release_and_dead_letter_work_with_publisher_confirms(): void
    {
        $this->channel->confirm_select();

        $this->transport->publish(
            EnvelopeCodec::encode(EnvelopeCodec::make('urn:babel:orders:created', ['order_id' => 1], self::QUEUE)),
            self::QUEUE,
        );

        $message = $this->transport->reserve(self::QUEUE, 2.0);
        self::assertNotNull($message);
        $this->transport->release($message, $message->rawBody(), 0);

        $again = $this->transport->reserve(self::QUEUE, 2.0);
        self::assertNotNull($again);
        $this->transport->deadLetter($again, $again->rawBody());

        self::assertNull($this->transport->reserve(self::QUEUE, 1.0));
        $this->channel->queue_purge(self::QUEUE . '.failed');
    }

    public function test_a_message_waiting_on_an_in_flight_key_stays_on_the_queue(): void
    {
        $envelope = EnvelopeCodec::make('urn:babel:orders:created', ['order_id' => 1], self::QUEUE);
        $envelope['meta']['id'] = 'msg-1';
        $this->transport->publish(EnvelopeCodec::encode($envelope), self::QUEUE);

        $store = new InMemoryIdempotencyStore();
        $store->claim('bq:idemp:msg-1', 300); // another worker holds the key
        $handler = new RecordingHandler();
        $worker = new Worker(
            $this->transport,
            new Dispatcher(
                (new HandlerMap())->register('urn:babel:orders:created', $handler),
                idempotency: new IdempotencyOptions($store),
            ),
            new WorkerOptions(sleepWhenEmpty: 0.0),
        );

        $worker->runOnce(self::QUEUE);
        self::assertSame([], $handler->handled);

        $store->forget('bq:idemp:msg-1'); // the other worker failed
        self::assertTrue($worker->runOnce(self::QUEUE), 'the message was still there');
        self::assertCount(1, $handler->handled);
        self::assertNull($this->transport->reserve(self::QUEUE, 1.0));
    }

    public function test_a_delivery_that_was_never_acked_is_counted_when_the_broker_redelivers_it(): void
    {
        $this->transport->publish(
            EnvelopeCodec::encode(EnvelopeCodec::make('urn:babel:orders:created', ['order_id' => 1], self::QUEUE)),
            self::QUEUE,
        );

        $first = $this->transport->reserve(self::QUEUE, 2.0);
        self::assertNotNull($first);
        self::assertFalse($first->wasAbandoned());
        self::assertSame(0, $first->attempts());

        $this->channel->basic_recover(true); // the unacked delivery goes back to the queue

        $second = $this->transport->reserve(self::QUEUE, 2.0);
        self::assertNotNull($second);
        self::assertTrue($second->wasAbandoned());
        self::assertSame(1, $second->attempts());
        $this->transport->ack($second);
    }
}
