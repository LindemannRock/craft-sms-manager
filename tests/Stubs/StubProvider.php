<?php
/**
 * LindemannRock SMS Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Stubs;

use lindemannrock\smsmanager\providers\BaseProvider;
use lindemannrock\smsmanager\records\ProviderRecord;

/**
 * Test-only SMS provider stub. Records every {@see send()} call the
 * {@see \lindemannrock\smsmanager\services\SmsService} drives, and lets the
 * test force a failure path via {@see $failSend}.
 *
 * State is held on STATIC properties: `ProvidersService::createProviderByType()`
 * constructs a fresh `new $class()` per send, so a per-instance recorder
 * would be reset on every call. Tests reset the counters in `setUp` via
 * {@see TestCase::registerStubProvider()}.
 *
 * Register with `ProvidersService::registerProviderType(StubProvider::class)`
 * and seed `ProviderRecord::type = '__sm_test_stub'`.
 *
 * @since 5.12.0
 */
final class StubProvider extends BaseProvider
{
    /**
     * Recorded calls to {@see send()}, in order. Each entry captures the full
     * argument tuple SmsService passes down so tests can assert routing,
     * payload, and the `isDev` flag merging.
     *
     * @var list<array{to: string, message: string, senderId: string, language: string, settings: array<string, mixed>}>
     */
    public static array $sentCalls = [];

    /**
     * When true, {@see send()} returns an error result with the configured
     * error message. Mirrors the partial-failure flag pattern from
     * `search-manager/tests/Stubs/StubBackend::$failBatchIndex`.
     */
    public static bool $failSend = false;

    /**
     * Message ID the stub claims for a successful send. Tests can assert the
     * log row picked this up.
     */
    public static string $successMessageId = 'stub-msg-id';

    /**
     * Raw response body the stub returns. Lives on the log row as
     * `providerResponse` on both happy and failure paths.
     */
    public static string $successResponse = 'OK,stub';

    /**
     * Error string returned when {@see $failSend} is set.
     */
    public static string $failError = 'stub: forced failure';

    public static function handle(): string
    {
        return '__sm_test_stub';
    }

    public static function displayName(): string
    {
        return 'Test Stub Provider';
    }

    public static function description(): string
    {
        return 'In-suite stub used by sms-manager integration tests.';
    }

    public function getSettingsHtml(?ProviderRecord $provider = null): string
    {
        return '';
    }

    /**
     * @inheritdoc
     */
    public function validateSettings(array $settings): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function send(string $to, string $message, string $senderId, string $language, array $settings): array
    {
        self::$sentCalls[] = [
            'to' => $to,
            'message' => $message,
            'senderId' => $senderId,
            'language' => $language,
            'settings' => $settings,
        ];

        if (self::$failSend) {
            return [
                'success' => false,
                'messageId' => null,
                'response' => null,
                'error' => self::$failError,
            ];
        }

        return [
            'success' => true,
            'messageId' => self::$successMessageId,
            'response' => self::$successResponse,
            'error' => null,
        ];
    }

    /**
     * Reset every static recorder + flag back to defaults. Called from
     * {@see \lindemannrock\smsmanager\tests\TestCase::registerStubProvider()}
     * at the start of every test that exercises the stub.
     */
    public static function reset(): void
    {
        self::$sentCalls = [];
        self::$failSend = false;
        self::$successMessageId = 'stub-msg-id';
        self::$successResponse = 'OK,stub';
        self::$failError = 'stub: forced failure';
    }
}
