<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Routing;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\Contracts\InboundMessage;
use InitPHP\Queue\Message\ReceivedMessage;
use InitPHP\Queue\Routing\CallableHandler;
use PHPUnit\Framework\TestCase;

final class CallableHandlerTest extends TestCase
{
    public function test_it_forwards_the_message_to_the_callable(): void
    {
        $received = null;
        $handler = new CallableHandler(function (InboundMessage $message) use (&$received): void {
            $received = $message;
        });

        $envelope = EnvelopeCodec::make('urn:a', ['k' => 'v'], 'q');
        $message = new ReceivedMessage('q', EnvelopeCodec::encode($envelope), $envelope, 1);

        $handler->handle($message);

        self::assertSame($message, $received);
        self::assertSame(['k' => 'v'], $received->getData());
    }
}
