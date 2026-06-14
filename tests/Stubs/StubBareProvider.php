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
 * Minimal provider that does NOT override getSettingsHtml(), so it exercises
 * the generic default form supplied by {@see BaseProvider::getSettingsHtml()}.
 *
 * @since 5.14.0
 */
final class StubBareProvider extends BaseProvider
{
    public static function handle(): string
    {
        return '__sm_test_bare';
    }

    public static function displayName(): string
    {
        return 'Bare Test Provider';
    }

    public static function description(): string
    {
        return 'Provider with no getSettingsHtml() override; uses the BaseProvider default.';
    }

    public function validateSettings(array $settings): array
    {
        return [];
    }

    public function send(string $to, string $message, string $senderId, string $language, array $settings): array
    {
        return ['success' => true, 'messageId' => null, 'response' => null, 'error' => null];
    }
}
