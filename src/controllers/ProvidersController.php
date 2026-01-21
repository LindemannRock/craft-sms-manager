<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\controllers;

use Craft;
use craft\helpers\StringHelper;
use craft\web\Controller;
use lindemannrock\base\helpers\GeoHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\SmsManager;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Providers Controller
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
class ProvidersController extends Controller
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
     * List all providers
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('smsManager:viewProviders');

        $settings = SmsManager::$plugin->getSettings();
        $providers = SmsManager::$plugin->providers->getAllProviders();
        $isDefaultFromConfig = SmsManager::$plugin->providers->isDefaultProviderFromConfig();

        // Auto-assign default if needed (only if not set via config file)
        if (!$isDefaultFromConfig) {
            $defaultHandle = $settings->defaultProviderHandle;
            $needsReassign = false;

            if (empty($defaultHandle)) {
                $needsReassign = true;
            } else {
                $defaultProvider = SmsManager::$plugin->providers->getProviderByHandle($defaultHandle);
                if (!$defaultProvider || !$defaultProvider->enabled) {
                    $needsReassign = true;
                }
            }

            if ($needsReassign && !empty($providers)) {
                foreach ($providers as $provider) {
                    if ($provider->enabled) {
                        $settings->defaultProviderHandle = $provider->handle;
                        $settings->saveToDatabase();

                        $this->logInfo('Auto-assigned default provider', [
                            'handle' => $provider->handle,
                            'reason' => empty($defaultHandle) ? 'no default set' : 'previous default invalid',
                        ]);
                        break;
                    }
                }
            }
        }

        return $this->renderTemplate('sms-manager/providers/index', [
            'providers' => $providers,
            'settings' => $settings,
            'defaultProviderHandle' => $settings->defaultProviderHandle,
            'isDefaultFromConfig' => $isDefaultFromConfig,
        ]);
    }

    /**
     * View a provider (read-only, works for both config and database providers)
     *
     * @param string|null $handle Provider handle
     * @return Response
     */
    public function actionView(?string $handle = null): Response
    {
        $this->requirePermission('smsManager:viewProviders');

        if (!$handle) {
            throw new NotFoundHttpException('Provider handle required');
        }

        $provider = ProviderRecord::findByHandleWithConfig($handle);

        if (!$provider) {
            throw new NotFoundHttpException('Provider not found');
        }

        $providerSettings = $provider->getSettingsArray();
        $providerTypes = SmsManager::$plugin->providers->getProviderTypeOptions();
        $countryOptions = GeoHelper::getCountryDialCodeOptions(true);
        $settings = SmsManager::$plugin->getSettings();
        $providerCount = ProviderRecord::find()->count();

        return $this->renderTemplate('sms-manager/providers/edit', [
            'provider' => $provider,
            'providerSettings' => $providerSettings,
            'providerTypes' => $providerTypes,
            'countryOptions' => $countryOptions,
            'isNew' => false,
            'providerCount' => $providerCount,
            'defaultProviderHandle' => $settings->defaultProviderHandle,
            'isDefaultFromConfig' => SmsManager::$plugin->providers->isDefaultProviderFromConfig(),
        ]);
    }

    /**
     * Edit a provider
     *
     * @param int|null $providerId
     * @return Response
     */
    public function actionEdit(?int $providerId = null): Response
    {
        $this->requirePermission($providerId ? 'smsManager:editProviders' : 'smsManager:createProviders');

        $provider = null;
        $providerSettings = [];

        if ($providerId) {
            $provider = ProviderRecord::findOne($providerId);

            if (!$provider) {
                throw new NotFoundHttpException('Provider not found');
            }

            $providerSettings = $provider->getSettingsArray();
        }

        $providerTypes = SmsManager::$plugin->providers->getProviderTypeOptions();
        $countryOptions = GeoHelper::getCountryDialCodeOptions(true);
        $providerCount = ProviderRecord::find()->count();
        $settings = SmsManager::$plugin->getSettings();

        return $this->renderTemplate('sms-manager/providers/edit', [
            'provider' => $provider,
            'providerSettings' => $providerSettings,
            'providerTypes' => $providerTypes,
            'countryOptions' => $countryOptions,
            'isNew' => $provider === null,
            'providerCount' => $providerCount,
            'defaultProviderHandle' => $settings->defaultProviderHandle,
            'isDefaultFromConfig' => SmsManager::$plugin->providers->isDefaultProviderFromConfig(),
        ]);
    }

    /**
     * Save a provider
     *
     * @return Response|null
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $providerId = $request->getBodyParam('providerId');

        $this->requirePermission($providerId ? 'smsManager:editProviders' : 'smsManager:createProviders');

        $provider = $providerId ? ProviderRecord::findOne($providerId) : new ProviderRecord();

        if ($providerId && !$provider) {
            throw new NotFoundHttpException('Provider not found');
        }

        // Set basic attributes
        $provider->name = $request->getBodyParam('name');
        $provider->handle = $request->getBodyParam('handle') ?: StringHelper::toHandle($provider->name);
        $provider->type = $request->getBodyParam('type');
        $provider->enabled = (bool)$request->getBodyParam('enabled', true);
        $provider->sortOrder = (int)$request->getBodyParam('sortOrder', 0);

        // Handle isDefault via settings, not on the record
        $setAsDefault = (bool)$request->getBodyParam('isDefault', false);

        // Set provider-specific settings
        $providerSettings = $request->getBodyParam('providerSettings', []);
        $provider->settings = json_encode($providerSettings) ?: '{}';

        if (SmsManager::$plugin->providers->saveProvider($provider)) {
            // Set as default if requested (and not controlled by config)
            if ($setAsDefault && !SmsManager::$plugin->providers->isDefaultProviderFromConfig()) {
                SmsManager::$plugin->providers->setDefaultProviderByHandle($provider->handle);
            }
            Craft::$app->getSession()->setNotice(Craft::t('sms-manager', 'Provider saved.'));
            return $this->redirectToPostedUrl($provider);
        }

        Craft::$app->getSession()->setError(Craft::t('sms-manager', 'Could not save provider.'));

        // Re-render edit form with submitted data
        $providerTypes = SmsManager::$plugin->providers->getProviderTypeOptions();
        $countryOptions = GeoHelper::getCountryDialCodeOptions(true);
        $providerCount = ProviderRecord::find()->count();
        $settings = SmsManager::$plugin->getSettings();

        return $this->renderTemplate('sms-manager/providers/edit', [
            'provider' => $provider,
            'providerSettings' => $providerSettings,
            'providerTypes' => $providerTypes,
            'countryOptions' => $countryOptions,
            'isNew' => !$providerId,
            'providerCount' => $providerCount,
            'defaultProviderHandle' => $settings->defaultProviderHandle,
            'isDefaultFromConfig' => SmsManager::$plugin->providers->isDefaultProviderFromConfig(),
        ]);
    }

    /**
     * Delete a provider
     *
     * @return Response
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('smsManager:deleteProviders');

        $providerId = Craft::$app->getRequest()->getRequiredBodyParam('providerId');

        $result = SmsManager::$plugin->providers->deleteProvider($providerId);

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asJson($result);
        }

        if ($result['success']) {
            Craft::$app->getSession()->setNotice(Craft::t('sms-manager', 'Provider deleted.'));
        } else {
            Craft::$app->getSession()->setError($result['error'] ?? Craft::t('sms-manager', 'Could not delete provider.'));
        }

        return $this->redirect('sms-manager/providers');
    }

    /**
     * Toggle provider enabled status
     *
     * @return Response
     */
    public function actionToggleEnabled(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('smsManager:editProviders');

        $request = Craft::$app->getRequest();
        $providerId = $request->getRequiredBodyParam('providerId');
        $enabled = (bool)$request->getRequiredBodyParam('enabled');

        $provider = ProviderRecord::findOne($providerId);
        if (!$provider) {
            return $this->asJson(['success' => false, 'error' => 'Provider not found']);
        }

        // Cannot toggle config providers
        if ($provider->isFromConfig()) {
            return $this->asJson(['success' => false, 'error' => Craft::t('sms-manager', 'Cannot modify config-based provider.')]);
        }

        $provider->enabled = $enabled;
        if ($provider->save(false)) {
            return $this->asJson(['success' => true]);
        }

        return $this->asJson(['success' => false, 'error' => 'Could not update provider']);
    }

    /**
     * Test provider connection
     *
     * @return Response
     */
    public function actionTestConnection(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('smsManager:viewProviders');

        $request = Craft::$app->getRequest();
        $providerId = $request->getRequiredBodyParam('providerId');

        // Try by ID first, then by handle (for config providers)
        if (is_numeric($providerId)) {
            $provider = ProviderRecord::findOne((int)$providerId);
        } else {
            $provider = ProviderRecord::findByHandleWithConfig((string)$providerId);
        }

        if (!$provider) {
            return $this->asJson(['success' => false, 'error' => 'Provider not found']);
        }

        $providerInstance = SmsManager::$plugin->providers->createProviderByType($provider->type);
        if (!$providerInstance) {
            return $this->asJson(['success' => false, 'error' => 'Unknown provider type']);
        }

        $result = $providerInstance->testConnection($provider->getSettingsArray());

        return $this->asJson($result);
    }

    /**
     * Set a provider as the default
     *
     * @return Response
     */
    public function actionSetDefault(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('smsManager:editProviders');
        $this->requireAcceptsJson();

        // Check if default is set via config
        if (SmsManager::$plugin->providers->isDefaultProviderFromConfig()) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('sms-manager', 'Default provider is set via config file and cannot be changed here.'),
            ]);
        }

        $providerId = Craft::$app->getRequest()->getBodyParam('providerId');

        // Find the provider - try by ID first, then by handle
        if (is_numeric($providerId)) {
            $provider = ProviderRecord::findOne((int)$providerId);
        } else {
            $provider = ProviderRecord::findByHandleWithConfig((string)$providerId);
        }

        if (!$provider) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('sms-manager', 'Provider not found.'),
            ]);
        }

        if (SmsManager::$plugin->providers->setDefaultProviderByHandle($provider->handle)) {
            $this->logInfo('Default provider changed', [
                'handle' => $provider->handle,
                'name' => $provider->name,
            ]);

            return $this->asJson([
                'success' => true,
                'message' => Craft::t('sms-manager', 'Default provider updated.'),
            ]);
        }

        return $this->asJson([
            'success' => false,
            'error' => Craft::t('sms-manager', 'Failed to update default provider.'),
        ]);
    }

    /**
     * Bulk enable providers
     *
     * @return Response
     */
    public function actionBulkEnable(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('smsManager:editProviders');

        $providerIds = Craft::$app->getRequest()->getRequiredBodyParam('providerIds');
        $count = 0;
        $errors = [];

        foreach ($providerIds as $id) {
            $provider = ProviderRecord::findOne($id);
            if ($provider) {
                // Cannot modify config providers
                if ($provider->isFromConfig()) {
                    $errors[] = Craft::t('sms-manager', 'Cannot modify config-based provider "{name}".', ['name' => $provider->name]);
                    continue;
                }
                $provider->enabled = true;
                if ($provider->save(false)) {
                    $count++;
                }
            }
        }

        if ($count > 0 && empty($errors)) {
            return $this->asJson(['success' => true, 'count' => $count]);
        }

        if ($count > 0) {
            return $this->asJson(['success' => true, 'count' => $count, 'errors' => $errors]);
        }

        return $this->asJson(['success' => false, 'errors' => $errors]);
    }

    /**
     * Bulk disable providers
     *
     * @return Response
     */
    public function actionBulkDisable(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('smsManager:editProviders');

        $providerIds = Craft::$app->getRequest()->getRequiredBodyParam('providerIds');
        $settings = SmsManager::$plugin->getSettings();
        $count = 0;
        $errors = [];

        foreach ($providerIds as $id) {
            $provider = ProviderRecord::findOne($id);
            if ($provider) {
                // Cannot modify config providers
                if ($provider->isFromConfig()) {
                    $errors[] = Craft::t('sms-manager', 'Cannot modify config-based provider "{name}".', ['name' => $provider->name]);
                    continue;
                }
                // Cannot disable default provider
                if ($provider->handle === $settings->defaultProviderHandle) {
                    $errors[] = Craft::t('sms-manager', 'Cannot disable default provider "{name}".', ['name' => $provider->name]);
                    continue;
                }
                $provider->enabled = false;
                if ($provider->save(false)) {
                    $count++;
                }
            }
        }

        if ($count > 0 && empty($errors)) {
            return $this->asJson(['success' => true, 'count' => $count]);
        }

        if ($count > 0) {
            return $this->asJson(['success' => true, 'count' => $count, 'errors' => $errors]);
        }

        return $this->asJson(['success' => false, 'errors' => $errors]);
    }

    /**
     * Bulk delete providers
     *
     * @return Response
     */
    public function actionBulkDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('smsManager:deleteProviders');

        $providerIds = Craft::$app->getRequest()->getRequiredBodyParam('providerIds');
        $count = 0;
        $errors = [];

        foreach ($providerIds as $id) {
            $result = SmsManager::$plugin->providers->deleteProvider($id);
            if ($result['success']) {
                $count++;
            } else {
                $errors[] = $result['error'];
            }
        }

        if ($count > 0 && empty($errors)) {
            return $this->asJson(['success' => true, 'count' => $count]);
        }

        if ($count > 0) {
            return $this->asJson(['success' => true, 'count' => $count, 'errors' => $errors]);
        }

        return $this->asJson(['success' => false, 'error' => implode(' ', $errors)]);
    }
}
