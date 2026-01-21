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
use lindemannrock\smsmanager\records\SenderIdRecord;
use lindemannrock\smsmanager\SmsManager;

/**
 * Sender IDs Service
 *
 * Manages SMS sender IDs.
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
class SenderIdsService extends Component
{
    use LoggingTrait;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('sms-manager');
    }

    /**
     * Get all sender IDs (config + database merged)
     *
     * @param bool $enabledOnly Only return enabled sender IDs
     * @return SenderIdRecord[]
     */
    public function getAllSenderIds(bool $enabledOnly = false): array
    {
        $senderIds = SenderIdRecord::findAllWithConfig();

        if ($enabledOnly) {
            $senderIds = array_filter($senderIds, fn($s) => $s->enabled);
        }

        return $senderIds;
    }

    /**
     * Get sender IDs by provider (config + database merged)
     *
     * @param int|string $providerIdOrHandle Provider ID or handle
     * @param bool $enabledOnly Only return enabled sender IDs
     * @return SenderIdRecord[]
     */
    public function getSenderIdsByProvider(int|string $providerIdOrHandle, bool $enabledOnly = false): array
    {
        $senderIds = SenderIdRecord::findAllByProviderWithConfig($providerIdOrHandle);

        if ($enabledOnly) {
            $senderIds = array_filter($senderIds, fn($s) => $s->enabled);
        }

        return array_values($senderIds);
    }

    /**
     * Get sender ID by ID
     *
     * @param int $id Sender ID
     * @return SenderIdRecord|null
     */
    public function getSenderIdById(int $id): ?SenderIdRecord
    {
        return SenderIdRecord::findOne($id);
    }

    /**
     * Get sender ID by handle (checks config first, then database)
     *
     * @param string $handle Sender ID handle
     * @return SenderIdRecord|null
     */
    public function getSenderIdByHandle(string $handle): ?SenderIdRecord
    {
        return SenderIdRecord::findByHandleWithConfig($handle);
    }

    /**
     * Get the default sender ID
     *
     * Uses defaultSenderIdHandle from settings, falls back to isDefault flag,
     * then first enabled sender ID.
     *
     * @param int|string|null $providerIdOrHandle Optional provider ID or handle to filter by
     * @return SenderIdRecord|null
     */
    public function getDefaultSenderId(int|string|null $providerIdOrHandle = null): ?SenderIdRecord
    {
        $settings = SmsManager::$plugin->getSettings();

        // First, check handle-based default from settings
        if (!empty($settings->defaultSenderIdHandle)) {
            $senderId = $this->getSenderIdByHandle($settings->defaultSenderIdHandle);
            if ($senderId && $senderId->enabled) {
                // If filtering by provider, make sure it matches
                if ($providerIdOrHandle !== null) {
                    $matchesProvider = $this->senderIdMatchesProvider($senderId, $providerIdOrHandle);
                    if ($matchesProvider) {
                        return $senderId;
                    }
                } else {
                    return $senderId;
                }
            }
        }

        // Fall back to isDefault flag in database
        $conditions = ['isDefault' => true, 'enabled' => true];
        if ($providerIdOrHandle !== null && is_int($providerIdOrHandle)) {
            $conditions['providerId'] = $providerIdOrHandle;
        }
        $senderId = SenderIdRecord::findOne($conditions);
        if ($senderId) {
            return $senderId;
        }

        // Fall back to first enabled sender ID
        if ($providerIdOrHandle !== null) {
            $senderIds = $this->getSenderIdsByProvider($providerIdOrHandle, true);
        } else {
            $senderIds = $this->getAllSenderIds(true);
        }
        return $senderIds[0] ?? null;
    }

    /**
     * Check if a sender ID matches a provider
     */
    private function senderIdMatchesProvider(SenderIdRecord $senderId, int|string $providerIdOrHandle): bool
    {
        if (is_string($providerIdOrHandle)) {
            // Compare by handle
            if ($senderId->isFromConfig() && $senderId->providerHandle !== null) {
                return $senderId->providerHandle === $providerIdOrHandle;
            }
            $provider = SmsManager::$plugin->providers->getProviderByHandle($providerIdOrHandle);
            return $provider && $senderId->providerId === $provider->id;
        }
        // Compare by ID
        return $senderId->providerId === $providerIdOrHandle;
    }

    /**
     * Check if the default sender ID is set from config file
     *
     * @return bool
     */
    public function isDefaultSenderIdFromConfig(): bool
    {
        $settings = SmsManager::$plugin->getSettings();
        return $settings->isOverriddenByConfig('defaultSenderIdHandle');
    }

    /**
     * Get the default sender ID handle
     *
     * @return string|null
     */
    public function getDefaultSenderIdHandle(): ?string
    {
        $settings = SmsManager::$plugin->getSettings();

        // Check handle-based default
        if (!empty($settings->defaultSenderIdHandle)) {
            return $settings->defaultSenderIdHandle;
        }

        // Fall back to isDefault flag
        $senderId = SenderIdRecord::findOne(['isDefault' => true, 'enabled' => true]);
        return $senderId?->handle;
    }

    /**
     * Set the default sender ID by handle
     *
     * @param string $handle Sender ID handle
     * @return bool
     */
    public function setDefaultSenderIdByHandle(string $handle): bool
    {
        $senderId = $this->getSenderIdByHandle($handle);
        if (!$senderId) {
            return false;
        }

        // Cannot set default if controlled by config
        if ($this->isDefaultSenderIdFromConfig()) {
            $this->logWarning('Cannot set default sender ID - controlled by config file');
            return false;
        }

        $settings = SmsManager::$plugin->getSettings();
        $settings->defaultSenderIdHandle = $handle;

        // Also update isDefault flag in database for backward compatibility
        SenderIdRecord::updateAll(['isDefault' => false]);
        if ($senderId->id) {
            SenderIdRecord::updateAll(['isDefault' => true], ['id' => $senderId->id]);
        }

        return $settings->saveToDatabase();
    }

    /**
     * Save a sender ID
     *
     * @param SenderIdRecord $senderId Sender ID record
     * @param bool $runValidation Whether to run validation
     * @return bool
     */
    public function saveSenderId(SenderIdRecord $senderId, bool $runValidation = true): bool
    {
        // Cannot save config-based sender IDs
        if ($senderId->isFromConfig()) {
            $this->logWarning('Cannot save config-based sender ID', ['handle' => $senderId->handle]);
            return false;
        }

        $isNew = !$senderId->id;

        if ($runValidation && !$senderId->validate()) {
            $this->logError('Sender ID validation failed', ['errors' => $senderId->getErrors()]);
            return false;
        }

        // Set UID for new records
        if ($isNew && !$senderId->uid) {
            $senderId->uid = StringHelper::UUID();
        }

        // If setting as default, update the settings
        if ($senderId->isDefault) {
            // Clear other defaults in database
            SenderIdRecord::updateAll(
                ['isDefault' => false],
                ['!=', 'id', $senderId->id ?: 0]
            );

            // Update settings if not controlled by config
            if (!$this->isDefaultSenderIdFromConfig()) {
                $settings = SmsManager::$plugin->getSettings();
                $settings->defaultSenderIdHandle = $senderId->handle;
                $settings->saveToDatabase();
            }
        }

        $saved = $senderId->save(false);

        if ($saved) {
            $this->logInfo('Sender ID saved', [
                'id' => $senderId->id,
                'name' => $senderId->name,
                'isNew' => $isNew,
            ]);
        } else {
            $this->logError('Failed to save sender ID', ['errors' => $senderId->getErrors()]);
        }

        return $saved;
    }

    /**
     * Delete a sender ID
     *
     * @param int $id Sender ID
     * @return array Result with success status and optional error
     */
    public function deleteSenderId(int $id): array
    {
        $senderId = $this->getSenderIdById($id);
        if (!$senderId) {
            return ['success' => false, 'error' => Craft::t('sms-manager', 'Sender ID not found.')];
        }

        // Cannot delete config-based sender IDs
        if ($senderId->isFromConfig()) {
            return ['success' => false, 'error' => Craft::t('sms-manager', 'Cannot delete config-based sender ID. Remove it from config/sms-manager.php instead.')];
        }

        // Check if default
        $defaultHandle = $this->getDefaultSenderIdHandle();
        if ($senderId->handle === $defaultHandle) {
            return ['success' => false, 'error' => Craft::t('sms-manager', 'Cannot delete the default sender ID. Set another sender ID as default first.')];
        }

        // Check if in use by integrations
        $usages = SmsManager::$plugin->integrations->getSenderIdUsages($id);
        if (count($usages) > 0) {
            $usageLabels = array_map(fn($u) => $u['pluginName'] . ': ' . $u['label'], $usages);
            return [
                'success' => false,
                'error' => Craft::t('sms-manager', 'Cannot delete sender ID. It is in use by: {usages}', [
                    'usages' => implode(', ', $usageLabels),
                ]),
                'usages' => $usages,
            ];
        }

        $deleted = $senderId->delete();

        if ($deleted) {
            $this->logInfo('Sender ID deleted', [
                'id' => $id,
                'name' => $senderId->name,
            ]);
            return ['success' => true];
        }

        return ['success' => false, 'error' => Craft::t('sms-manager', 'Could not delete sender ID.')];
    }

    /**
     * Get sender ID options for select fields
     *
     * @param int|string|null $providerIdOrHandle Optional provider ID or handle to filter by
     * @param bool $enabledOnly Only return enabled sender IDs
     * @return array
     */
    public function getSenderIdOptions(int|string|null $providerIdOrHandle = null, bool $enabledOnly = true): array
    {
        if ($providerIdOrHandle !== null) {
            $senderIds = $this->getSenderIdsByProvider($providerIdOrHandle, $enabledOnly);
        } else {
            $senderIds = $this->getAllSenderIds($enabledOnly);
        }

        $options = [];

        foreach ($senderIds as $senderId) {
            $options[] = [
                'label' => $senderId->name,
                'value' => $senderId->id,
            ];
        }

        return $options;
    }

    /**
     * Get sender ID options as key=>value array (for dropdowns)
     *
     * Returns array with handle as key and name as value.
     *
     * @param int|string|null $providerIdOrHandle Optional provider ID or handle to filter by
     * @param bool $enabledOnly Only return enabled sender IDs
     * @return array
     */
    public function getSenderIdOptionsArray(int|string|null $providerIdOrHandle = null, bool $enabledOnly = true): array
    {
        if ($providerIdOrHandle !== null) {
            $senderIds = $this->getSenderIdsByProvider($providerIdOrHandle, $enabledOnly);
        } else {
            $senderIds = $this->getAllSenderIds($enabledOnly);
        }

        $options = [];

        foreach ($senderIds as $senderId) {
            $options[$senderId->handle] = $senderId->name;
        }

        return $options;
    }
}
