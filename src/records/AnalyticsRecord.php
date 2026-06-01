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
 * Analytics Record
 *
 * Stores aggregated SMS analytics per day.
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 *
 * @property int $id
 * @property int|null $providerId
 * @property int|null $senderIdId
 * @property int|null $siteId
 * @property string|null $language
 * @property \DateTime|string $date
 * @property int $totalSent
 * @property int $totalDelivered
 * @property int $totalFailed
 * @property int $totalPending
 * @property int $totalCharacters
 * @property int $totalMessages
 * @property int $englishCount
 * @property int $arabicCount
 * @property int $otherCount
 * @property string|null $sourcePlugin
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class AnalyticsRecord extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%smsmanager_analytics}}';
    }

    /**
     * Get the provider for this analytics record
     *
     * @return ActiveQueryInterface
     */
    public function getProvider(): ActiveQueryInterface
    {
        return $this->hasOne(ProviderRecord::class, ['id' => 'providerId']);
    }

    /**
     * Get the sender ID for this analytics record
     *
     * @return ActiveQueryInterface
     */
    public function getSenderId(): ActiveQueryInterface
    {
        return $this->hasOne(SenderIdRecord::class, ['id' => 'senderIdId']);
    }

    /**
     * Get the site for this analytics record
     *
     * @return ActiveQueryInterface
     */
    public function getSite(): ActiveQueryInterface
    {
        return $this->hasOne(Site::class, ['id' => 'siteId']);
    }

    /**
     * Get total count (all statuses)
     *
     * @return int
     */
    public function getTotalCount(): int
    {
        return $this->totalSent + $this->totalDelivered + $this->totalFailed + $this->totalPending;
    }

    /**
     * Get success rate as percentage
     *
     * @return float
     */
    public function getSuccessRate(): float
    {
        $total = $this->totalSent + $this->totalDelivered + $this->totalFailed;
        if ($total === 0) {
            return 0.0;
        }

        return round(($this->totalDelivered / $total) * 100, 2);
    }
}
