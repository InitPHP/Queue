<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Message;

use BabelQueue\Codec\EnvelopeCodec;
use InitPHP\Queue\Message\ReceivedMessage;
use PHPUnit\Framework\TestCase;

final class ReceivedMessageTest extends TestCase
{
    public function test_it_exposes_the_decoded_envelope(): void
    {
        $envelope = EnvelopeCodec::make('urn:babel:orders:created', ['order_id' => 7], 'orders');
        $raw = EnvelopeCodec::encode($envelope);
        $message = new ReceivedMessage('orders', $raw, $envelope, 99);

        self::assertSame('urn:babel:orders:created', $message->getUrn());
        self::assertSame(['order_id' => 7], $message->getData());
        self::assertSame('orders', $message->queue());
        self::assertSame($raw, $message->rawBody());
        self::assertSame($envelope, $message->envelope());
        self::assertSame(99, $message->receipt());
        self::assertSame(0, $message->attempts());
        self::assertSame('php', $message->getMeta()['lang']);
        self::assertNotSame('', $message->getTraceId());
    }

    public function test_a_malformed_envelope_degrades_safely(): void
    {
        $message = new ReceivedMessage('orders', 'not-json', [], null);

        self::assertSame('', $message->getUrn());
        self::assertSame([], $message->getData());
        self::assertSame([], $message->getMeta());
        self::assertSame('', $message->getTraceId());
        self::assertSame(0, $message->attempts());
        self::assertNull($message->receipt());
    }

    public function test_attempts_reflects_the_envelope_value(): void
    {
        $envelope = EnvelopeCodec::make('urn:a', [], 'q');
        $envelope['attempts'] = 4;
        $message = new ReceivedMessage('q', EnvelopeCodec::encode($envelope), $envelope, 1);

        self::assertSame(4, $message->attempts());
    }
}
