<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\helpers;

use Craft;
use craft\helpers\StringHelper;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;

/**
 * Keeps provider failures and operational recipient references free of SMS payload data.
 *
 * @since 5.16.0
 */
final class SmsPrivacyHelper
{
    private const FAILURE_PATTERN = '/^SMS provider failure \[provider=(?<provider>[a-z0-9][a-z0-9._-]{0,63}); category=(?<category>[a-z][a-z-]{0,31})(?:; status=(?<status>[1-5][0-9]{2}))?; reference=(?<reference>[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\]$/';

    /** @var list<string> */
    private const FAILURE_CATEGORIES = [
        'configuration',
        'endpoint-policy',
        'http',
        'invalid-recipient',
        'malformed-response',
        'provider',
        'provider-exception',
        'provider-response',
        'transport',
    ];

    /**
     * Return a stable, keyed reference suitable for operational logs.
     */
    public static function recipientReference(string $recipient): string
    {
        $key = Craft::$app->getConfig()->getGeneral()->securityKey;
        if ($key === '') {
            return '[redacted]';
        }

        return 'hmac-sha256:' . hash_hmac('sha256', "sms-manager:recipient\0" . trim($recipient), $key);
    }

    /**
     * Create a provider result containing only bounded diagnostic metadata.
     *
     * @return array{success: false, messageId: null, response: null, error: string}
     */
    public static function failureResult(
        string $provider,
        string $category,
        ?int $status = null,
        ?string $reference = null,
    ): array {
        $provider = self::providerToken($provider);
        $category = in_array($category, self::FAILURE_CATEGORIES, true) ? $category : 'provider';
        $statusPart = $status !== null && $status >= 100 && $status <= 599
            ? '; status=' . $status
            : '';
        if ($reference === null || preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $reference) !== 1) {
            $reference = StringHelper::UUID();
        }
        $reference = strtolower($reference);

        return [
            'success' => false,
            'messageId' => null,
            'response' => null,
            'error' => sprintf(
                'SMS provider failure [provider=%s; category=%s%s; reference=%s]',
                $provider,
                $category,
                $statusPart,
                $reference,
            ),
        ];
    }

    /**
     * Classify a provider throwable without reading its potentially sensitive payload.
     *
     * @return array{success: false, messageId: null, response: null, error: string}
     */
    public static function failureResultFromThrowable(string $provider, \Throwable $throwable): array
    {
        $response = $throwable instanceof RequestException
            ? $throwable->getResponse()
            : null;

        if ($response !== null) {
            return self::failureResult($provider, 'http', $response->getStatusCode());
        }

        return self::failureResult(
            $provider,
            $throwable instanceof TransferException ? 'transport' : 'provider-exception',
        );
    }

    /**
     * Enforce the public provider result contract and remove all free-text failure data.
     *
     * @param array<string, mixed> $result
     * @return array{success: bool, messageId: string|null, response: string|null, error: string|null}
     */
    public static function sanitizeProviderResult(array $result, string $provider): array
    {
        if (self::isSuccessfulResult($result)) {
            return [
                'success' => true,
                'messageId' => $result['messageId'],
                'response' => $result['response'],
                'error' => null,
            ];
        }

        $error = $result['error'] ?? null;
        $matches = [];
        if (
            is_string($error)
            && preg_match(self::FAILURE_PATTERN, $error, $matches) === 1
            && $matches['provider'] === self::providerToken($provider)
            && in_array($matches['category'], self::FAILURE_CATEGORIES, true)
        ) {
            return [
                'success' => false,
                'messageId' => null,
                'response' => null,
                'error' => $error,
            ];
        }

        $category = array_key_exists('success', $result) && $result['success'] === false
            ? 'provider'
            : 'malformed-response';

        return self::failureResult($provider, $category);
    }

    /** @param array<string, mixed> $result */
    private static function isSuccessfulResult(array $result): bool
    {
        return ($result['success'] ?? null) === true
            && array_key_exists('messageId', $result)
            && ($result['messageId'] === null || is_string($result['messageId']))
            && array_key_exists('response', $result)
            && ($result['response'] === null || is_string($result['response']))
            && (!array_key_exists('error', $result) || $result['error'] === null);
    }

    private static function providerToken(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
        $value = trim(substr($value, 0, 64), '-._');

        return $value !== '' ? $value : 'unknown';
    }
}
