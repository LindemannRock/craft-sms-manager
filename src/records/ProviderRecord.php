<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025 LindemannRock
 */

namespace lindemannrock\smsmanager\records;

use craft\db\ActiveRecord;

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
 * @property bool $isDefault
 * @property int $sortOrder
 * @property string|null $settings
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class ProviderRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%smsmanager_providers}}';
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
}
