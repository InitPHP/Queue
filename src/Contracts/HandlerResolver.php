<?php

declare(strict_types=1);

namespace InitPHP\Queue\Contracts;

/**
 * Resolves the {@see Handler} responsible for a message URN.
 *
 * This is the routing seam between a consumed message and the code that handles
 * it. {@see \InitPHP\Queue\Routing\HandlerMap} is the bundled array-backed
 * implementation; an application that already has a PSR-11 container can
 * implement this interface over it instead, so handlers are constructed with
 * their dependencies.
 */
interface HandlerResolver
{
    /**
     * The handler mapped to `$urn`, or null when none is mapped (the worker then
     * applies its unknown-URN strategy).
     */
    public function resolve(string $urn): ?Handler;
}
