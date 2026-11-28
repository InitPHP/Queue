<?php

declare(strict_types=1);

namespace InitPHP\Queue\Consumer;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\DeadLetter\DeadLetter;
use InitPHP\Queue\Contracts\ConsumerTransport;
use InitPHP\Queue\Message\ReceivedMessage;
use JsonException;
use Throwable;

/**
 * The framework-less consumer loop — the worker `babelqueue/php-sdk` leaves to
 * the framework. It reserves messages from a {@see ConsumerTransport}, asks a
 * {@see Dispatcher} what to do with each, and performs the resulting broker
 * action (ack / retry-with-back-off / dead-letter), so the same loop runs over a
 * database, Redis or RabbitMQ.
 *
 * Failure handling realises the retry policy: a failed message is re-queued with
 * an incremented `attempts` and a back-off delay until {@see WorkerOptions::$maxAttempts}
 * is reached, then it is annotated with a `dead_letter` block and moved to the
 * dead-letter queue. Delivery is at-least-once, so handlers must be idempotent.
 *
 * On platforms with `ext-pcntl` the loop shuts down gracefully on `SIGINT`/`SIGTERM`,
 * finishing the in-flight message before exiting.
 */
final class Worker
{
    /**
     * The shortest pause after putting a message back with a delay, so a
     * transport that ignores the delay (RabbitMQ) cannot be spun on even with
     * `sleepWhenEmpty: 0`.
     */
    private const DEFERRAL_PAUSE_SECONDS = 0.1;

    /**
     * The longest pause after a message could not be acted on (the transport threw
     * while acking, releasing or dead-lettering it). Kept short so a connection that
     * needs heartbeats is not left idle for long.
     */
    private const FAILURE_PAUSE_CAP_SECONDS = 5.0;

    private bool $shouldStop = false;

    private int $processed = 0;

    /** @var (\Closure(string, string, array<string, mixed>): void)|null */
    private ?\Closure $logger;

    /**
     * @param  ConsumerTransport  $transport  The broker binding to consume from.
     * @param  Dispatcher  $dispatcher  Routes each message to its handler.
     * @param  WorkerOptions  $options  Retry/limit tuning.
     * @param  (callable(string, string, array<string, mixed>): void)|null  $logger
     *         Optional sink called as `($level, $event, $context)` for observability.
     */
    public function __construct(
        private readonly ConsumerTransport $transport,
        private readonly Dispatcher $dispatcher,
        private readonly WorkerOptions $options = new WorkerOptions(),
        ?callable $logger = null,
    ) {
        $this->logger = $logger !== null ? $logger(...) : null;
    }

    /**
     * Consume `$queue` until a stop signal or one of the configured limits is
     * reached. Resilient: a transport error on a single iteration is logged and
     * the loop continues after a short sleep, rather than crashing the worker.
     */
    public function run(string $queue): void
    {
        $this->shouldStop = false;
        $this->listenForSignals();
        $startedAt = microtime(true);

        while (! $this->shouldStop) {
            if ($this->shouldStopForLimits($startedAt)) {
                break;
            }

            try {
                $message = $this->transport->reserve($queue, $this->options->reserveTimeout);
            } catch (Throwable $e) {
                $this->log('error', 'reserve.failed', ['queue' => $queue, 'error' => $e->getMessage()]);
                $this->sleep($this->options->sleepWhenEmpty);
                $this->dispatchSignals();

                continue;
            }

            if ($message === null) {
                if ($this->options->stopWhenEmpty) {
                    break;
                }
                $this->sleep($this->options->sleepWhenEmpty);
                $this->dispatchSignals();

                continue;
            }

            $counted = true;

            try {
                $counted = $this->process($message);
            } catch (Throwable $e) {
                $this->log('error', 'process.failed', $this->context($message, ['error' => $e->getMessage()]));
                $this->pauseAfterFailure($message);
            }

            if ($counted) {
                ++$this->processed;
            }
            $this->dispatchSignals();
        }
    }

