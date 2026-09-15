# Resource service API

Resolve providers and sender IDs from custom PHP without querying SMS Manager's tables directly. The resource services merge database and config-backed records, preserve handle-based defaults, and apply the same rules used by the Control Panel.

Access them from the plugin instance:

```php
use lindemannrock\smsmanager\SmsManager;

$providers = SmsManager::$plugin->providers;
$senderIds = SmsManager::$plugin->senderIds;
```

For message dispatch, use the dedicated [Sending SMS](sending-sms.md) API. For safe-deletion usage tracking, use [Integrations](integrations.md).

## Provider lookups

| Method | Returns | Purpose |
|--------|---------|---------|
| `getAllProviders(bool $enabledOnly = false)` | `ProviderRecord[]` | Database and config-backed providers, optionally enabled only |
| `getProviderById(int $id)` | `?ProviderRecord` | One database-backed provider by numeric ID |
| `getProviderByHandle(string $handle)` | `?ProviderRecord` | One provider by handle, including config-backed records |
| `getDefaultProvider()` | `?ProviderRecord` | Effective default, with fail-loud handling for an invalid configured handle |
| `getDefaultProviderHandle()` | `?string` | Effective configured default handle |
| `isDefaultProviderFromConfig()` | `bool` | Whether config locks the default provider |
| `getProviderOptions(bool $enabledOnly = true)` | `array` | Control Panel-ready provider options |

```php
$provider = SmsManager::$plugin->providers->getProviderByHandle('production-provider');

if ($provider && $provider->enabled) {
    $settings = $provider->getSettingsArray();
}
```

## Provider types and capabilities

| Method | Purpose |
|--------|---------|
| `registerProviderType(string $class)` | Imperatively register a provider class; the [`registerProviders` event](events.md) is preferred |
| `getProviderTypes(bool $useCache = true)` | Return the registered provider classes keyed by handle |
| `getProviderTypeOptions()` | Return type choices for Control Panel fields |
| `getProviderTypeMetadata(string $type)` @since(5.10.0) | Return display and capability metadata, or `null` for an unknown type |
| `supportsDevelopmentSenders(string $type)` @since(5.16.0) | Check the optional development-sender capability; unknown and legacy types return `false` |
| `createProviderByType(string $type)` | Instantiate a registered provider, or return `null` |

## Provider settings and country rules

| Method | Purpose |
|--------|---------|
| `getProviderSettings(int\|string $providerIdOrHandle)` @since(5.7.0) | Return the provider's decoded settings array |
| `getAllowedCountries(int\|string $providerIdOrHandle)` @since(5.7.0) | Return its country-code allowlist |
| `isCountryAllowed(int\|string $providerIdOrHandle, string $countryCode)` @since(5.7.0) | Check one country code against the allowlist |
| `getProvidersForCountry(string $countryCode, bool $enabledOnly = true)` @since(5.7.0) | Return providers that allow the country |

An empty allowlist or `['*']` permits every country.

## Provider writes

| Method | Returns | Purpose |
|--------|---------|---------|
| `setDefaultProviderByHandle(string $handle)` | `bool` | Persist the default unless config controls it |
| `saveProvider(ProviderRecord $provider, bool $runValidation = true)` | `bool` | Validate and save an editable database record |
| `deleteProvider(int $id)` | `array` | Delete when allowed, or return the blocking usage/error result |

Config-backed providers are read-only. Use [Configuration](../get-started/configuration.md#defining-providers-and-sender-ids-in-config) to change them.

## Sender ID lookups

| Method | Returns | Purpose |
|--------|---------|---------|
| `getAllSenderIds(bool $enabledOnly = false)` | `SenderIdRecord[]` | Database and config-backed sender IDs |
| `getSenderIdsByProvider(int\|string $providerIdOrHandle, bool $enabledOnly = false)` | `SenderIdRecord[]` | Sender IDs for one provider ID or handle |
| `getSenderIdById(int $id)` | `?SenderIdRecord` | One database-backed sender by numeric ID |
| `getSenderIdByHandle(string $handle)` | `?SenderIdRecord` | One sender by handle, including config-backed records |
| `getDefaultSenderId(int\|string\|null $providerIdOrHandle = null)` | `?SenderIdRecord` | Effective default, optionally constrained to a provider |
| `getDefaultSenderIdHandle()` | `?string` | Effective configured default handle |
| `isDefaultSenderIdFromConfig()` | `bool` | Whether config locks the default sender |
| `isDevelopmentSender(SenderIdRecord $senderId)` @since(5.16.0) | `bool` | Effective development state after provider-capability checks |
| `getSenderIdOptions(...)` | `array` | Grouped Control Panel options |
| `getSenderIdOptionsArray(...)` | `array` | Flat sender option data |

## Sender ID writes

| Method | Returns | Purpose |
|--------|---------|---------|
| `setDefaultSenderIdByHandle(string $handle)` | `bool` | Persist the default unless config controls it |
| `saveSenderId(SenderIdRecord $senderId, bool $runValidation = true)` | `bool` | Validate and save an editable database record |
| `deleteSenderId(int $id)` | `array` | Delete when allowed, or return the blocking usage/error result |

Config-backed sender IDs are read-only. When saving an editable sender, SMS Manager also normalizes its Development flag against the selected provider's effective capability.

## Next steps

- [Sending SMS](sending-sms.md) — dispatch messages by ID or stable sender handle
- [Custom providers](custom-providers.md) — implement and register another gateway
- [Integrations](integrations.md) — report resource usage so deletion stays safe
