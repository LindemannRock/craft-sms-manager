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
        $periodFilter = $request->getQueryParam('period', 'all');
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

        // Apply period filter
        if ($periodFilter !== 'all' && is_numeric($periodFilter)) {
            $date = (new \DateTime())->modify("-{$periodFilter} days")->format('Y-m-d H:i:s');
            $query->andWhere(['>=', 'dateCreated', $date]);
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

        return $this->renderTemplate('sms-manager/sms-logs/index', [
            'logs' => $logs,
            'settings' => $settings,
            'providers' => $providers,
            'totalCount' => $totalCount,
            'totalPages' => $totalPages,
            'page' => $page,
            'limit' => $limit,
            'offset' => $offset,
            'search' => $search,
            'statusFilter' => $statusFilter,
            'providerFilter' => $providerFilter,
            'languageFilter' => $languageFilter,
            'periodFilter' => $periodFilter,
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

        $period = $request->getQueryParam('period', '30');
        $format = $request->getQueryParam('format', 'csv');

        $endDate = new \DateTime();
        $startDate = (clone $endDate)->modify("-{$period} days");

        $logs = (new Query())
            ->from(LogRecord::tableName())
            ->where(['>=', 'dateCreated', $startDate->format('Y-m-d H:i:s')])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();

        // Enrich with provider/sender names
        foreach ($logs as &$log) {
            $provider = ProviderRecord::findOne($log['providerId']);
            $senderId = SenderIdRecord::findOne($log['senderIdId']);
            $log['providerName'] = $provider ? $provider->name : 'Unknown';
            $log['senderIdName'] = $senderId ? $senderId->name : 'Unknown';
        }

        if ($format === 'csv') {
            return $this->exportCsv($logs);
        }

        return $this->asJson($logs);
    }

    /**
     * Export logs as CSV
     *
     * @param array $logs
     * @return Response
     */
    private function exportCsv(array $logs): Response
    {
        $settings = SmsManager::$plugin->getSettings();
        $filenamePart = strtolower(str_replace(' ', '-', $settings->getPluralLowerDisplayName()));
        $filename = $filenamePart . '-logs-' . date('Y-m-d-His') . '.csv';

        $headers = [
            'Date',
            'Recipient',
            'Message',
            'Language',
            'Status',
            'Provider',
            'Sender ID',
            'Message ID',
            'Error',
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
                $log['providerMessageId'],
                $log['errorMessage'],
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
}
