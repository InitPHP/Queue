<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Support;

use InitPHP\Queue\Contracts\ConsumerTransport;
use InitPHP\Queue\Message\ReceivedMessage;

/**
 * Wraps a transport and, while `$dropOutcomes` is set, swallows every ack, release
 * and dead-letter: the handler ran but its worker died before reporting anything
 * (OOM, `exit`, SIGKILL), so the transport never hears an outcome.
 */
final class OutcomeDroppingTransport implements ConsumerTransport
{
    public bool $dropOutcomes = true;

    public function __construct(private readonly ConsumerTransport $inner) {}

    public function reserve(string $queue, float $timeout = 5.0): ?ReceivedMessage
    {
        return $this->inner->reserve($queue, $timeout);
    }

    public function ack(ReceivedMessage $message): void
    {
        if (! $this->dropOutcomes) {
            $this->inner->ack($message);
        }
    }

    public function release(ReceivedMessage $message, string $payload, int $delaySeconds = 0): void
    {
        if (! $this->dropOutcomes) {
            $this->inner->release($message, $payload, $delaySeconds);
        }
    }

    public function deadLetter(ReceivedMessage $message, string $payload): void
    {
        if (! $this->dropOutcomes) {
            $this->inner->deadLetter($message, $payload);
        }
    }
}
