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
use craft\helpers\Db;
use craft\web\Controller;
use lindemannrock\base\helpers\CpNavHelper;
use lindemannrock\base\helpers\DateRangeHelper;
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

        // Date bounds come from the base DateRangeHelper so they live in the
        // Craft app timezone (e.g. Kuwait UTC+3). Db::prepareDateForDb() then
        // converts those local boundaries to the UTC string Craft uses for
        // `dateCreated` — without it, a non-UTC install undercounts/overcounts
        // by the timezone offset. 'today' / 'last7days' return only a start
        // bound (no upper limit needed since no future records exist);
        // 'yesterday' returns both bounds (start of yesterday → start of today).
        $todayBounds = DateRangeHelper::getBounds('today');
        $todayStart = Db::prepareDateForDb($todayBounds['start']);

        $yesterdayBounds = DateRangeHelper::getBounds('yesterday');
        $yesterdayStart = Db::prepareDateForDb($yesterdayBounds['start']);
        $yesterdayEnd = Db::prepareDateForDb($yesterdayBounds['end']);

        $last7Bounds = DateRangeHelper::getBounds('last7days');
        $last7Days = Db::prepareDateForDb($last7Bounds['start']);

        // SMS Today count
        $smsToday = (new Query())
            ->from(SmsLogRecord::tableName())
            ->where(['>=', 'dateCreated', $todayStart])
            ->count();

        // SMS Yesterday count (for comparison)
        $smsYesterday = (new Query())
            ->from(SmsLogRecord::tableName())
            ->where(['>=', 'dateCreated', $yesterdayStart])
            ->andWhere(['<', 'dateCreated', $yesterdayEnd])
            ->count();

        // Success rate (last 7 days)
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

        $senderIdIds = array_values(array_unique(array_filter(array_column($recentLogs, 'senderIdId'))));
        $senderIdHandles = array_values(array_unique(array_filter(array_column($recentLogs, 'senderIdHandle'))));

        /** @var array<int, SenderIdRecord> $senderIdsById */
        $senderIdsById = $senderIdIds
            ? SenderIdRecord::find()->where(['id' => $senderIdIds])->indexBy('id')->all()
            : [];

        // Fall back to handle snapshot (8.6) for config-only senders and rows
        // whose record was later deleted (SET NULL on the int FK).
        $senderIdsByHandle = [];
        foreach ($senderIdHandles as $handle) {
            $record = SenderIdRecord::findByHandleWithConfig($handle);
            if ($record) {
                $senderIdsByHandle[$handle] = $record;
            }
        }

        foreach ($recentLogs as &$log) {
            $senderId = $senderIdsById[$log['senderIdId']] ?? null;
            if (!$senderId && !empty($log['senderIdHandle'])) {
                $senderId = $senderIdsByHandle[$log['senderIdHandle']] ?? null;
            }
            $log['senderIdName'] = $senderId ? $senderId->name : 'Unknown';
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
}
