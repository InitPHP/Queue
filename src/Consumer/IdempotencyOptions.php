<?php

declare(strict_types=1);

namespace InitPHP\Queue\Consumer;

use Closure;
use InitPHP\Queue\Contracts\IdempotencyStore;
use InitPHP\Queue\Exceptions\ConfigurationException;
use InitPHP\Queue\Message\ReceivedMessage;

/**
 * Opt-in configuration for the {@see Dispatcher}'s idempotency layer: the
 * {@see IdempotencyStore} that records seen messages, how a stable dedup key is
 * derived from each message, and the TTL/lease bookkeeping.
 *
 * Constructing one of these and passing it to the {@see Dispatcher} is the
 * single switch that turns deduplication on; with no instance (the default) the
 * dispatcher behaves exactly as before. It is immutable, mirroring
 * {@see WorkerOptions}.
 *
 * Key derivation, in order:
 *  - a caller-supplied `$keyResolver` (`fn (ReceivedMessage): ?string`) wins
 *    when it returns a non-empty string;
 *  - otherwise the producer-minted **`meta.id`** (the canonical message
 *    identity) is used;
 *  - failing that, the **`trace_id`** is used as a last resort.
 * A message that yields none of these has no stable identity and is processed
 * normally (the dispatcher cannot safely dedupe it).
 */
final class IdempotencyOptions
{
    /** @var (Closure(ReceivedMessage): ?string)|null */
    private readonly ?Closure $keyResolver;

    /**
     * @param  IdempotencyStore  $store  Backend recording processed/in-flight keys.
     * @param  string  $keyPrefix  Namespacing prefix for every key (lets one
     *                             shared store serve several workers without
     *                             collisions).
     * @param  int|null  $ttl  Seconds a processed key is retained (should
     *                         comfortably outlive the transport's redelivery
     *                         window); null retains it indefinitely.
     * @param  int  $leaseSeconds  How long an in-flight claim is held before it
     *                            may be reclaimed should the worker die mid-handler.
     * @param  (callable(ReceivedMessage): ?string)|null  $keyResolver  Optional
     *         custom key derivation; return null/'' to fall back to meta.id/trace_id.
     *
     * @throws ConfigurationException When a numeric option is out of range.
     */
    public function __construct(
        public readonly IdempotencyStore $store,
        public readonly string $keyPrefix = 'bq:idemp:',
        public readonly ?int $ttl = 86_400,
        public readonly int $leaseSeconds = 300,
        ?callable $keyResolver = null,
    ) {
        if ($ttl !== null && $ttl < 0) {
            throw new ConfigurationException('Idempotency ttl must be null or zero/positive.');
        }

        if ($leaseSeconds < 0) {
            throw new ConfigurationException('Idempotency leaseSeconds must be zero or positive.');
        }

        $this->keyResolver = $keyResolver !== null ? $keyResolver(...) : null;
    }

    /**
     * Derive the stable dedup key for `$message`, or null when the message has
     * no stable identity (in which case it is processed without deduplication).
     */
    public function keyFor(ReceivedMessage $message): ?string
    {
        $identity = $this->resolveIdentity($message);

        return $identity === null ? null : $this->keyPrefix . $identity;
    }

    private function resolveIdentity(ReceivedMessage $message): ?string
    {
        if ($this->keyResolver !== null) {
            $custom = ($this->keyResolver)($message);
            if (is_string($custom) && $custom !== '') {
                return $custom;
            }
        }

        $metaId = $message->getMeta()['id'] ?? null;
        if (is_string($metaId) && $metaId !== '') {
            return $metaId;
        }

        $traceId = $message->getTraceId();

        return $traceId !== '' ? $traceId : null;
    }
}
