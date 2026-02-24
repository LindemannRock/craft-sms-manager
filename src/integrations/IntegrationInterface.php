<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\integrations;

/**
 * Integration Interface
 *
 * Defines the contract for plugins that integrate with SMS Manager.
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
interface IntegrationInterface
{
    /**
     * Get usages of a specific provider
     *
     * Returns an array of usages, each containing:
     * - label: string - Human-readable description (e.g., "Contact Form")
     * - editUrl: string|null - URL to edit the configuration
     *
     * @param int $providerId The provider ID to check
     * @return array<array{label: string, editUrl: string|null}>
     */
    public function getProviderUsages(int $providerId): array;

    /**
     * Get usages of a specific sender ID
     *
     * Returns an array of usages, each containing:
     * - label: string - Human-readable description (e.g., "Contact Form")
     * - editUrl: string|null - URL to edit the configuration
     *
     * @param int $senderIdId The sender ID to check
     * @return array<array{label: string, editUrl: string|null}>
     */
    public function getSenderIdUsages(int $senderIdId): array;
}
