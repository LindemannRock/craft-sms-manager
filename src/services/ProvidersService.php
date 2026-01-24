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
use craft\helpers\StringHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\smsmanager\providers\MppSmsProvider;
use lindemannrock\smsmanager\providers\ProviderInterface;
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
        $this->setLoggingHandle('sms-manager');
        $this->registerDefaultProviders();
    }

    /**
     * Register default provider types
     */
    private function registerDefaultProviders(): void
    {
        $this->registerProviderType(MppSmsProvider::class);
    }

    /**
     * Register a provider type
     *
     * @param class-string<ProviderInterface> $class Provider class
     * @since 5.0.0
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
     * @since 5.0.0
     */
    public function getProviderTypes(): array
    {
        return $this->providerTypes;
    }

    /**
     * Get provider type options for select fields
     *
     * @return array
     * @since 5.0.0
     */
    public function getProviderTypeOptions(): array
    {
        $options = [];
        foreach ($this->providerTypes as $handle => $class) {
            $options[] = [
                'label' => $class::displayName(),
                'value' => $handle,
            ];
        }
        return $options;
    }

    /**
     * Create a provider instance by type
     *
     * @param string $type Provider type handle
     * @return ProviderInterface|null
     * @since 5.0.0
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
     * @since 5.0.0
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
     * @since 5.0.0
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
     * @since 5.0.0
     */
    public function getProviderByHandle(string $handle): ?ProviderRecord
    {
        return ProviderRecord::findByHandleWithConfig($handle);
    }

    /**
     * Get the default provider
     *
     * Uses defaultProviderHandle from settings, falls back to first enabled provider.
     *
     * @return ProviderRecord|null
     * @since 5.0.0
     */
    public function getDefaultProvider(): ?ProviderRecord
    {
        $settings = SmsManager::$plugin->getSettings();

        // First, check handle-based default from settings
        if (!empty($settings->defaultProviderHandle)) {
            $provider = $this->getProviderByHandle($settings->defaultProviderHandle);
            if ($provider && $provider->enabled) {
                return $provider;
            }
        }

        // Fall back to first enabled provider
        $providers = $this->getAllProviders(true);
        return $providers[0] ?? null;
    }

    /**
     * Check if the default provider is set from config file
     *
     * @return bool
     * @since 5.0.0
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
     * @since 5.0.0
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
     * @since 5.0.0
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
     * @since 5.0.0
     */
    public function saveProvider(ProviderRecord $provider, bool $runValidation = true): bool
    {
        // Cannot save config-based providers
        if ($provider->isFromConfig()) {
            $this->logWarning('Cannot save config-based provider', ['handle' => $provider->handle]);
            return false;
        }

        $isNew = !$provider->id;

        if ($runValidation && !$provider->validate()) {
            $this->logError('Provider validation failed', ['errors' => $provider->getErrors()]);
            return false;
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
     * @since 5.0.0
     */
    public function deleteProvider(int $id): array
    {
        $provider = $this->getProviderById($id);
        if (!$provider) {
            return ['success' => false, 'error' => Craft::t('sms-manager', 'Provider not found.')];
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
     * @since 5.0.0
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
}
