<?php

declare(strict_types=1);

namespace InitPHP\Queue\Consumer;

use Throwable;

/**
 * The immutable result of dispatching one message: an {@see OutcomeKind} plus the
 * dead-letter `reason` and the originating exception (when any), which the worker
 * forwards to {@see \BabelQueue\DeadLetter\DeadLetter::annotate()}.
 *
 * `reason` uses the canonical BabelQueue vocabulary — `failed` (a handler threw),
 * `unknown_urn` (no handler mapped) or one of the
 * {@see \BabelQueue\Validation\EnvelopeValidator}'s `REASON_*` values for a
 * malformed/poison envelope.
 */
final class Outcome
{
    private function __construct(
        public readonly OutcomeKind $kind,
        public readonly string $reason = '',
        public readonly ?Throwable $exception = null,
    ) {
    }

    /** Handled successfully. */
    public static function ack(): self
    {
        return new self(OutcomeKind::Ack);
    }

    /** The handler failed (or an unknown URN under the "fail" strategy); retry then dead-letter. */
    public static function retry(?Throwable $exception = null, string $reason = 'failed'): self
    {
        return new self(OutcomeKind::Retry, $reason, $exception);
    }

    /** Quarantine immediately without retrying. */
    public static function deadLetter(string $reason, ?Throwable $exception = null): self
    {
        return new self(OutcomeKind::DeadLetter, $reason, $exception);
    }

    /** Acknowledge and discard. */
    public static function drop(string $reason): self
    {
        return new self(OutcomeKind::Drop, $reason);
    }

    /** Put back verbatim, no attempt counted. */
    public static function release(string $reason): self
    {
        return new self(OutcomeKind::Release, $reason);
    }
}
