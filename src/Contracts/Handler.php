<?php

declare(strict_types=1);

namespace InitPHP\Queue\Contracts;

use BabelQueue\Contracts\InboundMessage;

/**
 * Handles one consumed message type.
 *
 * A handler is mapped to a message URN (never a PHP class name) in a
 * {@see HandlerResolver}, so a Go/Python/Node producer that emits the same URN is
 * routed here without sharing any PHP type. The decoded envelope is exposed as a
 * read-only {@see InboundMessage} (`getUrn()`, `getTraceId()`, `getData()`,
 * `getMeta()`).
 *
 * Contract: return normally to signal success — the worker acknowledges the
 * message. **Throw** any exception to signal failure — the worker applies its
 * retry/back-off policy and dead-letters the message once attempts are
 * exhausted. A handler must therefore be safe to run more than once for the same
 * message (at-least-once delivery): make it idempotent.
 */
interface Handler
{
    /**
     * Process the message, or throw to fail it.
     *
     * @throws \Throwable Any throwable marks the message as failed.
     */
    public function handle(InboundMessage $message): void;
}
