<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use lindemannrock\smsmanager\helpers\SmsEncodingHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Content-based SMS encoding and segment boundaries.
 *
 * @since 5.16.0
 */
final class SmsEncodingTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, int|string>}> */
    public static function vectors(): iterable
    {
        $path = dirname(__DIR__) . '/Fixtures/sms-encoding-vectors.json';
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new \RuntimeException('Unable to read SMS encoding vectors.');
        }

        $vectors = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        foreach ($vectors as $vector) {
            $message = $vector['message'] ?? str_repeat($vector['repeat']['value'], $vector['repeat']['count']);
            yield $vector['name'] => [$message, [
                'encoding' => $vector['encoding'],
                'characters' => $vector['characters'],
                'units' => $vector['units'],
                'segments' => $vector['segments'],
            ]];
        }
    }

    /** @param array<string, int|string> $expected */
    #[DataProvider('vectors')]
    public function testContentDeterminesEncodingAndSegments(string $message, array $expected): void
    {
        self::assertSame($expected, SmsEncodingHelper::analyze($message));
    }

    public function testLanguageMetadataCannotChangeTheResult(): void
    {
        $expected = SmsEncodingHelper::analyze('Hello ^ 😀');

        foreach (['en', 'ar', 'ja', 'fr'] as $language) {
            self::assertSame($expected, SmsEncodingHelper::analyze('Hello ^ 😀'), $language);
        }
    }
}