    /**
     * Reserve and process at most one message. Returns whether one was processed.
     * Unlike {@see self::run()} this does not swallow transport errors, which
     * makes it the convenient entry point for tests and one-shot draining.
     */
    public function runOnce(string $queue): bool
    {
        $message = $this->transport->reserve($queue, $this->options->reserveTimeout);
        if ($message === null) {
            return false;
        }

        if ($this->process($message)) {
            ++$this->processed;
        }

        return true;
    }

    /**
     * Request a graceful stop before the next reservation.
     */
    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * Total messages processed across this worker's lifetime. A message put back
     * to wait for another handler's in-flight key is not processing and is not
     * counted, so waiting never uses up `maxJobs`.
     */
    public function processedCount(): int
    {
        return $this->processed;
    }

    /**
     * Act on one message. Returns false when the message was only deferred (put
     * back with a delay to wait for another handler), true when it was handled.
     */
    private function process(ReceivedMessage $message): bool
    {
        // A message whose earlier delivery ended without an outcome has already had
        // that delivery counted in `attempts` by the transport. A handler that
        // kills its process (OOM, `exit`, SIGKILL) never reports a failure, so when
        // that count reaches `maxAttempts` the dispatcher quarantines the message
        // instead of running it again for ever (after checking the idempotency
        // store: a message another handler finished, or still holds, is not failed).
        // The verdict has to outlive that check: a message put back because its key is
        // still claimed is no longer "abandoned" when it returns, but it is still out of
        // attempts. Only the count can bring a live message to `maxAttempts` (a failure
        // at that count is dead-lettered, never re-queued), so a message that arrives
        // there without a `dead_letter` block is exhausted too. A replayed dead letter
        // keeps its block and, as before, runs once more.
        $exhausted = $message->attempts() >= $this->options->maxAttempts
            && ($message->wasAbandoned() || ! array_key_exists('dead_letter', $message->envelope()));
        $outcome = $this->dispatcher->dispatch($message, $exhausted);

        switch ($outcome->kind) {
            case OutcomeKind::Ack:
                $this->transport->ack($message);
                $this->log('info', 'job.ack', $this->context($message));

                break;

            case OutcomeKind::Drop:
                $this->transport->ack($message);
                $this->log('notice', 'job.dropped', $this->context($message, ['reason' => $outcome->reason]));

                break;

            case OutcomeKind::Release:
                $this->transport->release($message, $message->rawBody(), $outcome->delaySeconds);
                $this->log('notice', 'job.released', $this->context($message, [
                    'reason' => $outcome->reason,
                    ...($outcome->delaySeconds > 0 ? ['delay' => $outcome->delaySeconds] : []),
                ]));

                // A delayed release is a message that is waiting on something else.
                // Transports without native delay (RabbitMQ) hand it straight back,
                // so pause briefly rather than reserving it again in a tight loop.
                if ($outcome->delaySeconds > 0) {
                    $this->sleep(max(self::DEFERRAL_PAUSE_SECONDS, $this->options->sleepWhenEmpty));

                    return false;
                }

                break;

            case OutcomeKind::DeadLetter:
                $this->moveToDeadLetter($message, $outcome, $message->attempts());

                break;

            case OutcomeKind::Retry:
                $attempt = $message->attempts() + 1;
                if ($attempt >= $this->options->maxAttempts) {
                    $this->moveToDeadLetter($message, $outcome, $attempt);
                } else {
                    $this->retry($message, $attempt);
                }

                break;
        }

        return true;
    }

    private function retry(ReceivedMessage $message, int $attempt): void
    {
        $envelope = $message->envelope();
        $envelope['attempts'] = $attempt;
        $delay = $this->options->delayForAttempt($attempt);

        $this->transport->release($message, $this->encode($envelope, $message->rawBody()), $delay);
        $this->log('warning', 'job.retry', $this->context($message, ['attempt' => $attempt, 'delay' => $delay]));
    }

