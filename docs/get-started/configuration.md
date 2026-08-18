# Configuration

SMS Manager works out of the box — its settings live in the database and are editable under **SMS Manager → Settings**. This page covers every setting and how to lock them down with an optional config file.

## Where settings live

Settings are stored in a dedicated database table (`smsmanager_settings`), not Craft's project config. You can edit them in the Control Panel, or override any of them from a `config/sms-manager.php` file.

When a setting is defined in the config file, it becomes **read-only** in the Control Panel and a notice tells editors to change the config file instead. This is the recommended way to manage per-environment values (a different default provider in `dev` vs `production`, for example).

```bash
cp vendor/lindemannrock/craft-sms-manager/src/config.php config/sms-manager.php
```

The config file supports Craft's multi-environment format — a `'*'` group for all environments plus per-environment overrides (`dev`, `staging`, `production`).

## Settings reference

### General

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `pluginName` | `string` | `'SMS Manager'` | Display name shown in the Control Panel menu |
| `defaultProviderHandle` | `string` | `null` | Handle of the provider used when a send doesn't name one. Empty falls back to the first enabled provider |
| `defaultSenderIdHandle` | `string` | `null` | Handle of the sender ID used when a send doesn't name one. Empty falls back to the first enabled sender ID |
| `logLevel` | `string` | `'error'` | Logging Library level: `'debug'`, `'info'`, `'warning'`, `'error'` |

> [!NOTE]
> `defaultProviderHandle` and `defaultSenderIdHandle` fail loud: if a handle is set but doesn't resolve to an enabled record, the default is treated as unconfigured and sends that rely on it return an explicit error rather than silently routing through a different provider. Fix the handle to recover.

The `defaultProviderId` and `defaultSenderIdId` settings still exist for backward compatibility but are **deprecated** — use the handle-based settings instead.

### Analytics

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `enableAnalytics` | `bool` | `true` | Record per-message analytics |
| `analyticsLimit` | `int` | `1000` | Maximum analytics records to retain when count trimming is enabled (1–100000) |
| `analyticsRetention` | `int` | `30` | Days to keep analytics records (0–3650, `0` = keep forever) |
| `autoTrimAnalytics` | `bool` | `true` | After date cleanup, trim the oldest remaining analytics records to `analyticsLimit` |

The daily analytics-cleanup family runs only while `enableAnalytics` is on and `analyticsRetention` is greater than `0`. Turning analytics off or setting retention to `0` cancels its future recurring cleanup. Changing the positive retention value or the count-trim settings does not replace the daily chain.

### SMS logs

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `enableSmsLogs` | `bool` | `true` | Record a delivery log row for every message |
| `smsLogsLimit` | `int` | `10000` | Maximum log records to retain when count trimming is enabled (1–100000) |
| `smsLogsRetention` | `int` | `30` | Days to keep log records (0–3650, `0` = keep forever) |
| `autoTrimSmsLogs` | `bool` | `true` | After date cleanup, trim the oldest remaining logs to `smsLogsLimit` |

The daily SMS-log-cleanup family runs only while `enableSmsLogs` is on and `smsLogsRetention` is greater than `0`. Turning logs off or setting retention to `0` cancels its future recurring cleanup. This family is independent from analytics cleanup, so changing one does not replace or cancel the other.

Both cleanup families preserve the canonical daily Craft-timezone target. Queue backends with a bounded delay use one or more short handoffs to reach that target; the handoffs only continue the schedule and never delete data. Native and other queue backends keep the complete delay.

### Interface

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `itemsPerPage` | `int` | `100` | Rows per page in Control Panel list views |
| `refreshIntervalSecs` | `int` | `null` | Auto-refresh interval (seconds) for the dashboard and logs. `null` disables auto-refresh |

### Date, time, and export formatting

