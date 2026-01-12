<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025 LindemannRock
 */

namespace lindemannrock\smsmanager\utilities;

use Craft;
use craft\base\Utility;
use craft\db\Query;
use lindemannrock\smsmanager\SmsManager;

/**
 * SMS Manager Utility
 *
 * Provides system utilities for clearing analytics data
 */
class SmsManagerUtility extends Utility
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return SmsManager::$plugin->getSettings()->getFullName();
    }

    /**
     * @inheritdoc
     */
    public static function id(): string
    {
        return 'sms-manager';
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return 'paper-plane';
    }

    /**
     * @inheritdoc
     */
    public static function contentHtml(): string
    {
        $settings = SmsManager::$plugin->getSettings();

        // Get provider stats
        $providersCount = (new Query())
            ->from('{{%smsmanager_providers}}')
            ->count();

        $activeProviders = (new Query())
            ->from('{{%smsmanager_providers}}')
            ->where(['enabled' => true])
            ->count();

        // Get sender ID stats
        $senderIdsCount = (new Query())
            ->from('{{%smsmanager_senderids}}')
            ->count();

        $activeSenderIds = (new Query())
            ->from('{{%smsmanager_senderids}}')
            ->where(['enabled' => true])
            ->count();

        // Get analytics stats (last 7 days)
        $analyticsCount = 0;
        if ($settings->enableAnalytics) {
            $analyticsCount = (new Query())
                ->from('{{%smsmanager_analytics}}')
                ->count();
        }

        // Get message stats from analytics (using aggregated columns)
        $totalSent = 0;
        $totalFailed = 0;
        if ($settings->enableAnalytics) {
            $messageStats = (new Query())
                ->select([
                    'SUM([[totalSent]]) as sent',
                    'SUM([[totalFailed]]) as failed',
                ])
                ->from('{{%smsmanager_analytics}}')
                ->one();

            $totalSent = (int)($messageStats['sent'] ?? 0);
            $totalFailed = (int)($messageStats['failed'] ?? 0);
        }

        // Get logs count
        $logsCount = 0;
        if ($settings->enableLogs) {
            $logsCount = (new Query())
                ->from('{{%smsmanager_logs}}')
                ->count();
        }

        return Craft::$app->getView()->renderTemplate('sms-manager/utilities/index', [
            'settings' => $settings,
            'providersCount' => (int)$providersCount,
            'activeProviders' => (int)$activeProviders,
            'senderIdsCount' => (int)$senderIdsCount,
            'activeSenderIds' => (int)$activeSenderIds,
            'analyticsCount' => (int)$analyticsCount,
            'logsCount' => (int)$logsCount,
            'totalSent' => $totalSent,
            'totalFailed' => $totalFailed,
        ]);
    }
}
