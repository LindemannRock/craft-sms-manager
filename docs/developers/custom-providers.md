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

## The interface

These are the methods you can implement. `BaseProvider` provides defaults for everything except `handle()`, `displayName()`, `description()`, `getSettingsHtml()`, `validateSettings()`, and `send()`.

| Method | Default | Description |
|--------|---------|-------------|
| `handle()` | — | Unique type handle (e.g. `'my-provider'`) |
| `displayName()` | — | Full name shown in the CP |
| `description()` | — | Short description |
| `getSettingsHtml(?ProviderRecord)` | — | HTML for the provider's settings form |
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

`shortName()`, `website()`, `docsUrl()`, and `dashboardUrl()` are static.

### The `send()` result shape

`send()` must return an array with these keys:

| Key | Type | Description |
|-----|------|-------------|
| `success` | `bool` | Whether the gateway accepted the message |
| `messageId` | `string\|null` | Gateway message ID, if any |
| `response` | `string\|null` | Raw gateway response (stored on the log) |
| `error` | `string\|null` | Error message on failure |

## Helpers from `BaseProvider`

Inside `send()` you can use the protected helpers the built-in providers rely on:

- `normalizeAndValidatePhone($to, $allowedCountries)` — normalize the recipient and repair common country-code mistakes for the supported GCC/MENA countries.
- `sanitizeMessage($message)` — strip characters that break gateway encoding.
- `validateApiEndpoint($url, $providerAllowedHosts)` — enforce the [outbound request security](../get-started/configuration.md#outbound-request-security) policy before a request.
- `getRedirectPolicy()` — a safe Guzzle redirect policy honoring the security settings.

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
