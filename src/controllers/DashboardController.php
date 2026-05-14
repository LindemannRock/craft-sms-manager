<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\controllers;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use lindemannrock\base\helpers\CpNavHelper;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\records\SenderIdRecord;
use lindemannrock\smsmanager\records\SmsLogRecord;
use lindemannrock\smsmanager\SmsManager;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Dashboard Controller
 *
 * Handles the main dashboard/landing page and utility pages.
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
class DashboardController extends Controller
{
    /**
     * Dashboard index page
     *
     * Shows overview stats, recent logs, and quick actions.
     * If user doesn't have permission for dashboard, redirect to first accessible section.
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $user = Craft::$app->getUser();
        $settings = SmsManager::$plugin->getSettings();

        // If user doesn't have viewSmsLogs permission or logs disabled, redirect to first accessible section
        if (!$user->checkPermission('smsManager:viewSmsLogs') || !$settings->enableSmsLogs) {
            $sections = SmsManager::$plugin->getCpSections($settings, false);
            $route = CpNavHelper::firstAccessibleRoute($user, $settings, $sections);
            if ($route) {
                return $this->redirect($route);
            }

            // No accessible sections - throw forbidden
            throw new ForbiddenHttpException('You do not have permission to access this area.');
        }

        // Get today's date boundaries
        $today = DateTimeHelper::toDateTime('today');
        $todayStart = $today->format('Y-m-d 00:00:00');
        $todayEnd = $today->format('Y-m-d 23:59:59');

        // Get yesterday's date boundaries
        $yesterday = DateTimeHelper::toDateTime('yesterday');
        $yesterdayStart = $yesterday->format('Y-m-d 00:00:00');
        $yesterdayEnd = $yesterday->format('Y-m-d 23:59:59');

        // SMS Today count
        $smsToday = (new Query())
            ->from(SmsLogRecord::tableName())
            ->where(['>=', 'dateCreated', $todayStart])
            ->andWhere(['<=', 'dateCreated', $todayEnd])
            ->count();

        // SMS Yesterday count (for comparison)
        $smsYesterday = (new Query())
            ->from(SmsLogRecord::tableName())
            ->where(['>=', 'dateCreated', $yesterdayStart])
            ->andWhere(['<=', 'dateCreated', $yesterdayEnd])
            ->count();

        // Success rate (last 7 days)
        $last7Days = DateTimeHelper::toDateTime('-7 days')->format('Y-m-d 00:00:00');
        $totalLast7Days = (new Query())
            ->from(SmsLogRecord::tableName())
            ->where(['>=', 'dateCreated', $last7Days])
            ->count();

        $sentLast7Days = (new Query())
            ->from(SmsLogRecord::tableName())
            ->where(['>=', 'dateCreated', $last7Days])
            ->andWhere(['status' => 'sent'])
            ->count();

        $successRate = $totalLast7Days > 0 ? round(($sentLast7Days / $totalLast7Days) * 100, 1) : 100;

        // Failed count today
        $failedToday = (new Query())
            ->from(SmsLogRecord::tableName())
            ->where(['>=', 'dateCreated', $todayStart])
            ->andWhere(['<=', 'dateCreated', $todayEnd])
            ->andWhere(['status' => 'failed'])
            ->count();

        // Provider stats
        $totalProviders = (new Query())
            ->from(ProviderRecord::tableName())
            ->count();

        $enabledProviders = (new Query())
            ->from(ProviderRecord::tableName())
            ->where(['enabled' => true])
            ->count();

        // Recent logs (last 10)
        $recentLogs = (new Query())
            ->from(SmsLogRecord::tableName())
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit(10)
            ->all();

        $providerIds = array_values(array_unique(array_filter(array_column($recentLogs, 'providerId'))));
        $senderIdIds = array_values(array_unique(array_filter(array_column($recentLogs, 'senderIdId'))));

        /** @var array<int, ProviderRecord> $providersById */
        $providersById = $providerIds
            ? ProviderRecord::find()->where(['id' => $providerIds])->indexBy('id')->all()
            : [];
        /** @var array<int, SenderIdRecord> $senderIdsById */
        $senderIdsById = $senderIdIds
            ? SenderIdRecord::find()->where(['id' => $senderIdIds])->indexBy('id')->all()
            : [];

        foreach ($recentLogs as &$log) {
            $provider = $providersById[$log['providerId']] ?? null;
            $senderId = $senderIdsById[$log['senderIdId']] ?? null;
            $log['providerName'] = $provider ? $provider->name : 'Unknown';
            $log['senderIdName'] = $senderId ? $senderId->name : 'Unknown';
            $log['senderIdValue'] = $senderId ? $senderId->senderId : 'Unknown';
        }
        unset($log);

        return $this->renderTemplate('sms-manager/dashboard/index', [
            'settings' => $settings,
            'smsToday' => $smsToday,
            'smsYesterday' => $smsYesterday,
            'successRate' => $successRate,
            'failedToday' => $failedToday,
            'totalProviders' => $totalProviders,
            'enabledProviders' => $enabledProviders,
            'recentLogs' => $recentLogs,
        ]);
    }

    /**
     * Badges test page - displays all ColorHelper color sets
     *
     * @return Response
     * @since 5.6.0
     */
    public function actionBadges(): Response
    {
        return $this->renderTemplate('sms-manager/badges');
    }
}
