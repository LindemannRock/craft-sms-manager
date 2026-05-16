<?php
/**
 * LindemannRock SMS Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use lindemannrock\smsmanager\providers\BaseProvider;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\tests\TestCase;

/**
 * Phone-number normalization pinned by {@see BaseProvider::normalizeAndValidatePhone}.
 *
 * The helper is protected — pin the contract via an anonymous in-test
 * subclass that re-exposes it. These are the two behaviours that have
 * silently mangled real customer numbers in the past:
 *
 *  1. **Duplicate dial code**: `96596594400999` arrives when a user types
 *     their local number into a field that auto-prefixes the dial code.
 *     The helper must strip one copy of the dial code and emit a valid 11-
 *     digit Kuwait number, with `fixed: true` so downstream logs can flag
 *     the correction.
 *  2. **Missing dial code**: `94400999` is a bare 8-digit Kuwait local
 *     number. The helper must add the country code and mark `fixed: true`.
 *
 * These two cover the duplicate-prefix and the missing-prefix paths the
 * helper distinguishes — the other branches (already-valid, invalid-length,
 * non-matching country) drop out as part of the same code path and aren't
 * worth separate tests in the initial suite.
 *
 * @since 5.12.0
 */
final class BaseProviderPhoneNormalizationTest extends TestCase
{
    private BaseProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new class () extends BaseProvider {
            public static function handle(): string
            {
                return '__sm_test_phone';
            }
            public static function displayName(): string
            {
                return 'Phone Test';
            }
            public static function description(): string
            {
                return '';
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
                return ['success' => false, 'messageId' => null, 'response' => null, 'error' => null];
            }

            /**
             * @param list<string> $countries
             * @return array{number: string, valid: bool, error: string|null, fixed: bool}
             */
            public function exposeNormalize(string $number, array $countries): array
            {
                return $this->normalizeAndValidatePhone($number, $countries);
            }
        };
    }

    public function testFixesDuplicateKuwaitCountryCode(): void
    {
        $result = $this->provider->exposeNormalize('96596594400999', ['KW']);

        self::assertTrue($result['valid'], 'Number with stripped duplicate dial code should be valid');
        self::assertTrue($result['fixed'], 'Duplicate-dial-code fix must set fixed=true so callers can log it');
        self::assertSame('96594400999', $result['number']);
        self::assertNull($result['error']);
    }

    public function testAddsMissingCountryCodeForLocalNumber(): void
    {
        $result = $this->provider->exposeNormalize('94400999', ['KW']);

        self::assertTrue($result['valid']);
        self::assertTrue($result['fixed'], 'Missing-dial-code fix must set fixed=true');
        self::assertSame('96594400999', $result['number']);
        self::assertNull($result['error']);
    }
}
