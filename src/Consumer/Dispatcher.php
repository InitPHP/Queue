<?php

declare(strict_types=1);

namespace InitPHP\Queue\Consumer;

use BabelQueue\Exceptions\UnknownUrnException;
use BabelQueue\Routing\UnknownUrnStrategy;
use BabelQueue\Validation\EnvelopeValidator;
use InitPHP\Queue\Contracts\HandlerResolver;
use InitPHP\Queue\Exceptions\ConfigurationException;
use InitPHP\Queue\Message\ReceivedMessage;
use Throwable;

/**
 * Turns a reserved message into an {@see Outcome} — the routing brain of the
 * consumer, with no broker knowledge of its own.
 *
 * The pipeline is: validate the envelope (a malformed/unsupported one is
 * quarantined, never dropped — per the SDK's "quarantine, don't drop" rule),
 * resolve the handler by URN, and run it. A handler that returns is an ack; one
 * that throws is a retry; an unmapped URN is resolved by the configured
 * {@see UnknownUrnStrategy} (`fail` / `delete` / `release` / `dead_letter`).
 */
final class Dispatcher
{
    /**
     * @param  HandlerResolver  $resolver  Maps a message URN to its handler.
     * @param  string  $unknownUrnStrategy  One of the {@see UnknownUrnStrategy} constants.
     *
     * @throws ConfigurationException When `$unknownUrnStrategy` is not a known strategy.
     */
    public function __construct(
        private readonly HandlerResolver $resolver,
        private readonly string $unknownUrnStrategy = UnknownUrnStrategy::FAIL,
    ) {
        if (! in_array($unknownUrnStrategy, UnknownUrnStrategy::all(), true)) {
            throw new ConfigurationException(sprintf(
                'Unknown unknown-URN strategy "%s"; expected one of: %s.',
                $unknownUrnStrategy,
                implode(', ', UnknownUrnStrategy::all()),
            ));
        }
    }

    /**
     * Decide what should happen to `$message`.
     */
    public function dispatch(ReceivedMessage $message): Outcome
    {
        $reason = EnvelopeValidator::check($message->envelope());
        if ($reason !== null) {
            // Malformed body or an unsupported schema_version: quarantine it
            // rather than dropping a message a newer producer may rely on.
            return Outcome::deadLetter($reason);
        }

        $handler = $this->resolver->resolve($message->getUrn());
        if ($handler === null) {
            return $this->onUnknownUrn($message);
        }

        try {
            $handler->handle($message);

            return Outcome::ack();
        } catch (Throwable $e) {
            return Outcome::retry($e, 'failed');
        }
    }

    private function onUnknownUrn(ReceivedMessage $message): Outcome
    {
        return match ($this->unknownUrnStrategy) {
            UnknownUrnStrategy::DELETE => Outcome::drop('unknown_urn'),
            UnknownUrnStrategy::RELEASE => Outcome::release('unknown_urn'),
            UnknownUrnStrategy::DEAD_LETTER => Outcome::deadLetter('unknown_urn'),
            default => Outcome::retry(
                new UnknownUrnException(sprintf('No handler is mapped for URN "%s".', $message->getUrn())),
                'unknown_urn',
            ),
        };
    }
}
