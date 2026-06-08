<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Support;

use BabelQueue\Contracts\InboundMessage;
use InitPHP\Queue\Contracts\Handler;

/**
 * A handler that records every message it receives, for assertions.
 */
final class RecordingHandler implements Handler
{
    /** @var list<InboundMessage> */
    public array $handled = [];

    public function handle(InboundMessage $message): void
    {
        $this->handled[] = $message;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payloads(): array
    {
        return array_map(static fn (InboundMessage $m): array => $m->getData(), $this->handled);
    }
}
