<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\providers;

use Craft;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * Base Provider
 *
 * Abstract base class for SMS providers with common functionality.
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
abstract class BaseProvider implements ProviderInterface
{
    use LoggingTrait;

    /**
     * @inheritdoc
     */
    public static function iconUrl(): ?string
    {
        return null;
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
    public function __construct()
    {
        $this->setLoggingHandle('sms-manager');
    }

    /**
     * @inheritdoc
     */
    public function testConnection(array $settings): bool
    {
        return true;
    }

    /**
     * Country dial codes and expected local number lengths
     */
    private const COUNTRY_PHONE_CONFIG = [
        'KW' => ['dialCode' => '965', 'localLength' => 8],  // Kuwait: 965 + 8 digits
        'SA' => ['dialCode' => '966', 'localLength' => 9],  // Saudi Arabia: 966 + 9 digits
        'AE' => ['dialCode' => '971', 'localLength' => 9],  // UAE: 971 + 9 digits
        'BH' => ['dialCode' => '973', 'localLength' => 8],  // Bahrain: 973 + 8 digits
        'QA' => ['dialCode' => '974', 'localLength' => 8],  // Qatar: 974 + 8 digits
        'OM' => ['dialCode' => '968', 'localLength' => 8],  // Oman: 968 + 8 digits
        'EG' => ['dialCode' => '20', 'localLength' => 10],  // Egypt: 20 + 10 digits
        'JO' => ['dialCode' => '962', 'localLength' => 9],  // Jordan: 962 + 9 digits
        'LB' => ['dialCode' => '961', 'localLength' => 8],  // Lebanon: 961 + 8 digits
        'IQ' => ['dialCode' => '964', 'localLength' => 10], // Iraq: 964 + 10 digits
    ];

    /**
     * Normalize phone number to standard format
     *
     * Converts Arabic/Persian numerals to Western numerals,
     * removes non-numeric characters, and handles prefixes.
     *
     * @param string $number Phone number to normalize
     * @return string Normalized phone number
     */
    protected function normalizePhoneNumber(string $number): string
    {
        // Remove invisible Unicode characters (zero-width spaces, formatting marks, etc.)
        // Common hidden characters from copy/paste:
        // - \xC2\xA0: Non-breaking space
        // - \xE2\x80\x8B: Zero-width space
        // - \xE2\x80\x8C: Zero-width non-joiner
        // - \xE2\x80\x8D: Zero-width joiner
        // - \xE2\x80\x8E: Left-to-right mark
        // - \xE2\x80\x8F: Right-to-left mark
        // - \xE2\x80\xAA-\xE2\x80\xAE: Directional formatting
        // - \xEF\xBB\xBF: BOM
        $number = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}\x{2060}\x{202A}-\x{202E}]/u', '', $number);

        // Arabic numerals
        $number = str_replace('٠', '0', $number);
        $number = str_replace('١', '1', $number);
        $number = str_replace('٢', '2', $number);
        $number = str_replace('٣', '3', $number);
        $number = str_replace('٤', '4', $number);
        $number = str_replace('٥', '5', $number);
        $number = str_replace('٦', '6', $number);
        $number = str_replace('٧', '7', $number);
        $number = str_replace('٨', '8', $number);
        $number = str_replace('٩', '9', $number);

        // Persian numerals
        $number = str_replace('۰', '0', $number);
        $number = str_replace('۱', '1', $number);
        $number = str_replace('۲', '2', $number);
        $number = str_replace('۳', '3', $number);
        $number = str_replace('۴', '4', $number);
        $number = str_replace('۵', '5', $number);
        $number = str_replace('۶', '6', $number);
        $number = str_replace('۷', '7', $number);
        $number = str_replace('۸', '8', $number);
        $number = str_replace('۹', '9', $number);

        // Remove + prefix
        $number = ltrim($number, '+');

        // Remove all non-numeric characters
        $number = preg_replace('/[^0-9]/', '', $number);

        // Remove 00 prefix
        if (str_starts_with($number, '00')) {
            $number = substr($number, 2);
        }

