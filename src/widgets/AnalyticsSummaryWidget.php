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
use craft\helpers\Db;
use lindemannrock\base\helpers\DateRangeHelper;
use lindemannrock\smsmanager\records\SmsLogRecord;
use lindemannrock\smsmanager\SmsManager;

/**
 * SMS Manager analytics summary dashboard widget.
 *
 * @since 5.14.0
 */
class AnalyticsSummaryWidget extends Widget
{
    use SiteLanguageFilterTrait;

    /**
     * @var string Date range for the widget metrics
     */
    public string $dateRange = 'last7days';

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['dateRange'], 'in', 'range' => array_keys(DateRangeHelper::getOptions('assoc'))];
        $rules[] = [['siteId'], 'in', 'range' => array_column($this->siteOptions(), 'value')];
        $rules[] = [['language'], 'in', 'range' => array_column($this->languageOptions(), 'value')];
        $rules[] = [['dateRange'], 'default', 'value' => 'last7days'];
        $rules[] = [['siteId', 'language'], 'default', 'value' => 'all'];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        $pluginName = SmsManager::$plugin->getSettings()->getFullName();

        return $pluginName . ' - ' . Craft::t('sms-manager', 'Analytics');
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
        $labels = DateRangeHelper::getOptions('assoc');

        return $labels[$this->dateRange] ?? $labels['last7days'];
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('sms-manager/widgets/analytics-summary/settings', [
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

        $rangeQuery = (new Query())
            ->from(SmsLogRecord::tableName());

        $this->applySiteFilter($rangeQuery);
        $this->applyLanguageFilter($rangeQuery);

        $rangeBounds = DateRangeHelper::getBounds($this->dateRange);
        if ($rangeBounds['start']) {
            $rangeQuery->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($rangeBounds['start'])]);
        }
        if ($rangeBounds['end']) {
            $rangeQuery->andWhere(['<', 'dateCreated', Db::prepareDateForDb($rangeBounds['end'])]);
        }

        $totalMessages = (clone $rangeQuery)->count();

        $sentMessages = (clone $rangeQuery)
            ->andWhere(['status' => 'sent'])
            ->count();

        $failedMessages = (clone $rangeQuery)
            ->andWhere(['status' => 'failed'])
            ->count();

        $successRate = $totalMessages > 0 ? round(($sentMessages / $totalMessages) * 100, 1) : 100;

        return Craft::$app->getView()->renderTemplate('sms-manager/widgets/analytics-summary/body', [
            'chartId' => 'sms-manager-analytics-widget-chart-' . ($this->id ?: spl_object_id($this)),
            'totalMessages' => $totalMessages,
            'sentMessages' => $sentMessages,
            'failedMessages' => $failedMessages,
            'successRate' => $successRate,
        ]);
    }
}
