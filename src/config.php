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
 * @since 5.3.0
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
         * Enable SMS delivery logs
         * Default: true
         */
        // 'enableSmsLogs' => true,

        /**
         * Maximum number of SMS log records to retain
         * Default: 10000
         */
        // 'smsLogsLimit' => 10000,

        /**
         * Number of days to retain SMS logs (0 = keep forever)
         * Default: 30
         */
        // 'smsLogsRetention' => 30,

        /**
         * Whether SMS logs should be automatically trimmed
         * Default: true
         */
        // 'autoTrimSmsLogs' => true,

        // ========================================
        // BASE SETTINGS OVERRIDES
        // ========================================
        // Optional per-plugin overrides for settings that normally cascade from
        // the plugin Settings UI. When the UI value is "Use global default",
        // the value cascades from config/lindemannrock-base.php.
        //
        // To customize globally, copy:
        // vendor/lindemannrock/craft-plugin-base/src/config.php
        // to:
        // config/lindemannrock-base.php
        //
        // Uncomment a key here only when this plugin should override the global base value.

         /**
         * Date/time formatting overrides
         * Override base plugin date/time display settings for this plugin
         * Defaults: from config/lindemannrock-base.php
         */
        // 'timeFormat' => '24',      // '12' (AM/PM) or '24' (military)
        // 'monthFormat' => 'short',  // 'numeric' (01), 'short' (Jan), 'long' (January)
        // 'dateOrder' => 'dmy',      // 'dmy', 'mdy', 'ymd'
        // 'dateSeparator' => '/',    // '/', '-', '.'
        // 'showSeconds' => false,    // Show seconds in time display

        /**
         * Default date range for analytics, logs, and dashboard pages
         * Options: 'today', 'yesterday', 'thisWeek', 'lastWeek', 'last7days',
         *          'last14days', 'last30days', 'last90days', 'thisMonth',
         *          'lastMonth', 'thisQuarter', 'lastQuarter', 'thisYear',
         *          'lastYear', 'last12months', 'all'
         * Default: 'last30days' (from base plugin)
         */
        // 'defaultDateRange' => 'last7days',

        /**
         * Export format overrides
         * Enable/disable specific export formats for this plugin
         * Default: CSV and Excel enabled, JSON disabled (developer format — from base plugin)
         */
        // 'exports' => [
        //     'csv' => true,
        //     'json' => true,
        //     'excel' => true,
        // ],

        // ========================================
        // DEFAULT PROVIDER & SENDER ID
        // ========================================

        /**
         * Default provider handle
         *
         * Must match a handle from `providers` below (or a provider in the
         * database). Resolution:
         *   - If unset or empty → falls back to the first enabled provider.
         *   - If set to a handle that resolves to an enabled provider → uses it.
         *   - If set to a handle that does NOT resolve (typo, deleted) or
         *     resolves to a disabled provider → resolution returns null and
         *     any send that relies on the default will fail with an explicit
         *     "No provider configured" error. Fix the handle to recover.
         *
         * The fail-loud-on-bad-handle behaviour replaces an older silent
         * "auto-assign first enabled" fallback that masked configuration
         * errors at runtime.
         *
         * Default: null
         */
        // 'defaultProviderHandle' => 'production-provider',

        /**
         * Default sender ID handle
         *
         * Must match a handle from `senderIds` below (or a sender in the
         * database). Resolution:
         *   - If unset or empty → falls back to the first enabled sender ID.
         *   - If set to a handle that resolves to an enabled sender → uses it.
         *   - If set to a handle that does NOT resolve or resolves to a
         *     disabled sender → resolution returns null and any send that
         *     relies on the default will fail with an explicit "No sender ID
         *     configured" error. Fix the handle to recover.
         *
         * Default: null
         */
        // 'defaultSenderIdHandle' => 'main-sender',

        // ========================================
        // SECURITY SETTINGS
        // ========================================

        /**
         * Require HTTPS for provider API endpoints
         * Default: true
         */
        // 'security' => [
        //     'requireHttps' => true,
        //     'blockPrivateNetworks' => true,
        //     'allowRedirects' => false,
        //     'allowedPorts' => [443],
        //     'allowedApiHosts' => [
        //         'api.mpp-sms.com',
        //     ],
        // ],

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
            //     'settings' => [
            //         'apiUrl' => App::env('MPP_SMS_API_URL'),
            //         'apiKey' => App::env('MPP_SMS_API_KEY'),
            //         'devApiKey' => App::env('MPP_SMS_DEV_API_KEY'),
            //         'allowedCountries' => ['*'],  // ['*'] for all, or ['KW', 'SA', 'AE'] for specific
            //         // Optional per-provider API host allowlist
            //         // 'allowedApiHosts' => ['api.mpp-sms.com'],
            //     ],
            // ],

            // Example: Development provider (restricted to specific countries)
            // 'dev-provider' => [
            //     'name' => 'Development Provider',
            //     'type' => 'mpp-sms',
            //     'enabled' => true,
            //     'settings' => [
            //         'apiUrl' => App::env('MPP_SMS_API_URL'),
            //         'apiKey' => App::env('MPP_SMS_DEV_API_KEY'),
            //         'allowedCountries' => ['KW'],  // Kuwait only for dev
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
         * - isDev: Mark as development-only sender ID
         */
        'senderIds' => [
            // Example: Main production sender
            // 'main-sender' => [
            //     'name' => 'Main Sender',
            //     'provider' => 'production-provider',
            //     'senderId' => 'MYCOMPANY',
            //     'description' => 'Primary production sender ID',
            //     'enabled' => true,
            //     'isDev' => false,
            // ],

            // Example: Marketing sender
            // 'marketing' => [
            //     'name' => 'Marketing',
            //     'provider' => 'production-provider',
            //     'senderId' => 'MYMARKET',
            //     'description' => 'For marketing campaigns',
            //     'enabled' => true,
            //     'isDev' => false,
            // ],

            // Example: Development sender
            // 'dev-sender' => [
            //     'name' => 'Development Sender',
            //     'provider' => 'dev-provider',
            //     'senderId' => 'DEV',
            //     'description' => 'For development only',
            //     'enabled' => true,
            //     'isDev' => true,
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
