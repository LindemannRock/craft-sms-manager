# Sending SMS

SMS Manager's job is to send text messages from your code. All sending goes through the `sms` service on the plugin instance:

```php
use lindemannrock\smsmanager\SmsManager;

$sms = SmsManager::$plugin->sms;
```

There are four send methods — two return a simple boolean, two return a detailed result array. Each has an ID-based and a handle-based form.

> [!NOTE]
> Sending is a PHP API — there is no Twig sending function. Trigger sends from a controller, a module, an event handler, or another plugin.

## send()

Send a message and get back whether it succeeded.

```php
public function send(
    string $to,
    string $message,
    string $language = 'en',
    ?int $providerId = null,
    ?int $senderIdId = null,
    ?string $sourcePlugin = null,
    ?int $sourceElementId = null,
    ?int $siteId = null,
): bool
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$to` | `string` | Recipient phone number |
| `$message` | `string` | Message content |
| `$language` | `string` | `'en'` or `'ar'` — controls provider encoding (default `'en'`) |
| `$providerId` | `?int` | Provider ID. `null` uses the default provider |
| `$senderIdId` | `?int` | Sender ID. `null` uses the default sender for the provider |
| `$sourcePlugin` | `?string` | Source handle for analytics and logs (e.g. your plugin's handle) |
| `$sourceElementId` | `?int` | Related element ID for log attribution (e.g. a submission ID) |
| `$siteId` | `?int` | Site ID for analytics attribution. `null` uses the current site |

```php
// Uses the default provider and sender ID
$ok = SmsManager::$plugin->sms->send('+96512345678', 'Hello from Craft!', 'en');

// Arabic message through a specific provider and sender
$ok = SmsManager::$plugin->sms->send(
    '+96512345678',
    'مرحبا من كرافت',
    'ar',
    $providerId,
    $senderIdId,
    'my-plugin',
    $entry->id,
);
```

## sendWithDetails()

Same inputs as `send()`, but returns the full result instead of a boolean — useful when you need the provider message ID, the raw response, or the error.

```php
public function sendWithDetails(
    string $to,
    string $message,
    string $language = 'en',
    ?int $providerId = null,
    ?int $senderIdId = null,
    ?string $sourcePlugin = null,
    ?int $sourceElementId = null,
    ?int $siteId = null,
): array
```

```php
$result = SmsManager::$plugin->sms->sendWithDetails('+96512345678', 'Hello!', 'en');

if ($result['success']) {
    $id = $result['messageId'];
}
```

See [Result shape](#result-shape) below.

## sendWithHandle() @since(5.7.0)

Send by sender ID **handle** rather than numeric ID. The provider is resolved from the sender. Prefer this when the sender comes from configuration or a stable identifier — a handle works for config-only senders, which have no database ID.

```php
public function sendWithHandle(
    string $to,
    string $message,
    string $senderIdHandle,
    string $language = 'en',
    ?string $sourcePlugin = null,
    ?int $sourceElementId = null,
    ?int $siteId = null,
): bool
```

```php
$ok = SmsManager::$plugin->sms->sendWithHandle(
    '+96512345678',
    'Hello!',
    'marketing',   // sender ID handle
    'en',
    'my-plugin',
);
```

## sendWithHandleDetails() @since(5.12.0)

The detailed-result counterpart of `sendWithHandle()` — same handle-based routing, returns the full result array. This is what the [Test SMS](../feature-tour/test-sms.md) page uses.

```php
public function sendWithHandleDetails(
    string $to,
    string $message,
    string $senderIdHandle,
    string $language = 'en',
    ?string $sourcePlugin = null,
    ?int $sourceElementId = null,
    ?int $siteId = null,
): array
```

## Result shape

`sendWithDetails()` and `sendWithHandleDetails()` return:

| Key | Type | Description |
|-----|------|-------------|
| `success` | `bool` | Whether the message was accepted by the provider |
| `messageId` | `string\|null` | Provider message ID, if returned |
| `response` | `string\|null` | Raw provider response |
| `error` | `string\|null` | Error message on failure |
| `executionTime` | `int` | Time taken, in milliseconds |
| `providerName` | `string\|null` | Resolved provider name |
| `senderIdName` | `string\|null` | Resolved sender ID name |
| `senderIdValue` | `string\|null` | The actual sender ID value sent to the gateway |
| `recipient` | `string` | The recipient passed in |

## Source plugin tracking

Pass `$sourcePlugin` (and optionally `$sourceElementId`) so messages are attributed to whatever triggered them. The source is shown per message in [SMS logs](../feature-tour/sms-logs.md), where you can also filter by it.

```php
SmsManager::$plugin->sms->send(
    $phoneNumber,
    $message,
    'en',
    null,            // default provider
    null,            // default sender ID
    'my-custom-plugin',
    $entry->id,
);
```

If your plugin participates in resource usage tracking (so providers and senders it uses can't be deleted out from under it), see [Integrations](integrations.md).

## What happens on send

Every send resolves a provider and sender ID, checks both are enabled, writes a delivery log row (when logging is enabled), invokes the provider, then records the outcome on the log and in analytics. A send fails early — with a specific error — when no provider or sender resolves, when either is disabled, or when the provider type is unknown.

## Next steps

- [Providers](../feature-tour/providers.md) — set up the gateway a send routes to
- [Custom providers](custom-providers.md) — add support for another gateway
- [Events](events.md) — register as an integration
