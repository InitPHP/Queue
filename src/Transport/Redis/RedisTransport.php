<?php

declare(strict_types=1);

namespace InitPHP\Queue\Transport\Redis;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\Contracts\Transport;
use BabelQueue\Transport\RedisTransport as BabelRedisTransport;
use BadMethodCallException;
use Error;
use InitPHP\Queue\Contracts\ConsumerTransport;
use InitPHP\Queue\Message\ReceivedMessage;
use Predis\ClientInterface;
use Predis\Response\ServerException;
use Throwable;

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
 * script when the client can `EVAL`, so they are atomic: a worker that dies half
 * way cannot leave a message on no list at all. `release()` and `deadLetter()`
 * also never fork a message. They act on the worker's own processing entry
 * (`LREM` removes one); when a reaper has already moved the message back, they
 * replace the live copy instead (still on the queue, or parked in the delayed
 * set, with the same body), so the failure's `attempts` and back-off are carried
 * onto the one copy that exists. If the live copy cannot be found (a reaper that
 * rewrote the body, or the message already finished) the call is ignored.
 *
 * A client without `EVAL` (an adapter that only implements the commands listed in
 * the docs, or a Redis user without scripting permission) is not an error: the
 * transport falls back to issuing the same steps as separate commands, with the
 * same ownership rules but without atomicity.
 *
 * Requires Redis 6.2+ (for `BLMOVE`; Lua scripting is built in). Optional
 * dependency: `predis/predis`.
 */
final class RedisTransport implements Transport, ConsumerTransport
{
    /**
     * KEYS: processing, queue, delayed. ARGV: receipt, payload, due time (0 = now).
     * Takes the worker's own processing entry, or else the live copy (parked in
     * the delayed set, or on the queue), and puts the new payload in its place.
     */
    private const RELEASE_SCRIPT = <<<'LUA'
        -- queue:release
        if redis.call('LREM', KEYS[1], 1, ARGV[1]) < 1 then
            if redis.call('ZREM', KEYS[3], ARGV[1]) < 1 and redis.call('LREM', KEYS[2], 1, ARGV[1]) < 1 then
                return 0
            end
        end
        if tonumber(ARGV[3]) > 0 then
            redis.call('ZADD', KEYS[3], ARGV[3], ARGV[2])
        else
            redis.call('RPUSH', KEYS[2], ARGV[2])
        end
        return 1
        LUA;

    /**
     * KEYS: processing, failed, delayed, queue. ARGV: receipt, payload.
     */
    private const DEAD_LETTER_SCRIPT = <<<'LUA'
        -- queue:dead-letter
        if redis.call('LREM', KEYS[1], 1, ARGV[1]) < 1 then
            if redis.call('ZREM', KEYS[3], ARGV[1]) < 1 and redis.call('LREM', KEYS[4], 1, ARGV[1]) < 1 then
                return 0
            end
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

    /** Whether the client can EVAL; cleared the first time it proves it cannot. */
    private bool $scripting = true;

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
        $processing = $queue . $this->processingSuffix;
        $delayed = $queue . $this->delayedSuffix;
        $receipt = (string) $message->receipt();
        $due = $delaySeconds > 0 ? time() + $delaySeconds : 0;

        if ($this->evaluate(self::RELEASE_SCRIPT, [$processing, $queue, $delayed], [$receipt, $payload, (string) $due])) {
            return;
        }

        // Without scripting: the same steps, one command at a time.
        if (! $this->takeOwnership($processing, $delayed, $queue, $receipt)) {
            return;
        }

        if ($due > 0) {
            $this->client->zadd($delayed, [$payload => $due]);

            return;
        }

        $this->client->rpush($queue, [$payload]);
    }

    public function deadLetter(ReceivedMessage $message, string $payload): void
    {
        $queue = $message->queue();
        $processing = $queue . $this->processingSuffix;
        $failed = $queue . $this->failedSuffix;
        $delayed = $queue . $this->delayedSuffix;
        $receipt = (string) $message->receipt();

        if ($this->evaluate(self::DEAD_LETTER_SCRIPT, [$processing, $failed, $delayed, $queue], [$receipt, $payload])) {
            return;
        }

        if ($this->takeOwnership($processing, $delayed, $queue, $receipt)) {
            $this->client->rpush($failed, [$payload]);
        }
    }

    /**
     * Run one of the scripts. Returns false (without having done anything) when the
     * client cannot evaluate scripts, so the caller can take the command-by-command
     * path; any other failure, such as a lost connection, is rethrown.
     *
     * @param  list<string>  $keys
     * @param  list<string>  $args
     */
    private function evaluate(string $script, array $keys, array $args): bool
    {
        if (! $this->scripting) {
            return false;
        }

        try {
            $this->client->eval($script, count($keys), ...$keys, ...$args);

            return true;
        } catch (Throwable $e) {
            if (! $this->isScriptingUnavailable($e)) {
                throw $e;
            }

            $this->scripting = false;

            return false;
        }
    }

    /**
     * Whether `$e` means "this client cannot EVAL" (no such method, a different
     * signature, or a server that refuses the command) rather than a real failure.
     */
    private function isScriptingUnavailable(Throwable $e): bool
    {
        if ($e instanceof BadMethodCallException || $e instanceof Error) {
            return true;
        }

        if ($e instanceof ServerException) {
            $reason = strtolower($e->getMessage());

            return str_contains($reason, 'noperm') || str_contains($reason, 'unknown command');
        }

        return false;
    }

    /**
     * The non-scripted twin of the ownership rule: take the worker's own processing
     * entry, or else the live copy; false when there is nothing to act on.
     */
    private function takeOwnership(string $processing, string $delayed, string $queue, string $receipt): bool
    {
        return (int) $this->client->lrem($processing, 1, $receipt) > 0
            || (int) $this->client->zrem($delayed, $receipt) > 0
            || (int) $this->client->lrem($queue, 1, $receipt) > 0;
    }

    /**
     * Move any delayed messages whose time has come back onto the main queue. With
     * scripting this is one atomic script, so concurrent workers cannot duplicate or
     * drop a member; without it each member is re-enqueued only by the worker that
     * wins its `ZREM`.
     */
    private function migrateDueDelayed(string $queue): void
    {
        $delayed = $queue . $this->delayedSuffix;

        if ($this->evaluate(self::MIGRATE_SCRIPT, [$delayed, $queue], [(string) time()])) {
            return;
        }

        /** @var list<string> $due */
        $due = $this->client->zrangebyscore($delayed, '-inf', (string) time());

        foreach ($due as $payload) {
            if ((int) $this->client->zrem($delayed, $payload) > 0) {
                $this->client->rpush($queue, [$payload]);
            }
        }
    }
}
