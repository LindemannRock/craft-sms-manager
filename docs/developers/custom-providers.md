# Custom providers

SMS Manager ships with [MPP-SMS](../feature-tour/provider-mpp-sms.md) and [Twilio](../feature-tour/provider-twilio.md), but you can add support for any SMS gateway by writing a provider class and registering it. Once registered, your provider appears in the **Provider type** dropdown and behaves like a built-in one.

## Extend `BaseProvider`

`BaseProvider` implements `ProviderInterface` and supplies sensible defaults, phone-number normalization, message sanitization, and endpoint security checks — so a custom provider only has to declare its identity, validate its settings, and send.

```php
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
        return 'Sends SMS through My Gateway.';
    }

    public function getSettingsHtml(?ProviderRecord $provider = null): string
    {
        $settings = $provider ? json_decode((string)$provider->settings, true) : [];

        return $this->renderSettingsTemplate('my-module/provider-settings', [
            'provider' => $provider,
            'settings' => $settings,
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
        // Call your gateway's API and map the response into this shape.
        return [
            'success'   => true,
            'messageId' => 'msg-123',
            'response'  => 'OK',
            'error'     => null,
        ];
    }
}
```

Development senders are disabled for custom providers by default. If your gateway has a real per-sender development credential or routing mode, opt in by overriding the optional capability:

```php
public static function supportsDevelopmentSenders(): bool
{
    return true;
}
```

SMS Manager then exposes the sender's **Development** control and passes `isDev => true` in the provider settings for senders marked as development. Providers that implement `ProviderInterface` directly remain compatible: the capability is optional, and the safe default is `false`. A direct implementation can opt in by also implementing `DevelopmentSenderProviderInterface`.

## The interface

These are the methods you can implement. `BaseProvider` provides defaults for everything except `handle()`, `displayName()`, `description()`, `validateSettings()`, and `send()`.

| Method | Default | Description |
|--------|---------|-------------|
| `handle()` | — | Unique type handle (e.g. `'my-provider'`) |
| `displayName()` | — | Full name shown in the CP |
| `description()` | — | Short description |
| `getSettingsHtml(?ProviderRecord)` | generic API Key / URL / username / password form @since(5.14.0) | HTML for the provider's settings form, rendered on the provider edit page. Override to supply your own fields; the default works if those four are all you need |
| `validateSettings(array)` | — | Return an array of field → error (empty if valid) |
| `send(string, string, string, string, array)` | — | Send a message; return the result shape below |
| `shortName()` @since(5.10.0) | `displayName()` | Abbreviated name for badges/compact UI |
| `iconUrl()` | `null` | Provider icon URL |
| `website()` @since(5.10.0) | `null` | Provider website |
| `docsUrl()` @since(5.10.0) | `null` | API documentation URL |
| `dashboardUrl()` @since(5.10.0) | `null` | Account dashboard URL |
| `supportsUnicode()` @since(5.10.0) | `true` | Whether non-Latin messages are supported |
| `supportsDeliveryReports()` @since(5.10.0) | `false` | Whether the gateway exposes delivery reports |
| `supportsConnectionTest()` | `false` | Whether `testConnection()` is implemented |
| `testConnection(array)` | returns `true` | Credentials check without sending |
| `supportsDevelopmentSenders()` @since(5.16.0) | `false` | Optional capability that enables per-sender development routing and exposes the Development control |

`shortName()`, `website()`, `docsUrl()`, `dashboardUrl()`, and `supportsDevelopmentSenders()` are static. The development capability is not part of `ProviderInterface`; direct implementations only need it when opting in.

### How the settings form is rendered

On the provider edit page, SMS Manager renders your provider's `getSettingsHtml()` directly, and re-fetches it when the **Provider type** dropdown changes — so each provider owns its own settings UI. Use the `forms.*` macros (or `craft\helpers\Cp`) in your settings template, name your fields `providerSettings[yourKey]`, and read them back from the `$settings` array in `validateSettings()` and `send()`. Any field keys you post are persisted as-is, so you're not limited to a fixed set.

Every settings template also receives a `countryOptions` variable — the dial-code country list — so you can offer the same **Allowed Countries** field the built-in providers use without building the list yourself:

```twig
{{ forms.selectizeField({
    label: 'Allowed Countries'|t('your-plugin'),
    name: 'providerSettings[allowedCountries]',
    values: providerSettings.allowedCountries ?? [],
    options: countryOptions,
    multi: true,
}) }}
```

### The `send()` result shape

`send()` must return `success`, `messageId`, and `response`. On a successful result, `error` is optional; returning `error => null` is recommended so the shape stays consistent. SMS Manager normalizes either successful form to include `error => null` and null `failureCode`, `failureScope` and `retryAfterSeconds`. No interface method or required provider return key was added.

| Key | Type | Description |
|-----|------|-------------|
| `success` | `bool` | Whether the gateway accepted the message |
| `messageId` | `string\|null` | Gateway message ID, if any |
| `response` | `string\|null` | Raw gateway response on success |
| `error` | `string\|null` | Optional on success and must be `null` when present. Use the safe failure helper for failures; the service replaces other free text with bounded metadata before returning or persisting it |

