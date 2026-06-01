<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\records;

use craft\db\ActiveRecord;
use craft\records\Site;
use yii\db\ActiveQueryInterface;

/**
 * SMS Log Record
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
 * @property string|null $providerHandle
 * @property string|null $senderIdHandle
 * @property int|null $siteId
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
class SmsLogRecord extends ActiveRecord
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
     * @return ActiveQueryInterface
     */
    public function getProvider(): ActiveQueryInterface
    {
        return $this->hasOne(ProviderRecord::class, ['id' => 'providerId']);
    }

    /**
     * Get the sender ID for this log
     *
     * @return ActiveQueryInterface
     */
    public function getSenderId(): ActiveQueryInterface
    {
        return $this->hasOne(SenderIdRecord::class, ['id' => 'senderIdId']);
    }

    /**
     * Get the site for this log
     *
     * @return ActiveQueryInterface
     */
    public function getSite(): ActiveQueryInterface
    {
        return $this->hasOne(Site::class, ['id' => 'siteId']);
    }
}
