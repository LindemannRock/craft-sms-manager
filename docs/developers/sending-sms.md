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
| `$language` | `string` | Message language metadata (default `'en'`). Providers may use it for their own wire protocol; analytics encoding is determined from `$message` content |
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

Same inputs as `send()`, but returns the full result instead of a boolean — useful when you need the provider message ID, timing, or safe failure details. Successful sends retain the provider response. Failed provider calls return bounded diagnostic metadata instead of gateway or transport text that could contain message data or credentials.

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
| `messageId` | `string\|null` | Provider message ID on success; `null` for a provider failure |
| `response` | `string\|null` | Raw provider response on success; `null` for a provider failure |
| `error` | `string\|null` | Service validation error, or bounded provider failure metadata with provider, category, optional HTTP status, and correlation reference |
| `failureCode` | `string\|null` | Bounded failure reason; `null` on success |
| `failureScope` | `string\|null` | `recipient`, `account`, `transport`, or `unknown`; `null` on success |
| `retryAfterSeconds` | `int\|null` | Earliest admission reconsideration delay, never an instruction to replay a message |
| `executionTime` | `int` | Time taken, in milliseconds |
| `providerName` | `string\|null` | Resolved provider name |
| `senderIdName` | `string\|null` | Resolved sender ID name |
| `senderIdValue` | `string\|null` | Actual sender ID on success; `[redacted]` after a provider failure |
| `recipient` | `string` | Recipient on success; an irreversible `hmac-sha256:` reference after a provider failure |

A provider failure looks like this:

```text
SMS provider failure [provider=mpp-sms; category=http; status=503; reference=…]
```

Use the category and reference when correlating the API result, SMS log, and plugin-level log. SMS Manager never forwards transport exception messages, request URLs, authorization headers, or free-text gateway failures through this result.

## Failure metadata and queue ownership

Detailed results add three bounded fields without changing the existing Boolean APIs or their arguments. On success all three are `null`. Existing custom providers need no new methods; missing or invalid metadata becomes `provider-error` / `unknown`, with no automatic retry hint.

| Code | Scope | Meaning |
|------|-------|---------|
| `invalid-recipient` | `recipient` | The provider rejected recipient validation |
| `account-unavailable` | `account` | MPP rejected the account; a manual account hold is now active |
| `ip-blocked` | `transport` | MPP reported an IP block; an installation-wide hold is now active |
| `cooldown-active` | `account` or `transport` | Transport was not attempted because that scope is held |
| `guard-busy` | `transport` | Another process owns MPP admission; no transport attempt, one-second reconsideration hint |
| `guard-unavailable` | `transport` | Admission could not be established; no transport attempt |
| `transport-uncertain` | `transport` | A request may have happened but guard completion failed; reconcile delivery before any retry |
| `provider-error` | `unknown` | No more specific supported classification is available |

`retryAfterSeconds` is a nonnegative integer or `null`. Account holds and uncertain outcomes have no automatic recovery. A timed IP delay is bounded to 7,260 seconds. A zero delay means only that the hold time has elapsed; it does not prove a message is safe to resend. Unknown MPP text is discarded and does not create an account/IP hold. MPP redirects are disabled so one admitted attempt cannot follow a second gateway request.

A consumer can use the classification to stop its own SMS loop and preserve unattempted work:

```php
$result = $sms->sendWithHandleDetails($phoneNumber, $message, $senderHandle);
if (!$result['success'] && in_array($result['failureScope'], ['account', 'transport'], true)) {
    // Persist your own paused state and leave unattempted recipients pending.
    // Reconcile the current message separately; do not blindly replay it.
}
```

SMS Manager never manages the consumer's queue. An account release, timed expiry or administrative transport release does not resume a campaign or retry a failed job. Boolean consumers remain compatible but cannot distinguish a hold from other failures; they must adopt detailed results before implementing scope-aware pause/resume behavior.

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

Every send resolves a provider and sender ID and checks both are enabled. Provider dispatches record their outcome in delivery logs and analytics when enabled. MPP persists its delivery log after the guarded result: calls suppressed by a hold, busy admission or unavailable guard storage create neither a delivery log nor an analytics failure. The first account/IP failure remains a recorded attempt. An abrupt process death can leave a transport hold without a completed delivery log. A send fails early — with a specific error — when no provider or sender resolves, when either is disabled, or when the provider type is unknown.

For each attempted provider dispatch, analytics classifies the actual message content as GSM-7 or UCS-2. GSM-7 has a 160-septet single-segment limit and a 153-septet multipart limit; characters from the GSM extension table consume two septets. UCS-2 has a 70 UTF-16-code-unit single-segment limit and a 67-unit multipart limit; emoji and other astral characters consume two UTF-16 code units. The stored character total is the number of Unicode code points, while the SMS total is the calculated segment count. An empty message has zero characters and zero segments.

Language and encoding are deliberately separate analytics dimensions: changing only `$language` does not change content classification or segment count. MPP-SMS continues to use the language value for its existing gateway-specific wire parameter and encoding behavior; that transport contract is separate from the content-derived analytics facts.

## Next steps

- [Providers](../feature-tour/providers.md) — set up the gateway a send routes to
- [Custom providers](custom-providers.md) — add support for another gateway
- [Events](events.md) — register as an integration
