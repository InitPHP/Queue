<?php

declare(strict_types=1);

namespace InitPHP\Queue\Console;

use InitPHP\Queue\Consumer\Worker;
use Throwable;

/**
 * The `bin/queue work` entry point: load a user bootstrap file that wires and
 * returns a {@see Worker}, then run it against a queue.
 *
 * Because handler maps, transports and credentials are application-specific, the
 * CLI does not guess them — the bootstrap PHP file owns that wiring and simply
 * `return`s a configured Worker. This keeps the command a thin, testable shell.
 *
 *     php bin/queue work --bootstrap=worker.php --queue=orders [--once]
 */
final class WorkerCommand
{
    /** @var \Closure(string): void */
    private \Closure $writer;

    /**
     * @param  (callable(string): void)|null  $writer  Output sink; defaults to STDOUT/STDERR.
     */
    public function __construct(?callable $writer = null)
    {
        $this->writer = $writer !== null
            ? $writer(...)
            : static function (string $line): void {
                fwrite(STDERR, $line . PHP_EOL);
            };
    }

    /**
     * @param  list<string>  $argv  The raw `$argv`, including the script name at index 0.
     * @return int  A process exit code (0 success, 1 usage/error).
     */
    public function run(array $argv): int
    {
        $args = $this->parse($argv);

        if (($args['_command'] ?? null) !== 'work') {
            $this->usage();

            return 1;
        }

        $bootstrap = $args['bootstrap'] ?? null;
        if (! is_string($bootstrap) || $bootstrap === '') {
            $this->write('error: --bootstrap=<file> is required.');
            $this->usage();

            return 1;
        }

        if (! is_file($bootstrap)) {
            $this->write(sprintf('error: bootstrap file "%s" does not exist.', $bootstrap));

            return 1;
        }

        $worker = require $bootstrap;
        if (! $worker instanceof Worker) {
            $this->write(sprintf('error: bootstrap "%s" must return an %s instance.', $bootstrap, Worker::class));

            return 1;
        }

        $queue = is_string($args['queue'] ?? null) && $args['queue'] !== '' ? $args['queue'] : 'default';

        try {
            isset($args['once']) ? $worker->runOnce($queue) : $worker->run($queue);
        } catch (Throwable $e) {
            $this->write('error: ' . $e->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * @param  list<string>  $argv
     * @return array<string, string|true>
     */
    private function parse(array $argv): array
    {
        $args = [];

        foreach (array_slice($argv, 1) as $token) {
            if (! str_starts_with($token, '--')) {
                $args['_command'] ??= $token;

                continue;
            }

            $option = substr($token, 2);
            if (str_contains($option, '=')) {
                [$key, $value] = explode('=', $option, 2);
                $args[$key] = $value;
            } else {
                $args[$option] = true;
            }
        }

        return $args;
    }

    private function usage(): void
    {
        $this->write('Usage: queue work --bootstrap=<file.php> [--queue=<name>] [--once]');
        $this->write('  --bootstrap  A PHP file that wires and returns an InitPHP\\Queue\\Consumer\\Worker.');
        $this->write('  --queue      The queue to consume (default: "default").');
        $this->write('  --once       Process at most one message, then exit.');
    }

    private function write(string $line): void
    {
        ($this->writer)($line);
    }
}
