<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025 LindemannRock
 */

namespace lindemannrock\smsmanager\controllers;

use Craft;
use craft\db\Query;
use craft\web\Controller;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\smsmanager\records\AnalyticsRecord;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\records\SenderIdRecord;
use lindemannrock\smsmanager\SmsManager;
use yii\web\Response;

/**
 * Analytics Controller
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
class AnalyticsController extends Controller
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
     * Analytics index
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('smsManager:viewAnalytics');

        $request = Craft::$app->getRequest();
        $settings = SmsManager::$plugin->getSettings();

        // Get filter parameters
        $dateRange = $request->getQueryParam('dateRange', 'last30days');
        $providerId = $request->getQueryParam('provider', 'all');
        $senderIdId = $request->getQueryParam('senderId', 'all');

        // Calculate date range
        $dates = $this->getDateRangeFromParam($dateRange);
        $startDate = $dates['start'];
        $endDate = $dates['end'];

        // Build query
        $query = (new Query())
            ->from(AnalyticsRecord::tableName())
            ->where(['>=', 'date', $startDate->format('Y-m-d')])
            ->andWhere(['<=', 'date', $endDate->format('Y-m-d')]);

        if ($providerId !== 'all') {
            $query->andWhere(['providerId' => $providerId]);
        }

        if ($senderIdId !== 'all') {
            $query->andWhere(['senderIdId' => $senderIdId]);
        }

        // Get summary stats
        $summaryStats = (clone $query)
            ->select([
                'SUM(totalSent) as sent',
                'SUM(totalDelivered) as delivered',
                'SUM(totalFailed) as failed',
                'SUM(totalPending) as pending',
                'SUM(englishCount) as english',
                'SUM(arabicCount) as arabic',
                'SUM(otherCount) as other',
            ])
            ->one();

        // Get daily breakdown
        $dailyData = (clone $query)
            ->select([
                'date',
                'SUM(totalSent) as sent',
                'SUM(totalFailed) as failed',
            ])
            ->groupBy(['date'])
            ->orderBy(['date' => SORT_ASC])
            ->all();

        // Get provider breakdown
        $providerData = (clone $query)
            ->select([
                'providerId',
                'SUM(totalSent) as sent',
                'SUM(totalFailed) as failed',
            ])
            ->groupBy(['providerId'])
            ->all();

        // Enrich provider data with names
        foreach ($providerData as &$row) {
            $provider = ProviderRecord::findOne($row['providerId']);
            $row['providerName'] = $provider ? $provider->name : 'Unknown';
        }

        // Get sender ID breakdown
        $senderIdData = (clone $query)
            ->select([
                'senderIdId',
                'SUM(totalSent) as sent',
                'SUM(totalFailed) as failed',
            ])
            ->groupBy(['senderIdId'])
            ->all();

        // Enrich sender ID data with names
        foreach ($senderIdData as &$row) {
            $senderId = SenderIdRecord::findOne($row['senderIdId']);
            $row['senderIdName'] = $senderId ? $senderId->name : 'Unknown';
        }

        // Get providers and sender IDs for filters
        $providers = SmsManager::$plugin->providers->getAllProviders();
        $senderIds = SmsManager::$plugin->senderIds->getAllSenderIds();

        // Calculate totals
        $totalSent = (int)($summaryStats['sent'] ?? 0);
        $totalFailed = (int)($summaryStats['failed'] ?? 0);
        $total = $totalSent + $totalFailed;
        $successRate = $total > 0 ? round(($totalSent / $total) * 100, 1) : 0;

        return $this->renderTemplate('sms-manager/analytics/index', [
            'settings' => $settings,
            'dateRange' => $dateRange,
            'providerId' => $providerId,
            'senderIdId' => $senderIdId,
            'summaryStats' => [
                'sent' => $totalSent,
                'delivered' => (int)($summaryStats['delivered'] ?? 0),
                'failed' => $totalFailed,
                'pending' => (int)($summaryStats['pending'] ?? 0),
                'english' => (int)($summaryStats['english'] ?? 0),
                'arabic' => (int)($summaryStats['arabic'] ?? 0),
                'other' => (int)($summaryStats['other'] ?? 0),
                'total' => $total,
                'successRate' => $successRate,
            ],
            'dailyData' => $dailyData,
            'providerData' => $providerData,
            'senderIdData' => $senderIdData,
            'providers' => $providers,
            'senderIds' => $senderIds,
        ]);
    }

    /**
     * Get chart data via AJAX
     *
     * @return Response
     */
    public function actionGetData(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePermission('smsManager:viewAnalytics');

        $request = Craft::$app->getRequest();
        $type = $request->getBodyParam('type', 'daily');
        $dateRange = $request->getBodyParam('dateRange', 'last30days');
        $providerId = $request->getBodyParam('providerId', 'all');

        $dates = $this->getDateRangeFromParam($dateRange);
        $startDate = $dates['start'];
        $endDate = $dates['end'];

        $data = match ($type) {
            'daily' => $this->getDailyChartData($startDate, $endDate, $providerId),
            'providers' => $this->getProviderChartData($startDate, $endDate),
            'senderids' => $this->getSenderIdChartData($startDate, $endDate, $providerId),
            'languages' => $this->getLanguageChartData($startDate, $endDate, $providerId),
            'language-daily' => $this->getLanguageDailyChartData($startDate, $endDate, $providerId),
            default => [],
        };

        return $this->asJson([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get daily chart data
     */
    private function getDailyChartData(\DateTime $startDate, \DateTime $endDate, string $providerId): array
    {
        $query = (new Query())
            ->select([
                'date',
                'SUM(totalSent) as sent',
                'SUM(totalFailed) as failed',
            ])
            ->from(AnalyticsRecord::tableName())
            ->where(['>=', 'date', $startDate->format('Y-m-d')])
            ->andWhere(['<=', 'date', $endDate->format('Y-m-d')])
            ->groupBy(['date'])
            ->orderBy(['date' => SORT_ASC]);

        if ($providerId !== 'all') {
            $query->andWhere(['providerId' => $providerId]);
        }

        $data = $query->all();

        // Fill in missing dates
        $chartData = [];
        $date = clone $startDate;
        while ($date <= $endDate) {
            $dateStr = $date->format('Y-m-d');
            $dayData = array_filter($data, fn($row) => $row['date'] === $dateStr);
            $dayData = $dayData ? array_values($dayData)[0] : null;

            $chartData[] = [
                'date' => $date->format('M j'),
                'sent' => (int)($dayData['sent'] ?? 0),
                'failed' => (int)($dayData['failed'] ?? 0),
            ];

            $date->modify('+1 day');
        }

        return [
            'labels' => array_column($chartData, 'date'),
            'sent' => array_column($chartData, 'sent'),
            'failed' => array_column($chartData, 'failed'),
        ];
    }

    /**
     * Get provider chart data
     */
    private function getProviderChartData(\DateTime $startDate, \DateTime $endDate): array
    {
        $data = (new Query())
            ->select([
                'providerId',
                'SUM(totalSent) as sent',
                'SUM(totalFailed) as failed',
            ])
            ->from(AnalyticsRecord::tableName())
            ->where(['>=', 'date', $startDate->format('Y-m-d')])
            ->andWhere(['<=', 'date', $endDate->format('Y-m-d')])
            ->groupBy(['providerId'])
            ->all();

        $labels = [];
        $values = [];

        foreach ($data as $row) {
            $provider = ProviderRecord::findOne($row['providerId']);
            $labels[] = $provider ? $provider->name : 'Unknown';
            $values[] = (int)$row['sent'] + (int)$row['failed'];
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    /**
     * Get sender ID chart data
     */
    private function getSenderIdChartData(\DateTime $startDate, \DateTime $endDate, string $providerId): array
    {
        $query = (new Query())
            ->select([
                'senderIdId',
                'SUM(totalSent) as sent',
                'SUM(totalFailed) as failed',
            ])
            ->from(AnalyticsRecord::tableName())
            ->where(['>=', 'date', $startDate->format('Y-m-d')])
            ->andWhere(['<=', 'date', $endDate->format('Y-m-d')])
            ->groupBy(['senderIdId']);

        if ($providerId !== 'all') {
            $query->andWhere(['providerId' => $providerId]);
        }

        $data = $query->all();

        $labels = [];
        $sent = [];
        $failed = [];

        foreach ($data as $row) {
            $senderId = SenderIdRecord::findOne($row['senderIdId']);
            $labels[] = $senderId ? $senderId->name : 'Unknown';
            $sent[] = (int)$row['sent'];
            $failed[] = (int)$row['failed'];
        }

        return [
            'labels' => $labels,
            'sent' => $sent,
            'failed' => $failed,
        ];
    }

    /**
     * Get language chart data
     */
    private function getLanguageChartData(\DateTime $startDate, \DateTime $endDate, string $providerId): array
    {
        $query = (new Query())
            ->select([
                'SUM(englishCount) as english',
                'SUM(arabicCount) as arabic',
                'SUM(otherCount) as other',
            ])
            ->from(AnalyticsRecord::tableName())
            ->where(['>=', 'date', $startDate->format('Y-m-d')])
            ->andWhere(['<=', 'date', $endDate->format('Y-m-d')]);

        if ($providerId !== 'all') {
            $query->andWhere(['providerId' => $providerId]);
        }

        $data = $query->one();

        // Build dynamic language labels from Craft sites
        $labels = $this->getLanguageLabels();

        return [
            'labels' => [$labels['en'], $labels['ar'], $labels['other']],
            'values' => [
                (int)($data['english'] ?? 0),
                (int)($data['arabic'] ?? 0),
                (int)($data['other'] ?? 0),
            ],
        ];
    }

    /**
     * Get dynamic language labels from Craft sites
     */
    private function getLanguageLabels(): array
    {
        $labels = [
            'en' => Craft::t('sms-manager', 'English'),
            'ar' => Craft::t('sms-manager', 'Arabic'),
            'other' => Craft::t('sms-manager', 'Other'),
        ];

        // Override with site-specific labels if available
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $langCode = explode('-', $site->language)[0];
            if ($langCode === 'en') {
                $labels['en'] = $site->language . ' (' . $site->name . ')';
            } elseif ($langCode === 'ar') {
                $labels['ar'] = $site->language . ' (' . $site->name . ')';
            }
        }

        return $labels;
    }

    /**
     * Get language daily chart data
     */
    private function getLanguageDailyChartData(\DateTime $startDate, \DateTime $endDate, string $providerId): array
    {
        $query = (new Query())
            ->select([
                'date',
                'SUM(englishCount) as english',
                'SUM(arabicCount) as arabic',
                'SUM(otherCount) as other',
            ])
            ->from(AnalyticsRecord::tableName())
            ->where(['>=', 'date', $startDate->format('Y-m-d')])
            ->andWhere(['<=', 'date', $endDate->format('Y-m-d')])
            ->groupBy(['date'])
            ->orderBy(['date' => SORT_ASC]);

        if ($providerId !== 'all') {
            $query->andWhere(['providerId' => $providerId]);
        }

        $data = $query->all();

        // Fill in missing dates
        $chartData = [];
        $date = clone $startDate;
        while ($date <= $endDate) {
            $dateStr = $date->format('Y-m-d');
            $dayData = array_filter($data, fn($row) => $row['date'] === $dateStr);
            $dayData = $dayData ? array_values($dayData)[0] : null;

            $chartData[] = [
                'date' => $date->format('M j'),
                'english' => (int)($dayData['english'] ?? 0),
                'arabic' => (int)($dayData['arabic'] ?? 0),
                'other' => (int)($dayData['other'] ?? 0),
            ];

            $date->modify('+1 day');
        }

        return [
            'labels' => array_column($chartData, 'date'),
            'english' => array_column($chartData, 'english'),
            'arabic' => array_column($chartData, 'arabic'),
            'other' => array_column($chartData, 'other'),
        ];
    }

    /**
     * Get date range from parameter
     */
    private function getDateRangeFromParam(string $dateRange): array
    {
        $endDate = new \DateTime();
        $startDate = match ($dateRange) {
            'today' => new \DateTime(),
            'yesterday' => (new \DateTime())->modify('-1 day'),
            'last7days' => (new \DateTime())->modify('-7 days'),
            'last30days' => (new \DateTime())->modify('-30 days'),
            'last90days' => (new \DateTime())->modify('-90 days'),
            'all' => (new \DateTime())->modify('-365 days'),
            default => (new \DateTime())->modify('-30 days'),
        };

        if ($dateRange === 'yesterday') {
            $endDate = (new \DateTime())->modify('-1 day');
        }

        return [
            'start' => $startDate,
            'end' => $endDate,
        ];
    }

    /**
     * Export analytics data
     *
     * @return Response
     */
    public function actionExport(): Response
    {
        $this->requirePermission('smsManager:exportAnalytics');

        $request = Craft::$app->getRequest();

        $dateRange = $request->getQueryParam('dateRange', 'last30days');
        $format = $request->getQueryParam('format', 'csv');

        $dates = $this->getDateRangeFromParam($dateRange);
        $startDate = $dates['start'];
        $endDate = $dates['end'];

        $data = (new Query())
            ->from(AnalyticsRecord::tableName())
            ->where(['>=', 'date', $startDate->format('Y-m-d')])
            ->andWhere(['<=', 'date', $endDate->format('Y-m-d')])
            ->orderBy(['date' => SORT_ASC])
            ->all();

        // Enrich with provider/sender names
        foreach ($data as &$row) {
            $provider = ProviderRecord::findOne($row['providerId']);
            $senderId = SenderIdRecord::findOne($row['senderIdId']);
            $row['providerName'] = $provider ? $provider->name : 'Unknown';
            $row['senderIdName'] = $senderId ? $senderId->name : 'Unknown';
        }

        // Build filename with settings-based name
        $settings = SmsManager::$plugin->getSettings();
        $filenamePart = strtolower(str_replace(' ', '-', $settings->getPluralLowerDisplayName()));
        $dateRangeLabel = $dateRange === 'all' ? 'alltime' : $dateRange;
        $filename = $filenamePart . '-analytics-' . $dateRangeLabel . '-' . date('Y-m-d-His') . '.' . $format;

        if ($format === 'csv') {
            return $this->exportCsv($data, $filename);
        }

        $response = Craft::$app->getResponse();
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->content = json_encode($data, JSON_PRETTY_PRINT);

        return $response;
    }

    /**
     * Export data as CSV
     *
     * @param array $data
     * @param string $filename
     * @return Response
     */
    private function exportCsv(array $data, string $filename): Response
    {
        $headers = [
            'Date',
            'Provider',
            'Sender ID',
            'Total Sent',
            'Total Delivered',
            'Total Failed',
            'Total Pending',
            'English',
            'Arabic',
            'Other',
        ];

        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers);

        foreach ($data as $row) {
            fputcsv($output, [
                $row['date'],
                $row['providerName'],
                $row['senderIdName'],
                $row['totalSent'],
                $row['totalDelivered'],
                $row['totalFailed'],
                $row['totalPending'],
                $row['englishCount'],
                $row['arabicCount'],
                $row['otherCount'],
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        $response = Craft::$app->getResponse();
        $response->headers->set('Content-Type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->content = $csv;

        return $response;
    }

    /**
     * Clear analytics data
     *
     * @return Response
     */
    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('smsManager:clearAnalytics');

        $request = Craft::$app->getRequest();
        $olderThan = $request->getBodyParam('olderThan');

        $query = AnalyticsRecord::find();
        $date = null;

        if ($olderThan) {
            $date = (new \DateTime())->modify("-{$olderThan} days")->format('Y-m-d');
            $query->where(['<', 'date', $date]);
        }

        $count = $query->count();
        AnalyticsRecord::deleteAll($olderThan ? ['<', 'date', $date] : []);

        $this->logInfo('Analytics cleared', ['count' => $count, 'olderThan' => $olderThan]);

        Craft::$app->getSession()->setNotice(Craft::t('sms-manager', '{count} analytics records deleted.', ['count' => $count]));

        return $this->redirect('sms-manager/analytics');
    }
}
