<?php

declare(strict_types=1);

namespace InitPHP\Queue\Exceptions;

/**
 * Thrown for programmer/configuration mistakes that are detectable before any
 * message flows — an empty URN registration, an unknown unknown-URN strategy, a
 * handler class that does not exist or does not implement {@see \InitPHP\Queue\Contracts\Handler}.
 *
 * These are bugs to fix in code, not transient runtime failures, so they are
 * never retried or dead-lettered — they surface immediately.
 */
final class ConfigurationException extends QueueException
{
}
