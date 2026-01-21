<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\controllers;

use Craft;
use craft\helpers\App;
use craft\web\Controller;
use lindemannrock\base\helpers\GeoHelper;
use lindemannrock\smsmanager\SmsManager;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Settings Controller
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
class SettingsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        // Require permission
        $this->requirePermission('smsManager:manageSettings');

        return parent::beforeAction($action);
    }

    /**
     * Settings index - redirects to general
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        return $this->redirect('sms-manager/settings/general');
    }

    /**
     * General settings
     *
     * @return Response
     */
    public function actionGeneral(): Response
    {
        $settings = SmsManager::$plugin->getSettings();

        return $this->renderTemplate('sms-manager/settings/general', [
            'settings' => $settings,
        ]);
    }

    /**
     * Analytics settings
     *
     * @return Response
     */
    public function actionAnalytics(): Response
    {
        $settings = SmsManager::$plugin->getSettings();

        return $this->renderTemplate('sms-manager/settings/analytics', [
            'settings' => $settings,
        ]);
    }

    /**
     * Interface settings
     *
     * @return Response
     */
    public function actionInterface(): Response
    {
        $settings = SmsManager::$plugin->getSettings();

        return $this->renderTemplate('sms-manager/settings/interface', [
            'settings' => $settings,
        ]);
    }

    /**
     * Test page
     *
     * @return Response
     */
    public function actionTest(): Response
    {
        $plugin = SmsManager::$plugin;
        $settings = $plugin->getSettings();
        $providers = $plugin->providers->getAllProviders(true);
        $senderIds = $plugin->senderIds->getAllSenderIds(true);

        // Build provider options and track which have test API keys
        $providerOptions = [];
        $providersWithTestKey = [];
        $providerApiKeys = [];
        $providerAllowedCountries = [];
        foreach ($providers as $provider) {
            $providerOptions[] = [
                'label' => $provider->name . ($provider->isDefault ? ' (Default)' : ''),
                'value' => $provider->id,
            ];
            // Check if provider has a test API key configured and get masked keys
            $providerSettings = $provider->getSettingsArray();
            $mainKey = App::parseEnv($providerSettings['apiKey'] ?? '');
            $testKey = App::parseEnv($providerSettings['testApiKey'] ?? '');
            $providersWithTestKey[$provider->id] = !empty($testKey);
            $providerApiKeys[$provider->id] = [
                'main' => $this->maskApiKey($mainKey),
                'test' => $this->maskApiKey($testKey),
            ];
            // Get allowed countries for this provider
            $allowedCountries = $providerSettings['allowedCountries'] ?? [];
            if (in_array('*', $allowedCountries, true)) {
                $providerAllowedCountries[$provider->id] = ['All Countries'];
            } else {
                $providerAllowedCountries[$provider->id] = array_map(
                    fn($code) => GeoHelper::getCountryWithDialCode($code),
                    $allowedCountries
                );
            }
        }

        // Build sender ID options (initially for first/default provider)
        $senderIdOptions = [];
        $senderIdsByProvider = [];
        $defaultProviderId = null;
        $defaultSenderIdId = null;

        // Get default provider
        $defaultProvider = $plugin->providers->getDefaultProvider();
        if ($defaultProvider) {
            $defaultProviderId = $defaultProvider->id;
        } elseif (!empty($providers)) {
            $defaultProviderId = $providers[0]->id;
        }

        // Group sender IDs by provider for JS
        foreach ($providers as $provider) {
            $senderIdsByProvider[$provider->id] = [];
            foreach ($senderIds as $senderId) {
                if ($senderId->providerId === $provider->id) {
                    $senderIdsByProvider[$provider->id][] = [
                        'id' => $senderId->id,
                        'name' => $senderId->name,
                        'senderId' => $senderId->senderId,
                        'isDefault' => $senderId->isDefault,
                        'isTest' => $senderId->isTest,
                    ];
                }
            }
        }

        // Build initial sender ID options for the default provider
        if ($defaultProviderId && isset($senderIdsByProvider[$defaultProviderId])) {
            foreach ($senderIdsByProvider[$defaultProviderId] as $senderId) {
                $label = $senderId['name'];
                if ($senderId['isDefault']) {
                    $label .= ' (Default)';
                    $defaultSenderIdId = $senderId['id'];
                }
                if ($senderId['isTest']) {
                    $label .= ' [Test]';
                }
                $senderIdOptions[] = [
                    'label' => $label,
                    'value' => $senderId['id'],
                ];
            }
        }

        return $this->renderTemplate('sms-manager/settings/test', [
            'settings' => $settings,
            'providers' => $providers,
            'senderIds' => $senderIds,
            'providerOptions' => $providerOptions,
            'senderIdOptions' => $senderIdOptions,
            'senderIdsByProvider' => $senderIdsByProvider,
            'providersWithTestKey' => $providersWithTestKey,
            'providerApiKeys' => $providerApiKeys,
            'providerAllowedCountries' => $providerAllowedCountries,
            'defaultProviderId' => $defaultProviderId,
            'defaultSenderIdId' => $defaultSenderIdId,
        ]);
    }

    /**
     * Test SMS sending (AJAX)
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionTestSms(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();

        $providerId = (int)$request->getRequiredBodyParam('providerId');
        $senderIdId = (int)$request->getRequiredBodyParam('senderIdId');
        $recipient = $request->getRequiredBodyParam('recipient');
        $message = $request->getRequiredBodyParam('message');
        $language = $request->getBodyParam('language', 'en');

        // Send via SMS service with source tracking (logs to analytics and logs)
        $result = SmsManager::$plugin->sms->sendWithDetails(
            $recipient,
            $message,
            $language,
            $providerId,
            $senderIdId,
            'sms-manager-test' // Source plugin for filtering in analytics
        );

        return $this->asJson($result);
    }

    /**
     * Save settings
     *
     * @return Response|null
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $settings = SmsManager::$plugin->getSettings();
        $postedSettings = Craft::$app->getRequest()->getBodyParam('settings', []);
        $section = Craft::$app->getRequest()->getBodyParam('section', 'general');

        // Fields that should be cast to int (nullable)
        $nullableIntFields = ['defaultProviderId', 'defaultSenderIdId'];

        // Fields that should be cast to int (required)
        $intFields = ['analyticsLimit', 'analyticsRetention', 'logsLimit', 'logsRetention', 'itemsPerPage', 'refreshIntervalSecs'];

        // Fields that should be cast to bool
        $boolFields = ['enableAnalytics', 'autoTrimAnalytics', 'enableLogs', 'autoTrimLogs'];

        // Update settings with posted values
        foreach ($postedSettings as $key => $value) {
            if (property_exists($settings, $key)) {
                // Cast to appropriate type
                if (in_array($key, $nullableIntFields, true)) {
                    $settings->$key = $value !== '' && $value !== null ? (int)$value : null;
                } elseif (in_array($key, $intFields, true)) {
                    $settings->$key = (int)$value;
                } elseif (in_array($key, $boolFields, true)) {
                    $settings->$key = (bool)$value;
                } else {
                    $settings->$key = $value;
                }
            }
        }

        // Validate
        if (!$settings->validate()) {
            Craft::$app->getSession()->setError(Craft::t('sms-manager', 'Couldn\'t save settings.'));

            return $this->renderTemplate('sms-manager/settings/' . $section, [
                'settings' => $settings,
            ]);
        }

        // Save to database
        if (!$settings->saveToDatabase()) {
            Craft::$app->getSession()->setError(Craft::t('sms-manager', 'Couldn\'t save settings.'));

            return $this->renderTemplate('sms-manager/settings/' . $section, [
                'settings' => $settings,
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('sms-manager', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Mask an API key for display (show first 8 chars + XXX)
     */
    private function maskApiKey(string $key): string
    {
        if (empty($key)) {
            return '';
        }

        $visibleLength = min(8, strlen($key));
        return substr($key, 0, $visibleLength) . 'XXX';
    }
}
