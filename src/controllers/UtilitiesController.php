<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use yii\web\Response;

/**
 * Utilities Controller
 *
 * Handles utility actions like clearing analytics data
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
class UtilitiesController extends Controller
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
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Permission checks based on action
        switch ($action->id) {
            case 'clear-all-analytics':
                $this->requirePermission('smsManager:clearAnalytics');
                break;
            case 'clear-all-logs':
                $this->requirePermission('smsManager:deleteLogs');
                break;
        }

        return true;
    }

    /**
     * Clear all analytics data
     *
     * @return Response
     * @since 5.0.0
     */
    public function actionClearAllAnalytics(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            // Delete all analytics data
            $rowCount = Craft::$app->getDb()
                ->createCommand()
                ->delete('{{%smsmanager_analytics}}')
                ->execute();

            $this->logInfo('All analytics data cleared via utility', [
                'rowsDeleted' => $rowCount,
            ]);

            return $this->asJson([
                'success' => true,
                'message' => Craft::t('sms-manager', 'All analytics data cleared successfully ({count} records deleted).', [
                    'count' => $rowCount,
                ]),
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to clear analytics data', [
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('sms-manager', 'Failed to clear analytics data.'),
            ]);
        }
    }

    /**
     * Clear all logs data
     *
     * @return Response
     * @since 5.0.0
     */
    public function actionClearAllLogs(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            // Delete all logs data
            $rowCount = Craft::$app->getDb()
                ->createCommand()
                ->delete('{{%smsmanager_logs}}')
                ->execute();

            $this->logInfo('All SMS logs cleared via utility', [
                'rowsDeleted' => $rowCount,
            ]);

            return $this->asJson([
                'success' => true,
                'message' => Craft::t('sms-manager', 'All SMS logs cleared successfully ({count} records deleted).', [
                    'count' => $rowCount,
                ]),
            ]);
        } catch (\Throwable $e) {
            $this->logError('Failed to clear SMS logs', [
                'error' => $e->getMessage(),
            ]);

            return $this->asJson([
                'success' => false,
                'error' => Craft::t('sms-manager', 'Failed to clear SMS logs.'),
            ]);
        }
    }
}
