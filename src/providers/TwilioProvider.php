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
use lindemannrock\smsmanager\records\ProviderRecord;

/**
 * Twilio Provider
 *
 * Implementation for Twilio's Programmable Messaging API.
 *
 * The sender is supplied per-message by the Sender ID record (a Twilio phone
 * number in E.164, an alphanumeric sender ID, or a Messaging Service SID),
 * so provider settings only hold the account credentials.
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.13.0
 */
class TwilioProvider extends BaseProvider
{
    /**
     * @inheritdoc
     */
    public static function handle(): string
    {
        return 'twilio';
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Twilio';
    }

    /**
     * @inheritdoc
     */
    public static function description(): string
    {
        return 'Global SMS provider with worldwide coverage, Unicode support, and delivery reports.';
    }

    /**
     * @inheritdoc
     */
    public static function shortName(): string
    {
        return 'Twilio';
    }

    /**
     * @inheritdoc
     */
    public static function website(): ?string
    {
        return 'https://www.twilio.com';
    }

    /**
     * @inheritdoc
     */
    public static function docsUrl(): ?string
    {
        return 'https://www.twilio.com/docs/messaging/api/message-resource';
    }

    /**
     * @inheritdoc
     */
    public static function dashboardUrl(): ?string
    {
        return 'https://console.twilio.com';
    }

    /**
     * @inheritdoc
     */
    public static function supportsUnicode(): bool
    {
        // Twilio auto-detects encoding and sends non-GSM text as UCS-2.
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function supportsDeliveryReports(): bool
    {
        // Twilio exposes delivery state via status callbacks. The capability is
        // real; consuming the callbacks is a later phase (see Delivery
        // Confirmation), so log status stays at `sent` until then.
        return true;
    }

    /**
     * @inheritdoc
     */
    public static function supportsConnectionTest(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(?ProviderRecord $provider = null): string
    {
        $settings = $provider ? (json_decode((string)$provider->settings, true) ?: []) : [];

        return $this->renderSettingsTemplate('sms-manager/providers/_settings/twilio', [
            'provider' => $provider,
            'providerSettings' => $settings,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function validateSettings(array $settings): array
    {
        $errors = [];

        if (empty($settings['accountSid'])) {
            $errors['accountSid'] = Craft::t('sms-manager', 'Account SID is required.');
        }

        if (empty($settings['authToken'])) {
            $errors['authToken'] = Craft::t('sms-manager', 'Auth Token is required.');
        }

        return $errors;
    }

    /**
     * @inheritdoc
     */
    public function send(string $to, string $message, string $senderId, string $language, array $settings): array
    {
        $allowedCountries = $settings['allowedCountries'] ?? [];

        // Normalize to E.164 (Twilio requires the leading +).
        $phoneResult = $this->formatRecipient($to, $allowedCountries);
        $toNumber = $phoneResult['number'];

        if (!$phoneResult['valid']) {
            $this->logError('Twilio: Invalid phone number', [
                'to' => $to,
                'normalized' => $toNumber,
                'error' => $phoneResult['error'],
                'allowedCountries' => $allowedCountries,
            ]);

            return [
                'success' => false,
                'messageId' => null,
                'response' => null,
                'error' => $phoneResult['error'],
            ];
        }

        if ($phoneResult['fixed']) {
            $this->logInfo('Twilio: Phone number was auto-corrected', [
                'original' => $to,
                'corrected' => $toNumber,
            ]);
        }

        $accountSid = App::parseEnv($settings['accountSid'] ?? '');
        $authToken = App::parseEnv($settings['authToken'] ?? '');

        if (empty($accountSid) || empty($authToken)) {
            $this->logError('Twilio: credentials not configured');
            return [
                'success' => false,
                'messageId' => null,
                'response' => null,
                'error' => 'Twilio credentials not configured',
            ];
        }

        $endpoint = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($accountSid) . '/Messages.json';

        $endpointValidation = $this->validateApiEndpoint($endpoint, ['api.twilio.com']);
        if (!$endpointValidation['ok']) {
            $this->logError('Twilio: API endpoint validation failed', [
                'error' => $endpointValidation['error'],
            ]);
            return [
                'success' => false,
                'messageId' => null,
                'response' => null,
                'error' => $endpointValidation['error'],
            ];
        }

        try {
            $client = Craft::createGuzzleClient([
                'timeout' => 30,
                'connect_timeout' => 30,
                'http_errors' => false,
                'allow_redirects' => $this->getRedirectPolicy(),
            ]);

            $response = $client->post($endpoint, [
                'auth' => [$accountSid, $authToken],
                'form_params' => [
                    'To' => $toNumber,
                    'From' => $senderId,
                    'Body' => $message,
                ],
            ]);

            $result = $this->parseResponse(
                $response->getStatusCode(),
                $response->getBody()->getContents(),
            );

            if ($result['success']) {
                $this->logInfo('Twilio: Message sent successfully', [
                    'to' => $toNumber,
                    'language' => $language,
                    'messageId' => $result['messageId'],
                ]);
            } else {
                $this->logError('Twilio: Message failed', [
                    'to' => $toNumber,
                    'language' => $language,
                    'error' => $result['error'],
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logError('Twilio: Request failed', [
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
     * Normalize a recipient number into E.164 (leading +) for the Twilio API.
     *
     * Reuses the shared normalization/validation (Arabic numeral folding,
     * country-code repair) and re-prepends the + that the base helper strips,
     * because Twilio rejects numbers without it.
     *
     * @param string $to Raw recipient number
     * @param list<string> $allowedCountries Allowed country codes (or ['*'])
     * @return array{number: string, valid: bool, error: string|null, fixed: bool}
     */
    protected function formatRecipient(string $to, array $allowedCountries): array
    {
        $result = $this->normalizeAndValidatePhone($to, $allowedCountries);

        if ($result['valid']) {
            $result['number'] = '+' . $result['number'];
        }

        return $result;
    }

    /**
     * Parse a Twilio Messages API response into the provider result contract.
     *
     * A success is a 2xx response carrying a message `sid` with no `error_code`.
     * Twilio errors return a non-2xx status with a JSON `{code, message}` body.
     *
     * @param int $statusCode HTTP status code
     * @param string $body Raw response body
     * @return array{success: bool, messageId: string|null, response: string, error: string|null}
     */
    protected function parseResponse(int $statusCode, string $body): array
    {
        $data = json_decode($body, true);
        $data = is_array($data) ? $data : [];

        $messageId = $data['sid'] ?? null;
        $success = $statusCode >= 200
            && $statusCode < 300
            && $messageId !== null
            && empty($data['error_code']);

        if ($success) {
            return [
                'success' => true,
                'messageId' => $messageId,
                'response' => $body,
                'error' => null,
            ];
        }

        $error = $data['message']
            ?? $data['error_message']
            ?? ('Twilio request failed with status ' . $statusCode);

        return [
            'success' => false,
            'messageId' => $messageId,
            'response' => $body,
            'error' => $error,
        ];
    }
}
