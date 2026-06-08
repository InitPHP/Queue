<?php

declare(strict_types=1);

namespace InitPHP\Queue\Routing;

use Closure;
use InitPHP\Queue\Contracts\Handler;
use InitPHP\Queue\Contracts\HandlerResolver;
use InitPHP\Queue\Exceptions\ConfigurationException;

/**
 * The bundled array-backed {@see HandlerResolver}: a URN → handler registry.
 *
 * A handler may be registered as a ready {@see Handler} instance, a closure
 * (wrapped in a {@see CallableHandler}), or the class name of a no-argument
 * {@see Handler} (lazily instantiated on first use). Applications needing
 * constructor injection should back a {@see HandlerResolver} with their own
 * container instead.
 */
final class HandlerMap implements HandlerResolver
{
    /** @var array<string, Handler|Closure|class-string> */
    private array $handlers = [];

    /** @var array<string, Handler> Lazily resolved instances, keyed by URN. */
    private array $resolved = [];

    /**
     * Map `$urn` to a handler. Re-registering a URN replaces the previous entry.
     *
     * @param  Handler|Closure|class-string  $handler  A handler instance, a
     *         `fn (InboundMessage $m): void` closure, or a no-arg handler class name.
     *
     * @throws ConfigurationException When `$urn` is empty.
     */
    public function register(string $urn, Handler|Closure|string $handler): self
    {
        if (trim($urn) === '') {
            throw new ConfigurationException(
                'Cannot register a handler for an empty URN; a message type must have a stable identity.',
            );
        }

        $this->handlers[$urn] = $handler;
        unset($this->resolved[$urn]);

        return $this;
    }

    /**
     * Whether a handler is mapped for `$urn`.
     */
    public function has(string $urn): bool
    {
        return isset($this->handlers[$urn]);
    }

    public function resolve(string $urn): ?Handler
    {
        if (isset($this->resolved[$urn])) {
            return $this->resolved[$urn];
        }

        if (! isset($this->handlers[$urn])) {
            return null;
        }

        return $this->resolved[$urn] = $this->toHandler($urn, $this->handlers[$urn]);
    }

    /**
     * @param  Handler|Closure|class-string  $handler
     *
     * @throws ConfigurationException When a class name is not a constructible {@see Handler}.
     */
    private function toHandler(string $urn, Handler|Closure|string $handler): Handler
    {
        if ($handler instanceof Handler) {
            return $handler;
        }

        if ($handler instanceof Closure) {
            return new CallableHandler($handler);
        }

        if (! class_exists($handler)) {
            throw new ConfigurationException(sprintf(
                'Handler "%s" mapped to URN "%s" is not a class that exists.',
                $handler,
                $urn,
            ));
        }

        $instance = new $handler();

        if (! $instance instanceof Handler) {
            throw new ConfigurationException(sprintf(
                'Handler "%s" mapped to URN "%s" must implement %s.',
                $handler,
                $urn,
                Handler::class,
            ));
        }

        return $instance;
    }
}
