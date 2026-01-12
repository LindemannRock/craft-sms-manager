<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025 LindemannRock
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
