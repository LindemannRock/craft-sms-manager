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
use craft\web\Controller;
use lindemannrock\base\helpers\CpNavHelper;
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\DateRangeHelper;
use lindemannrock\base\helpers\DbHelper;
use lindemannrock\base\helpers\ExportHelper;
use lindemannrock\logginglibrary\LoggingLibrary;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\records\SenderIdRecord;
use lindemannrock\smsmanager\records\SmsLogRecord;
use lindemannrock\smsmanager\SmsManager;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * SMS Logs Controller
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
class SmsLogsController extends Controller
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
     * List all logs (main dashboard page).
     *
     * Follows the canonical CP table index-page pattern (SQL-paginated variant) —
     * see plugins/base/docs/template-guides/cp-table-index-pattern.md.
     * Controller owns query-param parsing, allowlist validation, filter, sort,
     * and pagination; the Twig template stays presentational.
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $user = Craft::$app->getUser();
        $settings = SmsManager::$plugin->getSettings();

        // If user doesn't have viewSmsLogs permission, redirect to first accessible section
        if (!$user->checkPermission('smsManager:viewSmsLogs') || !$settings->enableSmsLogs) {
            $sections = SmsManager::$plugin->getCpSections($settings, false);
            $route = CpNavHelper::firstAccessibleRoute($user, $settings, $sections);
            if ($route) {
                return $this->redirect($route);
            }

            // No access at all - require permission (will show 403)
            $this->requirePermission('smsManager:viewSmsLogs');
        }

        // Param parsing + allowlist validation lives in parseListParams() so
        // the AJAX-refresh endpoint (actionGetLogsData) inherits the same
        // hardening — a future contributor adding a filter to actionIndex
        // can't silently miss the AJAX path.
        $params = $this->parseListParams();

        $query = $this->buildLogsQuery($params);

        // Total count is computed AFTER filters, BEFORE pagination — matches
        // the visible-after-filter set.
        $totalCount = $query->count();
        $totalPages = $totalCount > 0 ? (int) ceil($totalCount / $params['limit']) : 1;

        $query->limit($params['limit'])->offset($params['offset']);

        $logs = $query->all();

        $this->enrichLogsWithRelations($logs);

        // Get log menu config from LoggingLibrary
        $logMenuItems = null;
        $logMenuLabel = null;

        if (class_exists(LoggingLibrary::class)) {
            $config = LoggingLibrary::getConfig('sms-manager');
            $logMenuItems = $config['logMenuItems'] ?? null;
            $logMenuLabel = $config['logMenuLabel'] ?? null;

            // Filter out 'system' item if system log viewer is disabled
            if ($logMenuItems && !($config['enableLogViewer'] ?? false)) {
                unset($logMenuItems['system']);
            }
        }

        return $this->renderTemplate('sms-manager/logs/sms', [
            'logMenuItems' => $logMenuItems,
            'logMenuLabel' => $logMenuLabel,
            'logs' => $logs,
            'settings' => $settings,
            'providers' => $params['providers'],
            'sources' => $params['sources'],
            'totalCount' => $totalCount,
            'totalPages' => $totalPages,
            'page' => $params['page'],
            'limit' => $params['limit'],
            'offset' => $params['offset'],
            'search' => $params['search'],
            'statusFilter' => $params['statusFilter'],
            'providerFilter' => $params['providerFilter'],
            'languageFilter' => $params['languageFilter'],
            'sourceFilter' => $params['sourceFilter'],
            'dateRange' => $params['dateRange'],
            'sort' => $params['sort'],
            'dir' => $params['dir'],
            'canDelete' => $user->checkPermission('smsManager:deleteSmsLogs'),
            'canExport' => $user->checkPermission('smsManager:exportSmsLogs'),
        ]);
    }

    /**
     * Parse + allowlist-validate every list-page query param.
     *
     * Shared by actionIndex (renders the CP table) and actionGetLogsData
     * (AJAX-refresh endpoint). Keeping the validation in one place is the
     * point — drift between the two endpoints is the bug this method
     * prevents.
     *
     * @return array<string, mixed>
     */
    private function parseListParams(): array
    {
        $request = Craft::$app->getRequest();
        $settings = SmsManager::$plugin->getSettings();

        // Get providers + sources up front so they can seed the filter
        // allowlists below.
        $providers = SmsManager::$plugin->providers->getAllProviders();
        $sources = (new Query())
            ->select(['sourcePlugin'])
            ->from(SmsLogRecord::tableName())
            ->distinct()
            ->where(['not', ['sourcePlugin' => null]])
            ->andWhere(['not', ['sourcePlugin' => '']])
            ->column();

        $statusFilter = (string) $request->getQueryParam('status', 'all');
        $validStatuses = ['all', 'sent', 'failed', 'pending'];
        if (!in_array($statusFilter, $validStatuses, true)) {
            $statusFilter = 'all';
        }

        // Provider filter — value is a numeric provider ID or 'all'.
        $providerFilter = (string) $request->getQueryParam('provider', 'all');
        $validProviderIds = ['all'];
        foreach ($providers as $provider) {
            $validProviderIds[] = (string) $provider->id;
        }
        if (!in_array($providerFilter, $validProviderIds, true)) {
            $providerFilter = 'all';
        }

        // Language filter — value is a 2-letter language code from Craft sites.
        $languageFilter = (string) $request->getQueryParam('language', 'all');
        $validLanguages = ['all'];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $langCode = explode('-', (string) $site->language)[0];
            if (!in_array($langCode, $validLanguages, true)) {
                $validLanguages[] = $langCode;
            }
        }
        if (!in_array($languageFilter, $validLanguages, true)) {
            $languageFilter = 'all';
        }

        // Source filter — 'all', 'direct', or one of the distinct sourcePlugin
        // values surfaced above.
        $sourceFilter = (string) $request->getQueryParam('source', 'all');
        $validSources = array_merge(['all', 'direct'], array_map('strval', $sources));
        if (!in_array($sourceFilter, $validSources, true)) {
            $sourceFilter = 'all';
        }

        // Date range is validated downstream by DateRangeHelper::applyToQuery.
        $dateRange = $request->getQueryParam('dateRange', DateRangeHelper::getDefaultDateRange(SmsManager::$plugin->id));

        $search = trim((string) $request->getQueryParam('search', ''));
        if (mb_strlen($search) > 64) {
            $search = mb_substr($search, 0, 64);
        }

        $validSortFields = ['dateCreated', 'recipient', 'status', 'language', 'providerId'];
        $sort = (string) $request->getQueryParam('sort', 'dateCreated');
        if (!in_array($sort, $validSortFields, true)) {
            $sort = 'dateCreated';
        }
        $dir = strtolower((string) $request->getQueryParam('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sortDir = $dir === 'asc' ? SORT_ASC : SORT_DESC;

        $page = max(1, (int) $request->getQueryParam('page', 1));
        $limit = max(1, (int) $settings->itemsPerPage);
        $offset = ($page - 1) * $limit;

        return [
            'providers' => $providers,
            'sources' => $sources,
            'statusFilter' => $statusFilter,
            'providerFilter' => $providerFilter,
            'languageFilter' => $languageFilter,
            'sourceFilter' => $sourceFilter,
            'dateRange' => $dateRange,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'sortDir' => $sortDir,
            'page' => $page,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Build the filtered + sorted logs query from a parseListParams() result.
     * Pagination is applied by the caller — keeping limit/offset out of here
     * means COUNT(*) and SELECT share a single query builder.
     *
     * @param array<string, mixed> $params
     */
    private function buildLogsQuery(array $params): Query
    {
        $query = (new Query())
            ->from(SmsLogRecord::tableName());

        if ($params['statusFilter'] !== 'all') {
            $query->andWhere(['status' => $params['statusFilter']]);
        }

        if ($params['providerFilter'] !== 'all') {
            $query->andWhere(['providerId' => (int) $params['providerFilter']]);
        }

        if ($params['languageFilter'] !== 'all') {
            $query->andWhere(['language' => $params['languageFilter']]);
        }

        if ($params['sourceFilter'] !== 'all') {
            if ($params['sourceFilter'] === 'direct') {
                $query->andWhere(['or', ['sourcePlugin' => null], ['sourcePlugin' => '']]);
            } else {
                $query->andWhere(['sourcePlugin' => $params['sourceFilter']]);
            }
        }

        // Apply date range filter (supports all options: thisMonth, lastYear, etc.)
        DateRangeHelper::applyToQuery($query, $params['dateRange']);

        if ($params['search'] !== '') {
            $query->andWhere([
                'or',
                ['like', 'LOWER([[recipient]])', mb_strtolower($params['search'])],
                ['like', 'LOWER([[message]])', mb_strtolower($params['search'])],
                ['like', 'LOWER([[providerMessageId]])', mb_strtolower($params['search'])],
            ]);
        }

        // Apply sorting — sort column comes from the validated allowlist.
        if ($params['sort'] === 'providerId') {
            // Nullable column (config-only providers): NULLs pinned last on both
            // engines, both directions — MySQL and PostgreSQL default NULL
            // ordering are opposites.
            $query->orderBy(new \yii\db\Expression(
                DbHelper::orderByNullsLast('providerId', $params['sortDir'] === SORT_DESC ? 'DESC' : 'ASC')
            ));
        } else {
            $query->orderBy([$params['sort'] => $params['sortDir']]);
        }

        return $query;
    }

    /**
     * Batch-load providers + sender IDs referenced by a log result set.
     *
     * Resolves by both int FK (`providerId` / `senderIdId`) and handle
     * snapshot (`providerHandle` / `senderIdHandle`). The handle pathway
     * covers two cases the int FK can't:
     *
     *   - Config-only resources, where the record has no DB row at all
     *     (`id` is null on the record → `providerId`/`senderIdId` is null
     *     on the log row from the moment it was written).
     *   - Records that got deleted after the SMS was sent, where the
     *     `SET NULL` FK clause nulled `providerId`/`senderIdId` on the log
     *     row but the handle snapshot survives.
     *
     * Audit 8.6. Returns four arrays for O(1) lookup in enrichment loops.
     *
     * @param array<int, array<string, mixed>> $logs
     * @return array{0: array<int, ProviderRecord>, 1: array<int, SenderIdRecord>, 2: array<string, ProviderRecord>, 3: array<string, SenderIdRecord>}
     */
    private function fetchLogRelations(array $logs): array
    {
        $providerIds = array_values(array_unique(array_filter(array_column($logs, 'providerId'))));
        $senderIdIds = array_values(array_unique(array_filter(array_column($logs, 'senderIdId'))));
        $providerHandles = array_values(array_unique(array_filter(array_column($logs, 'providerHandle'))));
        $senderHandles = array_values(array_unique(array_filter(array_column($logs, 'senderIdHandle'))));

        /** @var array<int, ProviderRecord> $providersById */
        $providersById = $providerIds
            ? ProviderRecord::find()->where(['id' => $providerIds])->indexBy('id')->all()
            : [];
        /** @var array<int, SenderIdRecord> $senderIdsById */
        $senderIdsById = $senderIdIds
            ? SenderIdRecord::find()->where(['id' => $senderIdIds])->indexBy('id')->all()
            : [];

        // Handle lookups go through `findByHandleWithConfig()` (not a flat
        // DB query) because the resource might be config-only. The helper
        // checks the sms-manager config file first, falls back to the DB.
        // Iterating the unique handle set keeps this O(distinct_handles)
        // — fine for log views.
        $providersByHandle = [];
        foreach ($providerHandles as $handle) {
            $record = ProviderRecord::findByHandleWithConfig($handle);
            if ($record) {
                $providersByHandle[$handle] = $record;
            }
        }

        $senderIdsByHandle = [];
        foreach ($senderHandles as $handle) {
            $record = SenderIdRecord::findByHandleWithConfig($handle);
            if ($record) {
                $senderIdsByHandle[$handle] = $record;
            }
        }

        return [$providersById, $senderIdsById, $providersByHandle, $senderIdsByHandle];
    }

    /**
     * Enrich a log result set with provider name, sender name, and sender value
     * fields. Mutates the input array in place.
     *
     * @param array<int, array<string, mixed>> $logs
     */
    private function enrichLogsWithRelations(array &$logs): void
    {
        [$providersById, $senderIdsById, $providersByHandle, $senderIdsByHandle] = $this->fetchLogRelations($logs);

        foreach ($logs as &$log) {
            // Try the int FK first; fall back to the handle snapshot for
            // config-only / deleted-record cases (see fetchLogRelations()).
            $provider = $providersById[$log['providerId']] ?? null;
            if (!$provider && !empty($log['providerHandle'])) {
                $provider = $providersByHandle[$log['providerHandle']] ?? null;
            }

            $senderId = $senderIdsById[$log['senderIdId']] ?? null;
            if (!$senderId && !empty($log['senderIdHandle'])) {
                $senderId = $senderIdsByHandle[$log['senderIdHandle']] ?? null;
            }

            $log['providerName'] = $provider ? $provider->name : 'Unknown';
            $log['senderIdName'] = $senderId ? $senderId->name : 'Unknown';
            $log['senderIdValue'] = $senderId ? $senderId->senderId : 'Unknown';
        }
    }

    /**
     * Export logs
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionExport(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('smsManager:exportSmsLogs');

        $request = Craft::$app->getRequest();
        $dateRange = $request->getBodyParam('dateRange', DateRangeHelper::getDefaultDateRange(SmsManager::$plugin->id));
        $format = $request->getBodyParam('format', 'csv');

        // Check for specific log IDs (selection-aware export)
        $logIdsJson = $request->getBodyParam('logIds');
        $logIds = $logIdsJson ? json_decode($logIdsJson, true) : null;

        // Validate format is enabled
        if (!ExportHelper::isFormatEnabled($format, SmsManager::$plugin->id)) {
            throw new BadRequestHttpException("Export format '{$format}' is not enabled.");
        }

        $query = (new Query())
            ->from(SmsLogRecord::tableName())
            ->orderBy(['dateCreated' => SORT_DESC]);

        // If specific IDs provided, export only those; otherwise apply date range filter
        if (!empty($logIds) && is_array($logIds)) {
            $query->where(['id' => $logIds]);
        } else {
            // Apply date range filter (supports all options: thisMonth, lastYear, etc.)
            DateRangeHelper::applyToQuery($query, $dateRange);
        }

        $logs = $query->all();

        [$providersById, $senderIdsById, $providersByHandle, $senderIdsByHandle] = $this->fetchLogRelations($logs);

        $rows = [];
        foreach ($logs as $log) {
            // Same id-first-then-handle resolution as enrichLogsWithRelations()
            // — config-only or deleted records keep showing their real
            // names in exported reports.
            $provider = $providersById[$log['providerId']] ?? null;
            if (!$provider && !empty($log['providerHandle'])) {
                $provider = $providersByHandle[$log['providerHandle']] ?? null;
            }

            $senderId = $senderIdsById[$log['senderIdId']] ?? null;
            if (!$senderId && !empty($log['senderIdHandle'])) {
                $senderId = $senderIdsByHandle[$log['senderIdHandle']] ?? null;
            }

            $rows[] = [
                'dateCreated' => $log['dateCreated'],
                'recipient' => $log['recipient'],
                'message' => $log['message'],
                'language' => $log['language'],
                'status' => $log['status'],
                'provider' => $provider ? $provider->name : 'Unknown',
                'senderId' => $senderId ? $senderId->senderId : 'Unknown',
                'source' => $log['sourcePlugin'] ?? 'Direct',
                'messageId' => $log['providerMessageId'],
                'error' => $log['errorMessage'],
                'providerResponse' => $log['providerResponse'],
            ];
        }

        // Check for empty data
        if (empty($rows)) {
            Craft::$app->getSession()->setError(Craft::t('sms-manager', 'No logs to export for the selected date range.'));
            return $this->redirect(Craft::$app->getRequest()->getReferrer());
        }

        $headers = [
            'Date',
            'Recipient',
            'Message',
            'Language',
            'Status',
            'Provider',
            'Sender ID',
            'Source',
            'Message ID',
            'Error',
            'Provider Response',
        ];

        // Build filename
        $settings = SmsManager::$plugin->getSettings();
        $dateRangeLabel = $dateRange === 'all' ? 'alltime' : $dateRange;
        $extension = ExportHelper::extensionForFormat($format);
        $filename = ExportHelper::filename($settings, ['logs', $dateRangeLabel], $extension);

        $dateColumns = ['dateCreated'];

        return ExportHelper::dispatchTable(
            rows: $rows,
            headers: $headers,
            format: $format,
            filename: $filename,
            dateColumns: $dateColumns,
            excelOptions: [
                'sheetTitle' => 'SMS Logs',
            ],
        );
    }

    /**
     * Delete a single log
     *
     * @return Response
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('smsManager:deleteSmsLogs');

        $logId = Craft::$app->getRequest()->getRequiredBodyParam('logId');

        $log = SmsLogRecord::findOne($logId);
        if ($log && $log->delete()) {
            return $this->asJson(['success' => true]);
        }

        return $this->asJson(['success' => false, 'error' => 'Could not delete log']);
    }

    /**
     * Get logs data for AJAX refresh.
     *
     * Shares parseListParams() + buildLogsQuery() with actionIndex so the
     * AJAX-refresh path inherits the same allowlist hardening.
     *
     * @return Response
     * @since 5.4.0
     */
    public function actionGetLogsData(): Response
    {
        $this->requirePermission('smsManager:viewSmsLogs');
        $this->requireAcceptsJson();

        $params = $this->parseListParams();
        $query = $this->buildLogsQuery($params);

        $totalCount = $query->count();
        $totalPages = $totalCount > 0 ? (int) ceil($totalCount / $params['limit']) : 1;

        // Status badges must reflect the same filter set as the table —
        // run them on a clone of the filtered query, before pagination is
        // applied. Single GROUP BY query replaces 3 unfiltered COUNTs.
        $statusCountRows = (clone $query)
            ->select(['status', 'cnt' => 'COUNT(*)'])
            ->groupBy('status')
            ->orderBy([])
            ->all();
        $statusCounts = array_column($statusCountRows, 'cnt', 'status');
        $sentCount = (int) ($statusCounts['sent'] ?? 0);
        $failedCount = (int) ($statusCounts['failed'] ?? 0);
        $pendingCount = (int) ($statusCounts['pending'] ?? 0);

        $query->limit($params['limit'])->offset($params['offset']);

        $logs = $query->all();

        $this->enrichLogsWithRelations($logs);
        foreach ($logs as &$log) {
            $log['datetimeFormatted'] = DateFormatHelper::formatDatetime($log['dateCreated'], 'medium');
        }
        unset($log);

        $settings = SmsManager::$plugin->getSettings();
        $canDelete = Craft::$app->getUser()->checkPermission('smsManager:deleteSmsLogs');
        $rowsHtml = '';
        foreach ($logs as $log) {
            $rowsHtml .= Craft::$app->getView()->renderTemplate('sms-manager/logs/_sms-row', [
                'item' => $log,
                'checkboxesEnabled' => $canDelete,
                'rowActionsEnabled' => true,
            ]);
        }

        if ($rowsHtml === '') {
            $rowsHtml = Craft::$app->getView()->renderTemplate('sms-manager/logs/_empty-row', [
                'colspan' => 8 + ($canDelete ? 1 : 0) + 1,
            ]);
        }

        return $this->asJson([
            'success' => true,
            'logs' => $logs,
            'rowsHtml' => $rowsHtml,
            'totalCount' => (int) $totalCount,
            'totalPages' => $totalPages,
            'page' => $params['page'],
            'limit' => $params['limit'],
            'offset' => $params['offset'],
            'pagination' => [
                'page' => $params['page'],
                'limit' => $params['limit'],
                'totalCount' => (int) $totalCount,
                'totalPages' => $totalPages,
            ],
            'refresh' => [
                'enabled' => $settings->refreshIntervalSecs > 0,
            ],
            'sentCount' => (int) $sentCount,
            'failedCount' => (int) $failedCount,
            'pendingCount' => (int) $pendingCount,
        ]);
    }

    /**
     * Bulk delete logs
     *
     * @return Response
     * @since 5.6.0
     */
    public function actionBulkDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('smsManager:deleteSmsLogs');

        $logIds = Craft::$app->getRequest()->getBodyParam('logIds', []);

        if (empty($logIds)) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('sms-manager', 'No logs selected.'),
            ]);
        }

        try {
            $deletedCount = SmsLogRecord::deleteAll(['id' => $logIds]);

            $this->logInfo("Bulk deleted {$deletedCount} SMS log(s)", [
                'logIds' => $logIds,
                'deletedCount' => $deletedCount,
            ]);

            return $this->asJson([
                'success' => true,
                'message' => Craft::t('sms-manager', '{count} log(s) deleted.', ['count' => $deletedCount]),
                'deletedCount' => $deletedCount,
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to bulk delete logs: ' . $e->getMessage(), [
                'logIds' => $logIds,
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'message' => Craft::t('sms-manager', 'Failed to delete logs.'),
            ]);
        }
    }

    /**
     * Delete all logs
     *
     * @return Response
     * @since 5.6.0
     */
    public function actionDeleteAll(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('smsManager:deleteSmsLogs');

        try {
            $deletedCount = SmsLogRecord::deleteAll();

            $this->logInfo("Deleted all SMS logs", [
                'deletedCount' => $deletedCount,
            ]);

            return $this->asJson([
                'success' => true,
                'message' => Craft::t('sms-manager', '{count} log(s) deleted.', ['count' => $deletedCount]),
                'deleted' => $deletedCount,
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to delete all logs: ' . $e->getMessage(), [
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'message' => Craft::t('sms-manager', 'Failed to delete logs.'),
            ]);
        }
    }
}
