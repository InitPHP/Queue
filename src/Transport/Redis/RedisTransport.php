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
 * `release()`, `deadLetter()` and the delayed-set migration each run as one Lua
 * script, so they are atomic: a worker that dies half way cannot leave a message
 * on no list at all. `release()` and `deadLetter()` also act only while the
 * message is still **this worker's** (its `LREM` from the processing list removes
 * one entry); if a reaper has already moved the message back onto the queue, the
 * live copy belongs to whoever reserves it next and the stale write is ignored,
 * which keeps one message from forking into two copies.
 *
 * Requires Redis 6.2+ (for `BLMOVE`; Lua scripting is built in). Optional
 * dependency: `predis/predis`.
 */
final class RedisTransport implements Transport, ConsumerTransport
{
    /**
     * KEYS: processing, queue, delayed. ARGV: receipt, payload, due time (0 = now).
     * Re-queues only when this worker still owns the processing entry.
     */
    private const RELEASE_SCRIPT = <<<'LUA'
        -- queue:release
        if redis.call('LREM', KEYS[1], 1, ARGV[1]) < 1 then
            return 0
        end
        if tonumber(ARGV[3]) > 0 then
            redis.call('ZADD', KEYS[3], ARGV[3], ARGV[2])
        else
            redis.call('RPUSH', KEYS[2], ARGV[2])
        end
        return 1
        LUA;

    /**
     * KEYS: processing, failed. ARGV: receipt, payload.
     */
    private const DEAD_LETTER_SCRIPT = <<<'LUA'
        -- queue:dead-letter
        if redis.call('LREM', KEYS[1], 1, ARGV[1]) < 1 then
            return 0
        end
        redis.call('RPUSH', KEYS[2], ARGV[2])
        return 1
        LUA;

    /**
     * KEYS: delayed, queue. ARGV: now. Moves due members, each removed and
     * pushed in the same step, so none can be lost or duplicated.
     */
    private const MIGRATE_SCRIPT = <<<'LUA'
        -- queue:migrate
        local due = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, 1000)
        for _, member in ipairs(due) do
            redis.call('ZREM', KEYS[1], member)
            redis.call('RPUSH', KEYS[2], member)
        end
        return #due
        LUA;

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
        $queue = $message->queue();

        $this->client->eval(
            self::RELEASE_SCRIPT,
            3,
            $queue . $this->processingSuffix,
            $queue,
            $queue . $this->delayedSuffix,
            (string) $message->receipt(),
            $payload,
            (string) ($delaySeconds > 0 ? time() + $delaySeconds : 0),
        );
    }

    public function deadLetter(ReceivedMessage $message, string $payload): void
    {
        $queue = $message->queue();

        $this->client->eval(
            self::DEAD_LETTER_SCRIPT,
            2,
            $queue . $this->processingSuffix,
            $queue . $this->failedSuffix,
            (string) $message->receipt(),
            $payload,
        );
    }

    /**
     * Move any delayed messages whose time has come back onto the main queue, in
     * one atomic script so concurrent workers cannot duplicate or drop them.
     */
    private function migrateDueDelayed(string $queue): void
    {
        $this->client->eval(
            self::MIGRATE_SCRIPT,
            2,
            $queue . $this->delayedSuffix,
            $queue,
            (string) time(),
        );
    }
}