    private function moveToDeadLetter(ReceivedMessage $message, Outcome $outcome, int $attempts): void
    {
        $envelope = $message->envelope();
        $envelope['attempts'] = $attempts;
        $reason = $outcome->reason !== '' ? $outcome->reason : 'failed';

        $annotated = DeadLetter::annotate($envelope, $reason, $outcome->exception, $message->queue(), $attempts);

        $this->transport->deadLetter($message, $this->encode($annotated, $message->rawBody()));
        $this->log('error', 'job.dead_letter', $this->context($message, [
            'reason' => $reason,
            'attempts' => $attempts,
            'error' => $outcome->exception?->getMessage(),
        ]));
    }

    /**
     * Encode an envelope, falling back to the original raw body if it somehow
     * cannot be re-encoded — so a poison message is still quarantined verbatim
     * rather than crashing the loop.
     *
     * @param  array<string, mixed>  $envelope
     */
    private function encode(array $envelope, string $fallback): string
    {
        try {
            return EnvelopeCodec::encode($envelope);
        } catch (JsonException) {
            return $fallback;
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function context(ReceivedMessage $message, array $extra = []): array
    {
        return [
            'queue' => $message->queue(),
            'urn' => $message->getUrn(),
            'trace_id' => $message->getTraceId(),
            ...$extra,
        ];
    }

    private function shouldStopForLimits(float $startedAt): bool
    {
        if ($this->options->maxJobs > 0 && $this->processed >= $this->options->maxJobs) {
            return true;
        }

        if ($this->options->maxRuntime > 0 && (microtime(true) - $startedAt) >= $this->options->maxRuntime) {
            return true;
        }

        return $this->options->memoryLimitMb > 0
            && memory_get_usage(true) >= $this->options->memoryLimitMb * 1048576;
    }

    private function listenForSignals(): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        $stop = function (): void {
            $this->shouldStop = true;
        };
        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGTERM, $stop);
    }

    private function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    /**
     * Wait before the next reservation after the transport failed to act on a
     * message. Without it a message the broker keeps refusing (RabbitMQ rejecting a
     * republish, a database that cannot be updated) comes straight back and its
     * handler runs again at once. The wait is the larger of 0.1 s, `sleepWhenEmpty`
     * and the back-off for the message's next attempt, capped at 5 s, and it is
     * taken in short slices so a stop signal ends it at once.
     */
    private function pauseAfterFailure(ReceivedMessage $message): void
    {
        // A monotonic clock, so a wall-clock step cannot stretch the 5 s cap.
        $until = hrtime(true) + (int) ($this->failurePauseSeconds($message) * 1_000_000_000);

        while (! $this->shouldStop && ($left = ($until - hrtime(true)) / 1_000_000_000) > 0) {
            $this->sleep(min($left, 0.25));
            $this->dispatchSignals();
        }
    }

    /**
     * How long to wait after a failure on `$message`: the larger of 0.1 s,
     * `sleepWhenEmpty` and the back-off for its next attempt, at most 5 s.
     */
    private function failurePauseSeconds(ReceivedMessage $message): float
    {
        // Capped so an `attempts` of PHP_INT_MAX (a foreign or damaged message) cannot
        // overflow into a float here, outside the failure handling that called us.
        $nextAttempt = min($message->attempts(), PHP_INT_MAX - 1) + 1;

        return min(
            self::FAILURE_PAUSE_CAP_SECONDS,
            max(self::DEFERRAL_PAUSE_SECONDS, $this->options->sleepWhenEmpty, $this->backoffFor($nextAttempt)),
        );
    }

    /**
     * The configured back-off for `$attempt`, read defensively: `backoff` is
     * documented as a list of ints, but a map (`[1 => 5]`) or floats (`[0.5]`) can
     * reach it, and the pause runs outside the failure handling, so it must not
     * throw. Anything that is not a number counts as no back-off.
     */
    private function backoffFor(int $attempt): float
    {
        $delays = array_values($this->options->backoff);
        if ($delays === []) {
            return 0.0;
        }

        $delay = $delays[min(max($attempt, 1) - 1, count($delays) - 1)];

        return is_int($delay) || is_float($delay) ? (float) $delay : 0.0;
    }

    private function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) ($seconds * 1_000_000));
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $level, string $event, array $context = []): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $event, $context);
        }
    }
}
