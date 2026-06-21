<?php

declare(strict_types=1);

namespace InitPHP\Queue\Consumer;

use BabelQueue\Exceptions\UnknownUrnException;
use BabelQueue\Routing\UnknownUrnStrategy;
use BabelQueue\Validation\EnvelopeValidator;
use InitPHP\Queue\Contracts\Handler;
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
 *
 * When an {@see IdempotencyOptions} is supplied the pipeline gains a
 * deduplication step: a redelivered message whose key is already recorded is
 * dropped without re-running the handler, and a key is only recorded *after*
 * the handler succeeds — so a throwing handler stays retryable. With no options
 * (the default) the dispatcher behaves exactly as before.
 */
final class Dispatcher
{
    /**
     * @param  HandlerResolver  $resolver  Maps a message URN to its handler.
     * @param  string  $unknownUrnStrategy  One of the {@see UnknownUrnStrategy} constants.
     * @param  IdempotencyOptions|null  $idempotency  Opt-in deduplication; null
     *         (the default) disables it and preserves the prior behaviour.
     *
     * @throws ConfigurationException When `$unknownUrnStrategy` is not a known strategy.
     */
    public function __construct(
        private readonly HandlerResolver $resolver,
        private readonly string $unknownUrnStrategy = UnknownUrnStrategy::FAIL,
        private readonly ?IdempotencyOptions $idempotency = null,
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

        return $this->runHandler($handler, $message);
    }

    /**
     * Run the resolved handler, guarded by the idempotency layer when enabled.
     *
     * The dedup key is claimed *before* the handler runs (so a concurrent
     * redelivery is skipped) and only committed with {@see IdempotencyStore::remember()}
     * *after* the handler returns — a throwing handler leaves the key
     * uncommitted, so the at-least-once redelivery is retried as normal.
     */
    private function runHandler(Handler $handler, ReceivedMessage $message): Outcome
    {
        $key = $this->idempotency?->keyFor($message);

        // A duplicate that is already recorded: it was processed before, so
        // acknowledge and discard rather than running the handler again.
        if ($key !== null && ! $this->idempotency->store->claim($key, $this->idempotency->leaseSeconds)) {
            return Outcome::drop('duplicate');
        }

        try {
            $handler->handle($message);
        } catch (Throwable $e) {
            // Release the in-flight claim so the redelivery can re-run; leaving
            // it set would suppress a legitimate retry.
            if ($key !== null) {
                $this->idempotency->store->forget($key);
            }

            return Outcome::retry($e, 'failed');
        }

        if ($key !== null) {
            $this->idempotency->store->remember($key, $this->idempotency->ttl);
        }

        return Outcome::ack();
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
