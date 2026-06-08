<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Integration;

use BabelQueue\Codec\EnvelopeCodec;
use InitPHP\Queue\Transport\Redis\RedisTransport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Predis\Client;

/**
 * Exercises {@see RedisTransport} against a real Redis 6.2+ server. Skipped
 * unless QUEUE_TEST_REDIS_DSN is set (the CI integration job provides it via a
 * Redis service container); locally it is a no-op.
 */
#[Group('integration')]
final class RedisIntegrationTest extends TestCase
{
    private Client $client;

    private RedisTransport $transport;

    private const QUEUE = 'it_orders';

    protected function setUp(): void
    {
        $dsn = getenv('QUEUE_TEST_REDIS_DSN');
        if (! is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set QUEUE_TEST_REDIS_DSN (e.g. tcp://127.0.0.1:6379) to run the Redis integration test.');
        }

        $this->client = new Client($dsn);
        $this->client->del(
            self::QUEUE,
            self::QUEUE . ':processing',
            self::QUEUE . ':failed',
            self::QUEUE . ':delayed',
        );
        $this->transport = new RedisTransport($this->client, 'default');
    }

    public function test_full_publish_reserve_ack_cycle(): void
    {
        $payload = EnvelopeCodec::encode(EnvelopeCodec::make('urn:babel:orders:created', ['order_id' => 1], self::QUEUE));
        $this->transport->publish($payload, self::QUEUE);

        $message = $this->transport->reserve(self::QUEUE, 2.0);
        self::assertNotNull($message);
        self::assertSame('urn:babel:orders:created', $message->getUrn());
        self::assertSame(1, (int) $this->client->llen(self::QUEUE . ':processing'));

        $this->transport->ack($message);
        self::assertSame(0, (int) $this->client->llen(self::QUEUE . ':processing'));
    }

    public function test_release_requeues_and_dead_letter_quarantines(): void
    {
        $payload = EnvelopeCodec::encode(EnvelopeCodec::make('urn:babel:orders:created', ['n' => 1], self::QUEUE));
        $this->transport->publish($payload, self::QUEUE);

        $message = $this->transport->reserve(self::QUEUE, 2.0);
        self::assertNotNull($message);
        $this->transport->release($message, $message->rawBody(), 0);
        self::assertSame(1, (int) $this->client->llen(self::QUEUE));

        $again = $this->transport->reserve(self::QUEUE, 2.0);
        self::assertNotNull($again);
        $this->transport->deadLetter($again, $again->rawBody());
        self::assertSame(1, (int) $this->client->llen(self::QUEUE . ':failed'));
    }
}
