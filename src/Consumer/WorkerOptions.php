<?php

declare(strict_types=1);

namespace InitPHP\Queue\Consumer;

use InitPHP\Queue\Exceptions\ConfigurationException;

/**
 * Immutable tuning for a {@see Worker}: how many times to retry, how long to wait
 * between attempts, and when to stop the loop.
 *
 * Limits default to "unlimited" (0) so a worker runs until signalled; setting
 * {@see self::$maxJobs}, {@see self::$maxRuntime} or {@see self::$memoryLimitMb}
 * makes it self-terminate, which pairs well with a process supervisor that
 * restarts it (the standard way to bound memory growth in long-running PHP).
 */
final class WorkerOptions
{
    /**
     * @param  int  $maxAttempts  Total delivery attempts before a failed message
     *                            is dead-lettered (must be >= 1).
     * @param  list<int>  $backoff  Per-attempt delay in seconds; the worker uses
     *                              `backoff[min(attempt-1, last)]`. `[0]` retries
     *                              immediately; `[1, 5, 15]` waits 1s, then 5s,
     *                              then 15s.
     * @param  float  $reserveTimeout  Seconds a blocking transport may wait for a
     *                                  message before returning null.
     * @param  float  $sleepWhenEmpty  Seconds to sleep when no message is ready
     *                                  (mainly for non-blocking transports).
     * @param  int  $maxJobs  Stop after processing this many messages (0 = unlimited).
     * @param  float  $maxRuntime  Stop after this many seconds (0 = unlimited).
     * @param  int  $memoryLimitMb  Stop once memory usage reaches this many MB (0 = unlimited).
     * @param  bool  $stopWhenEmpty  Stop as soon as the queue is empty (drain mode).
     *
     * @throws ConfigurationException When a value is out of range.
     */
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly array $backoff = [0],
        public readonly float $reserveTimeout = 5.0,
        public readonly float $sleepWhenEmpty = 0.5,
        public readonly int $maxJobs = 0,
        public readonly float $maxRuntime = 0.0,
        public readonly int $memoryLimitMb = 0,
        public readonly bool $stopWhenEmpty = false,
    ) {
        if ($maxAttempts < 1) {
            throw new ConfigurationException('maxAttempts must be at least 1.');
        }

        foreach ($backoff as $delay) {
            if ($delay < 0) {
                throw new ConfigurationException('backoff delays must be zero or positive.');
            }
        }

        if ($reserveTimeout < 0 || $sleepWhenEmpty < 0 || $maxJobs < 0 || $maxRuntime < 0 || $memoryLimitMb < 0) {
            throw new ConfigurationException('Worker option values must be zero or positive.');
        }
    }

    /**
     * The back-off delay, in seconds, to apply after the given (1-based) attempt
     * number has just failed.
     */
    public function delayForAttempt(int $attempt): int
    {
        if ($this->backoff === []) {
            return 0;
        }

        $index = min(max($attempt, 1) - 1, count($this->backoff) - 1);

        return $this->backoff[$index];
    }
}
