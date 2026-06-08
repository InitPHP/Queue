<?php

declare(strict_types=1);

namespace InitPHP\Queue\Contracts;

use InitPHP\Queue\Message\ReceivedMessage;

/**
 * The consume side of a broker binding — the seam `babelqueue/php-sdk`
 * deliberately leaves to the framework (its {@see \BabelQueue\Contracts\Transport}
 * only publishes). A {@see \InitPHP\Queue\Consumer\Worker} drives one of these to
 * pull, acknowledge, retry and dead-letter messages, so the same loop works over
 * a database, Redis or RabbitMQ.
 *
 * Implementations are expected to also implement the SDK's `Transport` (publish)
 * so a single object is a complete producer+consumer binding; the bundled
 * {@see \InitPHP\Queue\Transport\Pdo\PdoTransport},
 * {@see \InitPHP\Queue\Transport\Redis\RedisTransport} and
 * {@see \InitPHP\Queue\Transport\Amqp\AmqpTransport} all do.
 *
 * Delivery is at-least-once: a message reserved by a worker that then dies must
 * become reservable again so it is not lost.
 */
interface ConsumerTransport
{
    /**
     * Reserve (exclusively claim) the next ready message on `$queue`, or return
     * null if none becomes available within `$timeout` seconds. A reserved
     * message is hidden from other workers until it is acked, released or its
     * reservation lapses.
     *
     * @param  float  $timeout  Maximum seconds to wait; transports that cannot
     *                          block return immediately (the worker then sleeps).
     */
    public function reserve(string $queue, float $timeout = 5.0): ?ReceivedMessage;

    /**
     * Acknowledge successful processing: remove the message permanently.
     */
    public function ack(ReceivedMessage $message): void;

    /**
     * Return the message to its queue for another attempt, replacing its body
     * with `$payload` (the re-encoded envelope, typically with an incremented
     * `attempts`). When `$delaySeconds > 0` the message must not become
     * reservable again until that delay has elapsed (best-effort on transports
     * without native delay support).
     */
    public function release(ReceivedMessage $message, string $payload, int $delaySeconds = 0): void;

    /**
     * Move the message off its queue onto the dead-letter queue, storing
     * `$payload` (the envelope annotated with a `dead_letter` block).
     */
    public function deadLetter(ReceivedMessage $message, string $payload): void;
}
