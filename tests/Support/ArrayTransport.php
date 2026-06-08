<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Support;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\Contracts\Transport;
use InitPHP\Queue\Contracts\ConsumerTransport;
use InitPHP\Queue\Message\ReceivedMessage;

/**
 * An in-memory broker that implements both transport sides, so the {@see \InitPHP\Queue\Consumer\Worker}
 * and {@see \InitPHP\Queue\Producer\Producer} can be tested without a real broker.
 * It records dead-letters and releases for assertions.
 */
final class ArrayTransport implements Transport, ConsumerTransport
{
    /** @var array<string, list<string>> queue => ready payloads (FIFO) */
    private array $ready = [];

    /** @var array<int, array{queue: string, payload: string}> receipt => reserved entry */
    private array $reserved = [];

    /** @var array<string, list<string>> queue => dead-letter payloads */
    private array $dead = [];

    /** @var list<array{payload: string, delay: int}> every release(), in order */
    public array $releases = [];

    /** @var list<array{payload: string, queue: string}> every publish(), in order */
    public array $published = [];

    private int $receiptSeq = 0;

    public function __construct(private readonly string $defaultQueue = 'default')
    {
    }

    public function publish(string $payload, ?string $queue = null): ?string
    {
        $target = $queue ?? $this->defaultQueue;
        $this->ready[$target][] = $payload;
        $this->published[] = ['payload' => $payload, 'queue' => $target];

        return null;
    }

    public function reserve(string $queue, float $timeout = 5.0): ?ReceivedMessage
    {
        if (empty($this->ready[$queue])) {
            return null;
        }

        $payload = array_shift($this->ready[$queue]);
        $receipt = ++$this->receiptSeq;
        $this->reserved[$receipt] = ['queue' => $queue, 'payload' => $payload];

        return new ReceivedMessage($queue, $payload, EnvelopeCodec::decode($payload), $receipt);
    }

    public function ack(ReceivedMessage $message): void
    {
        unset($this->reserved[(int) $message->receipt()]);
    }

    public function release(ReceivedMessage $message, string $payload, int $delaySeconds = 0): void
    {
        unset($this->reserved[(int) $message->receipt()]);
        $this->releases[] = ['payload' => $payload, 'delay' => $delaySeconds];
        $this->ready[$message->queue()][] = $payload;
    }

    public function deadLetter(ReceivedMessage $message, string $payload): void
    {
        unset($this->reserved[(int) $message->receipt()]);
        $this->dead[$message->queue()][] = $payload;
    }

    public function readyCount(string $queue): int
    {
        return count($this->ready[$queue] ?? []);
    }

    public function reservedCount(): int
    {
        return count($this->reserved);
    }

    public function deadCount(string $queue): int
    {
        return count($this->dead[$queue] ?? []);
    }

    /**
     * @return list<string>
     */
    public function deadLetters(string $queue): array
    {
        return $this->dead[$queue] ?? [];
    }
}
