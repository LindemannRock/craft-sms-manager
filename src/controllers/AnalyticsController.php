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
use yii\web\ForbiddenHttpException;
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

        $providerId = (string) ($request->getQueryParam('providerId') ?? $request->getQueryParam('provider', 'all'));
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

        $rawSiteId = (string) $request->getQueryParam('siteId', 'all');
        $siteId = $this->resolveSiteId($rawSiteId);
        $sites = Craft::$app->getSites()->getEditableSites();

        $languageOptions = $this->getLanguageFilterOptions();
        $language = $this->resolveLanguageFilter((string) $request->getQueryParam('language', 'all'), $languageOptions);
        $sourceOptions = $this->getSourceFilterOptions();
        $source = $this->resolveSourceFilter((string) $request->getQueryParam('source', 'all'), $sourceOptions);

        $bounds = DateRangeHelper::getBounds($dateRange);
        $startDate = $bounds['start'] ?? null;
        $endDate = $bounds['end'] ?? null;

        // Build query
        $query = (new Query())
            ->from(AnalyticsRecord::tableName());

        $this->applyAnalyticsFilters($query, $startDate, $endDate, $providerId, $siteId, $language, $senderIdId, $source);

        // Get summary stats
        $summaryStats = (clone $query)
            ->select([
                'SUM([[totalSent]]) as sent',
                'SUM([[totalDelivered]]) as delivered',
                'SUM([[totalFailed]]) as failed',
                'SUM([[totalPending]]) as pending',
                "SUM(CASE WHEN [[encoding]] = 'gsm-7' THEN 1 ELSE 0 END) as gsm7",
                "SUM(CASE WHEN [[encoding]] = 'ucs-2' THEN 1 ELSE 0 END) as ucs2",
                'SUM(CASE WHEN [[encoding]] IS NULL THEN 1 ELSE 0 END) as [[unknownEncoding]]',
                'SUM([[totalCharacters]]) as characters',
                'SUM([[totalMessages]]) as segments',
                'COUNT(*) as [[eventRows]]',
                'COUNT([[totalCharacters]]) as [[knownCharacters]]',
                'COUNT([[totalMessages]]) as [[knownSegments]]',
            ])
            ->one();

        // Get provider breakdown
        $providerData = (clone $query)
            ->select([
                'providerId',
                'siteId',
                'SUM([[totalSent]]) as sent',
                'SUM([[totalFailed]]) as failed',
            ])
            ->groupBy(['providerId', 'siteId'])
            ->all();

        $providersById = $this->providersByIdFromRows($providerData);
        $sitesById = $this->sitesByIdFromRows($providerData);
        foreach ($providerData as &$row) {
            $provider = $providersById[$row['providerId']] ?? null;
            $rowSiteId = $row['siteId'] ? (int) $row['siteId'] : null;
            $site = $rowSiteId ? ($sitesById[$rowSiteId] ?? null) : null;
            $row['providerName'] = $provider ? $provider->name : Craft::t('sms-manager', 'Unknown');
            $row['siteName'] = $site ? $site->name : Craft::t('sms-manager', 'Unknown');
        }
        unset($row);

        $summaryStats = $this->normalizeSummaryStats($summaryStats ?: []);

        return $this->renderTemplate('sms-manager/analytics/index', [
            'settings' => $settings,
            'dateRange' => $dateRange,
            'providerId' => $providerId,
            'senderIdId' => $senderIdId,
            'siteId' => $siteId,
            'language' => $language,
            'source' => $source,
            'summaryStats' => $summaryStats,
            'providerData' => $providerData,
            'providers' => $providers,
            'senderIds' => $senderIds,
            'sites' => $sites,
            'languageOptions' => $languageOptions,
            'sourceOptions' => $sourceOptions,
            'pluginHandle' => SmsManager::$plugin->id,
        ]);
    }

    /**
     * Normalize aggregate values without presenting partial historical fact sums.
     *
     * @param array<string, mixed> $stats
     * @return array<string, int|float|null>
     */
    private function normalizeSummaryStats(array $stats): array
    {
        $sent = (int)($stats['sent'] ?? 0);
        $failed = (int)($stats['failed'] ?? 0);
        $total = $sent + $failed;
        $eventRows = (int)($stats['eventRows'] ?? 0);

        return [
            'sent' => $sent,
            'delivered' => (int)($stats['delivered'] ?? 0),
            'failed' => $failed,
            'pending' => (int)($stats['pending'] ?? 0),
            'gsm7' => (int)($stats['gsm7'] ?? 0),
            'ucs2' => (int)($stats['ucs2'] ?? 0),
            'unknownEncoding' => (int)($stats['unknownEncoding'] ?? 0),
            'characters' => (int)($stats['knownCharacters'] ?? 0) === $eventRows ? (int)($stats['characters'] ?? 0) : null,
            'segments' => (int)($stats['knownSegments'] ?? 0) === $eventRows ? (int)($stats['segments'] ?? 0) : null,
            'total' => $total,
            'successRate' => $total > 0 ? round(($sent / $total) * 100, 1) : 0,
        ];
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

        $validTypes = ['daily', 'providers', 'senderids', 'languages', 'sites', 'senderid-sites', 'encoding', 'encoding-daily', 'sender-id-table'];
        if (!in_array($type, $validTypes, true)) {
            throw new \yii\web\BadRequestHttpException('Invalid data type.');
        }

        $dateRange = $request->getBodyParam('dateRange', DateRangeHelper::getDefaultDateRange(SmsManager::$plugin->id));

        $providerId = $this->resolveProviderFilter((string) $request->getBodyParam('providerId', 'all'));
        $senderIdId = $this->resolveSenderIdFilter((string) $request->getBodyParam('senderId', 'all'));

        $siteId = $this->resolveSiteId((string) $request->getBodyParam('siteId', 'all'));
        $language = $this->resolveLanguageFilter(
            (string) $request->getBodyParam('language', 'all'),
            $this->getLanguageFilterOptions(),
        );
        $source = $this->resolveSourceFilter(
            (string) $request->getBodyParam('source', 'all'),
            $this->getSourceFilterOptions(),
        );

        // Calculate date range (supports all options: thisMonth, lastYear, etc.)
        $bounds = DateRangeHelper::getBounds($dateRange);
        $startDate = $bounds['start'] ?? null;
        $endDate = $bounds['end'] ?? null;

        $data = match ($type) {
            'daily' => $this->getDailyChartData($startDate, $endDate, $providerId, $siteId, $language, $senderIdId, $source),
            'providers' => $this->getProviderChartData($startDate, $endDate, $providerId, $siteId, $language, $senderIdId, $source),
            'senderids' => $this->getSenderIdChartData($startDate, $endDate, $providerId, $siteId, $language, $senderIdId, $source),
            'languages' => $this->getLanguageChartData($startDate, $endDate, $providerId, $siteId, $language, $senderIdId, $source),
            'sites' => $this->getSiteChartData($startDate, $endDate, $providerId, $siteId, $language, $senderIdId, $source),
            'senderid-sites' => $this->getSiteChartData($startDate, $endDate, $providerId, $siteId, $language, $senderIdId, $source),
            'encoding' => $this->getEncodingChartData($startDate, $endDate, $providerId, $siteId, $language, $senderIdId, $source),
            'encoding-daily' => $this->getEncodingDailyChartData($startDate, $endDate, $providerId, $siteId, $language, $senderIdId, $source),
            'sender-id-table' => $this->getSenderIdTableData($startDate, $endDate, $providerId, $siteId, $language, $senderIdId, $source),
        };

        return $this->asJson([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get daily chart data
     */
    private function getDailyChartData(?\DateTimeInterface $startDate, ?\DateTimeInterface $endDate, string $providerId, int|string $siteId, string $language, string $senderIdId, string $source): array
    {
        $localDate = DateFormatHelper::localDateExpression('date');

        $query = (new Query())
            ->select([
                'date' => $localDate,
                'SUM([[totalSent]]) as sent',
                'SUM([[totalFailed]]) as failed',
            ])
            ->from(AnalyticsRecord::tableName())
            ->groupBy([$localDate])
            ->orderBy(['date' => SORT_ASC]);

        $this->applyAnalyticsFilters($query, $startDate, $endDate, $providerId, $siteId, $language, $senderIdId, $source);

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
    private function getProviderChartData(?\DateTime $startDate, ?\DateTime $endDate, string $providerId, int|string $siteId, string $language, string $senderIdId, string $source): array
    {
        $query = (new Query())
            ->select([
                'providerId',
                'SUM([[totalSent]]) as sent',
                'SUM([[totalFailed]]) as failed',
            ])
            ->from(AnalyticsRecord::tableName())
            ->groupBy(['providerId'])
            ;

        $this->applyAnalyticsFilters($query, $startDate, $endDate, $providerId, $siteId, $language, $senderIdId, $source);

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
    private function getSenderIdChartData(?\DateTime $startDate, ?\DateTime $endDate, string $providerId, int|string $siteId, string $language, string $senderIdId, string $source): array
    {
        $query = (new Query())
            ->select([
                'senderIdId',
                'SUM([[totalSent]]) as sent',
                'SUM([[totalFailed]]) as failed',
            ])
            ->from(AnalyticsRecord::tableName())
            ->groupBy(['senderIdId']);

        $this->applyAnalyticsFilters($query, $startDate, $endDate, $providerId, $siteId, $language, $senderIdId, $source);

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
    private function getSenderIdTableData(?\DateTime $startDate, ?\DateTime $endDate, string $providerId, int|string $siteId, string $language, string $senderIdId, string $source): array
    {
        $query = (new Query())
            ->select([
                'senderIdId',
                'siteId',
                'SUM([[totalSent]]) as sent',
                'SUM([[totalFailed]]) as failed',
            ])
            ->from(AnalyticsRecord::tableName())
            ->groupBy(['senderIdId', 'siteId']);

        $this->applyAnalyticsFilters($query, $startDate, $endDate, $providerId, $siteId, $language, $senderIdId, $source);

        $data = $query->all();

        $senderIdsById = $this->senderIdsByIdFromRows($data);
        $sitesById = $this->sitesByIdFromRows($data);
        foreach ($data as &$row) {
            $senderId = $senderIdsById[$row['senderIdId']] ?? null;
            $rowSiteId = $row['siteId'] ? (int) $row['siteId'] : null;
            $site = $rowSiteId ? ($sitesById[$rowSiteId] ?? null) : null;
            $row['senderIdName'] = $senderId ? $senderId->name : Craft::t('sms-manager', 'Unknown');
            $row['siteName'] = $site ? $site->name : Craft::t('sms-manager', 'Unknown');
            $row['sent'] = (int)$row['sent'];
            $row['failed'] = (int)$row['failed'];
        }
        unset($row);

        return $data;
    }

    /**
     * Get site chart data from analytics records.
     */
    private function getSiteChartData(?\DateTime $startDate, ?\DateTime $endDate, string $providerId, int|string $siteId, string $language, string $senderIdId, string $source): array
    {
        $query = (new Query())
            ->select([
                'siteId',
                'SUM([[totalSent]]) as sent',
                'SUM([[totalFailed]]) as failed',
            ])
            ->from(AnalyticsRecord::tableName())
            ->groupBy(['siteId']);

        $this->applyAnalyticsFilters($query, $startDate, $endDate, $providerId, $siteId, $language, $senderIdId, $source);

        $data = $query->all();

        $sitesById = $this->sitesByIdFromRows($data);
        $labels = [];
        $values = [];

        foreach ($data as $row) {
            $rowSiteId = $row['siteId'] ? (int) $row['siteId'] : null;
            $site = $rowSiteId ? ($sitesById[$rowSiteId] ?? null) : null;
            $labels[] = $site ? $site->name : Craft::t('sms-manager', 'Unknown');
            $values[] = (int)$row['sent'] + (int)$row['failed'];
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }

    /**
     * Get language chart data from analytics records
     */
    private function getLanguageChartData(?\DateTime $startDate, ?\DateTime $endDate, string $providerId, int|string $siteId, string $language, string $senderIdId, string $source): array
    {
        $query = (new Query())
            ->select([
                'language',
                'SUM([[totalSent]] + [[totalFailed]] + [[totalDelivered]] + [[totalPending]]) as count',
            ])
            ->from(AnalyticsRecord::tableName())
            ->groupBy(['language'])
            ->orderBy(['count' => SORT_DESC]);

        $this->applyAnalyticsFilters($query, $startDate, $endDate, $providerId, $siteId, $language, $senderIdId, $source);

        $data = $query->all();

        // Get language display names
        $languageNames = $this->getLanguageDisplayNames();

        $labels = [];
        $values = [];

        foreach ($data as $row) {
            $langCode = $row['language'] ?? 'unknown';
            $labels[] = $this->languageLabel((string) $langCode, $languageNames);
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
    private function getEncodingChartData(?\DateTime $startDate, ?\DateTime $endDate, string $providerId, int|string $siteId, string $language, string $senderIdId, string $source): array
    {
        $query = (new Query())
            ->select([
                "SUM(CASE WHEN [[encoding]] = 'gsm-7' THEN 1 ELSE 0 END) as gsm7",
                "SUM(CASE WHEN [[encoding]] = 'ucs-2' THEN 1 ELSE 0 END) as ucs2",
                'SUM(CASE WHEN [[encoding]] IS NULL THEN 1 ELSE 0 END) as unknown',
            ])
            ->from(AnalyticsRecord::tableName());

        $this->applyAnalyticsFilters($query, $startDate, $endDate, $providerId, $siteId, $language, $senderIdId, $source);

        $data = $query->one();

        return [
            'labels' => [
                'GSM-7',
                'UCS-2',
                Craft::t('sms-manager', 'Unknown'),
            ],
            'values' => [
                (int)($data['gsm7'] ?? 0),
                (int)($data['ucs2'] ?? 0),
                (int)($data['unknown'] ?? 0),
            ],
        ];
    }

    /**
     * Get encoding daily chart data
     */
    private function getEncodingDailyChartData(?\DateTimeInterface $startDate, ?\DateTimeInterface $endDate, string $providerId, int|string $siteId, string $language, string $senderIdId, string $source): array
    {
        $localDate = DateFormatHelper::localDateExpression('date');

        $query = (new Query())
            ->select([
                'date' => $localDate,
                "SUM(CASE WHEN [[encoding]] = 'gsm-7' THEN 1 ELSE 0 END) as gsm7",
                "SUM(CASE WHEN [[encoding]] = 'ucs-2' THEN 1 ELSE 0 END) as ucs2",
                'SUM(CASE WHEN [[encoding]] IS NULL THEN 1 ELSE 0 END) as unknown',
            ])
            ->from(AnalyticsRecord::tableName())
            ->groupBy([$localDate])
            ->orderBy(['date' => SORT_ASC]);

        $this->applyAnalyticsFilters($query, $startDate, $endDate, $providerId, $siteId, $language, $senderIdId, $source);

        $data = $query->all();

        if (empty($data)) {
            return [
                'labels' => [],
                'gsm7' => [],
                'ucs2' => [],
                'unknown' => [],
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
                'unknown' => (int)($dayData['unknown'] ?? 0),
            ];

            $date->modify('+1 day');
        }

        return [
            'labels' => array_column($chartData, 'date'),
            'gsm7' => array_column($chartData, 'gsm7'),
            'ucs2' => array_column($chartData, 'ucs2'),
            'unknown' => array_column($chartData, 'unknown'),
        ];
    }

    /**
     * Apply the shared analytics filters to a query.
     */
    private function applyAnalyticsFilters(
        Query $query,
        ?\DateTimeInterface $startDate,
        ?\DateTimeInterface $endDate,
        string $providerId,
        int|string $siteId,
        string $language,
        string $senderIdId = 'all',
        string $source = 'all',
    ): void {
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

        if ($language !== 'all') {
            $query->andWhere(['language' => $language]);
        }

        if ($source === '__direct__') {
            $query->andWhere(['or', ['sourcePlugin' => null], ['sourcePlugin' => '']]);
        } elseif ($source !== 'all') {
            $query->andWhere(['sourcePlugin' => $source]);
        }

        if ($siteId !== 'all') {
            $query->andWhere(['siteId' => (int) $siteId]);
            return;
        }

        $editableSiteIds = Craft::$app->getSites()->getEditableSiteIds();
        if ($editableSiteIds === []) {
            $query->andWhere(['siteId' => null]);
            return;
        }

        $query->andWhere(['or', ['siteId' => $editableSiteIds], ['siteId' => null]]);
    }

    /**
     * Resolve and validate a requested site ID against editable sites.
     *
     * @return int|'all'
     * @throws ForbiddenHttpException
     */
    private function resolveSiteId(?string $rawSiteId): int|string
    {
        if ($rawSiteId === null || $rawSiteId === '' || $rawSiteId === 'all') {
            return 'all';
        }

        $siteId = null;
        if (is_numeric($rawSiteId)) {
            $siteId = (int) $rawSiteId;
        } else {
            $site = Craft::$app->getSites()->getSiteByHandle($rawSiteId);
            $siteId = $site ? $site->id : null;
        }

        if ($siteId === null || !in_array($siteId, Craft::$app->getSites()->getEditableSiteIds(), true)) {
            throw new ForbiddenHttpException(Craft::t('sms-manager', 'User does not have permission to view analytics for this site.'));
        }

        return $siteId;
    }

    /**
     * Build language filter options from editable site languages and stored analytics languages.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function getLanguageFilterOptions(): array
    {
        $languages = [];
        foreach (Craft::$app->getSites()->getEditableSites() as $site) {
            $languages[strtolower(explode('-', (string) $site->language)[0])] = true;
        }

        $storedLanguageQuery = (new Query())
            ->select(['language'])
            ->distinct()
            ->from(AnalyticsRecord::tableName())
            ->where(['not', ['language' => null]])
            ->andWhere(['not', ['language' => '']]);

        $this->applyAnalyticsFilters($storedLanguageQuery, null, null, 'all', 'all', 'all');
        $storedLanguages = $storedLanguageQuery->column();

        foreach ($storedLanguages as $language) {
            $languages[strtolower((string) $language)] = true;
        }

        unset($languages['']);
        ksort($languages);

        $languageNames = $this->getLanguageDisplayNames();
        $options = [
            ['value' => 'all', 'label' => Craft::t('sms-manager', 'All Languages')],
        ];

        foreach (array_keys($languages) as $language) {
            $options[] = [
                'value' => $language,
                'label' => $this->languageLabel($language, $languageNames),
            ];
        }

        return $options;
    }

    /**
     * Validate a language filter against the available language options.
     *
     * @param array<int, array{value: string, label: string}> $languageOptions
     */
    private function resolveLanguageFilter(string $language, array $languageOptions): string
    {
        $valid = array_column($languageOptions, 'value');
        return in_array($language, $valid, true) ? $language : 'all';
    }

    /** @return array<int, array{value: string, label: string}> */
    private function getSourceFilterOptions(): array
    {
        $query = (new Query())
            ->select(['sourcePlugin'])
            ->distinct()
            ->from(AnalyticsRecord::tableName())
            ->orderBy(['sourcePlugin' => SORT_ASC]);
        $this->applyAnalyticsFilters($query, null, null, 'all', 'all', 'all');
        $sources = $query->column();

        $options = [
            ['value' => 'all', 'label' => Craft::t('sms-manager', 'All Sources')],
        ];
        $directAdded = false;
        foreach ($sources as $source) {
            if ($source === null || $source === '') {
                if ($directAdded) {
                    continue;
                }
                $directAdded = true;
            }
            $options[] = $source === null || $source === ''
                ? ['value' => '__direct__', 'label' => Craft::t('sms-manager', 'Direct')]
                : ['value' => (string)$source, 'label' => (string)$source];
        }

        return $options;
    }

    /** @param array<int, array{value: string, label: string}> $sourceOptions */
    private function resolveSourceFilter(string $source, array $sourceOptions): string
    {
        return in_array($source, array_column($sourceOptions, 'value'), true) ? $source : 'all';
    }

    private function resolveProviderFilter(string $providerId): string
    {
        if ($providerId === 'all') {
            return 'all';
        }

        foreach (SmsManager::$plugin->providers->getAllProviders() as $provider) {
            if ($provider->id !== null && (string)$provider->id === $providerId) {
                return $providerId;
            }
        }

        return 'all';
    }

    private function resolveSenderIdFilter(string $senderIdId): string
    {
        if ($senderIdId === 'all') {
            return 'all';
        }

        foreach (SmsManager::$plugin->senderIds->getAllSenderIds() as $senderId) {
            if ($senderId->id !== null && (string)$senderId->id === $senderIdId) {
                return $senderIdId;
            }
        }

        return 'all';
    }

    /**
     * Return the display label for a language code.
     *
     * @param array<string, string> $languageNames
     */
    private function languageLabel(string $language, array $languageNames): string
    {
        return $languageNames[$language] ?? strtoupper($language);
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
     * Batch-load editable sites referenced by a set of rows.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, \craft\models\Site>
     */
    private function sitesByIdFromRows(array $rows, string $key = 'siteId'): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn(mixed $id): int => (int) $id,
            array_column($rows, $key),
        ))));
        if (!$ids) {
            return [];
        }

        $editableSiteIds = Craft::$app->getSites()->getEditableSiteIds();
        $sites = [];
        foreach ($ids as $id) {
            if (!in_array($id, $editableSiteIds, true)) {
                continue;
            }

            $site = Craft::$app->getSites()->getSiteById($id);
            if ($site) {
                $sites[$id] = $site;
            }
        }

        return $sites;
    }

    /**
     * Convert analytics records to consumer-facing export rows.
     *
     * @param array<int, array<string, mixed>> $data
     * @return array<int, array<string, int|string>>
     */
    private function formatAnalyticsExportRows(array $data): array
    {
        $providersById = $this->providersByIdFromRows($data);
        $senderIdsById = $this->senderIdsByIdFromRows($data);

        $rows = [];
        foreach ($data as $row) {
            $provider = $providersById[$row['providerId']] ?? null;
            $senderId = $senderIdsById[$row['senderIdId']] ?? null;
            $site = $row['siteId'] ? Craft::$app->getSites()->getSiteById((int) $row['siteId']) : null;

            $rows[] = [
                'date' => $row['date'],
                'site' => $site?->name ?? Craft::t('sms-manager', 'Unknown'),
                'language' => $row['language'] ?: Craft::t('sms-manager', 'Unknown'),
                'encoding' => $row['encoding'] ?: Craft::t('sms-manager', 'Unknown'),
                'source' => $row['sourcePlugin'] ?: Craft::t('sms-manager', 'Direct'),
                'provider' => $provider ? $provider->name : Craft::t('sms-manager', 'Unknown'),
                'senderId' => $senderId ? $senderId->name : Craft::t('sms-manager', 'Unknown'),
                'totalSent' => (int)$row['totalSent'],
                'totalDelivered' => (int)$row['totalDelivered'],
                'totalFailed' => (int)$row['totalFailed'],
                'totalPending' => (int)$row['totalPending'],
                'totalCharacters' => $row['totalCharacters'] !== null ? (int)$row['totalCharacters'] : Craft::t('sms-manager', 'Unknown'),
                'totalMessages' => $row['totalMessages'] !== null ? (int)$row['totalMessages'] : Craft::t('sms-manager', 'Unknown'),
            ];
        }

        return $rows;
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
        $providerId = (string) ($request->getBodyParam('providerId') ?? $request->getBodyParam('provider', 'all'));
        $language = $this->resolveLanguageFilter(
            (string) $request->getBodyParam('language', 'all'),
            $this->getLanguageFilterOptions(),
        );
        $siteId = $this->resolveSiteId((string) $request->getBodyParam('siteId', 'all'));
        $senderIdId = $this->resolveSenderIdFilter((string) $request->getBodyParam('senderId', 'all'));
        $source = $this->resolveSourceFilter(
            (string) $request->getBodyParam('source', 'all'),
            $this->getSourceFilterOptions(),
        );

        $providerId = $this->resolveProviderFilter($providerId);

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

        $this->applyAnalyticsFilters($query, $startDate, $endDate, $providerId, $siteId, $language, $senderIdId, $source);

        $data = $query->all();

        $rows = $this->formatAnalyticsExportRows($data);

        // Check for empty data
        if (empty($rows)) {
            Craft::$app->getSession()->setError(Craft::t('sms-manager', 'No analytics data to export for the selected filters.'));
            return $this->redirect(Craft::$app->getRequest()->getReferrer());
        }

        $headers = [
            'Date',
            Craft::t('sms-manager', 'Site'),
            Craft::t('sms-manager', 'Language'),
            Craft::t('sms-manager', 'Encoding'),
            Craft::t('sms-manager', 'Source'),
            'Provider',
            'Sender ID',
            'Total Sent',
            'Total Delivered',
            'Total Failed',
            'Total Pending',
            Craft::t('sms-manager', 'Characters'),
            Craft::t('sms-manager', 'SMS Segments'),
        ];

        // Build filename
        $settings = SmsManager::$plugin->getSettings();
        $dateRangeLabel = $dateRange === 'all' ? 'alltime' : $dateRange;
        $extension = ExportHelper::extensionForFormat($format);
        $filenameParts = ['analytics'];
        if (is_int($siteId)) {
            $site = Craft::$app->getSites()->getSiteById($siteId);
            if ($site) {
                $filenameParts[] = $site->handle;
            }
        }
        if ($language !== 'all') {
            $filenameParts[] = $language;
        }
        $filenameParts[] = $dateRangeLabel;

        $filename = ExportHelper::filename($settings, $filenameParts, $extension);

        return ExportHelper::dispatchTable(
            rows: $rows,
            headers: $headers,
            format: $format,
            filename: $filename,
            excelOptions: [
                'sheetTitle' => 'Analytics',
            ],
        );
    }
}
