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
            ];
        }
        return $options;
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
     * Get all providers
     *
     * @param bool $enabledOnly Only return enabled providers
     * @return array
     */
    public function getAllProviders(bool $enabledOnly = false): array
    {
        $query = ProviderRecord::find()
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC]);

        if ($enabledOnly) {
            $query->where(['enabled' => true]);
        }

        return $query->all();
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
     * Get provider by handle
     *
     * @param string $handle Provider handle
     * @return ProviderRecord|null
     */
    public function getProviderByHandle(string $handle): ?ProviderRecord
    {
        return ProviderRecord::findOne(['handle' => $handle]);
    }

    /**
     * Get the default provider
     *
     * @return ProviderRecord|null
     */
    public function getDefaultProvider(): ?ProviderRecord
    {
        return ProviderRecord::findOne(['isDefault' => true, 'enabled' => true]);
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
        $isNew = !$provider->id;

        if ($runValidation && !$provider->validate()) {
            $this->logError('Provider validation failed', ['errors' => $provider->getErrors()]);
            return false;
        }

        // Set UID for new records
        if ($isNew && !$provider->uid) {
            $provider->uid = StringHelper::UUID();
        }

        // If this is the default, unset other defaults
        if ($provider->isDefault) {
            ProviderRecord::updateAll(
                ['isDefault' => false],
                ['!=', 'id', $provider->id ?: 0]
            );
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
            return ['success' => false, 'error' => Craft::t('sms-manager', 'Provider not found.')];
        }

        // Check if default
        if ($provider->isDefault) {
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
}
