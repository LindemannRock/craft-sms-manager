<?php
/**
 * LindemannRock SMS Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use lindemannrock\smsmanager\events\RegisterProvidersEvent;
use lindemannrock\smsmanager\services\ProvidersService;
use lindemannrock\smsmanager\tests\Stubs\StubProvider;
use lindemannrock\smsmanager\tests\TestCase;

/**
 * Registration contract for {@see ProvidersService}: provider types are
 * collected by firing {@see ProvidersService::EVENT_REGISTER_PROVIDERS} from
 * a lazy, cached getter, with the two built-ins seeded into the event so a
 * listener can add its own type (or reshape the built-ins) before the registry
 * is finalized.
 *
 * This mirrors the suite's other pluggable registries (report-manager's
 * queued-export providers, this plugin's own IntegrationsService) and the
 * third-party convention (Formie, SEOmatic, craft-exporter, Freeform) — a
 * registration *event*, never an imperative call that depends on plugin load
 * order.
 *
 * @since 5.14.0
 */
final class ProvidersServiceRegistrationTest extends TestCase
{
    public function testBuiltInProvidersAreRegisteredWithoutAnyListener(): void
    {
        $service = new ProvidersService();
        $this->swapPluginComponent('sms-manager', 'providers', $service);

        $types = $service->getProviderTypes();

        self::assertArrayHasKey('mpp-sms', $types);
        self::assertArrayHasKey('twilio', $types);
    }

    public function testProviderRegisteredViaEventAppearsInTypes(): void
    {
        StubProvider::reset();

        $service = new ProvidersService();
        $service->on(
            ProvidersService::EVENT_REGISTER_PROVIDERS,
            static function (RegisterProvidersEvent $event): void {
                $event->register(StubProvider::class);
            }
        );
        $this->swapPluginComponent('sms-manager', 'providers', $service);

        $types = $service->getProviderTypes();

        // Listener-registered type is present...
        self::assertArrayHasKey(self::STUB_TYPE, $types);
        self::assertSame(StubProvider::class, $types[self::STUB_TYPE]);
        // ...alongside the seeded built-ins.
        self::assertArrayHasKey('mpp-sms', $types);
        self::assertArrayHasKey('twilio', $types);

        // And the type is usable end-to-end through the public surface.
        self::assertInstanceOf(StubProvider::class, $service->createProviderByType(self::STUB_TYPE));
    }

    public function testRegisterProviderTypeThrowsForNonProviderClass(): void
    {
        $service = new ProvidersService();
        $this->swapPluginComponent('sms-manager', 'providers', $service);

        $this->expectException(\InvalidArgumentException::class);
        $service->registerProviderType(\stdClass::class);
    }
}
