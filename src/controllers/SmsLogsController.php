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
use lindemannrock\base\helpers\ExportHelper;
use lindemannrock\logginglibrary\LoggingLibrary;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\records\SenderIdRecord;
use lindemannrock\smsmanager\records\SmsLogRecord;
use lindemannrock\smsmanager\SmsManager;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
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

        // Enrich with provider/sender names and actual sender ID
        foreach ($logs as &$log) {
            $provider = ProviderRecord::findOne($log['providerId']);
            $senderId = SenderIdRecord::findOne($log['senderIdId']);
            $log['providerName'] = $provider ? $provider->name : 'Unknown';
            $log['senderIdName'] = $senderId ? $senderId->name : 'Unknown';
            $log['senderIdValue'] = $senderId ? $senderId->senderId : 'Unknown';
        }

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
                ['like', 'recipient', $params['search']],
                ['like', 'message', $params['search']],
                ['like', 'providerMessageId', $params['search']],
            ]);
        }

        // Apply sorting — sort column comes from the validated allowlist.
        $query->orderBy([$params['sort'] => $params['sortDir']]);

        return $query;
    }

    /**
     * View a single log
     *
     * @param int $logId
     * @return Response
     */
    public function actionView(int $logId): Response
    {
        $this->requirePermission('smsManager:viewSmsLogs');

        $log = SmsLogRecord::findOne($logId);

        if (!$log) {
            throw new NotFoundHttpException('Log not found');
        }

        $provider = ProviderRecord::findOne($log->providerId);
        $senderId = SenderIdRecord::findOne($log->senderIdId);

        return $this->renderTemplate('sms-manager/logs/view', [
            'log' => $log,
            'provider' => $provider,
            'senderId' => $senderId,
        ]);
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

        // Build export rows with provider/sender names
        $rows = [];
        foreach ($logs as $log) {
            $provider = ProviderRecord::findOne($log['providerId']);
            $senderId = SenderIdRecord::findOne($log['senderIdId']);

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
        $extension = $format === 'excel' ? 'xlsx' : $format;
        $filename = ExportHelper::filename($settings, ['logs', $dateRangeLabel], $extension);

        $dateColumns = ['dateCreated'];

        return match ($format) {
            'csv' => ExportHelper::toCsv($rows, $headers, $filename, $dateColumns),
            'json' => ExportHelper::toJson($rows, $filename, $dateColumns),
            'excel' => ExportHelper::toExcel($rows, $headers, $filename, $dateColumns, [
                'sheetTitle' => 'SMS Logs',
            ]),
            default => throw new BadRequestHttpException("Unknown export format: {$format}"),
        };
    }

    /**
     * Clear logs
     *
     * @return Response
     */
    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('smsManager:deleteSmsLogs');

        $request = Craft::$app->getRequest();
        $olderThan = $request->getBodyParam('olderThan');

        $condition = [];

        if ($olderThan) {
            $date = (new \DateTime())->modify("-{$olderThan} days")->format('Y-m-d H:i:s');
            $condition = ['<', 'dateCreated', $date];
        }

        $count = SmsLogRecord::find()->where($condition ?: null)->count();
        SmsLogRecord::deleteAll($condition ?: []);

        $this->logInfo('Logs cleared', ['count' => $count, 'olderThan' => $olderThan]);

        Craft::$app->getSession()->setNotice(Craft::t('sms-manager', '{count} log records deleted.', ['count' => $count]));

        return $this->redirect('sms-manager/dashboard');
    }

    /**
     * Delete a single log
     *
     * @return Response
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
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

        $query->limit($params['limit'])->offset($params['offset']);

        $logs = $query->all();

        // Enrich with provider/sender names and format dates
        foreach ($logs as &$log) {
            $provider = ProviderRecord::findOne($log['providerId']);
            $senderId = SenderIdRecord::findOne($log['senderIdId']);
            $log['providerName'] = $provider ? $provider->name : 'Unknown';
            $log['senderIdName'] = $senderId ? $senderId->name : 'Unknown';
            $log['senderIdValue'] = $senderId ? $senderId->senderId : 'Unknown';
            // Format date for display using centralized DateTimeHelper
            $log['datetimeFormatted'] = DateFormatHelper::formatDatetime($log['dateCreated'], 'medium');
        }

        // Get status counts
        $sentCount = (new Query())->from(SmsLogRecord::tableName())->where(['status' => 'sent'])->count();
        $failedCount = (new Query())->from(SmsLogRecord::tableName())->where(['status' => 'failed'])->count();
        $pendingCount = (new Query())->from(SmsLogRecord::tableName())->where(['status' => 'pending'])->count();

        return $this->asJson([
            'success' => true,
            'logs' => $logs,
            'totalCount' => (int) $totalCount,
            'totalPages' => $totalPages,
            'page' => $params['page'],
            'limit' => $params['limit'],
            'offset' => $params['offset'],
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