Providers may optionally return the bounded [failure metadata](sending-sms.md#failure-metadata-and-queue-ownership). SMS Manager validates code/scope combinations and delay bounds, discarding arbitrary values. Metadata alone does not create a hold. Opt into the shared admission contract below to persist and enforce your provider’s classifications.

Treat failure data as untrusted. Transport exception messages and gateway bodies can contain request URLs, authorization headers, credentials, recipients, sender IDs, or complete message text. Catch provider-call failures inside `send()` and classify them without inspecting their free-text payload:

```php
try {
    // Call the gateway.
} catch (\Throwable $e) {
    return $this->failureResultFromThrowable($e);
}
```

The classifier uses only safe exception type and response-status metadata: a response-bearing Guzzle `RequestException` becomes `http`, a Guzzle `ConnectException` or other response-less `TransferException` becomes `transport`, and any other throwable becomes `provider-exception`.

Do not log the exception message, request URI, authorization headers, recipient, sender ID, or message. For operational correlation, log the returned failure string and use `recipientReference($to)` for the destination. As a final safeguard, `SmsService` discards all free-text failure fields returned by custom providers and catches uncaught provider exceptions.

## Shared transport protection

Implement the optional `TransportProviderInterface` alongside `BaseProvider` (or `ProviderInterface`). Return a `TransportContext` from `getTransportContext(array $settings)`, then wrap the actual HTTP operation in `SmsManager::$plugin->transport->send($context, $callback)`. The same context method supplies the list, detail and release UI; do not make network requests from it or from the provider constructor.

```php
use craft\helpers\App;
use lindemannrock\smsmanager\models\TransportContext;
use lindemannrock\smsmanager\providers\TransportProviderInterface;
use lindemannrock\smsmanager\SmsManager;

// Add TransportProviderInterface to your provider's implements clause.
public function getTransportContext(array $settings): TransportContext
{
    return new TransportContext(
        self::handle(),
        'my-gateway', // Stable namespace: keep it unchanged after deployment.
        ['main' => (string) App::parseEnv($settings['accountId'] ?? '')],
    );
}

// Inside send(), after credential, recipient and endpoint validation:
return SmsManager::$plugin->transport->send(
    $this->getTransportContext($settings),
    function () use ($to, $message, $senderId, $settings): array {
        // Make exactly one HTTP request with redirects and automatic retries off.
        // Parse documented response fields; never copy free-text failure data.
        // For a confirmed account outage, for example:
        return array_replace($this->failureResult('provider'), [
            'failureCode' => 'account-unavailable',
            'failureScope' => 'account',
            'retryAfterSeconds' => null,
        ]);
    },
);
```

Choose a stable resolved account identity, such as an account ID; use the effective credential only when the gateway exposes no separate account identity. The context hashes it with the installation's Craft security key. Never log the context's storage keys or include them in UI, results or release history. `providerType` must equal the registered provider handle; it and the identity namespace accept lowercase letters, digits and hyphens, up to 64 characters. All hosts need the same namespace, identity rules and security key.

For development credentials, supply `main` and `development` identities and set the active lane from the effective send settings. If development falls back to the main account, select `main`; identical identities should also share that lane. A transport-wide hold is shared across every account of this provider type, independently of other provider types.

The shared service creates holds only for sanitized `account-unavailable` (manual account), `ip-blocked` (transport), `rate-limited` and `provider-unavailable` results. The last two may target `account` or `transport`; a bounded `retryAfterSeconds` schedules automatic expiry, otherwise release is manual. Unknown codes, raw response text and arbitrary scope values cannot become stored hold reasons. Select rules from the gateway's documented behavior; a recipient error or timeout alone does not establish an account-wide outage.

Persistence, locking, process-death recovery, expiry, privacy-safe status and confirmed release belong to the shared service. Do not maintain a second cache or process-local guard. Suppressed dispatches produce no delivery log or analytics failure. The caller still owns pending messages; admission never schedules retries.

Providers that do not implement this optional interface keep their existing send contract and display **Not configured** for transport protection. All four public SMS methods and `ProviderInterface` remain compatible. Integrations that directly used the former MPP-specific `mppTransport` component must adopt `transport` and `TransportContext`; there is no compatibility alias for that component.

## Helpers from `BaseProvider`

Inside `send()` you can use the protected helpers the built-in providers rely on:

- `normalizeAndValidatePhone($to, $allowedCountries)` — normalize the recipient and repair common country-code mistakes for the supported GCC/MENA countries.
- `sanitizeMessage($message)` — strip characters that break gateway encoding.
- `validateApiEndpoint($url, $providerAllowedHosts)` — enforce the [outbound request security](../get-started/configuration.md#outbound-request-security) policy before a request.
- `getRedirectPolicy()` — a safe Guzzle redirect policy honoring the security settings.
- `failureResult($category, $status = null)` — create a failure with the provider handle, safe category/status, and correlation reference.
- `failureResultFromThrowable($e)` — safely classify Guzzle HTTP/transport failures and other provider throwables without reading their free-text payload.
- `recipientReference($to)` — create the irreversible recipient reference used in plugin-level logs.

## Register the provider

Register your provider type by listening for the `EVENT_REGISTER_PROVIDERS` event in your plugin or module's `init()`. The two built-in providers are seeded into the event, so your `register()` call adds yours alongside them:

```php
use lindemannrock\smsmanager\events\RegisterProvidersEvent;
use lindemannrock\smsmanager\services\ProvidersService;
use mymodule\providers\MyProvider;
use yii\base\Event;

Event::on(
    ProvidersService::class,
    ProvidersService::EVENT_REGISTER_PROVIDERS,
    function (RegisterProvidersEvent $event) {
        $event->register(MyProvider::class);
    }
);
```

Attaching to the event is safe regardless of plugin load order — unlike calling the service directly, it never depends on SMS Manager already being initialized when your `init()` runs.

The class must implement `ProviderInterface` (extending `BaseProvider` satisfies this). Once registered, create instances of it under **SMS Manager → Providers** like any other provider type.

## Next steps

- [Providers](../feature-tour/providers.md) — how providers are configured and used
- [Sending SMS](sending-sms.md) — the API that drives your provider's `send()`
