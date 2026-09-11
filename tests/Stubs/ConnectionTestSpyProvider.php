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

/**
 * Isolated provider double for credential-backed connection checks.
 *
 * @since 5.16.0
 */
final class ConnectionTestSpyProvider extends BaseProvider
{
    public static int $constructionCount = 0;

    /** @var list<array<string, mixed>> */
    public static array $connectionCalls = [];

    /** @var list<array<string, mixed>> */
    public static array $sendCalls = [];

    public function __construct()
    {
        self::$constructionCount++;
        parent::__construct();
    }

    public static function handle(): string
    {
        return '__sm_test_connection';
    }

    public static function displayName(): string
    {
        return 'Connection Test Provider';
    }

    public static function description(): string
    {
        return 'In-suite provider double for connection testing.';
    }

    public static function supportsConnectionTest(): bool
    {
        return true;
    }

    public function validateSettings(array $settings): array
    {
        return [];
    }

    public function testConnection(array $settings): bool
    {
        self::$connectionCalls[] = $settings;

        return (bool)($settings['connectionResult'] ?? true);
    }

    public function send(string $to, string $message, string $senderId, string $language, array $settings): array
    {
        self::$sendCalls[] = compact('to', 'message', 'senderId', 'language', 'settings');

        throw new \LogicException('The connection-test provider must never send SMS.');
    }

    public static function reset(): void
    {
        self::$constructionCount = 0;
        self::$connectionCalls = [];
        self::$sendCalls = [];
    }
}
