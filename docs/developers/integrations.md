# Integrations

If your plugin sends SMS through SMS Manager and stores a reference to a provider or sender ID, register it as an **integration**. SMS Manager then knows your plugin uses those resources and stops an admin from deleting a provider or sender ID that's still in use — showing exactly where it's referenced.

This is about *usage tracking*, not sending. To send, just call the [sending API](sending-sms.md). Register an integration when you also want safe deletion and visibility.

## How it works

1. Your plugin registers an integration class via the [`registerIntegrations` event](events.md).
2. The class implements `IntegrationInterface`, answering two questions: "where is provider X used?" and "where is sender ID Y used?"
3. When an admin tries to delete a provider or sender ID, SMS Manager asks every integration. If any report a usage, the delete is blocked with a message listing them.

## Implement the interface

```php
namespace mymodule\integrations;

use lindemannrock\smsmanager\integrations\IntegrationInterface;

class MyIntegration implements IntegrationInterface
{
    /**
     * @return array<array{label: string, editUrl: string|null}>
     */
    public function getProviderUsages(int $providerId): array
    {
        // Find configurations in your plugin that use this provider.
        return [
            ['label' => 'Contact form', 'editUrl' => '/admin/my-plugin/contact'],
        ];
    }

    /**
     * @return array<array{label: string, editUrl: string|null}>
     */
    public function getSenderIdUsages(int $senderIdId): array
    {
        return [];
    }
}
```

Each usage is an array with a human-readable `label` and an optional `editUrl` linking to where it's configured. Return an empty array when the resource isn't used.

## Register it

```php
use lindemannrock\smsmanager\services\IntegrationsService;
use lindemannrock\smsmanager\events\RegisterIntegrationsEvent;
use yii\base\Event;

Event::on(
    IntegrationsService::class,
    IntegrationsService::EVENT_REGISTER_INTEGRATIONS,
    function(RegisterIntegrationsEvent $event) {
        $event->register('my-plugin', 'My Plugin', MyIntegration::class);
    }
);
```

## Querying usage yourself

The integrations service exposes the same lookups SMS Manager uses internally:

```php
$integrations = SmsManager::$plugin->integrations;

$integrations->getRegisteredIntegrations();        // all registered integrations
$integrations->getProviderUsages($providerId);     // usages across all integrations
$integrations->getSenderIdUsages($senderIdId);
$integrations->isProviderInUse($providerId);       // bool
$integrations->isSenderIdInUse($senderIdId);       // bool
```

## Next steps

- [Sending SMS](sending-sms.md) — the send API your integration wraps
- [Events](events.md) — the registration event
