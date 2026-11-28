<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Support;

use BadMethodCallException;
use Predis\ClientInterface;
use Predis\Command\CommandInterface;

/**
 * A tiny in-memory stand-in for a Predis client implementing just the list and
 * sorted-set commands {@see \InitPHP\Queue\Transport\Redis\RedisTransport} uses
 * (`BLMOVE`, `RPUSH`, `LREM`, `LINSERT`, `ZADD`, `ZRANGEBYSCORE`, `ZREMRANGEBYSCORE`,
 * `ZREM`) plus an `eval()` that recognises the transport's four scripts by their leading marker comment
 * and applies the same steps atomically (the real scripts run against Redis in
 * the integration test). The remaining
 * {@see ClientInterface} methods are not exercised by the transport and throw.
 */
final class InMemoryRedis implements ClientInterface
{
    /** @var array<string, list<string>> */
    private array $lists = [];

    /** @var array<string, array<string, float>> member => score */
    private array $zsets = [];

    /**
     * @param  bool  $scripting  False models a client without EVAL (the documented
     *                           adapter surface, or a user without scripting rights).
     */
    public function __construct(private readonly bool $scripting = true) {}

    public function blmove(string $source, string $destination, string $from, string $to, int $timeout): ?string
    {
        if (empty($this->lists[$source])) {
            return null;
        }

        $value = $from === 'LEFT' ? array_shift($this->lists[$source]) : array_pop($this->lists[$source]);

        if ($to === 'RIGHT') {
            $this->lists[$destination][] = $value;
        } else {
            array_unshift($this->lists[$destination], $value);
        }

        return $value;
    }

    /**
     * @param  list<string>  $values
     */
    public function rpush(string $key, array $values): int
    {
        foreach ($values as $value) {
            $this->lists[$key][] = $value;
        }

        return count($this->lists[$key]);
    }

    public function lrem(string $key, int $count, string $value): int
    {
        $removed = 0;
        $kept = [];

        foreach ($this->lists[$key] ?? [] as $item) {
            if ($removed < $count && $item === $value) {
                ++$removed;

                continue;
            }
            $kept[] = $item;
        }

        $this->lists[$key] = $kept;

        return $removed;
    }

    /**
     * @param  array<string, int|float>  $memberScores
     */
    public function zadd(string $key, array $memberScores): int
    {
        $added = 0;

        foreach ($memberScores as $member => $score) {
            if (! isset($this->zsets[$key][(string) $member])) {
                ++$added;
            }
            $this->zsets[$key][(string) $member] = (float) $score;
        }

        return $added;
    }

    /**
     * @return list<string>
     */
    public function zrangebyscore(string $key, string $min, string $max): array
    {
        $low = $min === '-inf' ? -INF : (float) $min;
        $high = $max === '+inf' ? INF : (float) $max;

        $matching = array_filter(
            $this->zsets[$key] ?? [],
            static fn(float $score): bool => $score >= $low && $score <= $high,
        );
        asort($matching);

        return array_map(strval(...), array_keys($matching));
    }

    public function zremrangebyscore(string $key, string $min, string $max): int
    {
        $removed = 0;
        foreach ($this->zrangebyscore($key, $min, $max) as $member) {
            $removed += $this->zrem($key, $member);
        }

        return $removed;
    }

    /**
     * @return int the new list length, or -1 when the pivot is not in the list
     */
    public function linsert(string $key, string $whence, string $pivot, string $value): int
    {
        $list = $this->lists[$key] ?? [];
        $at = array_search($pivot, $list, true);
        if ($at === false) {
            return -1;
        }

        array_splice($list, $whence === 'BEFORE' ? $at : $at + 1, 0, [$value]);
        $this->lists[$key] = $list;

        return count($list);
    }

    public function zrem(string $key, string $member): int
    {
        if (! isset($this->zsets[$key][$member])) {
            return 0;
        }

        unset($this->zsets[$key][$member]);

        return 1;
    }

    /**
     * Emulates EVAL for the transport's scripts only. With `$scripting` false it
     * behaves like an adapter that does not implement `eval` at all.
     *
     * @return int
     */
    public function eval(string $script, int $numKeys, string ...$args): mixed
    {
        if (! $this->scripting) {
            throw new BadMethodCallException('InMemoryRedis does not implement "eval".');
        }

        $keys = array_slice($args, 0, $numKeys);
        $argv = array_slice($args, $numKeys);

        if (str_contains($script, '-- queue:release')) {
            if (! $this->takeOwnership($keys[0], $keys[2], $keys[1], $argv[0])) {
                return 0;
            }
            $this->zrem($keys[3], $argv[3]);
            (float) $argv[2] > 0
                ? $this->zadd($keys[2], [$argv[1] => (float) $argv[2]])
                : $this->rpush($keys[1], [$argv[1]]);

            return 1;
        }

        if (str_contains($script, '-- queue:dead-letter')) {
            if (! $this->takeOwnership($keys[0], $keys[2], $keys[3], $argv[0])) {
                return 0;
            }
            $this->zrem($keys[4], $argv[2]);
            $this->rpush($keys[1], [$argv[1]]);

            return 1;
        }

        if (str_contains($script, '-- queue:count-abandoned')) {
            if ($this->linsert($keys[0], 'AFTER', $argv[0], $argv[1]) <= 0) {
                return 0;
            }
            $this->lrem($keys[0], 1, $argv[0]);
            $this->zrem($keys[1], $argv[2]);
            $this->zadd($keys[1], [$argv[3] => (float) $argv[4]]);

            return 1;
        }

        if (str_contains($script, '-- queue:migrate')) {
            $due = array_slice($this->zrangebyscore($keys[0], '-inf', $argv[0]), 0, 1000);
            foreach ($due as $member) {
                $this->zrem($keys[0], $member);
                $this->rpush($keys[1], [$member]);
            }

            return count($due);
        }

        throw new BadMethodCallException('InMemoryRedis cannot evaluate this script.');
    }

    /** The processing entry, else the live copy in the delayed set, else on the queue. */
    private function takeOwnership(string $processing, string $delayed, string $queue, string $receipt): bool
    {
        return $this->lrem($processing, 1, $receipt) > 0
            || $this->zrem($delayed, $receipt) > 0
            || $this->lrem($queue, 1, $receipt) > 0;
    }

    /**
     * @return list<string>
     */
    public function list(string $key): array
    {
        return $this->lists[$key] ?? [];
    }

    /**
     * @return list<string> the members of a sorted set, by score
     */
    public function members(string $key): array
    {
        return $this->zrangebyscore($key, '-inf', '+inf');
    }

    public function zcard(string $key): int
    {
        return count($this->zsets[$key] ?? []);
    }

    public function getCommandFactory()
    {
        throw new BadMethodCallException('not supported');
    }

    public function getOptions()
    {
        throw new BadMethodCallException('not supported');
    }

    public function connect()
    {
        throw new BadMethodCallException('not supported');
    }

    public function disconnect()
    {
        throw new BadMethodCallException('not supported');
    }

    public function getConnection()
    {
        throw new BadMethodCallException('not supported');
    }

    public function createCommand($method, $arguments = [])
    {
        throw new BadMethodCallException('not supported');
    }

    public function executeCommand(CommandInterface $command)
    {
        throw new BadMethodCallException('not supported');
    }

    public function __call($method, $arguments)
    {
        throw new BadMethodCallException(sprintf('InMemoryRedis does not implement "%s".', $method));
    }
}
