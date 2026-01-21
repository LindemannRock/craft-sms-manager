<?php

/**
 * SMS Manager plugin configuration file
 *
 * IMPORTANT: This config file acts as an OVERRIDE layer only
 * - Settings are stored in the database ({{%smsmanager_settings}} table)
 * - Values defined here will override database settings (read-only)
 * - Settings overridden by this file cannot be changed in the Control Panel
 * - A warning will be displayed in the CP when a setting is overridden
 *
 * Multi-environment support:
 * - Use '*' for settings that apply to all environments
 * - Use 'dev', 'staging', 'production' for environment-specific overrides
 * - Environment-specific settings will be merged with '*' settings
 *
 * Copy this file to config/sms-manager.php to use it
 *
 * @since 5.0.0
 */

use craft\helpers\App;

return [
    // ========================================
    // GLOBAL SETTINGS (All Environments)
    // ========================================
    '*' => [
        // ========================================
        // GENERAL SETTINGS
        // ========================================

        /**
         * Plugin name (displayed in Control Panel)
         * Default: 'SMS Manager'
         */
        'pluginName' => 'SMS Manager',

        /**
         * Log level for plugin operations
         * Options: 'debug', 'info', 'warning', 'error'
         * Default: 'error'
         */
        // 'logLevel' => 'error',

        /**
         * Number of items per page in CP listings
         * Default: 100
         */
        // 'itemsPerPage' => 100,

        /**
         * Dashboard refresh interval in seconds
         * Default: 30
         */
        // 'refreshIntervalSecs' => 30,

        // ========================================
        // ANALYTICS SETTINGS
        // ========================================

        /**
         * Enable analytics tracking
         * Default: true
         */
        // 'enableAnalytics' => true,

        /**
         * Maximum number of analytics records to retain
         * Default: 1000
         */
        // 'analyticsLimit' => 1000,

        /**
         * Number of days to retain analytics (0 = keep forever)
         * Default: 30
         */
        // 'analyticsRetention' => 30,

        /**
         * Whether analytics should be automatically trimmed
         * Default: true
         */
        // 'autoTrimAnalytics' => true,

        // ========================================
        // LOGS SETTINGS
        // ========================================

        /**
         * Enable delivery logs
         * Default: true
         */
        // 'enableLogs' => true,

        /**
         * Maximum number of log records to retain
         * Default: 10000
         */
        // 'logsLimit' => 10000,

        /**
         * Number of days to retain logs (0 = keep forever)
         * Default: 30
         */
        // 'logsRetention' => 30,

        /**
         * Whether logs should be automatically trimmed
         * Default: true
         */
        // 'autoTrimLogs' => true,

        // ========================================
        // DEFAULT PROVIDER & SENDER ID
        // ========================================

        /**
         * Default provider handle
         * Must match a handle from providers (or database)
         * Auto-assigned: If not set, deleted, or disabled, automatically assigns first enabled provider
         * Default: null
         */
        // 'defaultProviderHandle' => 'production-provider',

        /**
         * Default sender ID handle
         * Must match a handle from senderIds (or database)
         * Auto-assigned: If not set, deleted, or disabled, automatically assigns first enabled sender ID
         * Default: null
         */
        // 'defaultSenderIdHandle' => 'main-sender',

        // ========================================
        // PROVIDER CONFIGURATION
        // ========================================

        /**
         * Provider instances
         * Define SMS provider configurations with credentials
         * These are marked as source='config' and cannot be edited in CP
         *
         * Available provider types: 'mpp-sms'
         */
        'providers' => [
            // Example: Production MPP-SMS provider
            // 'production-provider' => [
            //     'name' => 'Production MPP-SMS',
            //     'type' => 'mpp-sms',
            //     'enabled' => true,
            //     'sortOrder' => 1,
            //     'settings' => [
            //         'apiUrl' => App::env('MPP_SMS_API_URL'),
            //         'apiKey' => App::env('MPP_SMS_API_KEY'),
            //         'testMode' => false,
            //         'testApiKey' => App::env('MPP_SMS_TEST_API_KEY'),
            //     ],
            // ],

            // Example: Test provider
            // 'test-provider' => [
            //     'name' => 'Test Provider',
            //     'type' => 'mpp-sms',
            //     'enabled' => true,
            //     'sortOrder' => 2,
            //     'settings' => [
            //         'apiUrl' => App::env('MPP_SMS_API_URL'),
            //         'apiKey' => App::env('MPP_SMS_TEST_API_KEY'),
            //         'testMode' => true,
            //     ],
            // ],
        ],

        // ========================================
        // SENDER ID CONFIGURATION
        // ========================================

        /**
         * Sender ID definitions
         * Define sender IDs that can be used with providers
         * These are marked as source='config' and cannot be edited in CP
         *
         * Available options:
         * - name: Display name for the sender ID
         * - provider: Handle of the provider this sender ID belongs to
         * - senderId: The actual sender ID string (alphanumeric, max 11 chars)
         * - description: Optional description
         * - enabled: Whether the sender ID is active
         * - isTest: Mark as test-only sender ID
         * - sortOrder: Display order (optional)
         */
        'senderIds' => [
            // Example: Main production sender
            // 'main-sender' => [
            //     'name' => 'Main Sender',
            //     'provider' => 'production-provider',
            //     'senderId' => 'MYCOMPANY',
            //     'description' => 'Primary production sender ID',
            //     'enabled' => true,
            //     'isTest' => false,
            //     'sortOrder' => 1,
            // ],

            // Example: Marketing sender
            // 'marketing' => [
            //     'name' => 'Marketing',
            //     'provider' => 'production-provider',
            //     'senderId' => 'MYMARKET',
            //     'description' => 'For marketing campaigns',
            //     'enabled' => true,
            //     'isTest' => false,
            //     'sortOrder' => 2,
            // ],

            // Example: Test sender
            // 'test-sender' => [
            //     'name' => 'Test Sender',
            //     'provider' => 'test-provider',
            //     'senderId' => 'TEST',
            //     'description' => 'For testing only',
            //     'enabled' => true,
            //     'isTest' => true,
            //     'sortOrder' => 99,
            // ],
        ],
    ],

    // ========================================
    // DEVELOPMENT ENVIRONMENT
    // ========================================
    'dev' => [
        'logLevel' => 'debug',
        // Use test provider in development
        // 'defaultProviderHandle' => 'test-provider',
        // 'defaultSenderIdHandle' => 'test-sender',
    ],

    // ========================================
    // STAGING ENVIRONMENT
    // ========================================
    'staging' => [
        'logLevel' => 'info',
        // 'defaultProviderHandle' => 'test-provider',
        // 'defaultSenderIdHandle' => 'test-sender',
    ],

    // ========================================
    // PRODUCTION ENVIRONMENT
    // ========================================
    'production' => [
        'logLevel' => 'error',
        // 'defaultProviderHandle' => 'production-provider',
        // 'defaultSenderIdHandle' => 'main-sender',
    ],
];
