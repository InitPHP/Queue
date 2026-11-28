<?php

declare(strict_types=1);

namespace InitPHP\Queue\Transport\Redis;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\Contracts\Transport;
use BabelQueue\Transport\RedisTransport as BabelRedisTransport;
use BadMethodCallException;
use Error;
use InitPHP\Queue\Contracts\ConsumerTransport;
use InitPHP\Queue\Message\AbandonedDelivery;
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
 * A handler that kills its process (OOM, `exit`, SIGKILL) never reports a failure,
 * and the receipt is the message body itself, so the body cannot carry the fact
 * that it was started. `reserve()` therefore records each delivery in a
 * `<queue>:started` sorted set keyed by the SHA-1 of the body, and `ack()`,
 * `release()` and `deadLetter()` remove it. A body that comes back to `reserve()`
 * with its entry still present was started and never finished (your reaper moved
 * it from `<queue>:processing` back to the queue): that delivery is counted by
 * raising `attempts` in the body, in the processing entry too, so the count survives
 * the next crash and `maxAttempts` ends the message. Nothing about the body format
 * changes and a worker on an older version simply does not take part; entries
 * older than a week are pruned. A client that lacks the sorted-set commands this
 * uses is not an error: the tracking is switched off and `reserve()` behaves as before.
 *
 * Requires Redis 6.2+ (for `BLMOVE`; Lua scripting is built in). Optional
 * dependency: `predis/predis`.
 */
final class RedisTransport implements Transport, ConsumerTransport
{
    /**
     * KEYS: processing, queue, delayed, started. ARGV: receipt, payload, due time
     * (0 = now), started-set member. Takes the worker's own processing entry, or
     * else the live copy (parked in the delayed set, or on the queue), and puts the
     * new payload in its place; the delivery is no longer started.
     */
    private const RELEASE_SCRIPT = <<<'LUA'
        -- queue:release
        if redis.call('LREM', KEYS[1], 1, ARGV[1]) < 1 then
            if redis.call('ZREM', KEYS[3], ARGV[1]) < 1 and redis.call('LREM', KEYS[2], 1, ARGV[1]) < 1 then
                return 0
            end
        end
        redis.call('ZREM', KEYS[4], ARGV[4])
        if tonumber(ARGV[3]) > 0 then
            redis.call('ZADD', KEYS[3], ARGV[3], ARGV[2])
        else
            redis.call('RPUSH', KEYS[2], ARGV[2])
        end
        return 1
        LUA;

    /**
     * KEYS: processing, failed, delayed, queue, started. ARGV: receipt, payload,
     * started-set member.
     */
    private const DEAD_LETTER_SCRIPT = <<<'LUA'
        -- queue:dead-letter
        if redis.call('LREM', KEYS[1], 1, ARGV[1]) < 1 then
            if redis.call('ZREM', KEYS[3], ARGV[1]) < 1 and redis.call('LREM', KEYS[4], 1, ARGV[1]) < 1 then
                return 0
            end
        end
        redis.call('ZREM', KEYS[5], ARGV[3])
        redis.call('RPUSH', KEYS[2], ARGV[2])
        return 1
        LUA;

    /**
     * KEYS: processing, started. ARGV: old body, new body, old member, new member,
     * now. Swaps the processing entry for the body with the counted attempt and moves
     * the started-set entry with it. Returns 0 when the entry is no longer there.
     */
    private const COUNT_SCRIPT = <<<'LUA'
        -- queue:count-abandoned
        if redis.call('LINSERT', KEYS[1], 'AFTER', ARGV[1], ARGV[2]) <= 0 then
            return 0
        end
        redis.call('LREM', KEYS[1], 1, ARGV[1])
        redis.call('ZREM', KEYS[2], ARGV[3])
        redis.call('ZADD', KEYS[2], ARGV[5], ARGV[4])
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

    /** Seconds a started-set entry is kept before it is pruned (nobody reaped the message by then). */
    private const STARTED_TTL = 604800;

    /** The started set is pruned once per this many reservations. */
    private const PRUNE_EVERY = 100;

    private readonly BabelRedisTransport $publisher;

    /** Whether the client can EVAL; cleared the first time it proves it cannot. */
    private bool $scripting = true;

    /** Whether the client has the sorted-set commands delivery tracking needs; cleared when it proves it has not. */
    private bool $tracking = true;

    private int $reservations = 0;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly string $defaultQueue = 'default',
        private readonly string $processingSuffix = ':processing',
        private readonly string $failedSuffix = ':failed',
        private readonly string $delayedSuffix = ':delayed',
        private readonly string $startedSuffix = ':started',
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

        [$raw, $abandoned] = $this->markStarted($queue, (string) $reserved);

