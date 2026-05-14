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

        // Build provider options and track which have dev API keys. All
        // option values and lookup keys are handles (not ids) so config-only
        // providers — which have id=null — survive the round-trip through
        // the form back to the controller.
        $providerOptions = [];
        $providersWithDevKey = [];
        $providerApiKeys = [];
        $providerAllowedCountries = [];
        $defaultProviderHandle = $settings->defaultProviderHandle;
        foreach ($providers as $provider) {
            $isDefault = $provider->handle === $defaultProviderHandle;
            $providerOptions[] = [
                'label' => $provider->name . ($isDefault ? ' (Default)' : ''),
                'value' => $provider->handle,
            ];
            $providerSettings = $provider->getSettingsArray();
            $mainKey = App::parseEnv($providerSettings['apiKey'] ?? '');
            $devKey = App::parseEnv($providerSettings['devApiKey'] ?? '');
            $providersWithDevKey[$provider->handle] = !empty($devKey);
            $providerApiKeys[$provider->handle] = [
                'main' => $this->maskApiKey($mainKey),
                'dev' => $this->maskApiKey($devKey),
            ];
            $allowedCountries = $providerSettings['allowedCountries'] ?? [];
            if (in_array('*', $allowedCountries, true) || empty($allowedCountries)) {
                $providerAllowedCountries[$provider->handle] = ['*'];
            } else {
                $providerAllowedCountries[$provider->handle] = $allowedCountries;
            }
        }

        // Build sender ID options (initially for the default provider).
        $senderIdOptions = [];
        $senderIdsByProvider = [];
        $defaultSenderIdHandle = $settings->defaultSenderIdHandle;

        // Pick the initial provider handle for the UI dropdown's pre-selection.
        $initialProviderHandle = null;
        $defaultProvider = $plugin->providers->getDefaultProvider();
        if ($defaultProvider) {
            $initialProviderHandle = $defaultProvider->handle;
        } elseif (!empty($providers)) {
            $initialProviderHandle = $providers[0]->handle;
        }

        foreach ($providers as $provider) {
            $senderIdsByProvider[$provider->handle] = [];
            foreach ($senderIds as $senderId) {
                if ($senderId->providerHandle === $provider->handle) {
                    $senderIdsByProvider[$provider->handle][] = [
                        'handle' => $senderId->handle,
                        'name' => $senderId->name,
                        'senderId' => $senderId->senderId,
                        'isDefault' => $senderId->handle === $defaultSenderIdHandle,
                        'isDev' => $senderId->isDev,
                    ];
                }
            }
        }

        // Build initial sender ID options for the pre-selected provider.
        $initialSenderIdHandle = null;
        if ($initialProviderHandle !== null && isset($senderIdsByProvider[$initialProviderHandle])) {
            foreach ($senderIdsByProvider[$initialProviderHandle] as $senderId) {
                $label = $senderId['name'];
                if ($senderId['isDefault']) {
                    $label .= ' (Default)';
                    $initialSenderIdHandle = $senderId['handle'];
                }
                if ($senderId['isDev']) {
                    $label .= ' [Dev]';
                }
                $senderIdOptions[] = [
                    'label' => $label,
                    'value' => $senderId['handle'],
                ];
            }
        }

        // Strings used by Craft.t() in the inline JS country-not-allowed handler.
        Craft::$app->getView()->registerTranslations('sms-manager', [
            'The pasted number is from {country}, which is not allowed by this provider.',
        ]);

        return $this->renderTemplate('sms-manager/settings/test', [
            'settings' => $settings,
            'providers' => $providers,
            'senderIds' => $senderIds,
            'providerOptions' => $providerOptions,
            'senderIdOptions' => $senderIdOptions,
            'senderIdsByProvider' => $senderIdsByProvider,
            'providersWithDevKey' => $providersWithDevKey,
            'providerApiKeys' => $providerApiKeys,
            'providerAllowedCountries' => $providerAllowedCountries,
            'initialProviderHandle' => $initialProviderHandle,
            'initialSenderIdHandle' => $initialSenderIdHandle,
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

        $senderIdHandle = (string) $request->getRequiredBodyParam('senderIdHandle');
        $recipient = (string) $request->getRequiredBodyParam('recipient');
        $message = (string) $request->getRequiredBodyParam('message');
        $language = (string) $request->getBodyParam('language', 'en');

        // Route via handle so config-only senders dispatch to themselves
        // instead of silently falling through to the default sender — the
        // form's option values are handles, so this is the only marshaling
        // that round-trips correctly for both DB and config-backed records.
        $result = SmsManager::$plugin->sms->sendWithHandleDetails(
            $recipient,
            $message,
            $senderIdHandle,
            $language,
            'sms-manager-test',
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
        $section = $this->_validSettingsSection(
            Craft::$app->getRequest()->getBodyParam('section', 'general'),
        );

        // Fields that should be cast to int (nullable)
        $nullableIntFields = ['defaultProviderId', 'defaultSenderIdId'];

        // Fields that should be cast to int (required)
        $intFields = ['analyticsLimit', 'analyticsRetention', 'smsLogsLimit', 'smsLogsRetention', 'itemsPerPage', 'refreshIntervalSecs'];

        // Fields that should be cast to bool
        $boolFields = ['enableAnalytics', 'autoTrimAnalytics', 'enableSmsLogs', 'autoTrimSmsLogs'];

        // Update settings with posted values
        foreach ($postedSettings as $key => $value) {
            if (property_exists($settings, $key) && !$settings->isOverriddenByConfig($key)) {
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

        // Validate only fields belonging to the current section.
        $attributesToValidate = $this->_validationAttributesForSection($section);
        $attributesToValidate = array_values(array_filter(
            $attributesToValidate,
            fn(string $attribute): bool => !$settings->isOverriddenByConfig($attribute),
        ));

        if (!$settings->validate($attributesToValidate)) {
            Craft::$app->getSession()->setError(Craft::t('sms-manager', 'Couldn\'t save settings.'));

            return $this->renderTemplate('sms-manager/settings/' . $section, [
                'settings' => $settings,
            ]);
        }

        // Save to database (same scoped attributes)
        if (!$settings->saveToDatabase($attributesToValidate)) {
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

    /**
     * Validate and sanitize the settings section parameter
     *
     * @param string $section The section from POST data
     * @return string A validated section name
     */
    private function _validSettingsSection(string $section): string
    {
        $allowed = ['general', 'analytics', 'interface', 'test'];

        return in_array($section, $allowed, true) ? $section : 'general';
    }

    /**
     * Get validation attributes for a settings section.
     */
    private function _validationAttributesForSection(string $section): array
    {
        return match ($section) {
            'general' => [
                'pluginName',
                'defaultProviderId',
                'defaultSenderIdId',
                'defaultProviderHandle',
                'defaultSenderIdHandle',
                'logLevel',
            ],
            'analytics' => [
                'enableAnalytics',
                'analyticsLimit',
                'analyticsRetention',
                'autoTrimAnalytics',
                'enableSmsLogs',
                'smsLogsLimit',
                'smsLogsRetention',
                'autoTrimSmsLogs',
            ],
            'interface' => [
                'itemsPerPage',
                'refreshIntervalSecs',
            ],
            default => [],
        };
    }
}
