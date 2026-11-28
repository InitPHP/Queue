<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Consumer;

use BabelQueue\Codec\EnvelopeCodec;
use InitPHP\Queue\Consumer\Dispatcher;
use InitPHP\Queue\Consumer\Worker;
use InitPHP\Queue\Consumer\WorkerOptions;
use InitPHP\Queue\Contracts\ConsumerTransport;
use InitPHP\Queue\Exceptions\QueueException;
use InitPHP\Queue\Message\ReceivedMessage;
use InitPHP\Queue\Routing\HandlerMap;
use InitPHP\Queue\Tests\Support\ArrayTransport;
use InitPHP\Queue\Tests\Support\RecordingHandler;
use InitPHP\Queue\Transport\Amqp\AmqpTransport;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;

/**
 * A message the transport refuses to settle (RabbitMQ nacking the republish, say)
 * comes straight back. The worker must not run its handler again in a tight loop.
 */
final class WorkerFailurePauseTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const URN = 'urn:babel:orders:created';

    private function raw(int $attempts = 0): string
    {
        $envelope = EnvelopeCodec::make(self::URN, ['order_id' => 1], 'orders');
        $envelope['attempts'] = $attempts;

        return EnvelopeCodec::encode($envelope);
    }

    /**
     * A transport that always hands the same message back and refuses to settle it.
     */
    private function refusingTransport(string $raw): ConsumerTransport
    {
        return new class ($raw) implements ConsumerTransport {
            public function __construct(private readonly string $raw) {}

            public function reserve(string $queue, float $timeout = 5.0): ?ReceivedMessage
            {
                return new ReceivedMessage($queue, $this->raw, EnvelopeCodec::decode($this->raw), 1);
            }

            public function ack(ReceivedMessage $message): void
            {
                throw new QueueException('refused');
            }

            public function release(ReceivedMessage $message, string $payload, int $delaySeconds = 0): void
            {
                throw new QueueException('refused');
            }

            public function deadLetter(ReceivedMessage $message, string $payload): void
            {
                throw new QueueException('refused');
            }
        };
    }

    public function test_a_message_the_transport_keeps_refusing_does_not_spin_its_handler(): void
    {
        $handler = new RecordingHandler();
        $worker = new Worker(
            $this->refusingTransport($this->raw()),
            new Dispatcher((new HandlerMap())->register(self::URN, $handler)),
            new WorkerOptions(sleepWhenEmpty: 0.0, maxRuntime: 0.65),
        );

        $worker->run('orders');

        self::assertGreaterThanOrEqual(1, count($handler->handled));
        self::assertLessThanOrEqual(8, count($handler->handled), 'about one run per 0.1 s pause, not thousands');
    }

    public function test_the_pause_follows_the_back_off_and_a_stop_ends_it_at_once(): void
    {
        $worker = null;
        $runs = 0;
        $handlers = (new HandlerMap())->register(self::URN, function () use (&$worker, &$runs): void {
            ++$runs;
            $worker->stop(); // a stop signal arrives while the failure pause would start
        });
        $worker = new Worker(
            $this->refusingTransport($this->raw()),
            new Dispatcher($handlers),
            new WorkerOptions(backoff: [30], sleepWhenEmpty: 0.0),
        );

        $startedAt = microtime(true);
        $worker->run('orders');

        self::assertSame(1, $runs);
        self::assertLessThan(1.0, microtime(true) - $startedAt, 'the 5 s pause is skipped once a stop is requested');
    }

    public function test_a_rabbitmq_nack_of_the_dead_letter_republish_does_not_spin_the_handler(): void
    {
        // The dead-letter queue refuses publishes (x-overflow reject-publish) and the
        // message is at maxAttempts, so every run ends in a nack and a requeue.
        $body = $this->raw(attempts: 2);
        $nackHandler = null;

        $delivered = Mockery::mock(AMQPMessage::class);
        $delivered->shouldReceive('getBody')->andReturn($body);
        $delivered->shouldReceive('getDeliveryTag')->andReturn(7);
        $delivered->shouldReceive('isRedelivered')->andReturn(false);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('queue_declare');
        $channel->shouldReceive('basic_get')->andReturn($delivered);
        $channel->shouldReceive('basic_publish');
        $channel->shouldReceive('set_return_listener');
        $channel->shouldReceive('set_nack_handler')->andReturnUsing(function (callable $handler) use (&$nackHandler): void {
            $nackHandler = $handler;
        });
        $channel->shouldReceive('wait_for_pending_acks_returns')->andReturnUsing(function () use (&$nackHandler): void {
            $nackHandler(new AMQPMessage('refused'));
        });
        $channel->shouldReceive('basic_nack')->with(7, false, true);
        $channel->shouldNotReceive('basic_ack');

        $handler = new class implements \InitPHP\Queue\Contracts\Handler {
            public int $calls = 0;

            public function handle(\BabelQueue\Contracts\InboundMessage $message): void
            {
                ++$this->calls;

                throw new \RuntimeException('always fails');
            }
        };
        $worker = new Worker(
            new AmqpTransport($channel),
            new Dispatcher((new HandlerMap())->register(self::URN, $handler)),
            new WorkerOptions(maxAttempts: 3, backoff: [0], sleepWhenEmpty: 0.0, maxRuntime: 0.45),
        );

        $worker->run('orders');

        self::assertGreaterThanOrEqual(1, $handler->calls);
        self::assertLessThanOrEqual(8, $handler->calls, 'paced by the failure pause, not a tight loop');
    }

    /**
     * @return float the failure pause for a message with `$attempts`
     */
    private function pauseFor(WorkerOptions $options, int $attempts): float
    {
        $worker = new Worker($this->refusingTransport($this->raw()), new Dispatcher(new HandlerMap()), $options);
        $message = new ReceivedMessage('orders', $this->raw($attempts), EnvelopeCodec::decode($this->raw($attempts)), 1);

        return (new \ReflectionMethod($worker, 'failurePauseSeconds'))->invoke($worker, $message);
    }

    public function test_the_pause_is_the_larger_of_the_floor_the_idle_sleep_and_the_back_off(): void
    {
        self::assertSame(0.1, $this->pauseFor(new WorkerOptions(backoff: [0], sleepWhenEmpty: 0.0), 0), 'the floor');
        self::assertSame(2.0, $this->pauseFor(new WorkerOptions(backoff: [0], sleepWhenEmpty: 2.0), 0), 'sleepWhenEmpty');
        self::assertSame(3.0, $this->pauseFor(new WorkerOptions(backoff: [1, 3], sleepWhenEmpty: 0.5), 1), 'the back-off for the next attempt');
        self::assertSame(1.0, $this->pauseFor(new WorkerOptions(backoff: [1, 3], sleepWhenEmpty: 0.5), 0), 'attempt 1 uses the first back-off');
    }

    public function test_the_pause_is_capped_at_five_seconds(): void
    {
        self::assertSame(5.0, $this->pauseFor(new WorkerOptions(backoff: [30], sleepWhenEmpty: 0.0), 0));
        self::assertSame(5.0, $this->pauseFor(new WorkerOptions(backoff: [0], sleepWhenEmpty: 60.0), 0));
    }

    public function test_a_message_with_the_largest_possible_attempts_does_not_crash_the_worker(): void
    {
        $transport = new ArrayTransport();
        $envelope = EnvelopeCodec::make(self::URN, ['order_id' => 1], 'orders');
        $envelope['attempts'] = PHP_INT_MAX; // a foreign or damaged message
        $transport->publish(EnvelopeCodec::encode($envelope), 'orders');
        $transport->publish($this->raw(), 'orders');

        $handler = new RecordingHandler();
        $handlers = (new HandlerMap())
            ->register(self::URN, static function (\BabelQueue\Contracts\InboundMessage $message) use ($handler): void {
                if ($message->attempts() === PHP_INT_MAX) {
                    throw new \RuntimeException('poison');
                }
                $handler->handle($message);
            });
        $worker = new Worker(
            $transport,
            new Dispatcher($handlers),
            new WorkerOptions(sleepWhenEmpty: 0.0, stopWhenEmpty: true, maxRuntime: 3.0),
        );

        $worker->run('orders');

        self::assertCount(1, $handler->handled, 'the next message was still processed');
    }

    public function test_an_undocumented_back_off_shape_does_not_crash_the_worker(): void
    {
        // `backoff` is documented as list<int>; a map or floats must still not make
        // the failure pause throw (2.2 logged process.failed and carried on).
        self::assertSame(5.0, $this->pauseFor(new WorkerOptions(backoff: [1 => 5], sleepWhenEmpty: 0.0), 0), 'a map is read as its values');
        self::assertSame(0.5, $this->pauseFor(new WorkerOptions(backoff: [0.5], sleepWhenEmpty: 0.0), 0), 'a float is kept');

        // End to end with floats (a map also triggers a PHP warning in the retry path itself, as in 2.2).
        foreach ([[0.5]] as $backoff) {
            $transport = new ArrayTransport();
            $transport->publish($this->raw(), 'orders');
            $transport->publish($this->raw(), 'orders');
            $events = [];
            $handler = new RecordingHandler();
            $failing = true;
            $handlers = (new HandlerMap())->register(self::URN, static function (\BabelQueue\Contracts\InboundMessage $message) use ($handler, &$failing): void {
                if ($failing) {
                    $failing = false;
                    throw new \RuntimeException('first one fails');
                }
                $handler->handle($message);
            });
            $worker = new Worker(
                $transport,
                new Dispatcher($handlers),
                new WorkerOptions(backoff: $backoff, sleepWhenEmpty: 0.0, stopWhenEmpty: true, maxRuntime: 4.0),
                function (string $level, string $event) use (&$events): void {
                    $events[] = $event;
                },
            );

            $worker->run('orders');

            self::assertSame(1, count($handler->handled), 'the next message was still processed');
            self::assertContains('process.failed', $events, 'the failure is logged, as in 2.2');
        }
    }
}
