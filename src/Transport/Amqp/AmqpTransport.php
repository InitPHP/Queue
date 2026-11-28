<?php

declare(strict_types=1);

namespace InitPHP\Queue\Transport\Amqp;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\Contracts\Transport;
use BabelQueue\Transport\AmqpTransport as BabelAmqpTransport;
use InitPHP\Queue\Contracts\ConsumerTransport;
use InitPHP\Queue\Exceptions\QueueException;
use InitPHP\Queue\Message\AbandonedDelivery;
use InitPHP\Queue\Message\ReceivedMessage;
use PhpAmqpLib\Channel\AMQPChannel;
use ReflectionException;
use ReflectionProperty;

/**
 * A RabbitMQ transport that completes the SDK's publish-only
 * {@see BabelAmqpTransport} with the consume side.
 *
 * Publishing reuses the SDK transport (durable queue, persistent message, the
 * contract AMQP properties `type`/`correlation_id`/`message_id` and the
 * `x-schema-version`/`x-source-lang`/`x-attempts` headers), so a non-PHP consumer
 * can route and trace without decoding the body. Consuming pulls one message at a
 * time with `basic_get`; `ack` is `basic_ack` on the delivery tag.
 *
 * A message the broker delivers again because its first delivery was never acked
 * (a worker that died mid-handler) is counted as one failed attempt. RabbitMQ keeps
 * no delivery count on a classic queue, only a redelivered flag, and the body of the
 * unacked message cannot be rewritten in place, so the count is not carried across
 * further crashes: a handler that kills its process on every delivery is counted
 * once, not once per crash. See docs/Transport-RabbitMQ.md.
 *
 * Because RabbitMQ does not version a message body in place, a retry/dead-letter
 * republishes the updated envelope (so `attempts` and the `dead_letter` block
 * persist) and only then acks the original delivery. That order is deliberate: a
 * worker that dies between the two leaves a duplicate (which the dispatcher's
 * idempotency layer recognises), where acking first would leave nothing; and if
 * the publish fails the original is not acked and RabbitMQ redelivers it once the
 * channel closes. When the caller has put the channel in publisher-confirm mode
 * (`confirm_select()`), the ack also waits for the broker to confirm the new copy,
 * and a broker `nack` or a returned message counts as a failure: the original is
 * requeued, not acked, and a {@see QueueException} is thrown. Per-message delay is not applied — it
 * needs the delayed-message exchange plugin; configure that on the broker if you
 * need back-off windows.
 *
 * Optional dependency: `php-amqplib/php-amqplib`.
 */
final class AmqpTransport implements Transport, ConsumerTransport
{
    /** Seconds to wait for publisher confirms before giving up on the ack. */
    private const CONFIRM_TIMEOUT = 30;

    private readonly BabelAmqpTransport $publisher;

    public function __construct(
        private readonly AMQPChannel $channel,
        private readonly string $defaultQueue = 'default',
        private readonly string $failedSuffix = '.failed',
    ) {
        $this->publisher = new BabelAmqpTransport($channel, $defaultQueue);
    }

    public function publish(string $payload, ?string $queue = null): ?string
    {
        return $this->publisher->publish($payload, $queue);
    }

    public function reserve(string $queue, float $timeout = 5.0): ?ReceivedMessage
    {
        // passive=false, durable=true, exclusive=false, auto_delete=false — must
        // match the SDK publisher's declaration so the queue is compatible.
        $this->channel->queue_declare($queue, false, true, false, false);

        $message = $this->channel->basic_get($queue);
        if ($message === null) {
            return null;
        }

        $raw = $message->getBody();

        // The broker marks a message it delivers again after the first delivery was
        // never acked (the consumer died, or the channel closed mid-handler). That
        // delivery counts as an attempt. A retry, release or dead-letter publishes a
        // new message, so only a genuinely unfinished delivery carries the flag.
        $counted = $message->isRedelivered() === true ? AbandonedDelivery::countedBody($raw) : null;
        $body = $counted ?? $raw;

        return new ReceivedMessage($queue, $body, EnvelopeCodec::decode($body), $message->getDeliveryTag(), $counted !== null);
    }

    public function ack(ReceivedMessage $message): void
    {
        $this->channel->basic_ack($this->deliveryTag($message));
    }

    public function release(ReceivedMessage $message, string $payload, int $delaySeconds = 0): void
    {
        $this->republishThenAck($message, $payload, $message->queue());
    }

    public function deadLetter(ReceivedMessage $message, string $payload): void
    {
        $this->republishThenAck($message, $payload, $message->queue() . $this->failedSuffix);
    }

    /**
     * Publish the new copy, make sure the broker has it, then ack the original. A
     * failure before the ack leaves the original unacked (redelivered when the
     * channel closes); a broker `nack` or a returned copy requeues it at once.
     *
     * @throws QueueException When the broker refused the new copy.
     */
    private function republishThenAck(ReceivedMessage $message, string $payload, string $target): void
    {
        $this->publisher->publish($payload, $target);

        $refusal = $this->awaitConfirmation();
        if ($refusal !== null) {
            // The copy is not on the queue: keep the original by putting it back.
            $this->channel->basic_nack($this->deliveryTag($message), false, true);

            throw new QueueException(sprintf(
                'RabbitMQ %s the republished message for "%s"; the original was requeued, not acked.',
                $refusal,
                $target,
            ));
        }

        $this->channel->basic_ack($this->deliveryTag($message));
    }

    /**
     * Wait for the broker to settle every pending publish. Returns at once unless
     * the channel is in publisher-confirm mode. Returns `'nacked'` or `'returned'`
     * when the broker refused one, null when all were confirmed.
     *
     * The channel's own nack handler and return listener are wrapped, not replaced:
     * the caller's still run, and are put back afterwards. A timeout throws and
     * leaves the original unacked; the channel's pending confirms are then in an
     * unknown state, so restart the worker.
     */
    private function awaitConfirmation(): ?string
    {
        $refusal = null;
        $userNack = $this->channelCallback('nack_handler');
        $userReturn = $this->channelCallback('basic_return_callback');

        $this->channel->set_nack_handler(static function (mixed ...$arguments) use (&$refusal, $userNack): void {
            $refusal ??= 'nacked';
            if ($userNack !== null) {
                $userNack(...$arguments);
            }
        });
        $this->channel->set_return_listener(static function (mixed ...$arguments) use (&$refusal, $userReturn): void {
            $refusal ??= 'returned';
            if ($userReturn !== null) {
                $userReturn(...$arguments);
            }
        });

        try {
            $this->channel->wait_for_pending_acks_returns(self::CONFIRM_TIMEOUT);
        } finally {
            // amqplib cannot unset a handler; a no-op behaves exactly like none.
            $this->channel->set_nack_handler($userNack ?? static function (): void {});
            $this->channel->set_return_listener($userReturn ?? static function (): void {});
        }

        return $refusal;
    }

    /**
     * Read a callback php-amqplib keeps in a private property (it has no getter), so
     * that it can be wrapped instead of overwritten. Null when none is set or the
     * installed version stores it differently.
     */
    private function channelCallback(string $property): ?callable
    {
        try {
            $callback = (new ReflectionProperty(AMQPChannel::class, $property))->getValue($this->channel);
        } catch (ReflectionException) {
            return null;
        }

        return is_callable($callback) ? $callback : null;
    }

    private function deliveryTag(ReceivedMessage $message): int
    {
        return (int) $message->receipt();
    }
}
