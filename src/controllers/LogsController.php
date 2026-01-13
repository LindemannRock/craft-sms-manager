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
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\smsmanager\records\LogRecord;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\records\SenderIdRecord;
use lindemannrock\smsmanager\SmsManager;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Logs Controller
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
class LogsController extends Controller
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
     * List all logs
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('smsManager:viewLogs');

        $request = Craft::$app->getRequest();
        $settings = SmsManager::$plugin->getSettings();

        // Get filter parameters
        $search = $request->getQueryParam('search', '');
        $statusFilter = $request->getQueryParam('status', 'all');
        $providerFilter = $request->getQueryParam('provider', 'all');
        $languageFilter = $request->getQueryParam('language', 'all');
        $sourceFilter = $request->getQueryParam('source', 'all');
        $dateRange = $request->getQueryParam('dateRange', 'last30days');
        $sort = $request->getQueryParam('sort', 'dateCreated');
        $dir = $request->getQueryParam('dir', 'desc');
        $page = max(1, (int)$request->getQueryParam('page', 1));
        $limit = $settings->itemsPerPage ?? 100;
        $offset = ($page - 1) * $limit;

        // Build query
        $query = (new Query())
            ->from(LogRecord::tableName());

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

        // Apply date range filter
        if ($dateRange !== 'all') {
            $dates = $this->getDateRangeFromParam($dateRange);
            $query->andWhere(['>=', 'dateCreated', $dates['start']->format('Y-m-d 00:00:00')]);
            $query->andWhere(['<=', 'dateCreated', $dates['end']->format('Y-m-d 23:59:59')]);
        }

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
        $orderBy = match ($sort) {
            'recipient' => "recipient $dir",
            'status' => "status $dir",
            'language' => "language $dir",
            'providerId' => "providerId $dir",
            default => "dateCreated $dir",
        };
        $query->orderBy($orderBy);

        // Get total count for pagination
        $totalCount = $query->count();
        $totalPages = $totalCount > 0 ? (int)ceil($totalCount / $limit) : 1;

        // Apply pagination
        $query->limit($limit)->offset($offset);

        // Get logs
        $logs = $query->all();

        // Enrich with provider/sender names
        foreach ($logs as &$log) {
            $provider = ProviderRecord::findOne($log['providerId']);
            $senderId = SenderIdRecord::findOne($log['senderIdId']);
            $log['providerName'] = $provider ? $provider->name : 'Unknown';
            $log['senderIdName'] = $senderId ? $senderId->name : 'Unknown';
        }

        // Get providers for filter
        $providers = SmsManager::$plugin->providers->getAllProviders();

        // Get unique source plugins for filter
        $sources = (new Query())
            ->select(['sourcePlugin'])
            ->from(LogRecord::tableName())
            ->distinct()
            ->where(['not', ['sourcePlugin' => null]])
            ->andWhere(['not', ['sourcePlugin' => '']])
            ->column();

        return $this->renderTemplate('sms-manager/sms-logs/index', [
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
     */
    public function actionView(int $logId): Response
    {
        $this->requirePermission('smsManager:viewLogs');

        $log = LogRecord::findOne($logId);

        if (!$log) {
            throw new NotFoundHttpException('Log not found');
        }

        $provider = ProviderRecord::findOne($log->providerId);
        $senderId = SenderIdRecord::findOne($log->senderIdId);

        return $this->renderTemplate('sms-manager/sms-logs/view', [
            'log' => $log,
            'provider' => $provider,
            'senderId' => $senderId,
        ]);
    }

    /**
     * Export logs
     *
     * @return Response
     */
    public function actionExport(): Response
    {
        $this->requirePermission('smsManager:downloadLogs');

        $request = Craft::$app->getRequest();

        $dateRange = $request->getQueryParam('dateRange', 'last30days');
        $format = $request->getQueryParam('format', 'csv');

        $dates = $this->getDateRangeFromParam($dateRange);
        $startDate = $dates['start'];
        $endDate = $dates['end'];

        $query = (new Query())
            ->from(LogRecord::tableName())
            ->orderBy(['dateCreated' => SORT_DESC]);

        if ($dateRange !== 'all') {
            $query->where(['>=', 'dateCreated', $startDate->format('Y-m-d 00:00:00')])
                ->andWhere(['<=', 'dateCreated', $endDate->format('Y-m-d 23:59:59')]);
        }

        $logs = $query->all();

        // Enrich with provider/sender names
        foreach ($logs as &$log) {
            $provider = ProviderRecord::findOne($log['providerId']);
            $senderId = SenderIdRecord::findOne($log['senderIdId']);
            $log['providerName'] = $provider ? $provider->name : 'Unknown';
            $log['senderIdName'] = $senderId ? $senderId->name : 'Unknown';
        }

        // Build filename with settings-based name
        $settings = SmsManager::$plugin->getSettings();
        $filenamePart = strtolower(str_replace(' ', '-', $settings->getPluralLowerDisplayName()));
        $dateRangeLabel = $dateRange === 'all' ? 'alltime' : $dateRange;
        $filename = $filenamePart . '-logs-' . $dateRangeLabel . '-' . date('Y-m-d-His') . '.' . $format;

        if ($format === 'csv') {
            return $this->exportCsv($logs, $filename);
        }

        $response = Craft::$app->getResponse();
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->content = json_encode($logs, JSON_PRETTY_PRINT);

        return $response;
    }

    /**
     * Export logs as CSV
     *
     * @param array $logs
     * @param string $filename
     * @return Response
     */
    private function exportCsv(array $logs, string $filename): Response
    {
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

        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers);

        foreach ($logs as $log) {
            fputcsv($output, [
                $log['dateCreated'],
                $log['recipient'],
                $log['message'],
                $log['language'],
                $log['status'],
                $log['providerName'],
                $log['senderIdName'],
                $log['sourcePlugin'] ?? 'Direct',
                $log['providerMessageId'],
                $log['errorMessage'],
                $log['providerResponse'],
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
     * Clear logs
     *
     * @return Response
     */
    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('smsManager:downloadLogs');

        $request = Craft::$app->getRequest();
        $olderThan = $request->getBodyParam('olderThan');

        $condition = [];

        if ($olderThan) {
            $date = (new \DateTime())->modify("-{$olderThan} days")->format('Y-m-d H:i:s');
            $condition = ['<', 'dateCreated', $date];
        }

        $count = LogRecord::find()->where($condition ?: null)->count();
        LogRecord::deleteAll($condition ?: []);

        $this->logInfo('Logs cleared', ['count' => $count, 'olderThan' => $olderThan]);

        Craft::$app->getSession()->setNotice(Craft::t('sms-manager', '{count} log records deleted.', ['count' => $count]));

        return $this->redirect('sms-manager/sms-logs');
    }

    /**
     * Delete a single log
     *
     * @return Response
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('smsManager:downloadLogs');

        $logId = Craft::$app->getRequest()->getRequiredBodyParam('logId');

        $log = LogRecord::findOne($logId);
        if ($log && $log->delete()) {
            return $this->asJson(['success' => true]);
        }

        return $this->asJson(['success' => false, 'error' => 'Could not delete log']);
    }

    /**
     * Get date range from parameter
     *
     * @param string $dateRange Date range parameter
     * @return array{start: \DateTime, end: \DateTime}
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

        return ['start' => $startDate, 'end' => $endDate];
    }
}
