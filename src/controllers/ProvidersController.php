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

        return $this->renderTemplate('sms-manager/providers/index', [
            'providers' => $providers,
            'settings' => $settings,
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

        return $this->renderTemplate('sms-manager/providers/edit', [
            'provider' => $provider,
            'providerSettings' => $providerSettings,
            'providerTypes' => $providerTypes,
            'countryOptions' => $countryOptions,
            'isNew' => $provider === null,
            'providerCount' => $providerCount,
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
        $provider->isDefault = (bool)$request->getBodyParam('isDefault', false);
        $provider->sortOrder = (int)$request->getBodyParam('sortOrder', 0);

        // Set provider-specific settings
        $providerSettings = $request->getBodyParam('providerSettings', []);
        $provider->settings = json_encode($providerSettings) ?: '{}';

        if (SmsManager::$plugin->providers->saveProvider($provider)) {
            Craft::$app->getSession()->setNotice(Craft::t('sms-manager', 'Provider saved.'));
            return $this->redirectToPostedUrl($provider);
        }

        Craft::$app->getSession()->setError(Craft::t('sms-manager', 'Could not save provider.'));

        // Re-render edit form with submitted data
        $providerTypes = SmsManager::$plugin->providers->getProviderTypeOptions();
        $countryOptions = GeoHelper::getCountryDialCodeOptions(true);
        $providerCount = ProviderRecord::find()->count();

        return $this->renderTemplate('sms-manager/providers/edit', [
            'provider' => $provider,
            'providerSettings' => $providerSettings,
            'providerTypes' => $providerTypes,
            'countryOptions' => $countryOptions,
            'isNew' => !$providerId,
            'providerCount' => $providerCount,
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

        return $this->asJson($result);
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

        $provider = ProviderRecord::findOne($providerId);
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

        foreach ($providerIds as $id) {
            $provider = ProviderRecord::findOne($id);
            if ($provider) {
                $provider->enabled = true;
                if ($provider->save(false)) {
                    $count++;
                }
            }
        }

        return $this->asJson(['success' => true, 'count' => $count]);
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
        $count = 0;
        $errors = [];

        foreach ($providerIds as $id) {
            $provider = ProviderRecord::findOne($id);
            if ($provider) {
                // Cannot disable default provider
                if ($provider->isDefault) {
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
