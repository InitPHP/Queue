<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Console;

use InitPHP\Queue\Console\WorkerCommand;
use PHPUnit\Framework\TestCase;

final class WorkerCommandTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    /** @var list<string> */
    private array $output = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
    }

    private function command(): WorkerCommand
    {
        $this->output = [];

        return new WorkerCommand(function (string $line): void {
            $this->output[] = $line;
        });
    }

    private function tempFile(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'queue_test_');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function outputContains(string $needle): bool
    {
        foreach ($this->output as $line) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function test_without_the_work_command_it_prints_usage(): void
    {
        self::assertSame(1, $this->command()->run(['queue']));
        self::assertTrue($this->outputContains('Usage'));
    }

    public function test_missing_bootstrap_is_an_error(): void
    {
        self::assertSame(1, $this->command()->run(['queue', 'work']));
        self::assertTrue($this->outputContains('--bootstrap'));
    }

    public function test_nonexistent_bootstrap_file_is_an_error(): void
    {
        $code = $this->command()->run(['queue', 'work', '--bootstrap=/no/such/file.php']);

        self::assertSame(1, $code);
        self::assertTrue($this->outputContains('does not exist'));
    }

    public function test_bootstrap_must_return_a_worker(): void
    {
        $bootstrap = $this->tempFile("<?php\nreturn 123;\n");

        $code = $this->command()->run(['queue', 'work', '--bootstrap=' . $bootstrap]);

        self::assertSame(1, $code);
        self::assertTrue($this->outputContains('must return'));
    }

    public function test_once_processes_a_single_message_from_the_bootstrap(): void
    {
        $sentinel = (string) tempnam(sys_get_temp_dir(), 'queue_sentinel_');
        $this->tempFiles[] = $sentinel;
        @unlink($sentinel);

        $bootstrap = $this->tempFile($this->bootstrapSource($sentinel));

        $code = $this->command()->run(['queue', 'work', '--bootstrap=' . $bootstrap, '--queue=orders', '--once']);

        self::assertSame(0, $code, implode("\n", $this->output));
        self::assertFileExists($sentinel);
        self::assertSame('handled', (string) file_get_contents($sentinel));
    }

    public function test_a_worker_failure_is_reported_as_an_error(): void
    {
        $bootstrap = $this->tempFile($this->failingBootstrapSource());

        $code = $this->command()->run(['queue', 'work', '--bootstrap=' . $bootstrap, '--queue=orders', '--once']);

        self::assertSame(1, $code);
        self::assertTrue($this->outputContains('broker exploded'));
    }

    private function failingBootstrapSource(): string
    {
        return <<<'PHP'
            <?php

            use InitPHP\Queue\Consumer\Dispatcher;
            use InitPHP\Queue\Consumer\Worker;
            use InitPHP\Queue\Contracts\ConsumerTransport;
            use InitPHP\Queue\Message\ReceivedMessage;
            use InitPHP\Queue\Routing\HandlerMap;

            $transport = new class implements ConsumerTransport {
                public function reserve(string $queue, float $timeout = 5.0): ?ReceivedMessage
                {
                    throw new RuntimeException('broker exploded');
                }

                public function ack(ReceivedMessage $message): void {}
                public function release(ReceivedMessage $message, string $payload, int $delaySeconds = 0): void {}
                public function deadLetter(ReceivedMessage $message, string $payload): void {}
            };

            return new Worker($transport, new Dispatcher(new HandlerMap()));
            PHP;
    }

    private function bootstrapSource(string $sentinel): string
    {
        $sentinelLiteral = var_export($sentinel, true);

        return <<<PHP
            <?php

            use BabelQueue\\Codec\\EnvelopeCodec;
            use BabelQueue\\Contracts\\InboundMessage;
            use InitPHP\\Queue\\Consumer\\Dispatcher;
            use InitPHP\\Queue\\Consumer\\Worker;
            use InitPHP\\Queue\\Consumer\\WorkerOptions;
            use InitPHP\\Queue\\Routing\\HandlerMap;
            use InitPHP\\Queue\\Tests\\Support\\ArrayTransport;

            \$transport = new ArrayTransport();
            \$transport->publish(EnvelopeCodec::encode(EnvelopeCodec::make('urn:t', ['n' => 1], 'orders')), 'orders');

            \$map = (new HandlerMap())->register('urn:t', function (InboundMessage \$message): void {
                file_put_contents({$sentinelLiteral}, 'handled');
            });

            return new Worker(\$transport, new Dispatcher(\$map), new WorkerOptions(stopWhenEmpty: true));
            PHP;
    }
}
