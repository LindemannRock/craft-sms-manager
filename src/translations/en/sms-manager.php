<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

return [
    // General
    'SMS Manager' => 'SMS Manager',
    'Dashboard' => 'Dashboard',
    'Providers' => 'Providers',
    'Sender IDs' => 'Sender IDs',
    'Analytics' => 'Analytics',
    'Settings' => 'Settings',
    'Logs' => 'Logs',

    // Settings
    'General' => 'General',
    'Interface' => 'Interface',
    'Plugin Settings' => 'Plugin Settings',
    'Plugin Name' => 'Plugin Name',
    'The name of the plugin as it appears in the Control Panel menu' => 'The name of the plugin as it appears in the Control Panel menu',
    'Default Provider & Sender ID' => 'Default Provider & Sender ID',
    'Default Provider' => 'Default Provider',
    'The default provider to use for sending SMS messages' => 'The default provider to use for sending SMS messages',
    'Default Sender ID' => 'Default Sender ID',
    'The default sender ID to use for sending SMS messages' => 'The default sender ID to use for sending SMS messages',
    'Select a provider' => 'Select a provider',
    'Select a sender ID' => 'Select a sender ID',
    'Logging Settings' => 'Logging Settings',
    'Log Level' => 'Log Level',
    'Choose what types of messages to log. Debug level requires devMode to be enabled.' => 'Choose what types of messages to log. Debug level requires devMode to be enabled.',
    'Error (Critical errors only)' => 'Error (Critical errors only)',
    'Warning (Errors and warnings)' => 'Warning (Errors and warnings)',
    'Info (General information)' => 'Info (General information)',
    'Debug (Detailed debugging)' => 'Debug (Detailed debugging)',
    'Save Settings' => 'Save Settings',
    'Settings saved.' => 'Settings saved.',
    'Couldn\'t save settings.' => 'Couldn\'t save settings.',

    // Analytics Settings
    'Analytics Settings' => 'Analytics Settings',
    'Enable Analytics' => 'Enable Analytics',
    'Track SMS sending statistics and trends' => 'Track SMS sending statistics and trends',
    'When enabled, {pluginName} will track SMS sending statistics including success rates, language distribution, and provider usage.' => 'When enabled, {pluginName} will track SMS sending statistics including success rates, language distribution, and provider usage.',
    'Data Retention' => 'Data Retention',
    'Analytics Retention (Days)' => 'Analytics Retention (Days)',
    'Number of days to retain analytics (0 = keep forever)' => 'Number of days to retain analytics (0 = keep forever)',
    'Analytics Limit' => 'Analytics Limit',
    'Maximum number of analytics records to retain' => 'Maximum number of analytics records to retain',
    'Auto Trim Analytics' => 'Auto Trim Analytics',
    'Automatically trim analytics to respect the limit' => 'Automatically trim analytics to respect the limit',

    // Delivery Logs
    'Delivery Logs' => 'Delivery Logs',
    'Enable Delivery Logs' => 'Enable Delivery Logs',
    'Store individual SMS delivery records for auditing and debugging' => 'Store individual SMS delivery records for auditing and debugging',
    'When enabled, individual SMS records will be stored including recipient, message, status, and provider response.' => 'When enabled, individual SMS records will be stored including recipient, message, status, and provider response.',
    'Logs Retention (Days)' => 'Logs Retention (Days)',
    'Number of days to retain delivery logs (0 = keep forever)' => 'Number of days to retain delivery logs (0 = keep forever)',
    'Logs Limit' => 'Logs Limit',
    'Maximum number of log records to retain' => 'Maximum number of log records to retain',
    'Auto Trim Logs' => 'Auto Trim Logs',
    'Automatically trim logs to respect the limit' => 'Automatically trim logs to respect the limit',

    // Interface Settings
    'Interface Settings' => 'Interface Settings',
    'Items Per Page' => 'Items Per Page',
    'Number of items to display per page in lists' => 'Number of items to display per page in lists',
    'Dashboard Refresh Interval' => 'Dashboard Refresh Interval',
    'How often to refresh dashboard data' => 'How often to refresh dashboard data',
    '15 seconds' => '15 seconds',
    '30 seconds' => '30 seconds',
    '60 seconds (1 minute)' => '60 seconds (1 minute)',
    '2 minutes' => '2 minutes',

    // Permissions
    'Manage providers' => 'Manage providers',
    'View providers' => 'View providers',
    'Create providers' => 'Create providers',
    'Edit providers' => 'Edit providers',
    'Delete providers' => 'Delete providers',
    'Manage sender IDs' => 'Manage sender IDs',
    'View sender IDs' => 'View sender IDs',
    'Create sender IDs' => 'Create sender IDs',
    'Edit sender IDs' => 'Edit sender IDs',
    'Delete sender IDs' => 'Delete sender IDs',
    'View analytics' => 'View analytics',
    'Export analytics' => 'Export analytics',
    'Clear analytics' => 'Clear analytics',
    'View logs' => 'View logs',
    'Download logs' => 'Download logs',
    'Manage settings' => 'Manage settings',

    // Providers
    'API Key is required.' => 'API Key is required.',
];
