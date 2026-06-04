<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\StringHelper;
use lindemannrock\base\helpers\SlugHandleHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\smsmanager\providers\MppSmsProvider;
use lindemannrock\smsmanager\providers\ProviderInterface;
use lindemannrock\smsmanager\providers\TwilioProvider;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\SmsManager;

/**
 * Providers Service
 *
 * Manages SMS providers.
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
class ProvidersService extends Component
{
    use LoggingTrait;

    /**
     * @var array<string, class-string<ProviderInterface>> Registered provider types
     */
    private array $providerTypes = [];

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle(SmsManager::$plugin->id);
        $this->registerDefaultProviders();
    }

    /**
     * Register default provider types
     */
    private function registerDefaultProviders(): void
    {
        $this->registerProviderType(MppSmsProvider::class);
        $this->registerProviderType(TwilioProvider::class);
    }

    /**
     * Register a provider type
     *
     * @param class-string<ProviderInterface> $class Provider class
     */
    public function registerProviderType(string $class): void
    {
        if (!is_subclass_of($class, ProviderInterface::class)) {
            throw new \InvalidArgumentException("Provider class must implement ProviderInterface");
        }

        $this->providerTypes[$class::handle()] = $class;
    }

    /**
     * Get all registered provider types
     *
     * @return array<string, class-string<ProviderInterface>>
     */
    public function getProviderTypes(): array
    {
        return $this->providerTypes;
    }

    /**
     * Get provider type options for select fields
     *
     * @return array
     */
    public function getProviderTypeOptions(): array
    {
        $options = [];
        foreach ($this->providerTypes as $handle => $class) {
            $options[] = [
                'label' => $class::displayName(),
                'value' => $handle,
                'shortName' => $class::shortName(),
            ];
        }
        return $options;
    }

    /**
     * Get static metadata for a provider type
     *
     * @param string $type Provider type handle
     * @return array{shortName: string, website: string|null, docsUrl: string|null, dashboardUrl: string|null, supportsUnicode: bool, supportsDeliveryReports: bool, supportsConnectionTest: bool}|null
     * @since 5.10.0
     */
    public function getProviderTypeMetadata(string $type): ?array
    {
        if (!isset($this->providerTypes[$type])) {
            return null;
        }

        $class = $this->providerTypes[$type];

        return [
            'shortName' => $class::shortName(),
            'website' => $class::website(),
            'docsUrl' => $class::docsUrl(),
            'dashboardUrl' => $class::dashboardUrl(),
            'supportsUnicode' => $class::supportsUnicode(),
            'supportsDeliveryReports' => $class::supportsDeliveryReports(),
            'supportsConnectionTest' => $class::supportsConnectionTest(),
        ];
    }

    /**
     * Create a provider instance by type
     *
     * @param string $type Provider type handle
     * @return ProviderInterface|null
     */
    public function createProviderByType(string $type): ?ProviderInterface
    {
        if (!isset($this->providerTypes[$type])) {
            return null;
        }

        $class = $this->providerTypes[$type];
        return new $class();
    }

    /**
     * Get all providers (config + database merged)
     *
     * @param bool $enabledOnly Only return enabled providers
     * @return ProviderRecord[]
     */
    public function getAllProviders(bool $enabledOnly = false): array
    {
        $providers = ProviderRecord::findAllWithConfig();

        if ($enabledOnly) {
            $providers = array_filter($providers, fn($p) => $p->enabled);
        }

        return $providers;
    }

    /**
     * Get provider by ID
     *
     * @param int $id Provider ID
     * @return ProviderRecord|null
     */
    public function getProviderById(int $id): ?ProviderRecord
    {
        return ProviderRecord::findOne($id);
    }

    /**
     * Get provider by handle (checks config first, then database)
     *
     * @param string $handle Provider handle
     * @return ProviderRecord|null
     */
    public function getProviderByHandle(string $handle): ?ProviderRecord
    {
        return ProviderRecord::findByHandleWithConfig($handle);
    }

    /**
     * Get the default provider
     *
     * When `defaultProviderHandle` is set in settings, that handle is the
     * authoritative answer — if it doesn't resolve or is disabled, this
     * method returns null rather than silently substituting another
     * provider. Caller asked for this provider by name; ignoring it would
     * misroute SMS without anyone noticing. When the handle is unset, falls
     * back to the first enabled provider.
     *
     * @return ProviderRecord|null
     */
    public function getDefaultProvider(): ?ProviderRecord
    {
        $settings = SmsManager::$plugin->getSettings();

        if (!empty($settings->defaultProviderHandle)) {
            $provider = $this->getProviderByHandle($settings->defaultProviderHandle);
            if ($provider && $provider->enabled) {
                return $provider;
            }
            return null;
        }

        // No default configured — fall back to first enabled provider.
        $providers = $this->getAllProviders(true);
        return $providers[0] ?? null;
    }

    /**
     * Check if the default provider is set from config file
     *
     * @return bool
     */
    public function isDefaultProviderFromConfig(): bool
    {
        $settings = SmsManager::$plugin->getSettings();
        return $settings->isOverriddenByConfig('defaultProviderHandle');
    }

    /**
     * Get the default provider handle
     *
     * @return string|null
     */
    public function getDefaultProviderHandle(): ?string
    {
        $settings = SmsManager::$plugin->getSettings();
        return $settings->defaultProviderHandle ?: null;
    }

    /**
     * Set the default provider by handle
     *
     * @param string $handle Provider handle
     * @return bool
     */
    public function setDefaultProviderByHandle(string $handle): bool
    {
        $provider = $this->getProviderByHandle($handle);
        if (!$provider) {
            return false;
        }

        // Cannot set default if controlled by config
        if ($this->isDefaultProviderFromConfig()) {
            $this->logWarning('Cannot set default provider - controlled by config file');
            return false;
        }

        $settings = SmsManager::$plugin->getSettings();
        $settings->defaultProviderHandle = $handle;

        return $settings->saveToDatabase();
    }

    /**
     * Save a provider
     *
     * @param ProviderRecord $provider Provider record
     * @param bool $runValidation Whether to run validation
     * @return bool
     */
    public function saveProvider(ProviderRecord $provider, bool $runValidation = true): bool
    {
        // Cannot save config-based providers
        if ($provider->isFromConfig()) {
            $this->logWarning('Cannot save config-based provider', ['handle' => $provider->handle]);
            return false;
        }

        $isNew = !$provider->id;
        $provider->handle = SlugHandleHelper::normalizeSlug($provider->handle, (string)$provider->name);

        if ($isNew && $provider->handle !== '') {
            $provider->handle = SlugHandleHelper::makeUnique(ProviderRecord::tableName(), 'handle', $provider->handle);
        }

        if ($runValidation && !$provider->validate()) {
            $this->logError('Provider validation failed', ['errors' => $provider->getErrors()]);
            return false;
        }

        // Validate common API URL setting (if present) before provider-specific checks.
        if ($runValidation) {
            $settings = $provider->getSettingsArray();
            $apiUrl = trim((string)App::parseEnv($settings['apiUrl'] ?? ''));
            if ($apiUrl !== '') {
                if (!filter_var($apiUrl, FILTER_VALIDATE_URL)) {
                    $provider->addError('providerSettings.apiUrl', Craft::t('sms-manager', 'API URL must be a valid URL.'));
                    $this->logError('Provider settings validation failed', ['errors' => ['apiUrl' => 'invalid_url']]);
                    return false;
                }

                if (!str_starts_with(strtolower($apiUrl), 'https://')) {
                    $provider->addError('providerSettings.apiUrl', Craft::t('sms-manager', 'API URL must use HTTPS.'));
                    $this->logError('Provider settings validation failed', ['errors' => ['apiUrl' => 'must_use_https']]);
                    return false;
                }
            }
        }

        // Validate provider-specific settings
        if ($runValidation) {
            $providerInstance = $this->createProviderByType($provider->type);
            if ($providerInstance) {
                $settingsErrors = $providerInstance->validateSettings($provider->getSettingsArray());
                if (!empty($settingsErrors)) {
                    foreach ($settingsErrors as $field => $error) {
                        // Store with field-specific key for inline display
                        $provider->addError('providerSettings.' . $field, $error);
                    }
                    $this->logError('Provider settings validation failed', ['errors' => $settingsErrors]);
                    return false;
                }
            }
        }

        // Normalize allowedCountries before persisting: Craft's empty multi-select
        // posts "" rather than [], which would break the array contract on read.
        $settingsArray = $provider->getSettingsArray();
        if (array_key_exists('allowedCountries', $settingsArray)) {
            $settingsArray['allowedCountries'] = $this->normalizeAllowedCountries($settingsArray['allowedCountries']);
            $provider->setSettingsArray($settingsArray);
        }

        // Set UID for new records
        if ($isNew && !$provider->uid) {
            $provider->uid = StringHelper::UUID();
        }

        $saved = $provider->save(false);

        if ($saved) {
            $this->logInfo('Provider saved', [
                'id' => $provider->id,
                'name' => $provider->name,
                'isNew' => $isNew,
            ]);
        } else {
            $this->logError('Failed to save provider', ['errors' => $provider->getErrors()]);
        }

        return $saved;
    }

    /**
     * Delete a provider
     *
     * @param int $id Provider ID
     * @return array Result with success status and optional error
     */
    public function deleteProvider(int $id): array
    {
        $provider = $this->getProviderById($id);
        if (!$provider) {
            return ['success' => false, 'error' => Craft::t('sms-manager', 'Provider not found')];
        }

        // Cannot delete config-based providers
        if ($provider->isFromConfig()) {
            return ['success' => false, 'error' => Craft::t('sms-manager', 'Cannot delete config-based provider. Remove it from config/sms-manager.php instead.')];
        }

        // Check if default
        $defaultHandle = $this->getDefaultProviderHandle();
        if ($provider->handle === $defaultHandle) {
            return ['success' => false, 'error' => Craft::t('sms-manager', 'Cannot delete the default provider. Set another provider as default first.')];
        }

        // Check if in use by integrations
        $usages = SmsManager::$plugin->integrations->getProviderUsages($id);
        if (count($usages) > 0) {
            $usageLabels = array_map(fn($u) => $u['pluginName'] . ': ' . $u['label'], $usages);
            return [
                'success' => false,
                'error' => Craft::t('sms-manager', 'Cannot delete provider. It is in use by: {usages}', [
                    'usages' => implode(', ', $usageLabels),
                ]),
                'usages' => $usages,
            ];
        }

        $deleted = $provider->delete();

        if ($deleted) {
            $this->logInfo('Provider deleted', [
                'id' => $id,
                'name' => $provider->name,
            ]);
            return ['success' => true];
        }

        return ['success' => false, 'error' => Craft::t('sms-manager', 'Could not delete provider.')];
    }

    /**
     * Get provider options for select fields
     *
     * @param bool $enabledOnly Only return enabled providers
     * @return array
     */
    public function getProviderOptions(bool $enabledOnly = true): array
    {
        $providers = $this->getAllProviders($enabledOnly);
        $options = [];

        foreach ($providers as $provider) {
            $options[] = [
                'label' => $provider->name,
                'value' => $provider->id,
            ];
        }

        return $options;
    }

    /**
     * Get provider settings array
     *
     * @param int|string $providerIdOrHandle Provider ID or handle
     * @return array Provider settings or empty array if not found
     * @since 5.7.0
     */
    public function getProviderSettings(int|string $providerIdOrHandle): array
    {
        $provider = is_int($providerIdOrHandle)
            ? $this->getProviderById($providerIdOrHandle)
            : $this->getProviderByHandle($providerIdOrHandle);

        if (!$provider) {
            return [];
        }

        return $provider->getSettingsArray();
    }

    /**
     * Get allowed countries for a provider
     *
     * @param int|string $providerIdOrHandle Provider ID or handle
     * @return array Array of country codes (e.g., ['KW', 'SA']) or ['*'] for all
     * @since 5.7.0
     */
    public function getAllowedCountries(int|string $providerIdOrHandle): array
    {
        $settings = $this->getProviderSettings($providerIdOrHandle);
        return $this->normalizeAllowedCountries($settings['allowedCountries'] ?? []);
    }

    /**
     * Coerce a stored allowedCountries value to a clean list of country codes.
     *
     * Craft's multi-select renders a hidden empty-string fallback, so a provider
     * saved with no countries selected persists `allowedCountries` as "" rather
     * than []. Normalizing here keeps the strict array return types — and the
     * `in_array()` calls in the country-filter helpers — from blowing up on
     * malformed settings.
     *
     * @param mixed $value Raw stored value
     * @return list<string>
     */
    private function normalizeAllowedCountries(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, fn($v) => $v !== '' && $v !== null));
        }

        return is_string($value) && $value !== '' ? [$value] : [];
    }

    /**
     * Check if a country is allowed for a provider
     *
     * @param int|string $providerIdOrHandle Provider ID or handle
     * @param string $countryCode Country code (e.g., 'KW', 'SA')
     * @return bool True if country is allowed, false otherwise
     * @since 5.7.0
     */
    public function isCountryAllowed(int|string $providerIdOrHandle, string $countryCode): bool
    {
        $allowedCountries = $this->getAllowedCountries($providerIdOrHandle);

        // Empty or wildcard means all countries allowed
        if (empty($allowedCountries) || in_array('*', $allowedCountries, true)) {
            return true;
        }

        return in_array(strtoupper($countryCode), $allowedCountries, true);
    }

    /**
     * Get providers that support a specific country
     *
     * @param string $countryCode Country code (e.g., 'KW', 'SA')
     * @param bool $enabledOnly Only return enabled providers
     * @return array Array of ProviderRecord objects
     * @since 5.7.0
     */
    public function getProvidersForCountry(string $countryCode, bool $enabledOnly = true): array
    {
        $providers = $this->getAllProviders($enabledOnly);
        $countryCode = strtoupper($countryCode);

        return array_filter($providers, function($provider) use ($countryCode) {
            $settings = $provider->getSettingsArray();
            $allowedCountries = $this->normalizeAllowedCountries($settings['allowedCountries'] ?? []);

            // Empty or wildcard means all countries allowed
            if (empty($allowedCountries) || in_array('*', $allowedCountries, true)) {
                return true;
            }

            return in_array($countryCode, $allowedCountries, true);
        });
    }
}
