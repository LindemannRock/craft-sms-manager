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
use lindemannrock\base\helpers\DateRangeHelper;
use lindemannrock\base\helpers\DateTimeHelper;
use lindemannrock\base\helpers\ExportHelper;
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
     * List all logs (main dashboard page)
     *
     * @return Response
     * @since 5.0.0
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

        $request = Craft::$app->getRequest();

        // Get filter parameters
        $search = $request->getQueryParam('search', '');
        $statusFilter = $request->getQueryParam('status', 'all');
        $providerFilter = $request->getQueryParam('provider', 'all');
        $languageFilter = $request->getQueryParam('language', 'all');
        $sourceFilter = $request->getQueryParam('source', 'all');
        $dateRange = $request->getQueryParam('dateRange', DateRangeHelper::getDefaultDateRange(SmsManager::$plugin->id));
        $sort = $request->getQueryParam('sort', 'dateCreated');
        $dir = strtolower($request->getQueryParam('dir', 'desc'));
        $sortDir = $dir === 'asc' ? SORT_ASC : SORT_DESC;
        $page = max(1, (int)$request->getQueryParam('page', 1));
        $limit = $settings->itemsPerPage ?? 100;
        $offset = ($page - 1) * $limit;

        // Build query
        $query = (new Query())
            ->from(SmsLogRecord::tableName());

        // Apply status filter
        if ($statusFilter !== 'all') {
            $query->andWhere(['status' => $statusFilter]);
        }

        // Apply provider filter
        if ($providerFilter !== 'all') {
            $query->andWhere(['providerId' => $providerFilter]);
        }

        // Apply language filter
        if ($languageFilter !== 'all') {
            $query->andWhere(['language' => $languageFilter]);
        }

        // Apply source filter
        if ($sourceFilter !== 'all') {
            if ($sourceFilter === 'direct') {
                $query->andWhere(['or', ['sourcePlugin' => null], ['sourcePlugin' => '']]);
            } else {
                $query->andWhere(['sourcePlugin' => $sourceFilter]);
            }
        }

        // Apply date range filter (supports all options: thisMonth, lastYear, etc.)
        DateRangeHelper::applyToQuery($query, $dateRange);

        // Apply search
        if (!empty($search)) {
            $query->andWhere([
                'or',
                ['like', 'recipient', $search],
                ['like', 'message', $search],
                ['like', 'providerMessageId', $search],
            ]);
        }

        // Apply sorting
        $sortColumn = match ($sort) {
            'recipient' => 'recipient',
            'status' => 'status',
            'language' => 'language',
            'providerId' => 'providerId',
            default => 'dateCreated',
        };
        $query->orderBy([$sortColumn => $sortDir]);

        // Get total count for pagination
        $totalCount = $query->count();
        $totalPages = $totalCount > 0 ? (int)ceil($totalCount / $limit) : 1;

        // Apply pagination
        $query->limit($limit)->offset($offset);

        // Get logs
        $logs = $query->all();

        // Enrich with provider/sender names and actual sender ID
        foreach ($logs as &$log) {
            $provider = ProviderRecord::findOne($log['providerId']);
            $senderId = SenderIdRecord::findOne($log['senderIdId']);
            $log['providerName'] = $provider ? $provider->name : 'Unknown';
            $log['senderIdName'] = $senderId ? $senderId->name : 'Unknown';
            $log['senderIdValue'] = $senderId ? $senderId->senderId : 'Unknown';
        }

        // Get providers for filter
        $providers = SmsManager::$plugin->providers->getAllProviders();

        // Get unique source plugins for filter
        $sources = (new Query())
            ->select(['sourcePlugin'])
            ->from(SmsLogRecord::tableName())
            ->distinct()
            ->where(['not', ['sourcePlugin' => null]])
            ->andWhere(['not', ['sourcePlugin' => '']])
            ->column();

        return $this->renderTemplate('sms-manager/dashboard/index', [
            'logs' => $logs,
            'settings' => $settings,
            'providers' => $providers,
            'sources' => $sources,
            'totalCount' => $totalCount,
            'totalPages' => $totalPages,
            'page' => $page,
            'limit' => $limit,
            'offset' => $offset,
            'search' => $search,
            'statusFilter' => $statusFilter,
            'providerFilter' => $providerFilter,
            'languageFilter' => $languageFilter,
            'sourceFilter' => $sourceFilter,
            'dateRange' => $dateRange,
            'sort' => $sort,
            'dir' => $dir,
        ]);
    }

    /**
     * View a single log
     *
     * @param int $logId
     * @return Response
     * @since 5.0.0
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

        return $this->renderTemplate('sms-manager/dashboard/view', [
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
     * @since 5.0.0
     */
    public function actionExport(): Response
    {
        $this->requirePermission('smsManager:exportSmsLogs');

        $request = Craft::$app->getRequest();
        $dateRange = $request->getQueryParam('dateRange', DateRangeHelper::getDefaultDateRange(SmsManager::$plugin->id));
        $format = $request->getQueryParam('format', 'csv');

        // Check for specific log IDs (selection-aware export via query or body params)
        $logIdsJson = $request->getQueryParam('logIds') ?? $request->getBodyParam('logIds');
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
     * @since 5.0.0
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
     * @since 5.0.0
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
     * Get logs data for AJAX refresh
     *
     * @return Response
     * @since 5.4.0
     */
    public function actionGetLogsData(): Response
    {
        $this->requirePermission('smsManager:viewSmsLogs');

        $request = Craft::$app->getRequest();
        $settings = SmsManager::$plugin->getSettings();

        // Get filter parameters
        $search = $request->getQueryParam('search', '');
        $statusFilter = $request->getQueryParam('status', 'all');
        $providerFilter = $request->getQueryParam('provider', 'all');
        $languageFilter = $request->getQueryParam('language', 'all');
        $sourceFilter = $request->getQueryParam('source', 'all');
        $dateRange = $request->getQueryParam('dateRange', DateRangeHelper::getDefaultDateRange(SmsManager::$plugin->id));
        $sort = $request->getQueryParam('sort', 'dateCreated');
        $dir = strtolower($request->getQueryParam('dir', 'desc'));
        $sortDir = $dir === 'asc' ? SORT_ASC : SORT_DESC;
        $page = max(1, (int)$request->getQueryParam('page', 1));
        $limit = $settings->itemsPerPage ?? 100;
        $offset = ($page - 1) * $limit;

        // Build query
        $query = (new Query())
            ->from(SmsLogRecord::tableName());

        // Apply status filter
        if ($statusFilter !== 'all') {
            $query->andWhere(['status' => $statusFilter]);
        }

        // Apply provider filter
        if ($providerFilter !== 'all') {
            $query->andWhere(['providerId' => $providerFilter]);
        }

        // Apply language filter
        if ($languageFilter !== 'all') {
            $query->andWhere(['language' => $languageFilter]);
        }

        // Apply source filter
        if ($sourceFilter !== 'all') {
            if ($sourceFilter === 'direct') {
                $query->andWhere(['or', ['sourcePlugin' => null], ['sourcePlugin' => '']]);
            } else {
                $query->andWhere(['sourcePlugin' => $sourceFilter]);
            }
        }

        // Apply date range filter (supports all options: thisMonth, lastYear, etc.)
        DateRangeHelper::applyToQuery($query, $dateRange);

        // Apply search
        if (!empty($search)) {
            $query->andWhere([
                'or',
                ['like', 'recipient', $search],
                ['like', 'message', $search],
                ['like', 'providerMessageId', $search],
            ]);
        }

        // Apply sorting
        $sortColumn = match ($sort) {
            'recipient' => 'recipient',
            'status' => 'status',
            'language' => 'language',
            'providerId' => 'providerId',
            default => 'dateCreated',
        };
        $query->orderBy([$sortColumn => $sortDir]);

        // Get total count for pagination
        $totalCount = $query->count();
        $totalPages = $totalCount > 0 ? (int)ceil($totalCount / $limit) : 1;

        // Apply pagination
        $query->limit($limit)->offset($offset);

        // Get logs
        $logs = $query->all();

        // Enrich with provider/sender names and format dates
        foreach ($logs as &$log) {
            $provider = ProviderRecord::findOne($log['providerId']);
            $senderId = SenderIdRecord::findOne($log['senderIdId']);
            $log['providerName'] = $provider ? $provider->name : 'Unknown';
            $log['senderIdName'] = $senderId ? $senderId->name : 'Unknown';
            $log['senderIdValue'] = $senderId ? $senderId->senderId : 'Unknown';
            // Format date for display using centralized DateTimeHelper
            $log['datetimeFormatted'] = DateTimeHelper::formatDatetime($log['dateCreated'], 'medium');
        }

        // Get status counts
        $sentCount = (new Query())->from(SmsLogRecord::tableName())->where(['status' => 'sent'])->count();
        $failedCount = (new Query())->from(SmsLogRecord::tableName())->where(['status' => 'failed'])->count();
        $pendingCount = (new Query())->from(SmsLogRecord::tableName())->where(['status' => 'pending'])->count();

        return $this->asJson([
            'success' => true,
            'logs' => $logs,
            'totalCount' => (int)$totalCount,
            'totalPages' => $totalPages,
            'page' => $page,
            'limit' => $limit,
            'offset' => $offset,
            'sentCount' => (int)$sentCount,
            'failedCount' => (int)$failedCount,
            'pendingCount' => (int)$pendingCount,
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
