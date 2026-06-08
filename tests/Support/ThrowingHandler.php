<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Support;

use BabelQueue\Contracts\InboundMessage;
use InitPHP\Queue\Contracts\Handler;
use RuntimeException;

/**
 * A handler that always throws, to drive the failure/retry/dead-letter paths.
 */
final class ThrowingHandler implements Handler
{
    public int $calls = 0;

    public function __construct(private readonly string $message = 'handler failed')
    {
    }

    public function handle(InboundMessage $message): void
    {
        ++$this->calls;

        throw new RuntimeException($this->message);
    }
}
