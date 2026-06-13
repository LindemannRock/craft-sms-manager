# Events

SMS Manager fires two events, used by plugins that integrate with it.

## The `registerProviders` event @since(5.14.0)

Fired by `ProvidersService` when it collects its provider types. Listen for it to add a custom SMS gateway. The two built-in providers are seeded into the event before it fires, so your `register()` call adds yours alongside them.

```php
use lindemannrock\smsmanager\services\ProvidersService;
use lindemannrock\smsmanager\events\RegisterProvidersEvent;
use yii\base\Event;

Event::on(
    ProvidersService::class,
    ProvidersService::EVENT_REGISTER_PROVIDERS,
    function(RegisterProvidersEvent $event) {
        $event->register(\mymodule\providers\MyProvider::class);
    }
);
```

The registered class must implement `ProviderInterface` (extending `BaseProvider` satisfies this). Attaching to the event is safe regardless of plugin load order. See [Custom providers](custom-providers.md) for the full contract and a worked example.

## The `registerIntegrations` event @since(5.1.0)

Fired by `IntegrationsService` when it collects registered integrations. An integration is a plugin that sends through SMS Manager and wants its provider and sender ID usage tracked — so those resources can't be deleted while they're in use. Register from your plugin's `init()`.

```php
use lindemannrock\smsmanager\services\IntegrationsService;
use lindemannrock\smsmanager\events\RegisterIntegrationsEvent;
use yii\base\Event;

Event::on(
    IntegrationsService::class,
    IntegrationsService::EVENT_REGISTER_INTEGRATIONS,
    function(RegisterIntegrationsEvent $event) {
        $event->register(
            'my-plugin',                    // handle
            'My Plugin',                    // display name
            \mymodule\MyIntegration::class, // class implementing IntegrationInterface
        );
    }
);
```

The registered class must implement `IntegrationInterface`. See [Integrations](integrations.md) for the full contract and a worked example.
