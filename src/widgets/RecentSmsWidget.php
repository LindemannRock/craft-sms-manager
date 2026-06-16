<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\widgets;

use Craft;
use craft\base\Widget;
use craft\db\Query;
use lindemannrock\smsmanager\records\SenderIdRecord;
use lindemannrock\smsmanager\records\SmsLogRecord;
use lindemannrock\smsmanager\SmsManager;

/**
 * SMS Manager recent SMS dashboard widget.
 *
 * @since 5.14.0
 */
class RecentSmsWidget extends Widget
{
    use SiteLanguageFilterTrait;

    /**
     * @var int Number of recent messages to show
     */
    public int $limit = 5;

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['limit'], 'integer', 'min' => 3, 'max' => 20];
        $rules[] = [['siteId'], 'in', 'range' => array_column($this->siteOptions(), 'value')];
        $rules[] = [['language'], 'in', 'range' => array_column($this->languageOptions(), 'value')];
        $rules[] = [['limit'], 'default', 'value' => 5];
        $rules[] = [['siteId', 'language'], 'default', 'value' => 'all'];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        $pluginName = SmsManager::$plugin->getSettings()->getFullName();

        return $pluginName . ' - ' . Craft::t('sms-manager', 'Recent SMS');
    }

    /**
     * @inheritdoc
     */
    public static function isSelectable(): bool
    {
        $settings = SmsManager::$plugin->getSettings();

        return parent::isSelectable()
            && $settings->enableSmsLogs
            && Craft::$app->getUser()->checkPermission('smsManager:viewSmsLogs');
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return '@lindemannrock/smsmanager/icon-mask.svg';
    }

    /**
     * @inheritdoc
     */
    public static function maxColspan(): ?int
    {
        return 2;
    }

    /**
     * @inheritdoc
     */
    public function getTitle(): ?string
    {
        return self::displayName();
    }

    /**
     * @inheritdoc
     */
    public function getSubtitle(): ?string
    {
        return Craft::t('sms-manager', 'Latest {count}', ['count' => $this->limit]);
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('sms-manager/widgets/recent-sms/settings', [
            'widget' => $this,
            'siteOptions' => $this->siteOptions(),
            'languageOptions' => $this->languageOptions(),
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getBodyHtml(): ?string
    {
        $settings = SmsManager::$plugin->getSettings();

        if (!$settings->enableSmsLogs || !Craft::$app->getUser()->checkPermission('smsManager:viewSmsLogs')) {
            return Craft::$app->getView()->renderTemplate('lindemannrock-base/_components/dashboard-widget-empty', [
                'title' => Craft::t('sms-manager', 'No SMS logs yet'),
            ]);
        }

        $logsQuery = (new Query())
            ->from(SmsLogRecord::tableName());

        $this->applySiteFilter($logsQuery);
        $this->applyLanguageFilter($logsQuery);

        $logs = $logsQuery
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($this->limit)
            ->all();

        $senderIdIds = array_values(array_unique(array_filter(array_column($logs, 'senderIdId'))));
        $senderIdHandles = array_values(array_unique(array_filter(array_column($logs, 'senderIdHandle'))));

        /** @var array<int, SenderIdRecord> $senderIdsById */
        $senderIdsById = $senderIdIds
            ? SenderIdRecord::find()->where(['id' => $senderIdIds])->indexBy('id')->all()
            : [];

        $senderIdsByHandle = [];
        foreach ($senderIdHandles as $handle) {
            $record = SenderIdRecord::findByHandleWithConfig($handle);
            if ($record) {
                $senderIdsByHandle[$handle] = $record;
            }
        }

        foreach ($logs as &$log) {
            $senderId = $senderIdsById[$log['senderIdId']] ?? null;
            if (!$senderId && !empty($log['senderIdHandle'])) {
                $senderId = $senderIdsByHandle[$log['senderIdHandle']] ?? null;
            }
            $log['senderIdName'] = $senderId ? $senderId->name : null;
        }
        unset($log);

        return Craft::$app->getView()->renderTemplate('sms-manager/widgets/recent-sms/body', [
            'logs' => $logs,
        ]);
    }
}
