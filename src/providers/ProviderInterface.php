<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\providers;

use lindemannrock\smsmanager\records\ProviderRecord;

/**
 * Provider Interface
 *
 * All SMS providers must implement this interface.
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
interface ProviderInterface
{
    /**
     * Get the provider type handle
     *
     * @return string Unique handle (e.g., 'mpp-sms', 'twilio')
     * @since 5.0.0
     */
    public static function handle(): string;

    /**
     * Get the display name for the provider
     *
     * @return string Human-readable name (e.g., 'MPP-SMS', 'Twilio')
     * @since 5.0.0
     */
    public static function displayName(): string;

    /**
     * Get the provider description
     *
     * @return string Short description of the provider
     * @since 5.0.0
     */
    public static function description(): string;

    /**
     * Get the provider icon URL
     *
     * @return string|null URL to provider icon
     * @since 5.0.0
     */
    public static function iconUrl(): ?string;

    /**
     * Check if the provider supports connection testing
     *
     * @return bool
     * @since 5.0.0
     */
    public static function supportsConnectionTest(): bool;

    /**
     * Get the settings HTML for provider configuration
     *
     * @param ProviderRecord|null $provider Existing provider record for editing
     * @return string HTML for settings form
     * @since 5.0.0
     */
    public function getSettingsHtml(?ProviderRecord $provider = null): string;

    /**
     * Validate provider settings
     *
     * @param array $settings Settings to validate
     * @return array Validation errors (empty if valid)
     * @since 5.0.0
     */
    public function validateSettings(array $settings): array;

    /**
     * Test the provider connection
     *
     * @param array $settings Provider settings
     * @return bool True if connection successful
     * @throws \Exception If connection test fails
     * @since 5.0.0
     */
    public function testConnection(array $settings): bool;

    /**
     * Send an SMS message
     *
     * @param string $to Recipient phone number
     * @param string $message Message content
     * @param string $senderId Sender ID to use
     * @param string $language Message language ('en', 'ar', etc.)
     * @param array $settings Provider settings
     * @return array Result with 'success', 'messageId', 'response' keys
     * @since 5.0.0
     */
    public function send(string $to, string $message, string $senderId, string $language, array $settings): array;
}