        return new ReceivedMessage($queue, $raw, EnvelopeCodec::decode($raw), $raw, $abandoned);
    }

    public function ack(ReceivedMessage $message): void
    {
        $this->client->lrem($message->queue() . $this->processingSuffix, 1, (string) $message->receipt());
        $this->clearStarted($message);
    }

    public function release(ReceivedMessage $message, string $payload, int $delaySeconds = 0): void
    {
        $queue = $message->queue();
        $processing = $queue . $this->processingSuffix;
        $delayed = $queue . $this->delayedSuffix;
        $receipt = (string) $message->receipt();
        $due = $delaySeconds > 0 ? time() + $delaySeconds : 0;

        $args = [$receipt, $payload, (string) $due, sha1($receipt)];
        if ($this->evaluate(self::RELEASE_SCRIPT, [$processing, $queue, $delayed, $queue . $this->startedSuffix], $args) !== null) {
            return;
        }

        // Without scripting: the same steps, one command at a time.
        if (! $this->takeOwnership($processing, $delayed, $queue, $receipt)) {
            return;
        }
        $this->clearStarted($message);

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

        $keys = [$processing, $failed, $delayed, $queue, $queue . $this->startedSuffix];
        if ($this->evaluate(self::DEAD_LETTER_SCRIPT, $keys, [$receipt, $payload, sha1($receipt)]) !== null) {
            return;
        }

        if ($this->takeOwnership($processing, $delayed, $queue, $receipt)) {
            $this->clearStarted($message);
            $this->client->rpush($failed, [$payload]);
        }
    }

    /**
     * Record that the delivery just taken is being started and, when the same body
     * was already recorded as started and never finished, count that earlier
     * delivery: the body (and its processing entry) is replaced by one with
     * `attempts` raised by one. Returns the body to hand out and whether it was
     * counted. A failure here must not stop consumption, so a client without the
     * commands only turns the tracking off.
     *
     * @return array{0: string, 1: bool}
     */
    private function markStarted(string $queue, string $raw): array
    {
        if (! $this->tracking) {
            return [$raw, false];
        }

        $started = $queue . $this->startedSuffix;
        $now = time();

        try {
            if (++$this->reservations % self::PRUNE_EVERY === 1) {
                $this->client->zremrangebyscore($started, '-inf', (string) ($now - self::STARTED_TTL));
            }

            // ZADD answers 1 for a new member, 0 for one that was there: a body
            // started before and never finished.
            $unfinished = (int) $this->client->zadd($started, [sha1($raw) => $now]) === 0;
            if (! $unfinished) {
                return [$raw, false];
            }

            $counted = AbandonedDelivery::countedBody($raw);
            if ($counted === null) {
                return [$raw, false];
            }

            return $this->swapProcessingEntry($queue, $raw, $counted, $now) ? [$counted, true] : [$raw, false];
        } catch (Throwable $e) {
            // The message is already on the processing list: failing here would
            // strand it there. Counting is best effort, so hand the message out as it
            // is. A client or Redis user that lacks one of the commands (NOPERM, no
            // such method) turns the counting off, as 2.3 never needed them.
            if ($this->isCommandUnavailable($e)) {
                $this->tracking = false;
            }

            return [$raw, false];
        }
    }

    /**
     * Replace the worker's processing entry `$raw` with `$counted` and move the
     * started-set entry with it, so the count survives the next crash. False when the
     * entry is gone (a reaper moved it back in the meantime).
     */
    private function swapProcessingEntry(string $queue, string $raw, string $counted, int $now): bool
    {
        $processing = $queue . $this->processingSuffix;
        $started = $queue . $this->startedSuffix;
        $args = [$raw, $counted, sha1($raw), sha1($counted), (string) $now];

        $swapped = $this->evaluate(self::COUNT_SCRIPT, [$processing, $started], $args);
        if ($swapped !== null) {
            return $swapped === 1;
        }

        if ((int) $this->client->linsert($processing, 'AFTER', $raw, $counted) <= 0) {
            return false;
        }
        $this->client->lrem($processing, 1, $raw);
        $this->client->zrem($started, sha1($raw));
        $this->client->zadd($started, [sha1($counted) => $now]);

        return true;
    }

    /**
     * Forget the started-set entry of a delivery that has an outcome. Best effort: an
     * entry that stays is pruned after a week and costs nothing else.
     */
    private function clearStarted(ReceivedMessage $message): void
    {
        if (! $this->tracking) {
            return;
        }

        try {
            $this->client->zrem($message->queue() . $this->startedSuffix, sha1((string) $message->receipt()));
        } catch (Throwable $e) {
            // The message already has its outcome; a leftover entry is only pruned later.
            if ($this->isCommandUnavailable($e)) {
                $this->tracking = false;
            }
        }
    }

    /**
     * Run one of the scripts and return its integer result. Returns null (without
     * having done anything) when the client cannot evaluate scripts, so the caller
     * can take the command-by-command path; any other failure, such as a lost
     * connection, is rethrown.
     *
     * @param  list<string>  $keys
     * @param  list<string>  $args
     */
    private function evaluate(string $script, array $keys, array $args): ?int
    {
        if (! $this->scripting) {
            return null;
        }

        try {
            return (int) $this->client->eval($script, count($keys), ...$keys, ...$args);
        } catch (Throwable $e) {
            if (! $this->isCommandUnavailable($e)) {
                throw $e;
            }

            $this->scripting = false;

            return null;
        }
    }

    /**
     * Whether `$e` means "this client cannot run that command" (no such method, a
     * different signature, or a server that refuses it: `NOPERM`, unknown command)
     * rather than a real failure.
     */
    private function isCommandUnavailable(Throwable $e): bool
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

        if ($this->evaluate(self::MIGRATE_SCRIPT, [$delayed, $queue], [(string) time()]) !== null) {
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
