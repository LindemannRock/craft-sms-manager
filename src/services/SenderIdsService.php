<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025 LindemannRock
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
     * Get all sender IDs
     *
     * @param bool $enabledOnly Only return enabled sender IDs
     * @return array
     */
    public function getAllSenderIds(bool $enabledOnly = false): array
    {
        $query = SenderIdRecord::find()
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC]);

        if ($enabledOnly) {
            $query->where(['enabled' => true]);
        }

        return $query->all();
    }

    /**
     * Get sender IDs by provider
     *
     * @param int $providerId Provider ID
     * @param bool $enabledOnly Only return enabled sender IDs
     * @return array
     */
    public function getSenderIdsByProvider(int $providerId, bool $enabledOnly = false): array
    {
        $query = SenderIdRecord::find()
            ->where(['providerId' => $providerId])
            ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC]);

        if ($enabledOnly) {
            $query->andWhere(['enabled' => true]);
        }

        return $query->all();
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
     * Get sender ID by handle
     *
     * @param string $handle Sender ID handle
     * @return SenderIdRecord|null
     */
    public function getSenderIdByHandle(string $handle): ?SenderIdRecord
    {
        return SenderIdRecord::findOne(['handle' => $handle]);
    }

    /**
     * Get the default sender ID
     *
     * @param int|null $providerId Optional provider ID to filter by
     * @return SenderIdRecord|null
     */
    public function getDefaultSenderId(?int $providerId = null): ?SenderIdRecord
    {
        $conditions = ['isDefault' => true, 'enabled' => true];

        if ($providerId !== null) {
            $conditions['providerId'] = $providerId;
        }

        return SenderIdRecord::findOne($conditions);
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
        $isNew = !$senderId->id;

        if ($runValidation && !$senderId->validate()) {
            $this->logError('Sender ID validation failed', ['errors' => $senderId->getErrors()]);
            return false;
        }

        // Set UID for new records
        if ($isNew && !$senderId->uid) {
            $senderId->uid = StringHelper::UUID();
        }

        // If this is the default for this provider, unset other defaults
        if ($senderId->isDefault) {
            SenderIdRecord::updateAll(
                ['isDefault' => false],
                [
                    'and',
                    ['providerId' => $senderId->providerId],
                    ['!=', 'id', $senderId->id ?: 0],
                ]
            );
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

        // Check if default
        if ($senderId->isDefault) {
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
     * @param int|null $providerId Optional provider ID to filter by
     * @param bool $enabledOnly Only return enabled sender IDs
     * @return array
     */
    public function getSenderIdOptions(?int $providerId = null, bool $enabledOnly = true): array
    {
        if ($providerId !== null) {
            $senderIds = $this->getSenderIdsByProvider($providerId, $enabledOnly);
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
     * @param int|null $providerId Optional provider ID to filter by
     * @param bool $enabledOnly Only return enabled sender IDs
     * @return array
     */
    public function getSenderIdOptionsArray(?int $providerId = null, bool $enabledOnly = true): array
    {
        if ($providerId !== null) {
            $senderIds = $this->getSenderIdsByProvider($providerId, $enabledOnly);
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
