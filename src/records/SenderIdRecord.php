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
 * Sender ID Record
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 *
 * @property int $id
 * @property int $providerId
 * @property string $name
 * @property string $handle
 * @property string $senderId
 * @property string|null $description
 * @property bool $enabled
 * @property bool $isDefault
 * @property bool $isTest
 * @property int $sortOrder
 * @property string $source
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class SenderIdRecord extends ActiveRecord
{
    use ConfigSourceTrait;

    /**
     * @var string|null Raw config display for tooltips
     */
    public ?string $rawConfigDisplay = null;

    /**
     * @var string|null Provider handle (for config-based sender IDs)
     */
    public ?string $providerHandle = null;

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%smsmanager_senderids}}';
    }

    /**
     * Get the provider for this sender ID
     *
     * @return \yii\db\ActiveQuery
     */
    public function getProvider(): \yii\db\ActiveQuery
    {
        return $this->hasOne(ProviderRecord::class, ['id' => 'providerId']);
    }

    // =========================================================================
    // CONFIG FILE OPERATIONS
    // =========================================================================

    /**
     * Find sender ID by handle (checks config first, then database)
     */
    public static function findByHandleWithConfig(string $handle): ?self
    {
        // First, check config file
        $senderIdConfig = ConfigFileHelper::getConfigByHandle('senderIds', $handle);

        if ($senderIdConfig !== null) {
            return self::createFromConfig($handle, $senderIdConfig);
        }

        // Then, check database
        return self::findOne(['handle' => $handle]);
    }

    /**
     * Get all sender IDs (config + database merged)
     *
     * @return self[]
     */
    public static function findAllWithConfig(): array
    {
        $senderIds = [];
        $handlesFromConfig = ConfigFileHelper::getHandles('senderIds');

        // First, load sender IDs from config file
        $configSenderIds = self::findAllFromConfig();
        foreach ($configSenderIds as $senderId) {
            $senderIds[$senderId->handle] = $senderId;
        }

        // Then, load sender IDs from database (excluding those defined in config)
        /** @var self[] $rows */
        $rows = self::find()
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
            ->all();

        foreach ($rows as $row) {
            // Skip if this handle is already defined in config
            if (in_array($row->handle, $handlesFromConfig, true)) {
                continue;
            }
            $row->source = 'database';
            $senderIds[$row->handle] = $row;
        }

        /** @var self[] $result */
        $result = array_values($senderIds);

        return $result;
    }

    /**
     * Get all sender IDs for a provider (config + database merged)
     *
     * @param int|string $providerIdOrHandle Provider ID or handle
     * @return self[]
     */
    public static function findAllByProviderWithConfig(int|string $providerIdOrHandle): array
    {
        $allSenderIds = self::findAllWithConfig();

        // If it's a string, it's a handle - resolve to ID if needed
        $providerHandle = null;
        $providerId = null;

        if (is_string($providerIdOrHandle)) {
            $providerHandle = $providerIdOrHandle;
            $provider = ProviderRecord::findByHandleWithConfig($providerIdOrHandle);
            $providerId = $provider?->id;
        } else {
            $providerId = $providerIdOrHandle;
            $provider = ProviderRecord::findOne($providerId);
            $providerHandle = $provider?->handle;
        }

        return array_filter($allSenderIds, function($senderId) use ($providerId, $providerHandle) {
            // Config-based sender IDs use providerHandle
            if ($senderId->isFromConfig() && $senderId->providerHandle !== null) {
                return $senderId->providerHandle === $providerHandle;
            }
            // Database sender IDs use providerId
            return $senderId->providerId === $providerId;
        });
    }

    /**
     * Get all sender IDs defined in config file
     *
     * @return self[]
     */
    public static function findAllFromConfig(): array
    {
        $senderIds = [];
        $senderIdConfigs = ConfigFileHelper::getSenderIds();

        foreach ($senderIdConfigs as $handle => $senderIdConfig) {
            $senderIds[] = self::createFromConfig($handle, $senderIdConfig);
        }

        return $senderIds;
    }

    /**
     * Create a model from config file data
     */
    private static function createFromConfig(string $handle, array $config): self
    {
        $model = new self();
        $model->handle = $handle;
        $model->name = $config['name'] ?? ucfirst($handle);
        $model->senderId = $config['senderId'] ?? '';
        $model->description = $config['description'] ?? null;
        $model->enabled = $config['enabled'] ?? true;
        $model->isDefault = false; // Default is managed via settings
        $model->isTest = $config['isTest'] ?? false;
        $model->sortOrder = $config['sortOrder'] ?? 0;
        $model->source = 'config';

        // Store provider handle for config-based sender IDs
        $model->providerHandle = $config['provider'] ?? null;

        // Resolve provider ID if possible
        if ($model->providerHandle) {
            $provider = ProviderRecord::findByHandleWithConfig($model->providerHandle);
            if ($provider && $provider->id) {
                $model->providerId = $provider->id;
            }
        }

        // Build raw config display for tooltip
        $model->rawConfigDisplay = $model->formatConfigDisplay($config, $handle, []);

        return $model;
    }

    /**
     * Get all enabled sender IDs (config + database merged)
     *
     * @return self[]
     */
    public static function findAllEnabledWithConfig(): array
    {
        return array_filter(self::findAllWithConfig(), fn($s) => $s->enabled);
    }
}
