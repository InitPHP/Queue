<?php

declare(strict_types=1);

namespace InitPHP\Queue\Message;

use BabelQueue\Codec\EnvelopeCodec;
use Throwable;

/**
 * Counts a delivery that never ended in an ack, release or dead-letter (the worker
 * died mid-handler, or its reservation lapsed) by raising the envelope's `attempts`
 * by one. The transports use it when they hand such a message out again.
 *
 * @internal
 */
final class AbandonedDelivery
{
    /**
     * The body with `attempts` raised by one, or null when it cannot be counted: not
     * a decodable envelope (a poison message is quarantined by the dispatcher, not
     * counted), `attempts` already at the integer limit, or not re-encodable.
     */
    public static function countedBody(string $rawBody): ?string
    {
        $envelope = EnvelopeCodec::decode($rawBody);
        if ($envelope === []) {
            return null;
        }

        $attempts = is_int($envelope['attempts'] ?? null) ? $envelope['attempts'] : 0;
        if ($attempts >= PHP_INT_MAX) {
            return null;
        }
        $envelope['attempts'] = $attempts + 1;

        try {
            return EnvelopeCodec::encode($envelope);
        } catch (Throwable) {
            return null;
        }
    }
}
