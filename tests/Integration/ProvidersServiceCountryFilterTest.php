<?php
/**
 * LindemannRock SMS Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use lindemannrock\smsmanager\tests\TestCase;

/**
 * Coverage for {@see \lindemannrock\smsmanager\services\ProvidersService}'s
 * country-filter surface (5.7.0+): `getProviderSettings`,
 * `getAllowedCountries`, `isCountryAllowed`, `getProvidersForCountry`.
 *
 * The country gate determines whether an outbound SMS can be routed through
 * a given provider — a regression in the wildcard / case-insensitivity /
 * unlisted-rejection logic would silently drop valid sends or admit invalid
 * ones, so each branch is asserted directly.
 *
 * @since 5.13.0
 */
final class ProvidersServiceCountryFilterTest extends TestCase
{
    public function testGetProviderSettingsResolvesByIdAndHandle(): void
    {
        $provider = $this->seedProvider([
            'settings' => json_encode(['allowedCountries' => ['KW', 'SA']]),
        ]);

        $byId = $this->providers->getProviderSettings((int) $provider->id);
        $byHandle = $this->providers->getProviderSettings((string) $provider->handle);

        self::assertSame(['KW', 'SA'], $byId['allowedCountries']);
        self::assertSame(['KW', 'SA'], $byHandle['allowedCountries']);
    }

    public function testGetProviderSettingsReturnsEmptyArrayForUnknownProvider(): void
    {
        self::assertSame([], $this->providers->getProviderSettings(self::MARKER . 'nope_handle'));
    }

    public function testGetAllowedCountriesReturnsConfiguredList(): void
    {
        $provider = $this->seedProvider([
            'settings' => json_encode(['allowedCountries' => ['KW', 'SA', 'AE']]),
        ]);

        self::assertSame(['KW', 'SA', 'AE'], $this->providers->getAllowedCountries($provider->id));
    }

    public function testGetAllowedCountriesReturnsEmptyArrayWhenSettingAbsent(): void
    {
        $provider = $this->seedProvider([
            'settings' => json_encode([]),
        ]);

        self::assertSame([], $this->providers->getAllowedCountries($provider->id));
    }

    public function testGetAllowedCountriesNormalizesEmptyStringToArray(): void
    {
        // Regression: Craft's empty multi-select persists allowedCountries as ""
        // (not []). The `: array` return type used to throw a TypeError when a
        // consumer (campaign-manager's new-campaign page) iterated providers.
        $provider = $this->seedProvider([
            'settings' => json_encode(['allowedCountries' => '']),
        ]);

        self::assertSame([], $this->providers->getAllowedCountries($provider->id));
    }

    public function testIsCountryAllowedTreatsEmptyStringAsWildcard(): void
    {
        $provider = $this->seedProvider([
            'settings' => json_encode(['allowedCountries' => '']),
        ]);

        self::assertTrue($this->providers->isCountryAllowed($provider->id, 'KW'));
    }

    public function testGetProvidersForCountryToleratesEmptyStringCountries(): void
    {
        // An empty-string allowedCountries must not crash the in_array() filter,
        // and counts as "all countries" (wildcard) like an empty list does.
        $provider = $this->seedProvider([
            'settings' => json_encode(['allowedCountries' => '']),
        ]);

        $matchingIds = array_map(
            static fn($p) => (int) $p->id,
            $this->providers->getProvidersForCountry('KW'),
        );

        self::assertContains((int) $provider->id, $matchingIds);
    }

    public function testIsCountryAllowedWildcardAdmitsEveryCountry(): void
    {
        $provider = $this->seedProvider([
            'settings' => json_encode(['allowedCountries' => ['*']]),
        ]);

        self::assertTrue($this->providers->isCountryAllowed($provider->id, 'KW'));
        self::assertTrue($this->providers->isCountryAllowed($provider->id, 'US'));
        self::assertTrue($this->providers->isCountryAllowed($provider->id, 'ZZ'));
    }

    public function testIsCountryAllowedTreatsMissingListAsWildcard(): void
    {
        // Empty allowedCountries (or absent key) is documented to mean "all
        // countries" — without this, providers configured before the country
        // filter shipped would silently reject every send.
        $provider = $this->seedProvider([
            'settings' => json_encode([]),
        ]);

        self::assertTrue($this->providers->isCountryAllowed($provider->id, 'KW'));
    }

    public function testIsCountryAllowedAcceptsListedAndRejectsUnlisted(): void
    {
        $provider = $this->seedProvider([
            'settings' => json_encode(['allowedCountries' => ['KW', 'SA']]),
        ]);

        self::assertTrue($this->providers->isCountryAllowed($provider->id, 'KW'));
        self::assertTrue($this->providers->isCountryAllowed($provider->id, 'SA'));
        self::assertFalse($this->providers->isCountryAllowed($provider->id, 'US'));
        self::assertFalse($this->providers->isCountryAllowed($provider->id, 'AE'));
    }

    public function testIsCountryAllowedIsCaseInsensitive(): void
    {
        $provider = $this->seedProvider([
            'settings' => json_encode(['allowedCountries' => ['KW']]),
        ]);

        self::assertTrue($this->providers->isCountryAllowed($provider->id, 'kw'));
        self::assertTrue($this->providers->isCountryAllowed($provider->id, 'Kw'));
    }

    public function testGetProvidersForCountryReturnsOnlyMatchingProviders(): void
    {
        $kwOnly = $this->seedProvider([
            'settings' => json_encode(['allowedCountries' => ['KW']]),
        ]);
        $kwAndSa = $this->seedProvider([
            'settings' => json_encode(['allowedCountries' => ['KW', 'SA']]),
        ]);
        $saOnly = $this->seedProvider([
            'settings' => json_encode(['allowedCountries' => ['SA']]),
        ]);

        $matchingForKw = $this->providers->getProvidersForCountry('KW');
        $matchingIds = array_map(static fn($p) => (int) $p->id, $matchingForKw);

        self::assertContains((int) $kwOnly->id, $matchingIds);
        self::assertContains((int) $kwAndSa->id, $matchingIds);
        self::assertNotContains((int) $saOnly->id, $matchingIds);
    }

    public function testGetProvidersForCountryRespectsEnabledOnlyFlag(): void
    {
        $enabled = $this->seedProvider([
            'enabled' => true,
            'settings' => json_encode(['allowedCountries' => ['KW']]),
        ]);
        $disabled = $this->seedProvider([
            'enabled' => false,
            'settings' => json_encode(['allowedCountries' => ['KW']]),
        ]);

        $enabledIds = array_map(
            static fn($p) => (int) $p->id,
            $this->providers->getProvidersForCountry('KW', enabledOnly: true),
        );
        $allIds = array_map(
            static fn($p) => (int) $p->id,
            $this->providers->getProvidersForCountry('KW', enabledOnly: false),
        );

        self::assertContains((int) $enabled->id, $enabledIds);
        self::assertNotContains((int) $disabled->id, $enabledIds);

        self::assertContains((int) $enabled->id, $allIds);
        self::assertContains((int) $disabled->id, $allIds);
    }
}
