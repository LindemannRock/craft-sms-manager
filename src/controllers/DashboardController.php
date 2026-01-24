<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\controllers;

use craft\db\Query;
use craft\web\Controller;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\smsmanager\records\AnalyticsRecord;
use lindemannrock\smsmanager\records\LogRecord;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\records\SenderIdRecord;
use lindemannrock\smsmanager\SmsManager;
use yii\web\Response;

/**
 * Dashboard Controller
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
class DashboardController extends Controller
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
     * Badges test page - displays all ColorHelper color sets
     *
     * @return Response
     * @since 5.6.0
     */
    public function actionBadges(): Response
    {
        return $this->renderTemplate('sms-manager/badges');
    }

    /**
     * Dashboard index
     *
     * @return Response
     * @since 5.0.0
     */
    public function actionIndex(): Response
    {
        $settings = SmsManager::$plugin->getSettings();

        // Get counts
        $providerCount = ProviderRecord::find()->where(['enabled' => true])->count();
        $senderIdCount = SenderIdRecord::find()->where(['enabled' => true])->count();

        // Get stats for today
        $today = (new \DateTime())->format('Y-m-d');
        $todayStats = $this->getStatsForPeriod($today, $today);

        // Get stats for last 7 days
        $weekAgo = (new \DateTime())->modify('-7 days')->format('Y-m-d');
        $weekStats = $this->getStatsForPeriod($weekAgo, $today);

        // Get stats for last 30 days
        $monthAgo = (new \DateTime())->modify('-30 days')->format('Y-m-d');
        $monthStats = $this->getStatsForPeriod($monthAgo, $today);

        // Add language breakdown from logs
        $monthStats['languageBreakdown'] = $this->getLanguageBreakdown($monthAgo, $today);

        // Get recent logs
        $recentLogs = [];
        if ($settings->enableLogs) {
            $recentLogs = (new Query())
                ->from(LogRecord::tableName())
                ->orderBy(['dateCreated' => SORT_DESC])
                ->limit(15)
                ->all();
        }

        // Get chart data (last 14 days)
        $chartData = $this->getChartData(14);

        return $this->renderTemplate('sms-manager/dashboard/index', [
            'settings' => $settings,
            'providerCount' => $providerCount,
            'senderIdCount' => $senderIdCount,
            'todayStats' => $todayStats,
            'weekStats' => $weekStats,
            'monthStats' => $monthStats,
            'recentLogs' => $recentLogs,
            'chartData' => $chartData,
        ]);
    }

    /**
     * Get dashboard stats via AJAX (for auto-refresh)
     *
     * @return Response
     */
    public function actionGetStats(): Response
    {
        $this->requireAcceptsJson();

        $today = (new \DateTime())->format('Y-m-d');
        $todayStats = $this->getStatsForPeriod($today, $today);

        $weekAgo = (new \DateTime())->modify('-7 days')->format('Y-m-d');
        $weekStats = $this->getStatsForPeriod($weekAgo, $today);

        return $this->asJson([
            'todayStats' => $todayStats,
            'weekStats' => $weekStats,
        ]);
    }

    /**
     * Get statistics for a date period
     *
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    private function getStatsForPeriod(string $startDate, string $endDate): array
    {
        $query = (new Query())
            ->select([
                'SUM(totalSent) as sent',
                'SUM(totalDelivered) as delivered',
                'SUM(totalFailed) as failed',
                'SUM(totalPending) as pending',
                'SUM(englishCount) as english',
                'SUM(arabicCount) as arabic',
                'SUM(otherCount) as other',
            ])
            ->from(AnalyticsRecord::tableName())
            ->where(['>=', 'date', $startDate])
            ->andWhere(['<=', 'date', $endDate])
            ->one();

        return [
            'sent' => (int)($query['sent'] ?? 0),
            'delivered' => (int)($query['delivered'] ?? 0),
            'failed' => (int)($query['failed'] ?? 0),
            'pending' => (int)($query['pending'] ?? 0),
            'english' => (int)($query['english'] ?? 0),
            'arabic' => (int)($query['arabic'] ?? 0),
            'other' => (int)($query['other'] ?? 0),
            'total' => (int)(($query['sent'] ?? 0) + ($query['failed'] ?? 0)),
            'successRate' => $this->calculateSuccessRate(
                (int)($query['sent'] ?? 0),
                (int)($query['failed'] ?? 0)
            ),
        ];
    }

    /**
     * Calculate success rate
     *
     * @param int $sent
     * @param int $failed
     * @return float
     */
    private function calculateSuccessRate(int $sent, int $failed): float
    {
        $total = $sent + $failed;
        if ($total === 0) {
            return 0.0;
        }
        return round(($sent / $total) * 100, 1);
    }

    /**
     * Get language breakdown from logs
     *
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    private function getLanguageBreakdown(string $startDate, string $endDate): array
    {
        $data = (new Query())
            ->select(['language', 'COUNT(*) as count'])
            ->from(LogRecord::tableName())
            ->where(['>=', 'dateCreated', $startDate . ' 00:00:00'])
            ->andWhere(['<=', 'dateCreated', $endDate . ' 23:59:59'])
            ->groupBy(['language'])
            ->all();

        $breakdown = [];
        foreach ($data as $row) {
            $lang = $row['language'] ?? 'unknown';
            $breakdown[$lang] = (int)$row['count'];
        }

        return $breakdown;
    }

    /**
     * Get chart data for the last N days
     *
     * @param int $days
     * @return array
     */
    private function getChartData(int $days): array
    {
        $startDate = (new \DateTime())->modify("-{$days} days")->format('Y-m-d');

        $data = (new Query())
            ->select([
                'date',
                'SUM(totalSent) as sent',
                'SUM(totalFailed) as failed',
            ])
            ->from(AnalyticsRecord::tableName())
            ->where(['>=', 'date', $startDate])
            ->groupBy(['date'])
            ->orderBy(['date' => SORT_ASC])
            ->all();

        // Fill in missing dates
        $chartData = [];
        $date = new \DateTime($startDate);
        $endDate = new \DateTime();

        while ($date <= $endDate) {
            $dateStr = $date->format('Y-m-d');
            $dayData = array_filter($data, fn($row) => $row['date'] === $dateStr);
            $dayData = $dayData ? array_values($dayData)[0] : null;

            $chartData[] = [
                'date' => $dateStr,
                'label' => $date->format('M j'),
                'sent' => (int)($dayData['sent'] ?? 0),
                'failed' => (int)($dayData['failed'] ?? 0),
            ];

            $date->modify('+1 day');
        }

        return $chartData;
    }
}
