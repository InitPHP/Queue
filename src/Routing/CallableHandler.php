<?php

declare(strict_types=1);

namespace InitPHP\Queue\Routing;

use BabelQueue\Contracts\InboundMessage;
use InitPHP\Queue\Contracts\Handler;

/**
 * Adapts a closure into a {@see Handler}, so a URN can be mapped to an inline
 * function instead of a dedicated class. Used internally by {@see HandlerMap}
 * when a closure is registered.
 */
final class CallableHandler implements Handler
{
    /** @var \Closure(InboundMessage):void */
    private \Closure $callback;

    /**
     * @param  callable(InboundMessage):void  $callback
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback(...);
    }

    public function handle(InboundMessage $message): void
    {
        ($this->callback)($message);
    }
}
