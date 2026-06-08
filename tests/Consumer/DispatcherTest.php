<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Consumer;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\Exceptions\UnknownUrnException;
use BabelQueue\Routing\UnknownUrnStrategy;
use BabelQueue\Validation\EnvelopeValidator;
use InitPHP\Queue\Consumer\Dispatcher;
use InitPHP\Queue\Consumer\OutcomeKind;
use InitPHP\Queue\Exceptions\ConfigurationException;
use InitPHP\Queue\Message\ReceivedMessage;
use InitPHP\Queue\Routing\HandlerMap;
use InitPHP\Queue\Tests\Support\RecordingHandler;
use InitPHP\Queue\Tests\Support\ThrowingHandler;
use PHPUnit\Framework\TestCase;

final class DispatcherTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $envelope
     */
    private function message(array $envelope): ReceivedMessage
    {
        return new ReceivedMessage('orders', EnvelopeCodec::encode($envelope), $envelope, 1);
    }

    private function valid(string $urn = 'urn:babel:orders:created'): ReceivedMessage
    {
        return $this->message(EnvelopeCodec::make($urn, ['order_id' => 1], 'orders'));
    }

    public function test_successful_handler_yields_ack(): void
    {
        $handler = new RecordingHandler();
        $map = (new HandlerMap())->register('urn:babel:orders:created', $handler);

        $outcome = (new Dispatcher($map))->dispatch($this->valid());

        self::assertSame(OutcomeKind::Ack, $outcome->kind);
        self::assertCount(1, $handler->handled);
    }

    public function test_throwing_handler_yields_retry_with_the_exception(): void
    {
        $map = (new HandlerMap())->register('urn:babel:orders:created', new ThrowingHandler('kaboom'));

        $outcome = (new Dispatcher($map))->dispatch($this->valid());

        self::assertSame(OutcomeKind::Retry, $outcome->kind);
        self::assertSame('failed', $outcome->reason);
        self::assertNotNull($outcome->exception);
        self::assertSame('kaboom', $outcome->exception->getMessage());
    }

    public function test_unknown_urn_with_fail_strategy_yields_retry(): void
    {
        $outcome = (new Dispatcher(new HandlerMap(), UnknownUrnStrategy::FAIL))->dispatch($this->valid('urn:none'));

        self::assertSame(OutcomeKind::Retry, $outcome->kind);
        self::assertSame('unknown_urn', $outcome->reason);
        self::assertInstanceOf(UnknownUrnException::class, $outcome->exception);
    }

    public function test_unknown_urn_with_delete_strategy_yields_drop(): void
    {
        $outcome = (new Dispatcher(new HandlerMap(), UnknownUrnStrategy::DELETE))->dispatch($this->valid('urn:none'));

        self::assertSame(OutcomeKind::Drop, $outcome->kind);
        self::assertSame('unknown_urn', $outcome->reason);
    }

    public function test_unknown_urn_with_release_strategy_yields_release(): void
    {
        $outcome = (new Dispatcher(new HandlerMap(), UnknownUrnStrategy::RELEASE))->dispatch($this->valid('urn:none'));

        self::assertSame(OutcomeKind::Release, $outcome->kind);
    }

    public function test_unknown_urn_with_dead_letter_strategy_yields_dead_letter(): void
    {
        $outcome = (new Dispatcher(new HandlerMap(), UnknownUrnStrategy::DEAD_LETTER))->dispatch($this->valid('urn:none'));

        self::assertSame(OutcomeKind::DeadLetter, $outcome->kind);
        self::assertSame('unknown_urn', $outcome->reason);
    }

    public function test_missing_urn_envelope_is_quarantined(): void
    {
        $outcome = (new Dispatcher(new HandlerMap()))->dispatch($this->message([
            'data' => [],
            'meta' => ['schema_version' => 1],
            'trace_id' => 'x',
            'attempts' => 0,
        ]));

        self::assertSame(OutcomeKind::DeadLetter, $outcome->kind);
        self::assertSame(EnvelopeValidator::REASON_MISSING_URN, $outcome->reason);
    }

    public function test_unsupported_schema_version_is_quarantined_not_dropped(): void
    {
        $envelope = EnvelopeCodec::make('urn:babel:orders:created', [], 'orders');
        $envelope['meta']['schema_version'] = 999;

        $outcome = (new Dispatcher(new HandlerMap()))->dispatch($this->message($envelope));

        self::assertSame(OutcomeKind::DeadLetter, $outcome->kind);
        self::assertSame(EnvelopeValidator::REASON_UNSUPPORTED_SCHEMA_VERSION, $outcome->reason);
    }

    public function test_unknown_strategy_is_rejected_at_construction(): void
    {
        $this->expectException(ConfigurationException::class);
        new Dispatcher(new HandlerMap(), 'requeue-please');
    }
}
