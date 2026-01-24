<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\controllers;

use craft\web\Controller;
use yii\web\Response;

/**
 * Dashboard Controller
 *
 * Contains utility pages like the badges test page.
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
class DashboardController extends Controller
{
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
