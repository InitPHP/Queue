<?php

declare(strict_types=1);

namespace InitPHP\Queue\Message;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\Contracts\InboundMessage;

/**
 * A message reserved from a {@see \InitPHP\Queue\Contracts\ConsumerTransport}: the
 * decoded BabelQueue envelope plus the bookkeeping a worker needs to act on it.
 *
 * It implements {@see InboundMessage} so handlers receive a read-only, typed view
 * of the envelope. Beyond that read-only view it also exposes the queue it came
 * from, the raw JSON body (for verbatim re-queueing), the current `attempts`
 * count and an opaque transport `receipt` — the token (AMQP delivery tag, PDO row
 * id, Redis payload) the *originating* transport uses to ack/release/dead-letter
 * it. The receipt is only ever interpreted by the transport that minted it.
 */
final class ReceivedMessage implements InboundMessage
{
    /**
     * @param  string  $queue    The logical queue this message was reserved from.
     * @param  string  $rawBody  The exact JSON body as received.
     * @param  array<string, mixed>  $envelope  The decoded envelope (may be [] for a malformed body).
     * @param  mixed   $receipt  Opaque ack handle understood only by the originating transport.
     * @param  bool    $abandoned  True when the transport is handing the message out again after a
     *         delivery that was never acknowledged, released or dead-lettered (the worker died or
     *         the reservation lapsed), and has already counted that delivery in the envelope's
     *         `attempts`. A transport that cannot tell leaves it false.
     */
    public function __construct(
        private readonly string $queue,
        private readonly string $rawBody,
        private readonly array $envelope,
        private readonly mixed $receipt,
        private readonly bool $abandoned = false,
    ) {}

    /**
     * The message URN — its language-agnostic identity. Accepts the inbound
     * "urn" alias for "job" via the SDK codec.
     */
    public function getUrn(): string
    {
        return EnvelopeCodec::urn($this->envelope);
    }

    /**
     * The cross-service correlation id, or '' when absent.
     */
    public function getTraceId(): string
    {
        $traceId = $this->envelope['trace_id'] ?? null;

        return is_string($traceId) ? $traceId : '';
    }

    /**
     * The decoded "data" block (the business payload), or [] when absent.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $data = $this->envelope['data'] ?? null;

        return is_array($data) ? $data : [];
    }

    /**
     * The decoded "meta" block, or [] when absent.
     *
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        $meta = $this->envelope['meta'] ?? null;

        return is_array($meta) ? $meta : [];
    }

    /**
     * The logical queue this message was reserved from.
     */
    public function queue(): string
    {
        return $this->queue;
    }

    /**
     * The exact JSON body as received — used to re-queue a message verbatim
     * (e.g. an unknown-URN "release") without re-encoding.
     */
    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * The full decoded envelope, including any non-contract keys.
     *
     * @return array<string, mixed>
     */
    public function envelope(): array
    {
        return $this->envelope;
    }

    /**
     * The number of failed attempts already recorded on this message (the
     * envelope's top-level "attempts"), or 0 when absent/!int.
     */
    public function attempts(): int
    {
        $attempts = $this->envelope['attempts'] ?? null;

        return is_int($attempts) ? $attempts : 0;
    }

    /**
     * Whether a previous delivery of this message ended without an outcome (the
     * worker died mid-handler or its reservation lapsed) and the transport counted
     * it in {@see attempts()}.
     */
    public function wasAbandoned(): bool
    {
        return $this->abandoned;
    }

    /**
     * The opaque transport ack handle. Only the originating transport may
     * interpret it.
     */
    public function receipt(): mixed
    {
        return $this->receipt;
    }
}
