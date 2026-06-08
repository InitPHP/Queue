<?php

declare(strict_types=1);

namespace InitPHP\Queue\Tests\Conformance;

use BabelQueue\Codec\EnvelopeCodec;
use BabelQueue\Validation\EnvelopeValidator;
use InitPHP\Queue\Message\ReceivedMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Proves the runtime reads and writes the canonical BabelQueue envelope by
 * exercising the SDK's golden cross-SDK conformance fixtures — the same files
 * every BabelQueue SDK (Go, Python, Node, ...) must satisfy. If these pass, a
 * message produced by any of them is consumable here, and vice versa.
 */
final class EnvelopeConformanceTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../Fixtures/' . $name);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validFixtures(): array
    {
        return [
            'php order-created' => ['order-created.json'],
            'go urn-alias' => ['urn-alias.json'],
            'dead-lettered' => ['dead-lettered.json'],
            'python unicode-and-numbers' => ['unicode-and-numbers.json'],
        ];
    }

    #[DataProvider('validFixtures')]
    public function test_valid_fixtures_are_accepted(string $file): void
    {
        $envelope = EnvelopeCodec::decode($this->fixture($file));

        self::assertTrue(EnvelopeCodec::accepts($envelope), "{$file} should be accepted");
        self::assertNull(EnvelopeValidator::check($envelope), "{$file} should be valid");
    }

    public function test_missing_urn_fixture_is_rejected(): void
    {
        $envelope = EnvelopeCodec::decode($this->fixture('invalid-missing-urn.json'));

        self::assertFalse(EnvelopeCodec::accepts($envelope));
        self::assertSame(EnvelopeValidator::REASON_MISSING_URN, EnvelopeValidator::check($envelope));
    }

    public function test_unknown_schema_version_fixture_is_rejected(): void
    {
        $envelope = EnvelopeCodec::decode($this->fixture('invalid-unknown-schema-version.json'));

        self::assertSame(EnvelopeValidator::REASON_UNSUPPORTED_SCHEMA_VERSION, EnvelopeValidator::check($envelope));
        self::assertTrue(EnvelopeValidator::isUnsupportedSchemaVersion($envelope));
    }

    public function test_received_message_reads_the_go_urn_alias(): void
    {
        $raw = $this->fixture('urn-alias.json');
        $message = new ReceivedMessage('orders', $raw, EnvelopeCodec::decode($raw), 1);

        self::assertSame('urn:babel:orders:created', $message->getUrn());
        self::assertSame('go', $message->getMeta()['lang']);
        self::assertSame(['order_id' => 1042], $message->getData());
    }

    public function test_received_message_reads_attempts_and_trace_id(): void
    {
        $raw = $this->fixture('dead-lettered.json');
        $message = new ReceivedMessage('orders', $raw, EnvelopeCodec::decode($raw), 1);

        self::assertSame(3, $message->attempts());
        self::assertSame('7b3f9c2a-e41d-4f88-9b2a-1c0d5e6f7a8b', $message->getTraceId());
    }

    public function test_php_produced_envelope_round_trips_byte_for_byte(): void
    {
        $envelope = EnvelopeCodec::make('urn:babel:orders:created', ['order_id' => 1042], 'orders');
        $decoded = EnvelopeCodec::decode(EnvelopeCodec::encode($envelope));

        self::assertSame($envelope, $decoded);
        self::assertTrue(EnvelopeCodec::accepts($decoded));
    }
}
