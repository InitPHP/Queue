<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Routing;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\Contracts\InboundMessage;
use InitPHP\Queue\Contracts\Handler;
use InitPHP\Queue\Exceptions\ConfigurationException;
use InitPHP\Queue\Message\ReceivedMessage;
use InitPHP\Queue\Routing\CallableHandler;
use InitPHP\Queue\Tests\Support\RecordingHandler;
use PHPUnit\Framework\TestCase;
use stdClass;

final class HandlerMapTest extends TestCase
{
    private function message(string $urn): ReceivedMessage
    {
        $envelope = EnvelopeCodec::make($urn, [], 'q');

        return new ReceivedMessage('q', EnvelopeCodec::encode($envelope), $envelope, 1);
    }

    public function test_resolves_a_registered_instance(): void
    {
        $handler = new RecordingHandler();
        $map = new \InitPHP\Queue\Routing\HandlerMap();
        $map->register('urn:a', $handler);

        self::assertTrue($map->has('urn:a'));
        self::assertSame($handler, $map->resolve('urn:a'));
    }

    public function test_resolves_a_closure_as_a_callable_handler(): void
    {
        $seen = null;
        $map = new \InitPHP\Queue\Routing\HandlerMap();
        $map->register('urn:a', function (InboundMessage $message) use (&$seen): void {
            $seen = $message->getUrn();
        });

        $resolved = $map->resolve('urn:a');
        self::assertInstanceOf(CallableHandler::class, $resolved);

        $resolved->handle($this->message('urn:a'));
        self::assertSame('urn:a', $seen);
    }

    public function test_resolves_a_class_string_lazily_and_caches_it(): void
    {
        $map = new \InitPHP\Queue\Routing\HandlerMap();
        $map->register('urn:a', RecordingHandler::class);

        $first = $map->resolve('urn:a');
        $second = $map->resolve('urn:a');

        self::assertInstanceOf(RecordingHandler::class, $first);
        self::assertSame($first, $second, 'resolved instance is cached');
    }

    public function test_unmapped_urn_resolves_to_null(): void
    {
        self::assertNull((new \InitPHP\Queue\Routing\HandlerMap())->resolve('urn:missing'));
    }

    public function test_re_registering_replaces_the_previous_handler(): void
    {
        $first = new RecordingHandler();
        $second = new RecordingHandler();
        $map = new \InitPHP\Queue\Routing\HandlerMap();
        $map->register('urn:a', $first)->register('urn:a', $second);

        self::assertSame($second, $map->resolve('urn:a'));
    }

    public function test_empty_urn_is_rejected(): void
    {
        $this->expectException(ConfigurationException::class);
        (new \InitPHP\Queue\Routing\HandlerMap())->register('   ', new RecordingHandler());
    }

    public function test_unknown_class_is_rejected_on_resolve(): void
    {
        $map = new \InitPHP\Queue\Routing\HandlerMap();
        /** @phpstan-ignore-next-line intentional bad class name */
        $map->register('urn:a', 'No\\Such\\HandlerClass');

        $this->expectException(ConfigurationException::class);
        $map->resolve('urn:a');
    }

    public function test_non_handler_class_is_rejected_on_resolve(): void
    {
        $map = new \InitPHP\Queue\Routing\HandlerMap();
        $map->register('urn:a', stdClass::class);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/must implement/');
        $map->resolve('urn:a');
    }

    public function test_a_concrete_handler_can_be_used_via_resolver(): void
    {
        $handler = new class () implements Handler {
            public bool $ran = false;

            public function handle(InboundMessage $message): void
            {
                $this->ran = true;
            }
        };

        $map = new \InitPHP\Queue\Routing\HandlerMap();
        $map->register('urn:a', $handler);
        $map->resolve('urn:a')?->handle($this->message('urn:a'));

        self::assertTrue($handler->ran);
    }
}
