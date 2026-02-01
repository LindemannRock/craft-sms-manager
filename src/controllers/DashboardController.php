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
use lindemannrock\base\helpers\CpNavHelper;
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

        // For now, redirect to SMS logs until dashboard template is created
        // TODO: Replace with renderTemplate('sms-manager/dashboard/index', [...]) when dashboard is ready
        return $this->redirect('sms-manager/logs/sms');
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
