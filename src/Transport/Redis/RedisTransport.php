<?php

declare(strict_types=1);

namespace InitPHP\Queue\Transport\Redis;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\Contracts\Transport;
use BabelQueue\Transport\RedisTransport as BabelRedisTransport;
use InitPHP\Queue\Contracts\ConsumerTransport;
use InitPHP\Queue\Message\ReceivedMessage;
use Predis\ClientInterface;

/**
 * A reliable-queue Redis transport that completes the SDK's publish-only
 * {@see BabelRedisTransport} with the consume side.
 *
 * Publishing reuses the SDK transport verbatim (`RPUSH <queue>`), so the wire
 * convention every BabelQueue SDK shares is never duplicated or drifted.
 * Consuming uses the reliable-queue pattern: `BLMOVE <queue> <queue>:processing
 * LEFT RIGHT` atomically reserves a message onto a per-queue processing list, so
 * a worker crash leaves it recoverable; `ack` is `LREM <queue>:processing`.
 * Delayed retries use a `<queue>:delayed` sorted set drained on each reserve, and
 * dead-letters go to a `<queue>:failed` list.
 *
 * Requires Redis 6.2+ (for `BLMOVE`). Optional dependency: `predis/predis`.
 */
final class RedisTransport implements Transport, ConsumerTransport
{
    private readonly BabelRedisTransport $publisher;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly string $defaultQueue = 'default',
        private readonly string $processingSuffix = ':processing',
        private readonly string $failedSuffix = ':failed',
        private readonly string $delayedSuffix = ':delayed',
    ) {
        $this->publisher = new BabelRedisTransport($client, $defaultQueue);
    }

    public function publish(string $payload, ?string $queue = null): ?string
    {
        return $this->publisher->publish($payload, $queue);
    }

    public function reserve(string $queue, float $timeout = 5.0): ?ReceivedMessage
    {
        $this->migrateDueDelayed($queue);

        // Min 1s so the loop returns periodically to observe a stop signal,
        // instead of blocking indefinitely on an empty queue.
        $reserved = $this->client->blmove(
            $queue,
            $queue . $this->processingSuffix,
            'LEFT',
            'RIGHT',
            max(1, (int) ceil($timeout)),
        );

        if ($reserved === null) {
            return null;
        }

        $raw = (string) $reserved;

        return new ReceivedMessage($queue, $raw, EnvelopeCodec::decode($raw), $raw);
    }

    public function ack(ReceivedMessage $message): void
    {
        $this->client->lrem($message->queue() . $this->processingSuffix, 1, (string) $message->receipt());
    }

    public function release(ReceivedMessage $message, string $payload, int $delaySeconds = 0): void
    {
        $this->client->lrem($message->queue() . $this->processingSuffix, 1, (string) $message->receipt());

        if ($delaySeconds > 0) {
            $this->client->zadd($message->queue() . $this->delayedSuffix, [$payload => time() + $delaySeconds]);

            return;
        }

        $this->client->rpush($message->queue(), [$payload]);
    }

    public function deadLetter(ReceivedMessage $message, string $payload): void
    {
        $this->client->lrem($message->queue() . $this->processingSuffix, 1, (string) $message->receipt());
        $this->client->rpush($message->queue() . $this->failedSuffix, [$payload]);
    }

    /**
     * Move any delayed messages whose time has come back onto the main queue. A
     * member is re-enqueued only by the worker that wins its `ZREM`, so a delayed
     * retry is not duplicated across concurrent workers.
     */
    private function migrateDueDelayed(string $queue): void
    {
        $key = $queue . $this->delayedSuffix;

        /** @var list<string> $due */
        $due = $this->client->zrangebyscore($key, '-inf', (string) time());

        foreach ($due as $payload) {
            if ((int) $this->client->zrem($key, $payload) > 0) {
                $this->client->rpush($queue, [$payload]);
            }
        }
    }
}
