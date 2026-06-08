<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Consumer;

use BabelQueue\Codec\EnvelopeCodec;
use InitPHP\Queue\Consumer\Dispatcher;
use InitPHP\Queue\Consumer\Worker;
use InitPHP\Queue\Consumer\WorkerOptions;
use InitPHP\Queue\Contracts\ConsumerTransport;
use InitPHP\Queue\Message\ReceivedMessage;
use InitPHP\Queue\Routing\HandlerMap;
use InitPHP\Queue\Tests\Support\ArrayTransport;
use InitPHP\Queue\Tests\Support\RecordingHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkerResilienceTest extends TestCase
{
    private function envelope(): string
    {
        return EnvelopeCodec::encode(EnvelopeCodec::make('urn:t', ['n' => 1], 'orders'));
    }

    public function test_a_reserve_error_is_logged_and_the_loop_keeps_going(): void
    {
        $events = [];
        $transport = new class () implements ConsumerTransport {
            public int $reserveCalls = 0;

            public function reserve(string $queue, float $timeout = 5.0): ?ReceivedMessage
            {
                ++$this->reserveCalls;
                if ($this->reserveCalls === 1) {
                    throw new RuntimeException('broker down');
                }

                return null;
            }

            public function ack(ReceivedMessage $message): void
            {
            }

            public function release(ReceivedMessage $message, string $payload, int $delaySeconds = 0): void
            {
            }

            public function deadLetter(ReceivedMessage $message, string $payload): void
            {
            }
        };

        $worker = new Worker(
            $transport,
            new Dispatcher(new HandlerMap()),
            new WorkerOptions(stopWhenEmpty: true, sleepWhenEmpty: 0.0),
            function (string $level, string $event, array $context) use (&$events): void {
                $events[] = $event;
            },
        );
        $worker->run('orders');

        self::assertContains('reserve.failed', $events);
        self::assertSame(2, $transport->reserveCalls, 'the loop continued past the failure');
        self::assertSame(0, $worker->processedCount());
    }

    public function test_a_processing_error_is_logged_without_crashing_the_worker(): void
    {
        $events = [];
        $handler = new RecordingHandler();
        $transport = new class ($this->envelope()) implements ConsumerTransport {
            private bool $served = false;

            public function __construct(private readonly string $raw)
            {
            }

            public function reserve(string $queue, float $timeout = 5.0): ?ReceivedMessage
            {
                if ($this->served) {
                    return null;
                }
                $this->served = true;

                return new ReceivedMessage($queue, $this->raw, EnvelopeCodec::decode($this->raw), 1);
            }

            public function ack(ReceivedMessage $message): void
            {
                throw new RuntimeException('ack failed');
            }

            public function release(ReceivedMessage $message, string $payload, int $delaySeconds = 0): void
            {
            }

            public function deadLetter(ReceivedMessage $message, string $payload): void
            {
            }
        };

        $worker = new Worker(
            $transport,
            new Dispatcher((new HandlerMap())->register('urn:t', $handler)),
            new WorkerOptions(stopWhenEmpty: true, sleepWhenEmpty: 0.0),
            function (string $level, string $event, array $context) use (&$events): void {
                $events[] = $event;
            },
        );
        $worker->run('orders');

        self::assertCount(1, $handler->handled, 'the handler still ran');
        self::assertContains('process.failed', $events);
        self::assertSame(1, $worker->processedCount());
    }

    public function test_memory_limit_stops_the_loop_before_processing(): void
    {
        $transport = new ArrayTransport();
        $transport->publish($this->envelope(), 'orders');

        // memory_get_usage(true) is always well above 1 MB, so the guard trips on the first check.
        $worker = new Worker(
            $transport,
            new Dispatcher((new HandlerMap())->register('urn:t', new RecordingHandler())),
            new WorkerOptions(memoryLimitMb: 1, stopWhenEmpty: true),
        );
        $worker->run('orders');

        self::assertSame(0, $worker->processedCount());
        self::assertSame(1, $transport->readyCount('orders'));
    }

    public function test_a_handler_can_stop_the_worker_mid_run(): void
    {
        $transport = new ArrayTransport();
        $transport->publish($this->envelope(), 'orders');
        $transport->publish($this->envelope(), 'orders');

        $worker = null;
        $map = (new HandlerMap())->register('urn:t', function () use (&$worker): void {
            $worker?->stop();
        });
        $worker = new Worker($transport, new Dispatcher($map), new WorkerOptions(stopWhenEmpty: true));
        $worker->run('orders');

        self::assertSame(1, $worker->processedCount());
        self::assertSame(1, $transport->readyCount('orders'), 'the second message is left untouched');
    }
}