These cascade from the base plugin (`config/lindemannrock-base.php`). Leave them unset to inherit the global default, or override per-plugin in `config/sms-manager.php`.

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `timeFormat` | `string` | inherits | `'12'` (AM/PM) or `'24'` |
| `monthFormat` | `string` | inherits | `'numeric'`, `'short'`, or `'long'` |
| `dateOrder` | `string` | inherits | `'dmy'`, `'mdy'`, or `'ymd'` |
| `dateSeparator` | `string` | inherits | `'/'`, `'-'`, or `'.'` |
| `showSeconds` | `bool` | inherits | Show seconds in time displays |
| `defaultDateRange` | `string` | inherits | Default range for analytics, logs, and dashboard (e.g. `'last7days'`, `'last30days'`, `'all'`) |
| `exportsCsv` | `bool` | inherits | Offer CSV export |
| `exportsJson` | `bool` | inherits | Offer JSON export |
| `exportsExcel` | `bool` | inherits | Offer Excel export |

## Example config file

```php
// config/sms-manager.php
use craft\helpers\App;

return [
    '*' => [
        'pluginName' => 'SMS Manager',
        'logLevel' => 'error',

        // Default provider & sender ID (by handle)
        'defaultProviderHandle' => 'production-provider',
        'defaultSenderIdHandle' => 'main-sender',

        // Analytics
        'enableAnalytics' => true,
        'analyticsLimit' => 1000,
        'analyticsRetention' => 30,
        'autoTrimAnalytics' => true,

        // SMS logs
        'enableSmsLogs' => true,
        'smsLogsLimit' => 10000,
        'smsLogsRetention' => 30,
        'autoTrimSmsLogs' => true,

        // Interface
        'itemsPerPage' => 100,
        'refreshIntervalSecs' => 30,
    ],

    'dev' => [
        'logLevel' => 'debug',
    ],

    'production' => [
        'logLevel' => 'error',
    ],
];
```

## Defining providers and sender IDs in config

You can declare providers and sender IDs in the config file instead of creating them in the Control Panel. Config-defined items show a **Config** badge and are read-only in the CP — they can only be changed in the file. A config item takes precedence over a database item with the same handle.

```php
// config/sms-manager.php
use craft\helpers\App;

return [
    '*' => [
        'providers' => [
            'production-provider' => [
                'name' => 'Production MPP-SMS',
                'type' => 'mpp-sms',
                'enabled' => true,
                'settings' => [
                    'apiUrl' => App::env('MPP_SMS_API_URL'),
                    'apiKey' => App::env('MPP_SMS_API_KEY'),
                    'allowedCountries' => ['*'], // ['*'] for all, or ['KW', 'SA', 'AE']
                ],
            ],
        ],
        'senderIds' => [
            'main-sender' => [
                'name' => 'Main Sender',
                'provider' => 'production-provider', // provider handle
                'senderId' => 'MYCOMPANY',
                'enabled' => true,
                'isDev' => false,
            ],
        ],
    ],
];
```

Available provider `type` values are `'mpp-sms'` and `'twilio'`. See [Providers](../feature-tour/providers.md) for each provider's settings keys, and [Sender IDs](../feature-tour/sender-ids.md) for the full sender ID options.

## Outbound request security

SMS Manager validates every provider API endpoint before making a request. The defaults are strict: HTTPS required, private and loopback networks blocked, redirects disabled, and only port 443 allowed. You can tune this globally or allowlist specific hosts.

```php
// config/sms-manager.php
return [
    '*' => [
        'security' => [
            'requireHttps' => true,
            'blockPrivateNetworks' => true,
            'allowRedirects' => false,
            'allowedPorts' => [443],
            'allowedApiHosts' => [
                'api.mpp-sms.com',
            ],
        ],
    ],
];
```

If `allowedApiHosts` is empty, any public host over HTTPS is allowed. A per-provider allowlist (`providers.*.settings.allowedApiHosts`) is merged with the global list. See [Providers](../feature-tour/providers.md#outbound-request-security) for details.

## Next steps

- [Providers](../feature-tour/providers.md) — connect an SMS gateway
- [Sender IDs](../feature-tour/sender-ids.md) — register the names messages are sent from
- [Sending SMS](../developers/sending-sms.md) — the PHP API
