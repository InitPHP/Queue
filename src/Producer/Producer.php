<?php

declare(strict_types=1);

namespace InitPHP\Queue\Producer;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\Contracts\PolyglotJob;
use BabelQueue\Contracts\Transport;

/**
 * A thin producer facade over the SDK's {@see EnvelopeCodec} and a publish-only
 * {@see Transport}. It encodes a canonical envelope and hands the JSON to the
 * transport, so a plain-PHP app dispatches a polyglot message in one line and a
 * consumer in any language can read it.
 *
 * Pair it with any `Transport` — the bundled {@see \InitPHP\Queue\Transport\Pdo\PdoTransport},
 * {@see \InitPHP\Queue\Transport\Redis\RedisTransport},
 * {@see \InitPHP\Queue\Transport\Amqp\AmqpTransport}, or the SDK's own reference
 * transports.
 */
final class Producer
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $defaultQueue = 'default',
    ) {
    }

    /**
     * Publish a {@see PolyglotJob}. Its URN and payload come from the job; an
     * inherited trace id is honoured when the job implements
     * {@see \BabelQueue\Contracts\HasTraceId}.
     *
     * @param  string|null  $queue  Logical queue; defaults to the producer's default.
     * @return string|null  The transport's message id, if it exposes one.
     *
     * @throws \BabelQueue\Exceptions\BabelQueueException When the job exposes an empty URN.
     * @throws \JsonException When the payload is not cleanly JSON-encodable.
     */
    public function dispatch(PolyglotJob $job, ?string $queue = null): ?string
    {
        $target = $queue ?? $this->defaultQueue;

        return $this->transport->publish(
            EnvelopeCodec::encode(EnvelopeCodec::fromJob($job, $target)),
            $target,
        );
    }

    /**
     * Publish directly from a URN and a pure-JSON payload, without a job object.
     *
     * @param  array<string, mixed>  $data  Pure, JSON-serialisable payload.
     * @param  string|null  $queue  Logical queue; defaults to the producer's default.
     * @param  string|null  $traceId  A trace id to continue, or null to mint one.
     * @return string|null  The transport's message id, if it exposes one.
     *
     * @throws \BabelQueue\Exceptions\BabelQueueException When `$urn` is empty.
     * @throws \JsonException When `$data` is not cleanly JSON-encodable.
     */
    public function send(string $urn, array $data = [], ?string $queue = null, ?string $traceId = null): ?string
    {
        $target = $queue ?? $this->defaultQueue;

        return $this->transport->publish(
            EnvelopeCodec::encode(EnvelopeCodec::make($urn, $data, $target, $traceId)),
            $target,
        );
    }
}
