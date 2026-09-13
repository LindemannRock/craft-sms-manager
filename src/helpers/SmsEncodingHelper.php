<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\helpers;

/**
 * Content-based GSM-7/UCS-2 classification and SMS segment calculation.
 *
 * Character count means Unicode code points. Encoding units mean GSM septets
 * for GSM-7 (extension-table characters consume two) or UTF-16 code units for
 * UCS-2 (astral characters consume two). An empty message is GSM-7 with zero
 * characters, units, and segments.
 *
 * @since 5.16.0
 */
final class SmsEncodingHelper
{
    public const ENCODING_GSM7 = 'gsm-7';
    public const ENCODING_UCS2 = 'ucs-2';

    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    private const GSM_EXTENSION = "^{}\\[~]|€\f";

    /**
     * Analyze message content without consulting language or locale metadata.
     *
     * @return array{encoding: self::ENCODING_*, characters: int, units: int, segments: int}
     */
    public static function analyze(string $message): array
    {
        if ($message === '') {
            return [
                'encoding' => self::ENCODING_GSM7,
                'characters' => 0,
                'units' => 0,
                'segments' => 0,
            ];
        }

        $characters = mb_str_split($message, 1, 'UTF-8');
        $basic = array_fill_keys(mb_str_split(self::GSM_BASIC, 1, 'UTF-8'), true);
        $extension = array_fill_keys(mb_str_split(self::GSM_EXTENSION, 1, 'UTF-8'), true);
        $units = 0;

        foreach ($characters as $character) {
            if (isset($basic[$character])) {
                $units++;
                continue;
            }

            if (isset($extension[$character])) {
                $units += 2;
                continue;
            }

            $ucs2Units = intdiv(strlen(mb_convert_encoding($message, 'UTF-16BE', 'UTF-8')), 2);

            return [
                'encoding' => self::ENCODING_UCS2,
                'characters' => count($characters),
                'units' => $ucs2Units,
                'segments' => self::segments($ucs2Units, 70, 67),
            ];
        }

        return [
            'encoding' => self::ENCODING_GSM7,
            'characters' => count($characters),
            'units' => $units,
            'segments' => self::segments($units, 160, 153),
        ];
    }

    private static function segments(int $units, int $singleLimit, int $multipartLimit): int
    {
        if ($units === 0) {
            return 0;
        }

        return $units <= $singleLimit ? 1 : (int) ceil($units / $multipartLimit);
    }
}
