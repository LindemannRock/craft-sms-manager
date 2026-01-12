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
 * Log Record
 *
 * Stores individual SMS delivery records.
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 *
 * @property int $id
 * @property int|null $providerId
 * @property int|null $senderIdId
 * @property string $recipient
 * @property string|null $message
 * @property string|null $language
 * @property int|null $messageLength
 * @property string $status
 * @property string|null $providerMessageId
 * @property string|null $providerResponse
 * @property string|null $errorMessage
 * @property string|null $sourcePlugin
 * @property int|null $sourceElementId
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class LogRecord extends ActiveRecord
{
    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%smsmanager_logs}}';
    }

    /**
     * Get the provider for this log
     *
     * @return \yii\db\ActiveQuery
     */
    public function getProvider(): \yii\db\ActiveQuery
    {
        return $this->hasOne(ProviderRecord::class, ['id' => 'providerId']);
    }

    /**
     * Get the sender ID for this log
     *
     * @return \yii\db\ActiveQuery
     */
    public function getSenderId(): \yii\db\ActiveQuery
    {
        return $this->hasOne(SenderIdRecord::class, ['id' => 'senderIdId']);
    }
}
