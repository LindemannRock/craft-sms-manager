<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\records;

use craft\db\ActiveRecord;
use lindemannrock\smsmanager\helpers\ConfigFileHelper;
use lindemannrock\smsmanager\traits\ConfigSourceTrait;

/**
 * Provider Record
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 *
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string $type
 * @property bool $enabled
 * @property string|null $settings
 * @property string $source
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class ProviderRecord extends ActiveRecord
{
    use ConfigSourceTrait;

    /**
     * @var string|null Raw config display for tooltips
     */
    public ?string $rawConfigDisplay = null;
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%smsmanager_providers}}';
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['name', 'handle', 'type'], 'required'],
            [['name', 'handle', 'type'], 'string', 'max' => 255],
            [['settings'], 'string'],
            [['enabled'], 'boolean'],
            [['handle'], 'unique', 'targetClass' => self::class, 'message' => 'This handle is already in use.'],
        ];
    }

    /**
     * Get provider settings as array
     *
     * @return array
     */
    public function getSettingsArray(): array
    {
        if (empty($this->settings)) {
            return [];
        }

        return json_decode($this->settings, true) ?? [];
    }

    /**
     * Set provider settings from array
     *
     * @param array $settings
     */
    public function setSettingsArray(array $settings): void
    {
        $this->settings = json_encode($settings);
    }

    /**
     * Get sender IDs for this provider
     *
     * @return \yii\db\ActiveQuery
     */
    public function getSenderIds(): \yii\db\ActiveQuery
    {
        return $this->hasMany(SenderIdRecord::class, ['providerId' => 'id']);
    }

    // =========================================================================
    // CONFIG FILE OPERATIONS
    // =========================================================================

    /**
     * Find provider by handle (checks config first, then database)
     */
    public static function findByHandleWithConfig(string $handle): ?self
    {
        // First, check config file
        $providerConfig = ConfigFileHelper::getConfigByHandle('providers', $handle);

        if ($providerConfig !== null) {
            return self::createFromConfig($handle, $providerConfig);
        }

        // Then, check database
        return self::findOne(['handle' => $handle]);
    }

    /**
     * Get all providers (config + database merged)
     *
     * @return self[]
     */
    public static function findAllWithConfig(): array
    {
        $providers = [];
        $handlesFromConfig = ConfigFileHelper::getHandles('providers');

        // First, load providers from config file
        $configProviders = self::findAllFromConfig();
        foreach ($configProviders as $provider) {
            $providers[$provider->handle] = $provider;
        }

        // Then, load providers from database (excluding those defined in config)
        /** @var self[] $rows */
        $rows = self::find()
            ->orderBy(['name' => SORT_ASC])
            ->all();

        foreach ($rows as $row) {
            // Skip if this handle is already defined in config
            if (in_array($row->handle, $handlesFromConfig, true)) {
                continue;
            }
            $row->source = 'database';
            $providers[$row->handle] = $row;
        }

        /** @var self[] $result */
        $result = array_values($providers);

        return $result;
    }

    /**
     * Get all providers defined in config file
     *
     * @return self[]
     */
    public static function findAllFromConfig(): array
    {
        $providers = [];
        $providerConfigs = ConfigFileHelper::getProviders();

        foreach ($providerConfigs as $handle => $providerConfig) {
            $providers[] = self::createFromConfig($handle, $providerConfig);
        }

        return $providers;
    }

    /**
     * Create a model from config file data
     */
    private static function createFromConfig(string $handle, array $config): self
    {
        $model = new self();
        $model->handle = $handle;
        $model->name = $config['name'] ?? ucfirst($handle);
        $model->type = $config['type'] ?? 'mpp-sms';
        $model->settings = json_encode($config['settings'] ?? []);
        $model->enabled = $config['enabled'] ?? true;
        // Note: Default is managed via settings (defaultProviderHandle)
        $model->source = 'config';

        // Build raw config display for tooltip
        $model->rawConfigDisplay = $model->formatConfigDisplay($config, $handle, ['apiKey', 'password', 'devApiKey']);

        return $model;
    }

    /**
     * Get all enabled providers (config + database merged)
     *
     * @return self[]
     */
    public static function findAllEnabledWithConfig(): array
    {
        return array_filter(self::findAllWithConfig(), fn($p) => $p->enabled);
    }
}
