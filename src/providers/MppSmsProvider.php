<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\providers;

use Craft;
use craft\helpers\App;
use lindemannrock\base\helpers\GeoHelper;
use lindemannrock\smsmanager\records\ProviderRecord;

/**
 * MPP-SMS Provider
 *
 * Implementation for MPP-SMS API (Kuwait SMS provider).
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
class MppSmsProvider extends BaseProvider
{
    /**
     * Language codes for MPP-SMS API
     */
    private const LANG_ENGLISH = 1;
    private const LANG_ARABIC = 3;

    /**
     * Default API endpoint
     */
    public const DEFAULT_API_ENDPOINT = 'https://api.mpp-sms.com/api/send.aspx';

    /**
     * @inheritdoc
     */
    public static function handle(): string
    {
        return 'mpp-sms';
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'MPP-SMS';
    }

    /**
     * @inheritdoc
     */
    public static function description(): string
    {
        return 'Kuwait SMS provider with support for Arabic and English messages.';
    }

    /**
     * @inheritdoc
     */
    public static function supportsConnectionTest(): bool
    {
        // MPP-SMS doesn't provide a test endpoint
        return false;
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(?ProviderRecord $provider = null): string
    {
        $settings = $provider ? json_decode($provider->settings, true) : [];

        return $this->renderSettingsTemplate('sms-manager/providers/_mpp-sms-settings', [
            'provider' => $provider,
            'settings' => $settings,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function validateSettings(array $settings): array
    {
        $errors = [];

        if (empty($settings['apiKey'])) {
            $errors['apiKey'] = Craft::t('sms-manager', 'API Key is required.');
        }

        return $errors;
    }

    /**
     * @inheritdoc
     */
    public function send(string $to, string $message, string $senderId, string $language, array $settings): array
    {
        // Validate phone number against allowed countries
        $allowedCountries = $settings['allowedCountries'] ?? [];
        if (!empty($allowedCountries) && !GeoHelper::isPhoneNumberAllowed($to, $allowedCountries)) {
            // Build list of allowed country names for error message
            $allowedNames = array_map(
                fn($code) => GeoHelper::getCountryWithDialCode($code),
                $allowedCountries
            );
            $allowedList = implode(', ', array_filter($allowedNames));

            $this->logError('MPP-SMS: Phone number not allowed for this provider', [
                'to' => $to,
                'allowedCountries' => $allowedCountries,
            ]);

            return [
                'success' => false,
                'messageId' => null,
                'response' => null,
                'error' => Craft::t('sms-manager', 'This provider only supports: {countries}', ['countries' => $allowedList]),
            ];
        }

        // Check if this is a test sender ID and if a test API key is configured
        $isTest = $settings['isTest'] ?? false;
        $testApiKey = App::parseEnv($settings['testApiKey'] ?? '');
        $mainApiKey = App::parseEnv($settings['apiKey'] ?? '');

        // Use test API key if sender is marked as test and test key is configured
        $apiKey = ($isTest && !empty($testApiKey)) ? $testApiKey : $mainApiKey;
        $usingTestKey = $isTest && !empty($testApiKey);

        if ($usingTestKey) {
            $this->logInfo('MPP-SMS: Using test API key for test sender ID');
        }

        if (empty($apiKey)) {
            $this->logError('MPP-SMS: API key not configured');
            return [
                'success' => false,
                'messageId' => null,
                'response' => null,
                'error' => 'API key not configured',
            ];
        }

        // Normalize phone number
        $toNumber = $this->normalizePhoneNumber($to);

        // Sanitize message
        $message = $this->sanitizeMessage($message);

        // Determine language code and encode message
        $langCode = $this->getLanguageCode($language);
        $encodedMessage = $this->encodeMessage($message, $language);

        // Get API endpoint (use custom or default)
        $apiEndpoint = App::parseEnv($settings['apiUrl'] ?? '') ?: self::DEFAULT_API_ENDPOINT;

        // Build API URL manually to control encoding
        // (http_build_query double-encodes already-encoded values)
        $url = $apiEndpoint . '?' . implode('&', [
            'apikey=' . urlencode($apiKey),
            'language=' . $langCode,
            'sender=' . urlencode($senderId),
            'mobile=' . $toNumber,
            'message=' . $encodedMessage,
        ]);

        // Send request
        try {
            $client = Craft::createGuzzleClient([
                'timeout' => 60,
                'connect_timeout' => 60,
            ]);

            $response = $client->get($url);
            $content = $response->getBody()->getContents();

            $success = str_contains($content, 'OK');

            // Parse message ID from response (format: "OK,smsid:645-40bee68af,mobiles:1,time:...")
            $messageId = null;
            if (preg_match('/smsid:([^,]+)/', $content, $matches)) {
                $messageId = $matches[1];
            }

            if ($success) {
                $this->logInfo('MPP-SMS: Message sent successfully', [
                    'to' => $toNumber,
                    'language' => $language,
                    'messageId' => $messageId,
                    'response' => $content,
                ]);
            } else {
                $this->logError('MPP-SMS: Message failed', [
                    'to' => $toNumber,
                    'language' => $language,
                    'response' => $content,
                ]);
            }

            return [
                'success' => $success,
                'messageId' => $messageId,
                'response' => $content,
                'error' => $success ? null : $content,
            ];
        } catch (\Throwable $e) {
            $this->logError('MPP-SMS: Request failed', [
                'to' => $toNumber,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'messageId' => null,
                'response' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get MPP-SMS language code
     *
     * @param string $language Language code ('en', 'ar')
     * @return int MPP-SMS language code
     */
    private function getLanguageCode(string $language): int
    {
        return match ($language) {
            'ar' => self::LANG_ARABIC,
            default => self::LANG_ENGLISH,
        };
    }

    /**
     * Encode message for API
     *
     * Arabic messages use UCS-2 encoding (hex), English uses URL encoding.
     *
     * @param string $message Message to encode
     * @param string $language Language code
     * @return string Encoded message
     */
    private function encodeMessage(string $message, string $language): string
    {
        if ($language === 'ar') {
            // Arabic: UCS-2 hex encoding
            return strtoupper(bin2hex(mb_convert_encoding($message, 'UCS-2', 'auto')));
        }

        // English: URL encoding
        return urlencode($message);
    }
}
