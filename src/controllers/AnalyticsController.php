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
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\DateRangeHelper;
use lindemannrock\base\helpers\ExportHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\smsmanager\records\AnalyticsRecord;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\records\SenderIdRecord;
use lindemannrock\smsmanager\SmsManager;
use yii\web\BadRequestHttpException;
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
        $this->setLoggingHandle(SmsManager::$plugin->id);
    }

    /**
     * Analytics index
     *
     * @return Response
     * @since 5.1.0
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('smsManager:viewAnalytics');

        $request = Craft::$app->getRequest();
        $settings = SmsManager::$plugin->getSettings();

        // Load filter dropdowns up front so they can also seed the
        // provider / sender ID allowlists below.
        $providers = SmsManager::$plugin->providers->getAllProviders();
        $senderIds = SmsManager::$plugin->senderIds->getAllSenderIds();

        $dateRange = $request->getQueryParam('dateRange', DateRangeHelper::getDefaultDateRange(SmsManager::$plugin->id));

        $providerId = (string) $request->getQueryParam('provider', 'all');
        $validProviderIds = ['all'];
        foreach ($providers as $p) {
            $validProviderIds[] = (string) $p->id;
        }
        if (!in_array($providerId, $validProviderIds, true)) {
            $providerId = 'all';
        }

        $senderIdId = (string) $request->getQueryParam('senderId', 'all');
        $validSenderIdIds = ['all'];
        foreach ($senderIds as $s) {
            $validSenderIdIds[] = (string) $s->id;
        }
        if (!in_array($senderIdId, $validSenderIdIds, true)) {
            $senderIdId = 'all';
        }

        $bounds = DateRangeHelper::getBounds($dateRange);
        $startDate = $bounds['start'] ?? null;
        $endDate = $bounds['end'] ?? null;

        // Build query
        $query = (new Query())
            ->from(AnalyticsRecord::tableName());

        // Apply date filters (DATETIME column)
        if ($startDate) {
            $query->andWhere(['>=', 'date', Db::prepareDateForDb($startDate)]);
        }
        if ($endDate) {
            $query->andWhere(['<', 'date', Db::prepareDateForDb($endDate)]);
        }

        if ($providerId !== 'all') {
            $query->andWhere(['providerId' => (int) $providerId]);
        }

        if ($senderIdId !== 'all') {
            $query->andWhere(['senderIdId' => (int) $senderIdId]);
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

        // Get provider breakdown
        $providerData = (clone $query)
            ->select([
                'providerId',
                'SUM(totalSent) as sent',
                'SUM(totalFailed) as failed',
            ])
            ->groupBy(['providerId'])
            ->all();

        $providersById = $this->providersByIdFromRows($providerData);
        foreach ($providerData as &$row) {
            $provider = $providersById[$row['providerId']] ?? null;
            $row['providerName'] = $provider ? $provider->name : 'Unknown';
        }
        unset($row);

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
            'providerData' => $providerData,
            'providers' => $providers,
            'senderIds' => $senderIds,
            'pluginHandle' => SmsManager::$plugin->id,
        ]);
    }

    /**
     * Get chart data via AJAX
     *
     * @return Response
     * @since 5.4.0
     */
    public function actionGetData(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('smsManager:viewAnalytics');

        $request = Craft::$app->getRequest();
        $type = $request->getBodyParam('type', 'daily');

        $validTypes = ['daily', 'providers', 'senderids', 'languages', 'encoding', 'encoding-daily', 'sender-id-table'];
        if (!in_array($type, $validTypes, true)) {
            throw new \yii\web\BadRequestHttpException('Invalid data type.');
        }

        $dateRange = $request->getBodyParam('dateRange', DateRangeHelper::getDefaultDateRange(SmsManager::$plugin->id));

        // providerId is either the literal 'all' or a numeric provider ID.
        // Anything else collapses to 'all' so non-existent IDs don't silently
        // return zero-result queries.
        $providerIdRaw = $request->getBodyParam('providerId', 'all');
        $providerId = $providerIdRaw === 'all' || !is_numeric($providerIdRaw)
            ? 'all'
            : (string) (int) $providerIdRaw;

        // Calculate date range (supports all options: thisMonth, lastYear, etc.)
        $bounds = DateRangeHelper::getBounds($dateRange);
        $startDate = $bounds['start'] ?? null;
        $endDate = $bounds['end'] ?? null;

        $data = match ($type) {
            'daily' => $this->getDailyChartData($startDate, $endDate, $providerId),
            'providers' => $this->getProviderChartData($startDate, $endDate),
            'senderids' => $this->getSenderIdChartData($startDate, $endDate, $providerId),
            'languages' => $this->getLanguageChartData($startDate, $endDate, $providerId),
            'encoding' => $this->getEncodingChartData($startDate, $endDate, $providerId),
            'encoding-daily' => $this->getEncodingDailyChartData($startDate, $endDate, $providerId),
            'sender-id-table' => $this->getSenderIdTableData($startDate, $endDate, $providerId),
        };

        return $this->asJson([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get daily chart data
     */
    private function getDailyChartData(?\DateTimeInterface $startDate, ?\DateTimeInterface $endDate, string $providerId): array
    {
        $localDate = DateFormatHelper::localDateExpression('date');

        $query = (new Query())
            ->select([
                'date' => $localDate,
                'SUM(totalSent) as sent',
                'SUM(totalFailed) as failed',
            ])
            ->from(AnalyticsRecord::tableName())
            ->groupBy([$localDate])
            ->orderBy(['date' => SORT_ASC]);

        if ($startDate) {
            $query->andWhere(['>=', 'date', Db::prepareDateForDb($startDate)]);
        }
        if ($endDate) {
            $query->andWhere(['<', 'date', Db::prepareDateForDb($endDate)]);
        }

        if ($providerId !== 'all') {
            $query->andWhere(['providerId' => $providerId]);
        }

        $data = $query->all();

        if (empty($data)) {
            return [
                'labels' => [],
                'sent' => [],
                'failed' => [],
            ];
        }

        $tz = new \DateTimeZone(Craft::$app->getTimeZone());

        $startDate = $this->toMutableDateTime($startDate ?: new \DateTime($data[0]['date'], new \DateTimeZone('UTC')));
        $startDate->setTimezone($tz)->setTime(0, 0, 0);

        $endDateIsExclusive = $endDate !== null;
        $endDate = $this->toMutableDateTime($endDate ?: new \DateTime('now', new \DateTimeZone('UTC')));
        $endDate->setTimezone($tz)->setTime(0, 0, 0);

        $rangeEnd = clone $endDate;
        if ($endDateIsExclusive) {
            $rangeEnd->modify('-1 day');
        }

        $dataByDate = [];
        foreach ($data as $row) {
            $rowDate = $row['date'] ?? null;
            $rowDateObj = $this->toMutableDateTime(
                $rowDate instanceof \DateTimeInterface
                    ? $rowDate
                    : new \DateTime((string)$rowDate, new \DateTimeZone('UTC'))
            );
            $rowDateObj->setTimezone($tz);
            $rowDateStr = $rowDateObj->format('Y-m-d');
            $dataByDate[$rowDateStr] = $row;
        }

        // Fill in missing dates (local timezone labels)
        $chartData = [];
        $date = clone $startDate;
        while ($date <= $rangeEnd) {
            $dateStr = $date->format('Y-m-d');
            $dayData = $dataByDate[$dateStr] ?? null;

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
    private function getProviderChartData(?\DateTime $startDate, ?\DateTime $endDate): array
    {
        $query = (new Query())
            ->select([
                'providerId',
                'SUM(totalSent) as sent',
                'SUM(totalFailed) as failed',
            ])
            ->from(AnalyticsRecord::tableName())
            ->groupBy(['providerId'])
            ;

        if ($startDate) {
            $query->andWhere(['>=', 'date', Db::prepareDateForDb($startDate)]);
        }
        if ($endDate) {
            $query->andWhere(['<', 'date', Db::prepareDateForDb($endDate)]);
        }

        $data = $query->all();

        $providersById = $this->providersByIdFromRows($data);
        $labels = [];
        $values = [];

        foreach ($data as $row) {
            $provider = $providersById[$row['providerId']] ?? null;
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
    private function getSenderIdChartData(?\DateTime $startDate, ?\DateTime $endDate, string $providerId): array
    {
        $query = (new Query())
            ->select([
                'senderIdId',
                'SUM(totalSent) as sent',
                'SUM(totalFailed) as failed',
            ])
            ->from(AnalyticsRecord::tableName())
            ->groupBy(['senderIdId']);

        if ($startDate) {
            $query->andWhere(['>=', 'date', Db::prepareDateForDb($startDate)]);
        }
        if ($endDate) {
            $query->andWhere(['<', 'date', Db::prepareDateForDb($endDate)]);
        }

        if ($providerId !== 'all') {
            $query->andWhere(['providerId' => $providerId]);
        }

        $data = $query->all();

        $senderIdsById = $this->senderIdsByIdFromRows($data);
        $labels = [];
        $sent = [];
        $failed = [];

        foreach ($data as $row) {
            $senderId = $senderIdsById[$row['senderIdId']] ?? null;
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
     * Get sender ID table data for AJAX lazy-loading
     */
    private function getSenderIdTableData(?\DateTime $startDate, ?\DateTime $endDate, string $providerId): array
    {
        $query = (new Query())
            ->select([
                'senderIdId',
                'SUM(totalSent) as sent',
                'SUM(totalFailed) as failed',
            ])
            ->from(AnalyticsRecord::tableName())
            ->groupBy(['senderIdId']);

        if ($startDate) {
            $query->andWhere(['>=', 'date', Db::prepareDateForDb($startDate)]);
        }
        if ($endDate) {
            $query->andWhere(['<', 'date', Db::prepareDateForDb($endDate)]);
        }

        if ($providerId !== 'all') {
            $query->andWhere(['providerId' => $providerId]);
        }

        $data = $query->all();

        $senderIdsById = $this->senderIdsByIdFromRows($data);
        foreach ($data as &$row) {
            $senderId = $senderIdsById[$row['senderIdId']] ?? null;
            $row['senderIdName'] = $senderId ? $senderId->name : 'Unknown';
            $row['sent'] = (int)$row['sent'];
            $row['failed'] = (int)$row['failed'];
        }
        unset($row);

        return $data;
    }

    /**
     * Get language chart data from actual log records
     */
    private function getLanguageChartData(?\DateTime $startDate, ?\DateTime $endDate, string $providerId): array
    {
        $query = (new Query())
            ->select([
                'language',
                'COUNT(*) as count',
            ])
            ->from('{{%smsmanager_logs}}')
            ->groupBy(['language'])
            ->orderBy(['count' => SORT_DESC]);

        if ($startDate) {
            $query->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($startDate)]);
        }
        if ($endDate) {
            $query->andWhere(['<', 'dateCreated', Db::prepareDateForDb($endDate)]);
        }

        if ($providerId !== 'all') {
            $query->andWhere(['providerId' => $providerId]);
        }

        $data = $query->all();

        // Get language display names
        $languageNames = $this->getLanguageDisplayNames();

        $labels = [];
        $values = [];

        foreach ($data as $row) {
            $langCode = $row['language'] ?? 'unknown';
            $labels[] = $languageNames[$langCode] ?? ucfirst($langCode);
            $values[] = (int)$row['count'];
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    /**
     * Get language display names based on Craft's locale data
     */
    private function getLanguageDisplayNames(): array
    {
        $names = [
            'en' => Craft::t('sms-manager', 'English'),
            'ar' => Craft::t('sms-manager', 'Arabic'),
            'fr' => Craft::t('sms-manager', 'French'),
            'de' => Craft::t('sms-manager', 'German'),
            'es' => Craft::t('sms-manager', 'Spanish'),
            'it' => Craft::t('sms-manager', 'Italian'),
            'pt' => Craft::t('sms-manager', 'Portuguese'),
            'nl' => Craft::t('sms-manager', 'Dutch'),
            'ja' => Craft::t('sms-manager', 'Japanese'),
            'zh' => Craft::t('sms-manager', 'Chinese'),
            'ko' => Craft::t('sms-manager', 'Korean'),
            'ru' => Craft::t('sms-manager', 'Russian'),
            'unknown' => Craft::t('sms-manager', 'Unknown'),
        ];

        return $names;
    }

    /**
     * Get encoding chart data (GSM-7 vs UCS-2)
     */
    private function getEncodingChartData(?\DateTime $startDate, ?\DateTime $endDate, string $providerId): array
    {
        $query = (new Query())
            ->select([
                'SUM(englishCount) as gsm7',
                'SUM(arabicCount) as ucs2',
                'SUM(otherCount) as mixed',
            ])
            ->from(AnalyticsRecord::tableName());

        if ($startDate) {
            $query->andWhere(['>=', 'date', Db::prepareDateForDb($startDate)]);
        }
        if ($endDate) {
            $query->andWhere(['<', 'date', Db::prepareDateForDb($endDate)]);
        }

        if ($providerId !== 'all') {
            $query->andWhere(['providerId' => $providerId]);
        }

        $data = $query->one();

        return [
            'labels' => [
                Craft::t('sms-manager', 'GSM-7 (Latin)'),
                Craft::t('sms-manager', 'UCS-2 (Unicode)'),
                Craft::t('sms-manager', 'Mixed'),
            ],
            'values' => [
                (int)($data['gsm7'] ?? 0),
                (int)($data['ucs2'] ?? 0),
                (int)($data['mixed'] ?? 0),
            ],
        ];
    }

    /**
     * Get encoding daily chart data
     */
    private function getEncodingDailyChartData(?\DateTimeInterface $startDate, ?\DateTimeInterface $endDate, string $providerId): array
    {
        $localDate = DateFormatHelper::localDateExpression('date');

        $query = (new Query())
            ->select([
                'date' => $localDate,
                'SUM(englishCount) as gsm7',
                'SUM(arabicCount) as ucs2',
                'SUM(otherCount) as mixed',
            ])
            ->from(AnalyticsRecord::tableName())
            ->groupBy([$localDate])
            ->orderBy(['date' => SORT_ASC]);

        if ($startDate) {
            $query->andWhere(['>=', 'date', Db::prepareDateForDb($startDate)]);
        }
        if ($endDate) {
            $query->andWhere(['<', 'date', Db::prepareDateForDb($endDate)]);
        }

        if ($providerId !== 'all') {
            $query->andWhere(['providerId' => $providerId]);
        }

        $data = $query->all();

        if (empty($data)) {
            return [
                'labels' => [],
                'gsm7' => [],
                'ucs2' => [],
                'mixed' => [],
            ];
        }

        $tz = new \DateTimeZone(Craft::$app->getTimeZone());

        $startDate = $this->toMutableDateTime($startDate ?: new \DateTime($data[0]['date'], new \DateTimeZone('UTC')));
        $startDate->setTimezone($tz)->setTime(0, 0, 0);

        $endDateIsExclusive = $endDate !== null;
        $endDate = $this->toMutableDateTime($endDate ?: new \DateTime('now', new \DateTimeZone('UTC')));
        $endDate->setTimezone($tz)->setTime(0, 0, 0);

        $rangeEnd = clone $endDate;
        if ($endDateIsExclusive) {
            $rangeEnd->modify('-1 day');
        }

        $dataByDate = [];
        foreach ($data as $row) {
            $rowDate = $row['date'] ?? null;
            $rowDateObj = $this->toMutableDateTime(
                $rowDate instanceof \DateTimeInterface
                    ? $rowDate
                    : new \DateTime((string)$rowDate, new \DateTimeZone('UTC'))
            );
            $rowDateObj->setTimezone($tz);
            $rowDateStr = $rowDateObj->format('Y-m-d');
            $dataByDate[$rowDateStr] = $row;
        }

        // Fill in missing dates (local timezone labels)
        $chartData = [];
        $date = clone $startDate;
        while ($date <= $rangeEnd) {
            $dateStr = $date->format('Y-m-d');
            $dayData = $dataByDate[$dateStr] ?? null;

            $chartData[] = [
                'date' => $date->format('M j'),
                'gsm7' => (int)($dayData['gsm7'] ?? 0),
                'ucs2' => (int)($dayData['ucs2'] ?? 0),
                'mixed' => (int)($dayData['mixed'] ?? 0),
            ];

            $date->modify('+1 day');
        }

        return [
            'labels' => array_column($chartData, 'date'),
            'gsm7' => array_column($chartData, 'gsm7'),
            'ucs2' => array_column($chartData, 'ucs2'),
            'mixed' => array_column($chartData, 'mixed'),
        ];
    }

    /**
     * Normalize a DateTimeInterface into a mutable DateTime.
     *
     * @param \DateTimeInterface $dateTime
     * @return \DateTime
     */
    private function toMutableDateTime(\DateTimeInterface $dateTime): \DateTime
    {
        if ($dateTime instanceof \DateTime) {
            return $dateTime;
        }

        return new \DateTime($dateTime->format('Y-m-d H:i:s'), $dateTime->getTimezone());
    }

    /**
     * Batch-load ProviderRecord instances referenced by a set of rows.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, ProviderRecord>
     */
    private function providersByIdFromRows(array $rows, string $key = 'providerId'): array
    {
        $ids = array_values(array_unique(array_filter(array_column($rows, $key))));
        if (!$ids) {
            return [];
        }

        /** @var array<int, ProviderRecord> $records */
        $records = ProviderRecord::find()->where(['id' => $ids])->indexBy('id')->all();

        return $records;
    }

    /**
     * Batch-load SenderIdRecord instances referenced by a set of rows.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, SenderIdRecord>
     */
    private function senderIdsByIdFromRows(array $rows, string $key = 'senderIdId'): array
    {
        $ids = array_values(array_unique(array_filter(array_column($rows, $key))));
        if (!$ids) {
            return [];
        }

        /** @var array<int, SenderIdRecord> $records */
        $records = SenderIdRecord::find()->where(['id' => $ids])->indexBy('id')->all();

        return $records;
    }

    /**
     * Export analytics data
     *
     * @return Response
     * @throws BadRequestHttpException
     * @since 5.1.0
     */
    public function actionExport(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('smsManager:exportAnalytics');

        $request = Craft::$app->getRequest();
        $dateRange = $request->getBodyParam('dateRange', DateRangeHelper::getDefaultDateRange(SmsManager::$plugin->id));
        $format = $request->getBodyParam('format', 'csv');

        // Validate format is enabled
        if (!ExportHelper::isFormatEnabled($format, SmsManager::$plugin->id)) {
            throw new BadRequestHttpException("Export format '{$format}' is not enabled.");
        }

        // Calculate date range (supports all options: thisMonth, lastYear, etc.)
        $bounds = DateRangeHelper::getBounds($dateRange);
        $startDate = $bounds['start'] ?? null;
        $endDate = $bounds['end'] ?? null;

        $query = (new Query())
            ->from(AnalyticsRecord::tableName())
            ->orderBy(['date' => SORT_ASC]);

        // Apply date filters (DATETIME column)
        if ($startDate) {
            $query->andWhere(['>=', 'date', Db::prepareDateForDb($startDate)]);
        }
        if ($endDate) {
            $query->andWhere(['<', 'date', Db::prepareDateForDb($endDate)]);
        }

        $data = $query->all();

        $providersById = $this->providersByIdFromRows($data);
        $senderIdsById = $this->senderIdsByIdFromRows($data);

        $rows = [];
        foreach ($data as $row) {
            $provider = $providersById[$row['providerId']] ?? null;
            $senderId = $senderIdsById[$row['senderIdId']] ?? null;

            $rows[] = [
                'date' => $row['date'],
                'provider' => $provider ? $provider->name : 'Unknown',
                'senderId' => $senderId ? $senderId->name : 'Unknown',
                'totalSent' => (int)$row['totalSent'],
                'totalDelivered' => (int)$row['totalDelivered'],
                'totalFailed' => (int)$row['totalFailed'],
                'totalPending' => (int)$row['totalPending'],
                'english' => (int)$row['englishCount'],
                'arabic' => (int)$row['arabicCount'],
                'other' => (int)$row['otherCount'],
            ];
        }

        // Check for empty data
        if (empty($rows)) {
            Craft::$app->getSession()->setError(Craft::t('sms-manager', 'No analytics data to export for the selected date range.'));
            return $this->redirect(Craft::$app->getRequest()->getReferrer());
        }

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

        // Build filename
        $settings = SmsManager::$plugin->getSettings();
        $dateRangeLabel = $dateRange === 'all' ? 'alltime' : $dateRange;
        $extension = $format === 'excel' ? 'xlsx' : $format;
        $filename = ExportHelper::filename($settings, ['analytics', $dateRangeLabel], $extension);

        return match ($format) {
            'csv' => ExportHelper::toCsv($rows, $headers, $filename),
            'json' => ExportHelper::toJson($rows, $filename),
            'excel' => ExportHelper::toExcel($rows, $headers, $filename, [], [
                'sheetTitle' => 'Analytics',
            ]),
            default => throw new BadRequestHttpException("Unknown export format: {$format}"),
        };
    }
}
