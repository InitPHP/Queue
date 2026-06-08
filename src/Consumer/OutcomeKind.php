<?php

declare(strict_types=1);

namespace InitPHP\Queue\Consumer;

/**
 * What a {@see \InitPHP\Queue\Consumer\Worker} should do with a message after the
 * {@see Dispatcher} has examined it. The worker, not the dispatcher, performs the
 * broker action — these are the dispatcher's decision, decoupled from transport.
 */
enum OutcomeKind
{
    /** Handled successfully — acknowledge and remove. */
    case Ack;

    /** Failed — apply the retry/back-off policy, dead-lettering once exhausted. */
    case Retry;

    /** Terminal failure — quarantine on the dead-letter queue immediately. */
    case DeadLetter;

    /** Acknowledge and silently discard (no handler, "delete" strategy). */
    case Drop;

    /** Put back verbatim without counting an attempt ("release" strategy). */
    case Release;
}
