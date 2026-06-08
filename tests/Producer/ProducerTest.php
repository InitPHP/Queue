<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Producer;

use BabelQueue\Codec\EnvelopeCodec;
use InitPHP\Queue\Producer\Producer;
use InitPHP\Queue\Tests\Support\ArrayTransport;
use InitPHP\Queue\Tests\Support\SampleJob;
use PHPUnit\Framework\TestCase;

final class ProducerTest extends TestCase
{
    public function test_send_publishes_a_canonical_envelope_to_the_default_queue(): void
    {
        $transport = new ArrayTransport();
        (new Producer($transport, 'orders'))->send('urn:babel:orders:created', ['order_id' => 1042]);

        self::assertCount(1, $transport->published);
        self::assertSame('orders', $transport->published[0]['queue']);

        $envelope = EnvelopeCodec::decode($transport->published[0]['payload']);
        self::assertSame('urn:babel:orders:created', $envelope['job']);
        self::assertSame(['order_id' => 1042], $envelope['data']);
        self::assertSame('orders', $envelope['meta']['queue']);
        self::assertSame('php', $envelope['meta']['lang']);
        self::assertSame(1, $envelope['meta']['schema_version']);
        self::assertSame(0, $envelope['attempts']);
        self::assertNotSame('', $envelope['trace_id']);
    }

    public function test_send_can_target_an_explicit_queue(): void
    {
        $transport = new ArrayTransport();
        (new Producer($transport, 'default'))->send('urn:a', [], 'mail');

        self::assertSame('mail', $transport->published[0]['queue']);
        self::assertSame('mail', EnvelopeCodec::decode($transport->published[0]['payload'])['meta']['queue']);
    }

    public function test_dispatch_uses_the_job_urn_and_payload(): void
    {
        $transport = new ArrayTransport();
        (new Producer($transport))->dispatch(new SampleJob('urn:babel:mail:sent', ['to' => 'a@b.c']), 'mail');

        $envelope = EnvelopeCodec::decode($transport->published[0]['payload']);
        self::assertSame('urn:babel:mail:sent', $envelope['job']);
        self::assertSame(['to' => 'a@b.c'], $envelope['data']);
    }

    public function test_dispatch_propagates_an_inherited_trace_id(): void
    {
        $transport = new ArrayTransport();
        $trace = '11111111-2222-4333-8444-555555555555';
        (new Producer($transport, 'orders'))->dispatch(new SampleJob('urn:a', [], $trace));

        self::assertSame($trace, EnvelopeCodec::decode($transport->published[0]['payload'])['trace_id']);
    }
}
