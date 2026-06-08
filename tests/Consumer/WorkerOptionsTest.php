<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Consumer;

use InitPHP\Queue\Consumer\WorkerOptions;
use InitPHP\Queue\Exceptions\ConfigurationException;
use PHPUnit\Framework\TestCase;

final class WorkerOptionsTest extends TestCase
{
    public function test_back_off_is_clamped_to_the_last_entry(): void
    {
        $options = new WorkerOptions(backoff: [1, 5, 15]);

        self::assertSame(1, $options->delayForAttempt(1));
        self::assertSame(5, $options->delayForAttempt(2));
        self::assertSame(15, $options->delayForAttempt(3));
        self::assertSame(15, $options->delayForAttempt(4), 'beyond the list, the last delay holds');
    }

    public function test_attempt_below_one_uses_the_first_delay(): void
    {
        $options = new WorkerOptions(backoff: [2, 9]);

        self::assertSame(2, $options->delayForAttempt(0));
    }

    public function test_empty_back_off_means_no_delay(): void
    {
        $options = new WorkerOptions(backoff: []);

        self::assertSame(0, $options->delayForAttempt(1));
        self::assertSame(0, $options->delayForAttempt(9));
    }

    public function test_defaults_are_sensible(): void
    {
        $options = new WorkerOptions();

        self::assertSame(3, $options->maxAttempts);
        self::assertSame(0, $options->delayForAttempt(1));
        self::assertFalse($options->stopWhenEmpty);
    }

    public function test_max_attempts_must_be_at_least_one(): void
    {
        $this->expectException(ConfigurationException::class);
        new WorkerOptions(maxAttempts: 0);
    }

    public function test_negative_back_off_is_rejected(): void
    {
        $this->expectException(ConfigurationException::class);
        new WorkerOptions(backoff: [1, -3]);
    }

    public function test_negative_limit_is_rejected(): void
    {
        $this->expectException(ConfigurationException::class);
        new WorkerOptions(maxJobs: -1);
    }
}
