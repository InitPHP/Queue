<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Support;

use BadMethodCallException;
use Predis\ClientInterface;
use Predis\Command\CommandInterface;

/**
 * A tiny in-memory stand-in for a Predis client implementing just the list and
 * sorted-set commands {@see \InitPHP\Queue\Transport\Redis\RedisTransport} uses
 * (`BLMOVE`, `RPUSH`, `LREM`, `ZADD`, `ZRANGEBYSCORE`, `ZREM`). The remaining
 * {@see ClientInterface} methods are not exercised by the transport and throw.
 */
final class InMemoryRedis implements ClientInterface
{
    /** @var array<string, list<string>> */
    private array $lists = [];

    /** @var array<string, array<string, float>> member => score */
    private array $zsets = [];

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
            static fn (float $score): bool => $score >= $low && $score <= $high,
        );
        asort($matching);

        return array_map(strval(...), array_keys($matching));
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
     * @return list<string>
     */
    public function list(string $key): array
    {
        return $this->lists[$key] ?? [];
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
