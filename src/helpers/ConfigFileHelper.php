<?php

namespace lindemannrock\smsmanager\helpers;

use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;

/**
 * Config File Helper
 *
 * Provides static methods for loading and managing configurations
 * from the sms-manager.php config file.
 *
 * Used by: ProviderRecord, SenderIdRecord, ProvidersService, SenderIdsService
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.3.0
 */
class ConfigFileHelper
{
    private const PLUGIN_HANDLE = 'sms-manager';

    /**
     * Get the full config from sms-manager.php
     *
     * @return array The config array
     */
    public static function getConfig(): array
    {
        return BaseConfigFileHelper::getConfig(self::PLUGIN_HANDLE);
    }

    /**
     * Get a specific section from the config file
     *
     * @param string $key The config key (e.g., 'providers', 'senderIds')
     * @return array The config section or empty array if not found
     */
    public static function getConfigSection(string $key): array
    {
        return BaseConfigFileHelper::getConfigSection(self::PLUGIN_HANDLE, $key);
    }

    /**
     * Get providers from config file
     *
     * @return array Array of provider configs keyed by handle
     */
    public static function getProviders(): array
    {
        return self::getConfigSection('providers');
    }

    /**
     * Get sender IDs from config file
     *
     * @return array Array of sender ID configs keyed by handle
     */
    public static function getSenderIds(): array
    {
        return self::getConfigSection('senderIds');
    }

    /**
     * Check if a handle exists in config
     *
     * @param string $section The config section key
     * @param string $handle The handle to check
     * @return bool True if handle exists in config
     */
    public static function handleExistsInConfig(string $section, string $handle): bool
    {
        return BaseConfigFileHelper::handleExistsInConfig(self::PLUGIN_HANDLE, $section, $handle);
    }

    /**
     * Get a single config by handle
     *
     * @param string $section The config section key
     * @param string $handle The handle to get
     * @return array|null The config array or null if not found
     */
    public static function getConfigByHandle(string $section, string $handle): ?array
    {
        return BaseConfigFileHelper::getConfigByHandle(self::PLUGIN_HANDLE, $section, $handle);
    }

    /**
     * Clear the config cache
     *
     * Call this if you need to reload the config file (e.g., after file changes)
     */
    public static function clearCache(): void
    {
        BaseConfigFileHelper::clearCache(self::PLUGIN_HANDLE);
    }

    /**
     * Get all handles from a config section
     *
     * @param string $section The config section key
     * @return array Array of handles
     */
    public static function getHandles(string $section): array
    {
        return BaseConfigFileHelper::getHandles(self::PLUGIN_HANDLE, $section);
    }

    /**
     * Merge config-sourced items with database items
     *
     * Config items take precedence over database items with the same handle.
     * Returns array keyed by handle.
     *
     * @param array $configItems Items from config file (keyed by handle)
     * @param array $databaseItems Items from database (array of objects with 'handle' property)
     * @return array Merged items keyed by handle
     */
    public static function mergeConfigAndDatabase(array $configItems, array $databaseItems): array
    {
        return BaseConfigFileHelper::mergeConfigAndDatabase($configItems, $databaseItems);
    }
}
