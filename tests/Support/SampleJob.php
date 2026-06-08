<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Support;

use BabelQueue\Contracts\HasTraceId;
use BabelQueue\Contracts\PolyglotJob;

/**
 * A producible message used in producer/codec tests. Implements {@see HasTraceId}
 * so trace-id propagation can be exercised.
 *
 * @param array<string, mixed> $payload
 */
final class SampleJob implements PolyglotJob, HasTraceId
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly string $urn = 'urn:babel:orders:created',
        private readonly array $payload = ['order_id' => 1042],
        private readonly ?string $traceId = null,
    ) {
    }

    public function getBabelUrn(): string
    {
        return $this->urn;
    }

    public function toPayload(): array
    {
        return $this->payload;
    }

    public function getBabelTraceId(): ?string
    {
        return $this->traceId;
    }
}
