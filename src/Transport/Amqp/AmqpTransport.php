<?php

declare(strict_types=1);

namespace InitPHP\Queue\Transport\Amqp;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\Contracts\Transport;
use BabelQueue\Transport\AmqpTransport as BabelAmqpTransport;
use InitPHP\Queue\Contracts\ConsumerTransport;
use InitPHP\Queue\Message\ReceivedMessage;
use PhpAmqpLib\Channel\AMQPChannel;

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
 * Because RabbitMQ does not version a message body in place, a retry/dead-letter
 * acks the original delivery and republishes the updated envelope (so `attempts`
 * and the `dead_letter` block persist). Per-message delay is not applied — it
 * needs the delayed-message exchange plugin; configure that on the broker if you
 * need back-off windows.
 *
 * Optional dependency: `php-amqplib/php-amqplib`.
 */
final class AmqpTransport implements Transport, ConsumerTransport
{
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

        return new ReceivedMessage($queue, $raw, EnvelopeCodec::decode($raw), $message->getDeliveryTag());
    }

    public function ack(ReceivedMessage $message): void
    {
        $this->channel->basic_ack($this->deliveryTag($message));
    }

    public function release(ReceivedMessage $message, string $payload, int $delaySeconds = 0): void
    {
        $this->channel->basic_ack($this->deliveryTag($message));
        $this->publisher->publish($payload, $message->queue());
    }

    public function deadLetter(ReceivedMessage $message, string $payload): void
    {
        $this->channel->basic_ack($this->deliveryTag($message));
        $this->publisher->publish($payload, $message->queue() . $this->failedSuffix);
    }

    private function deliveryTag(ReceivedMessage $message): int
    {
        return (int) $message->receipt();
    }
}
