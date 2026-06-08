<?php

declare(strict_types=1);

namespace InitPHP\Queue\Exceptions;

use BabelQueue\Exceptions\BabelQueueException;

/**
 * Base exception for every error raised by the InitPHP Queue runtime.
 *
 * It extends {@see BabelQueueException} so a single `catch (BabelQueueException)`
 * captures both the envelope/codec errors thrown by the underlying
 * `babelqueue/php-sdk` and the runtime (worker, transport, routing) errors
 * thrown here.
 */
class QueueException extends BabelQueueException
{
}
