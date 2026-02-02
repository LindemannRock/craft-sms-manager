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
     * If user doesn't have permission for dashboard, redirect to first accessible section.
     *
     * @return Response
     * @since 5.0.0
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
        $page = max(1, (int) $request->getQueryParam('page', 1));
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

        // Apply date range filter
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
        $totalPages = $totalCount > 0 ? (int) ceil($totalCount / $limit) : 1;

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
     * Badges test page - displays all ColorHelper color sets
     *
     * @return Response
     * @since 5.6.0
     */
    public function actionBadges(): Response
    {
        return $this->renderTemplate('sms-manager/badges');
    }
}