        return $number;
    }

    /**
     * Normalize and validate phone number for specific allowed countries
     *
     * Fixes common issues like:
     * - Duplicate country codes (96596594400999 → 96594400999)
     * - Missing country codes (94400999 → 96594400999)
     * - Validates final length matches expected format
     *
     * @param string $number Phone number to normalize
     * @param array $allowedCountries Array of country codes (e.g., ['KW', 'SA'])
     * @return array{number: string, valid: bool, error: string|null, fixed: bool}
     */
    protected function normalizeAndValidatePhone(string $number, array $allowedCountries): array
    {
        // First do basic normalization
        $number = $this->normalizePhoneNumber($number);
        $originalNumber = $number;
        $fixed = false;

        // If wildcard or empty, just return the normalized number
        if (empty($allowedCountries) || in_array('*', $allowedCountries, true)) {
            return [
                'number' => $number,
                'valid' => strlen($number) >= 10 && strlen($number) <= 15,
                'error' => null,
                'fixed' => false,
            ];
        }

        // Try to match and fix for each allowed country
        foreach ($allowedCountries as $countryCode) {
            $config = self::COUNTRY_PHONE_CONFIG[$countryCode] ?? null;
            if (!$config) {
                continue;
            }

            $dialCode = $config['dialCode'];
            $localLength = $config['localLength'];
            $expectedTotalLength = strlen($dialCode) + $localLength;

            // Check for duplicate country code (e.g., 96596594400999)
            $doubleDialCode = $dialCode . $dialCode;
            if (str_starts_with($number, $doubleDialCode)) {
                $number = substr($number, strlen($dialCode));
                $fixed = true;
                $this->logInfo('Phone number fixed: removed duplicate country code', [
                    'original' => $originalNumber,
                    'fixed' => $number,
                    'country' => $countryCode,
                ]);
            }

            // Check if number starts with country code
            if (str_starts_with($number, $dialCode)) {
                // Validate length
                if (strlen($number) === $expectedTotalLength) {
                    return [
                        'number' => $number,
                        'valid' => true,
                        'error' => null,
                        'fixed' => $fixed,
                    ];
                }

                // Number has country code but wrong length
                return [
                    'number' => $number,
                    'valid' => false,
                    'error' => "Invalid phone number length for {$countryCode}. Expected {$expectedTotalLength} digits, got " . strlen($number),
                    'fixed' => $fixed,
                ];
            }

            // Check if it's a local number (without country code)
            if (strlen($number) === $localLength) {
                $number = $dialCode . $number;
                $fixed = true;
                $this->logInfo('Phone number fixed: added country code', [
                    'original' => $originalNumber,
                    'fixed' => $number,
                    'country' => $countryCode,
                ]);

                return [
                    'number' => $number,
                    'valid' => true,
                    'error' => null,
                    'fixed' => true,
                ];
            }
        }

        // Could not match any allowed country format
        $countryList = implode(', ', $allowedCountries);
        return [
            'number' => $number,
            'valid' => false,
            'error' => "Phone number format does not match any allowed country ({$countryList}). Number: {$number}",
            'fixed' => $fixed,
        ];
    }

    /**
     * Sanitize message content
     *
     * @param string $message Message to sanitize
     * @return string Sanitized message
     */
    protected function sanitizeMessage(string $message): string
    {
        // Decode HTML entities
        $message = htmlspecialchars_decode($message);

        // Remove encoding characters that can cause issues
        $message = preg_replace('~([*\\[\\]\\\\])~u', '', $message);

        return $message;
    }

    /**
     * Validate API endpoint against security policy
     *
     * @param string $url Endpoint URL
     * @param array $providerAllowedHosts Provider-specific allowlist
     * @return array{ok: bool, error: string|null}
     */
    protected function validateApiEndpoint(string $url, array $providerAllowedHosts = []): array
    {
        $security = $this->getSecurityConfig();

        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            return ['ok' => false, 'error' => 'API URL must include scheme and host.'];
        }

        $scheme = strtolower($parsed['scheme']);
        if (($security['requireHttps'] ?? true) && $scheme !== 'https') {
            return ['ok' => false, 'error' => 'API URL must use HTTPS.'];
        }

        $host = strtolower($parsed['host']);
        $port = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);

        $allowedPorts = $security['allowedPorts'] ?? [443];
        if (!in_array($port, $allowedPorts, true)) {
            return ['ok' => false, 'error' => 'API URL port is not allowed.'];
        }

        $allowedHosts = array_merge($security['allowedApiHosts'] ?? [], $providerAllowedHosts);
        if (!empty($allowedHosts) && !$this->hostMatchesAllowed($host, $allowedHosts)) {
            return ['ok' => false, 'error' => 'API URL host is not allowed.'];
        }

        if (($security['blockPrivateNetworks'] ?? true) && $this->hostResolvesToPrivateIp($host)) {
            return ['ok' => false, 'error' => 'API URL host resolves to a private network address.'];
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Get security config from sms-manager.php
     */
    private function getSecurityConfig(): array
    {
        $config = Craft::$app->getConfig()->getConfigFromFile('sms-manager');
        $security = $config['security'] ?? [];

        return array_merge([
            'requireHttps' => true,
            'blockPrivateNetworks' => true,
            'allowRedirects' => false,
            'allowedPorts' => [443],
            'allowedApiHosts' => [],
        ], $security);
    }

    /**
     * Check if host matches allowed list (supports *.example.com)
     */
    private function hostMatchesAllowed(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower(trim($allowed));
            if ($allowed === '') {
                continue;
            }
            if (str_starts_with($allowed, '*.')) {
                $suffix = substr($allowed, 1);
                if (str_ends_with($host, $suffix)) {
                    return true;
                }
            } elseif ($host === $allowed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if host resolves to private or reserved IP ranges
     */
    private function hostResolvesToPrivateIp(string $host): bool
    {
        $ips = [];

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                } elseif (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if (empty($ips)) {
            return true;
        }

        foreach ($ips as $ip) {
            if ($this->isPrivateIp($ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an IP is private/reserved
     */
    private function isPrivateIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        return false;
    }

    /**
     * Get safe Guzzle redirect policy
     */
    protected function getRedirectPolicy(): array|bool
    {
        $security = $this->getSecurityConfig();
        return ($security['allowRedirects'] ?? false) ? ['max' => 3, 'strict' => true] : false;
    }

    /**
     * Render settings template
     *
     * @param string $template Template path
     * @param array $variables Template variables
     * @return string Rendered HTML
     */
    protected function renderSettingsTemplate(string $template, array $variables = []): string
    {
        return Craft::$app->getView()->renderTemplate($template, $variables);
    }
}
