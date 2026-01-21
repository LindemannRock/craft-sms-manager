# SMS Manager for Craft CMS

[![Latest Version](https://img.shields.io/packagist/v/lindemannrock/craft-sms-manager.svg)](https://packagist.org/packages/lindemannrock/craft-sms-manager)
[![Craft CMS](https://img.shields.io/badge/Craft%20CMS-5.0%2B-orange.svg)](https://craftcms.com/)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://php.net/)
[![Logging Library](https://img.shields.io/badge/Logging%20Library-5.0%2B-green.svg)](https://github.com/LindemannRock/craft-logging-library)
[![License](https://img.shields.io/packagist/l/lindemannrock/craft-sms-manager.svg)](LICENSE)

SMS gateway and management plugin for Craft CMS 5.x with multi-provider support, analytics, and delivery tracking.

## Beta Notice

This plugin is currently in active development and provided under the MIT License for testing purposes.

**Licensing is subject to change.** We are finalizing our licensing structure and some or all features may require a paid license when officially released on the Craft Plugin Store. Some plugins may remain free, others may offer free and Pro editions, or be fully commercial.

If you are using this plugin, please be aware that future versions may have different licensing terms.

## Features

- **Multi-Provider Support**: Extensible provider system for different SMS gateways
  - **MPP-SMS**: Kuwait SMS provider with Arabic and English support
  - Extensible architecture for adding custom providers
- **Sender ID Management**: Configure multiple sender IDs per provider
  - Enable/disable sender IDs individually
  - Set default sender IDs per provider
  - Handle-based API access for templates
- **Multi-Language Support**: Native support for Arabic (UCS-2 encoding) and English messages
- **Comprehensive Analytics**:
  - Daily SMS statistics (sent, failed, pending)
  - Language breakdown (English, Arabic, other)
  - Provider and sender ID performance tracking
  - Source plugin tracking (know which plugin triggered each SMS)
  - Configurable retention period
- **Delivery Logs**:
  - Full message history with status tracking
  - Provider response logging
  - Error message capture
  - Export to CSV
  - Configurable retention and auto-cleanup
- **Dashboard**: Real-time overview of SMS activity and provider status
- **User Permissions**: Granular access control for providers, sender IDs, analytics, logs, and settings
- **Utilities**: Clear analytics and logs from Craft's utilities section
- **Logging**: Structured logging via Logging Library with configurable levels

## Requirements

- Craft CMS 5.0 or greater
- PHP 8.2 or greater
- [Logging Library](https://github.com/LindemannRock/craft-logging-library) 5.0 or greater (installed automatically as dependency)

## Installation

### Via Composer

```bash
cd /path/to/project
```

```bash
composer require lindemannrock/craft-sms-manager
```

```bash
./craft plugin/install sms-manager
```

### Using DDEV

```bash
cd /path/to/project
```

```bash
ddev composer require lindemannrock/craft-sms-manager
```

```bash
ddev craft plugin/install sms-manager
```

### Via Control Panel

In the Control Panel, go to Settings → Plugins and click "Install" for SMS Manager.

## Configuration

### Settings

Navigate to **SMS Manager → Settings** in the control panel to configure:

**General Settings:**
- **Plugin Name**: Customize the display name in the control panel

**Default Provider Settings:**
- **Default Provider**: Provider to use when none specified
- **Default Sender ID**: Sender ID to use when none specified

**Analytics Settings:**
- **Enable Analytics**: Toggle analytics tracking
- **Analytics Limit**: Maximum records to retain
- **Analytics Retention**: Days to keep analytics (0 = forever)
- **Auto-Trim Analytics**: Automatically clean up old records

**Logs Settings:**
- **Enable Logs**: Toggle delivery logging
- **Logs Limit**: Maximum log records to retain
- **Logs Retention**: Days to keep logs (0 = forever)
- **Auto-Trim Logs**: Automatically clean up old records

**Interface Settings:**
- **Items Per Page**: Number of items in list views
- **Dashboard Refresh**: Auto-refresh interval in seconds

**Logging Library Settings:**
- **Log Level**: debug, info, warning, error

### Config File

Create a `config/sms-manager.php` file to override default settings and define providers/sender IDs:

```bash
cp vendor/lindemannrock/craft-sms-manager/src/config.php config/sms-manager.php
```

The config file supports multi-environment configuration with `*` for global settings and environment-specific overrides:

```php
<?php

use craft\helpers\App;

return [
    // Global settings (all environments)
    '*' => [
        // Plugin Settings
        'pluginName' => 'SMS Manager',
        'logLevel' => 'error',

        // Default Provider & Sender ID (by handle)
        'defaultProviderHandle' => 'production-provider',
        'defaultSenderIdHandle' => 'main-sender',

        // Analytics Settings
        'enableAnalytics' => true,
        'analyticsLimit' => 1000,
        'analyticsRetention' => 30,
        'autoTrimAnalytics' => true,

        // Logs Settings
        'enableLogs' => true,
        'logsLimit' => 10000,
        'logsRetention' => 30,
        'autoTrimLogs' => true,

        // Interface Settings
        'itemsPerPage' => 100,
        'refreshIntervalSecs' => 30,

        // Provider Configuration (read-only in CP)
        'providers' => [
            'production-provider' => [
                'name' => 'Production MPP-SMS',
                'type' => 'mpp-sms',
                'enabled' => true,
                'settings' => [
                    'apiUrl' => App::env('MPP_SMS_API_URL'),
                    'apiKey' => App::env('MPP_SMS_API_KEY'),
                ],
            ],
        ],

        // Sender ID Configuration (read-only in CP)
        'senderIds' => [
            'main-sender' => [
                'name' => 'Main Sender',
                'provider' => 'production-provider', // Reference by handle
                'senderId' => 'MYCOMPANY',
                'enabled' => true,
                'isTest' => false,
            ],
        ],
    ],

    // Development environment
    'dev' => [
        'logLevel' => 'debug',
        'defaultProviderHandle' => 'test-provider',
        'defaultSenderIdHandle' => 'test-sender',
    ],

    // Production environment
    'production' => [
        'logLevel' => 'error',
    ],
];
```

**Config File Behavior:**
- Settings defined in the config file are **read-only** in the Control Panel
- Providers and sender IDs from config are displayed with a "Config" badge
- Config items cannot be edited or deleted via CP (only through the config file)
- Config items take precedence over database items with the same handle
- A warning is shown in CP when defaults are set via config file

## Usage

### Setting Up a Provider

1. Navigate to **SMS Manager → Providers**
2. Click **New Provider**
3. Select provider type (e.g., MPP-SMS)
4. Enter provider settings:
   - **Name**: Display name for the provider
   - **API Key**: Your provider API key (supports environment variables: `$MPP_SMS_API_KEY`)
   - **API URL**: Optional custom endpoint (defaults to provider's standard URL)
5. Enable the provider and save

### Setting Up Sender IDs

1. Navigate to **SMS Manager → Sender IDs**
2. Click **New Sender ID**
3. Configure:
   - **Name**: Display name (e.g., "My Brand")
   - **Handle**: Unique identifier for templates (e.g., `my-brand`)
   - **Sender ID**: The actual sender ID registered with your provider
   - **Provider**: Select which provider this sender ID belongs to
   - **Default**: Set as default for this provider
4. Enable and save

### Sending SMS from PHP

```php
use lindemannrock\smsmanager\SmsManager;

// Basic send (uses default provider and sender ID)
$success = SmsManager::$plugin->sms->send(
    '+96512345678',           // Recipient
    'Hello from Craft CMS!',  // Message
    'en'                      // Language: 'en' or 'ar'
);

// Send with specific provider and sender ID
$success = SmsManager::$plugin->sms->send(
    '+96512345678',
    'مرحبا من كرافت!',        // Arabic message
    'ar',                     // Arabic language
    $providerId,              // Provider ID
    $senderIdId,              // Sender ID
    'my-plugin',              // Source plugin (for analytics)
    $elementId                // Source element ID (optional)
);

// Send using sender ID handle
$success = SmsManager::$plugin->sms->sendWithHandle(
    '+96512345678',
    'Hello!',
    'my-brand',               // Sender ID handle
    'en',
    'my-plugin'
);

// Send with detailed response
$result = SmsManager::$plugin->sms->sendWithDetails(
    '+96512345678',
    'Hello!',
    'en',
    $providerId,
    $senderIdId
);

// Result includes:
// - success: bool
// - messageId: string|null
// - response: string|null (raw provider response)
// - error: string|null
// - executionTime: int (milliseconds)
// - providerName: string|null
// - senderIdName: string|null
// - senderIdValue: string|null
// - recipient: string
```

### Sending SMS from Twig

```twig
{# Send SMS using the Twig variable #}
{% set result = craft.smsHelper.send('+96512345678', 'Hello!', 'en') %}

{% if result %}
    <p>SMS sent successfully!</p>
{% else %}
    <p>Failed to send SMS.</p>
{% endif %}

{# Send with specific sender ID handle #}
{% set result = craft.smsHelper.sendWithHandle('+96512345678', 'Hello!', 'my-brand', 'en') %}

{# Get all enabled sender IDs #}
{% set senderIds = craft.smsHelper.getSenderIds() %}
{% for senderId in senderIds %}
    <option value="{{ senderId.id }}">{{ senderId.name }}</option>
{% endfor %}

{# Get sender IDs for a specific provider #}
{% set senderIds = craft.smsHelper.getSenderIds(providerId) %}
```

### Integration with Other Plugins

SMS Manager tracks which plugin triggered each SMS for analytics:

```php
// In your custom plugin
SmsManager::$plugin->sms->send(
    $phoneNumber,
    $message,
    'en',
    null,                    // Use default provider
    null,                    // Use default sender ID
    'my-custom-plugin',      // Your plugin handle
    $entry->id               // Optional: related element ID
);
```

This appears in analytics and logs, allowing you to see SMS usage by source plugin.

## Providers

### MPP-SMS

Kuwait SMS provider with support for Arabic and English messages.

**Settings:**
- **API Key**: Your MPP-SMS API key (required)
- **API URL**: Custom endpoint (optional, defaults to `https://api.mpp-sms.com/api/send.aspx`)

**Features:**
- Automatic UCS-2 encoding for Arabic messages
- English URL encoding
- Message ID extraction from responses
- Full error logging

**Environment Variables:**

```bash
# .env
MPP_SMS_API_KEY=your-api-key-here
```

```php
// In provider settings, use:
$MPP_SMS_API_KEY
```

### Creating Custom Providers

Extend `BaseProvider` to create custom SMS providers:

```php
<?php
namespace mymodule\providers;

use lindemannrock\smsmanager\providers\BaseProvider;
use lindemannrock\smsmanager\records\ProviderRecord;

class MyProvider extends BaseProvider
{
    public static function handle(): string
    {
        return 'my-provider';
    }

    public static function displayName(): string
    {
        return 'My SMS Provider';
    }

    public static function description(): string
    {
        return 'Description of my provider.';
    }

    public static function supportsConnectionTest(): bool
    {
        return true; // If you implement testConnection()
    }

    public function getSettingsHtml(?ProviderRecord $provider = null): string
    {
        return $this->renderSettingsTemplate('my-module/provider-settings', [
            'provider' => $provider,
        ]);
    }

    public function validateSettings(array $settings): array
    {
        $errors = [];
        if (empty($settings['apiKey'])) {
            $errors['apiKey'] = 'API Key is required.';
        }
        return $errors;
    }

    public function send(string $to, string $message, string $senderId, string $language, array $settings): array
    {
        // Implement your provider's API call
        return [
            'success' => true,
            'messageId' => 'msg-123',
            'response' => 'OK',
            'error' => null,
        ];
    }
}
```

Register your provider in your module's `init()`:

```php
use lindemannrock\smsmanager\services\ProvidersService;
use lindemannrock\smsmanager\events\RegisterProvidersEvent;
use yii\base\Event;

Event::on(
    ProvidersService::class,
    ProvidersService::EVENT_REGISTER_PROVIDERS,
    function(RegisterProvidersEvent $event) {
        $event->providers[] = MyProvider::class;
    }
);
```

## Analytics

### Viewing Analytics

Navigate to **SMS Manager → Analytics** to see:

- Total messages sent vs failed
- Daily trends chart
- Language breakdown (English, Arabic, other)
- Provider performance comparison
- Source plugin breakdown

### Analytics API

```php
use lindemannrock\smsmanager\SmsManager;

// Get analytics service
$analytics = SmsManager::$plugin->analytics;

// Get summary statistics
$summary = $analytics->getSummary();
// Returns: totalSent, totalFailed, totalPending, successRate

// Get daily statistics
$daily = $analytics->getDailyStats($startDate, $endDate);

// Get provider breakdown
$byProvider = $analytics->getByProvider();

// Get language breakdown
$byLanguage = $analytics->getByLanguage();
```

## SMS Logs

### Viewing Logs

Navigate to **SMS Manager → SMS Logs** to see:

- All sent messages with status (sent, failed, pending)
- Recipient, message content, language
- Provider and sender ID used
- Provider response and error messages
- Source plugin and element tracking

### Log Statuses

- **Pending**: Message queued, not yet sent
- **Sent**: Successfully delivered to provider
- **Failed**: Provider returned an error

### Exporting Logs

Click **Export** to download logs as CSV with columns:
- Date, Recipient, Message, Language
- Provider, Sender ID, Status
- Error Message, Provider Message ID
- Source Plugin, Source Element ID

## Utilities

Access via **Utilities → SMS Manager**:

### Analytics & Logs
- **Clear All Analytics**: Remove all analytics data
- **Clear All SMS Logs**: Remove all delivery logs

Both actions require confirmation and respect user permissions.

## Permissions

### Provider Permissions
- **Manage providers**
  - View providers
  - Create providers
  - Edit providers
  - Delete providers

### Sender ID Permissions
- **Manage sender IDs**
  - View sender IDs
  - Create sender IDs
  - Edit sender IDs
  - Delete sender IDs

### Analytics Permissions
- **View analytics**
  - Export analytics
  - Clear analytics

### Log Permissions
- **View logs**
  - Download logs

### Settings Permissions
- **Manage settings**

## Logging

SMS Manager uses the [LindemannRock Logging Library](https://github.com/LindemannRock/craft-logging-library) for system logging.

### Log Levels
- **Error**: Critical errors only (default)
- **Warning**: Errors and warnings
- **Info**: General information
- **Debug**: Detailed debugging (requires devMode)

### Configuration
```php
// config/sms-manager.php
return [
    'logLevel' => 'error', // error, warning, info, or debug
];
```

**Note:** Debug level requires Craft's `devMode` to be enabled.

### Log Files
- **Location**: `storage/logs/sms-manager-YYYY-MM-DD.log`
- **Retention**: 30 days (automatic cleanup)
- **Web Interface**: View logs at SMS Manager → System Logs

## Events

```php
use lindemannrock\smsmanager\services\SmsService;
use lindemannrock\smsmanager\events\SendEvent;
use yii\base\Event;

// Before sending SMS
Event::on(
    SmsService::class,
    SmsService::EVENT_BEFORE_SEND,
    function(SendEvent $event) {
        // Access: $event->to, $event->message, $event->language
        // Set $event->isValid = false to cancel
    }
);

// After sending SMS
Event::on(
    SmsService::class,
    SmsService::EVENT_AFTER_SEND,
    function(SendEvent $event) {
        // Access: $event->success, $event->messageId, $event->response
    }
);

// Register custom providers
use lindemannrock\smsmanager\services\ProvidersService;
use lindemannrock\smsmanager\events\RegisterProvidersEvent;

Event::on(
    ProvidersService::class,
    ProvidersService::EVENT_REGISTER_PROVIDERS,
    function(RegisterProvidersEvent $event) {
        $event->providers[] = MyCustomProvider::class;
    }
);
```

## Troubleshooting

### SMS Not Sending

1. **Check provider is enabled**: SMS Manager → Providers → ensure status is enabled
2. **Check sender ID is enabled**: SMS Manager → Sender IDs → ensure status is enabled
3. **Verify API credentials**: Check API key is correct in provider settings
4. **Check logs**: SMS Manager → SMS Logs for error messages
5. **Check system logs**: SMS Manager → System Logs for detailed errors

### Arabic Messages Not Displaying Correctly

- Ensure language is set to `'ar'` when sending
- MPP-SMS automatically uses UCS-2 encoding for Arabic
- Check recipient device supports Arabic SMS

### Analytics Not Tracking

- Verify analytics is enabled in settings
- Check `enableAnalytics` setting is `true`
- Analytics only tracks when messages are sent via the service

### Provider Response Errors

Common MPP-SMS errors:
- **Invalid API Key**: Check your API key in provider settings
- **Invalid Sender ID**: Sender ID not registered with provider
- **Invalid Mobile Number**: Check phone number format
- **Insufficient Balance**: Top up your provider account

## Support

- **Documentation**: [https://github.com/LindemannRock/craft-sms-manager](https://github.com/LindemannRock/craft-sms-manager)
- **Issues**: [https://github.com/LindemannRock/craft-sms-manager/issues](https://github.com/LindemannRock/craft-sms-manager/issues)
- **Email**: [support@lindemannrock.com](mailto:support@lindemannrock.com)

## License

This plugin is licensed under the MIT License. See [LICENSE](LICENSE) for details.

---

Developed by [LindemannRock](https://lindemannrock.com)
