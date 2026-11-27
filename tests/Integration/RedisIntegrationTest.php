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
            self::QUEUE . ':started',
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

    private function copies(): int
    {
        return (int) $this->client->llen(self::QUEUE)
            + (int) $this->client->llen(self::QUEUE . ':processing')
            + (int) $this->client->llen(self::QUEUE . ':failed')
            + (int) $this->client->zcard(self::QUEUE . ':delayed');
    }

    private function publishAndReserve(): \InitPHP\Queue\Message\ReceivedMessage
    {
        $payload = EnvelopeCodec::encode(EnvelopeCodec::make('urn:babel:orders:created', ['n' => 1], self::QUEUE));
        $this->transport->publish($payload, self::QUEUE);

        $message = $this->transport->reserve(self::QUEUE, 2.0);
        self::assertNotNull($message);

        return $message;
    }

    /** A reaper moving the message off the processing list and back onto the queue. */
    private function reap(\InitPHP\Queue\Message\ReceivedMessage $message): void
    {
        $this->client->lrem(self::QUEUE . ':processing', 1, $message->rawBody());
        $this->client->rpush(self::QUEUE, [$message->rawBody()]);
    }

    public function test_a_delayed_release_parks_the_message_and_migrates_it_when_due(): void
    {
        $message = $this->publishAndReserve();

        $this->transport->release($message, $message->rawBody(), 60);

        self::assertSame(0, (int) $this->client->llen(self::QUEUE));
        self::assertSame(0, (int) $this->client->llen(self::QUEUE . ':processing'));
        self::assertSame(1, (int) $this->client->zcard(self::QUEUE . ':delayed'));

        // Make it due and let reserve() migrate it.
        $this->client->zadd(self::QUEUE . ':delayed', [$message->rawBody() => time() - 1]);
        $again = $this->transport->reserve(self::QUEUE, 2.0);

        self::assertNotNull($again);
        self::assertSame(0, (int) $this->client->zcard(self::QUEUE . ':delayed'));
        self::assertSame(1, $this->copies());
    }

    public function test_a_release_from_a_worker_that_lost_the_message_replaces_the_live_copy(): void
    {
        $message = $this->publishAndReserve();
        $this->reap($message);

        $retry = $message->envelope();
        $retry['attempts'] = 1;
        $this->transport->release($message, EnvelopeCodec::encode($retry), 30);

        self::assertSame(1, $this->copies(), 'still one copy');
        self::assertSame(0, (int) $this->client->llen(self::QUEUE), 'the reaped copy was taken off the queue');
        $parked = $this->client->zrange(self::QUEUE . ':delayed', 0, -1);
        self::assertCount(1, $parked);
        self::assertSame(1, EnvelopeCodec::decode($parked[0])['attempts'], 'the failed attempt is carried onto it');
    }

    public function test_a_dead_letter_from_a_worker_that_lost_the_message_quarantines_the_live_copy(): void
    {
        $message = $this->publishAndReserve();
        $this->reap($message);

        $this->transport->deadLetter($message, $message->rawBody());

        self::assertSame(1, $this->copies());
        self::assertSame(0, (int) $this->client->llen(self::QUEUE));
        self::assertSame(1, (int) $this->client->llen(self::QUEUE . ':failed'));
    }

    public function test_a_release_for_a_message_that_is_gone_is_ignored(): void
    {
        $message = $this->publishAndReserve();
        $this->client->lrem(self::QUEUE . ':processing', 1, $message->rawBody());
        $rewritten = $message->envelope();
        $rewritten['attempts'] = 1;
        $this->client->rpush(self::QUEUE, [EnvelopeCodec::encode($rewritten)]); // a reaper that counted the reclaim

        $this->transport->release($message, $message->rawBody(), 0);
        $this->transport->deadLetter($message, $message->rawBody());

        self::assertSame(1, $this->copies());
        self::assertSame(0, (int) $this->client->llen(self::QUEUE . ':failed'));
    }

    public function test_a_message_waiting_on_an_in_flight_key_is_parked_not_lost(): void
    {
        $envelope = EnvelopeCodec::make('urn:babel:orders:created', ['n' => 1], self::QUEUE);
        $envelope['meta']['id'] = 'msg-1';
        $this->transport->publish(EnvelopeCodec::encode($envelope), self::QUEUE);

        $store = new InMemoryIdempotencyStore();
        $store->claim('bq:idemp:msg-1', 300);
        $handler = new RecordingHandler();
        $worker = new Worker(
            $this->transport,
            new Dispatcher(
                (new HandlerMap())->register('urn:babel:orders:created', $handler),
                idempotency: new IdempotencyOptions($store),
            ),
            new WorkerOptions(sleepWhenEmpty: 0.0, reserveTimeout: 1.0),
        );

        $worker->runOnce(self::QUEUE);

        self::assertSame([], $handler->handled);
        self::assertSame(1, $this->copies());
        self::assertSame(1, (int) $this->client->zcard(self::QUEUE . ':delayed'));
        self::assertSame(0, (int) $this->client->llen(self::QUEUE . ':processing'));
    }

    public function test_a_delivery_that_never_finished_is_counted_and_ends_at_max_attempts(): void
    {
        $this->transport->publish(EnvelopeCodec::encode(EnvelopeCodec::make('urn:babel:orders:created', ['n' => 1], self::QUEUE)), self::QUEUE);

        // Three deliveries whose handler died; the reaper puts the entry back each time.
        foreach ([0, 1, 2] as $expected) {
            $message = $this->transport->reserve(self::QUEUE, 1.0);
            self::assertNotNull($message);
            self::assertSame($expected, $message->attempts());
            self::assertSame($expected > 0, $message->wasAbandoned());
            self::assertSame(
                $expected,
                EnvelopeCodec::decode((string) $this->client->lindex(self::QUEUE . ':processing', 0))['attempts'],
                'the processing entry carries the count',
            );

            $this->client->lrem(self::QUEUE . ':processing', 1, (string) $message->receipt());
            $this->client->rpush(self::QUEUE, [(string) $message->receipt()]);
        }

        $handler = new RecordingHandler();
        $worker = new Worker(
            $this->transport,
            new Dispatcher((new HandlerMap())->register('urn:babel:orders:created', $handler)),
            new WorkerOptions(maxAttempts: 3, sleepWhenEmpty: 0.0, reserveTimeout: 1.0),
        );
        $worker->runOnce(self::QUEUE);

        self::assertSame([], $handler->handled);
        self::assertSame(0, (int) $this->client->llen(self::QUEUE . ':processing'));
        self::assertSame(1, (int) $this->client->llen(self::QUEUE . ':failed'));
        self::assertSame(0, (int) $this->client->zcard(self::QUEUE . ':started'));
    }

    public function test_a_message_put_back_verbatim_is_not_counted_when_reserved_again(): void
    {
        $this->transport->publish(EnvelopeCodec::encode(EnvelopeCodec::make('urn:babel:orders:created', ['n' => 1], self::QUEUE)), self::QUEUE);

        $message = $this->transport->reserve(self::QUEUE, 1.0);
        self::assertNotNull($message);
        $this->transport->release($message, $message->rawBody(), 0);

        $again = $this->transport->reserve(self::QUEUE, 1.0);
        self::assertNotNull($again);
        self::assertFalse($again->wasAbandoned());
        self::assertSame(0, $again->attempts());

        $this->transport->ack($again);
        self::assertSame(0, (int) $this->client->zcard(self::QUEUE . ':started'));
    }
}
