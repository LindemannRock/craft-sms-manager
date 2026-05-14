<?php

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use Craft;
use craft\services\Config;
use lindemannrock\smsmanager\helpers\ConfigFileHelper;
use lindemannrock\smsmanager\models\Settings;
use lindemannrock\smsmanager\SmsManager;
use lindemannrock\smsmanager\tests\Stubs\SmsManagerConfigStub;
use lindemannrock\smsmanager\tests\TestCase;
use ReflectionClass;

/**
 * Regression coverage for audit finding 8.2.
 *
 * Before the fix, both `ProvidersService::getDefaultProvider()` and
 * `SenderIdsService::getDefaultSenderId()` silently fell through to the
 * first enabled record when `defaultProviderHandle` / `defaultSenderIdHandle`
 * was set in settings but didn't resolve (typo, deleted, or disabled).
 * Callers passing null IDs to `SmsService::send()` would then receive a
 * substituted provider/sender with no signal that the configured default
 * was unusable — misattributing outbound SMS.
 *
 * After the fix, both methods return null when a configured handle is
 * present but unusable. The wildcard fall-through path (no handle
 * configured at all) still returns the first enabled record.
 *
 * Tests have to neutralise two layers of config override that
 * `SmsManager::getSettings()` would otherwise reapply on every call:
 *   1. The on-disk `config/sms-manager.php` file, read by Craft's Config
 *      service. We swap Craft's Config service for a subclass that
 *      returns an empty array for `sms-manager`, so
 *      `applyConfigOverridesToSettings()` becomes a no-op for the
 *      duration of the test.
 *   2. The cached Settings instance on `craft\base\Plugin::$_settings`.
 *      We drop it so the next `getSettings()` call rebuilds from
 *      whatever the DB holds, then we mutate the cached instance
 *      directly to set the handles we want for the test.
 *
 * Both are restored in tearDown.
 *
 * @since 5.13.0
 */
final class DefaultResolutionFailLoudTest extends TestCase
{
    private ?Config $originalConfigService = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfigService = Craft::$app->getConfig();
        Craft::$app->set('config', new SmsManagerConfigStub());
        ConfigFileHelper::clearCache();
        $this->dropCachedSettings();
    }

    protected function tearDown(): void
    {
        if ($this->originalConfigService !== null) {
            Craft::$app->set('config', $this->originalConfigService);
        }
        ConfigFileHelper::clearCache();
        $this->dropCachedSettings();
        parent::tearDown();
    }

    /**
     * Null out the cached Settings instance on `craft\base\Plugin::$_settings`
     * so the next `getSettings()` call rebuilds from the DB and applies
     * (our now-empty) config overrides.
     */
    private function dropCachedSettings(): void
    {
        $pluginRef = new ReflectionClass(\craft\base\Plugin::class);
        $settingsProp = $pluginRef->getProperty('_settings');
        $settingsProp->setAccessible(true);
        $settingsProp->setValue(SmsManager::$plugin, null);
    }

    /**
     * Set the live default handles on the plugin's Settings instance.
     * Safe to call multiple times within a test — the stub Config service
     * keeps `applyConfigOverridesToSettings()` from undoing the mutation.
     */
    private function setDefaults(?string $defaultProviderHandle, ?string $defaultSenderIdHandle): void
    {
        /** @var Settings $settings */
        $settings = SmsManager::$plugin->getSettings();
        $settings->defaultProviderHandle = $defaultProviderHandle;
        $settings->defaultSenderIdHandle = $defaultSenderIdHandle;
    }

    public function testGetDefaultProviderReturnsNullWhenConfiguredHandleDoesNotResolve(): void
    {
        $this->seedProvider(); // an enabled provider exists; should NOT be substituted

        $this->setDefaults(self::MARKER . 'nonexistent_handle', null);

        $result = $this->providers->getDefaultProvider();

        self::assertNull(
            $result,
            'getDefaultProvider() must return null when the configured handle is set but does not resolve — falling through to a substitute would misroute SMS for callers that pass null providerId to send().',
        );
    }

    public function testGetDefaultProviderReturnsNullWhenConfiguredHandleIsDisabled(): void
    {
        $disabled = $this->seedProvider(['enabled' => false]);
        $this->seedProvider(); // another, enabled — proves we don't silently swap

        $this->setDefaults((string) $disabled->handle, null);

        $result = $this->providers->getDefaultProvider();

        self::assertNull(
            $result,
            'getDefaultProvider() must return null when the configured handle resolves to a disabled provider — admin explicitly named this one, so substituting a different enabled provider hides the disabled state.',
        );
    }

    public function testGetDefaultProviderResolvesConfiguredHandleWhenValid(): void
    {
        $provider = $this->seedProvider();

        $this->setDefaults((string) $provider->handle, null);

        $result = $this->providers->getDefaultProvider();

        self::assertNotNull($result, 'A valid configured handle must resolve.');
        self::assertSame((int) $provider->id, (int) $result->id);
    }

    public function testGetDefaultSenderIdReturnsNullWhenConfiguredHandleDoesNotResolve(): void
    {
        $provider = $this->seedProvider();
        $this->seedSenderId($provider); // an enabled sender exists; should NOT be substituted

        $this->setDefaults(null, self::MARKER . 'nonexistent_sender_handle');

        $result = $this->senderIds->getDefaultSenderId();

        self::assertNull(
            $result,
            'getDefaultSenderId() must return null when the configured handle is set but does not resolve. This is the exact scenario that masked the audit 8.2 bug — admin typo in defaultSenderIdHandle silently swapped to A.Alghanim or similar.',
        );
    }

    public function testGetDefaultSenderIdReturnsNullWhenConfiguredHandleIsDisabled(): void
    {
        $provider = $this->seedProvider();
        $disabled = $this->seedSenderId($provider, ['enabled' => false]);
        $this->seedSenderId($provider); // an enabled sibling

        $this->setDefaults(null, (string) $disabled->handle);

        $result = $this->senderIds->getDefaultSenderId();

        self::assertNull(
            $result,
            'getDefaultSenderId() must return null when the configured handle resolves to a disabled sender — admin explicitly named this one, substituting an enabled sibling hides the disabled state.',
        );
    }

    public function testGetDefaultSenderIdFallsThroughWhenConfiguredDefaultIsForDifferentProvider(): void
    {
        $providerA = $this->seedProvider();
        $providerB = $this->seedProvider();
        $configuredDefault = $this->seedSenderId($providerA);
        $matchingForB = $this->seedSenderId($providerB);

        $this->setDefaults(null, (string) $configuredDefault->handle);

        $result = $this->senderIds->getDefaultSenderId($providerB->id);

        self::assertNotNull($result, 'Expected a sender to be returned for the requested provider.');
        self::assertSame(
            (int) $matchingForB->id,
            (int) $result->id,
            'When the configured default is for a different provider than requested, fall through to find a sender for the requested provider — this is a legitimate best-effort fit, not the silent-substitution bug.',
        );
    }

    public function testGetDefaultSenderIdResolvesConfiguredHandleWhenValid(): void
    {
        $provider = $this->seedProvider();
        $senderId = $this->seedSenderId($provider);

        $this->setDefaults(null, (string) $senderId->handle);

        $result = $this->senderIds->getDefaultSenderId();

        self::assertNotNull($result);
        self::assertSame((int) $senderId->id, (int) $result->id);
    }
}
