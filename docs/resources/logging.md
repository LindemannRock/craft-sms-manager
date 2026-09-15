# Logging

SMS Manager writes structured, per-day log files through the bundled [Logging Library](https://github.com/LindemannRock/craft-logging-library).

> [!NOTE]
> Logging Library is required by Composer. Install or activate it in Craft to enable log viewing.

```bash title="PHP"
php craft plugin/install logging-library
```

```bash title="DDEV"
ddev craft plugin/install logging-library
```

Or via the Control Panel: **Settings → Plugins → Logging Library → Install**

Use this page when you need to check what SMS Manager did: provider and sender changes, send outcomes, validation failures, cleanup work, and debug-level diagnostics.

## Log levels

Four log levels are available, in order of verbosity:

| Level | What is logged |
|-------|----------------|
| `error` | Critical errors only |
| `warning` | Errors and warnings |
| `info` | General informational messages |
| `debug` | Detailed debugging, including timing and step-by-step diagnostics |

Each level includes all messages from the levels above it. `error` is the least verbose; `debug` is the most.

> [!WARNING]
> Debug level requires Craft's `devMode` to be enabled. If `logLevel` is set to `debug` while `devMode` is disabled, SMS Manager falls back to `info` and records a warning. Use `debug` for local development or short diagnostic sessions, because it can create much more log output.

## Configuration

```php
// config/sms-manager.php
return [
    'logLevel' => 'error', // 'error', 'warning', 'info', or 'debug'
];
```

For environment-specific logging, keep production quieter and enable debug only where Craft's `devMode` is enabled:

```php
// config/sms-manager.php
return [
    '*' => [
        'logLevel' => 'error',
    ],
    'staging' => [
        'logLevel' => 'warning',
    ],
    'dev' => [
        'logLevel' => 'debug',
    ],
];
```

## Log file location

```text
storage/logs/sms-manager-YYYY-MM-DD.log
```

Log files are rotated daily. Retention is managed by Logging Library, with a 30-day default.

Logs are written as structured JSON with context data alongside each message, so they can be searched in the Control Panel or ingested by external logging tools.

## Viewing logs in the CP

The **SMS Manager → Logs → System** screen reads, filters, and downloads these files without leaving the Control Panel.

From there you can:

- Browse entries for the current and recent days
- Filter by log level
- Search messages and context
- View file sizes and entry counts
- Download individual log files for external analysis

The `smsManager:viewSystemLogs` permission is required to access the System logs screen. The `smsManager:downloadSystemLogs` sub-permission is required to download log files. In Craft's permissions UI, both are nested under the `smsManager:viewLogs` parent group.

## What gets logged

The level of detail depends on your configured `logLevel`.

### Error (`error`)

- Missing, disabled, or unknown providers and sender IDs
- Provider validation, endpoint-policy, request, and response failures
- Failed provider or sender persistence and failed utility actions

### Warning (`warning`)

- Attempts to change config-controlled defaults or config-backed records
- Invalid integration registrations
- Cleanup-scheduler lock contention during bootstrap
- Debug fallback when `logLevel` is set to `debug` without `devMode`

### Info (`info`)

- Successful sends and provider or sender changes
- Phone-number corrections
- Analytics and SMS-log cleanup results
- Manual analytics and log clearing

### Debug (`debug`)

- Detailed diagnostics emitted by the sending and cleanup workflows when debug logging is active

Provider failures use bounded categories and correlation references. Plugin-level logs do not store the full message and use an irreversible recipient reference; the governed per-message content remains in [SMS logs](../feature-tour/sms-logs.md) only when delivery logging is enabled.

## Permissions

| Action | Permission |
|--------|------------|
| Access the System logs screen in the CP | `smsManager:viewSystemLogs` |
| Download log files | `smsManager:downloadSystemLogs` |
| Logs group (parent, Craft permissions UI only) | `smsManager:viewLogs` |

See [Permissions](../developers/permissions.md) for the full permission hierarchy.
