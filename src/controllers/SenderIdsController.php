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
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\smsmanager\records\SenderIdRecord;
use lindemannrock\smsmanager\SmsManager;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Sender IDs Controller
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
class SenderIdsController extends Controller
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
     * List all sender IDs
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('smsManager:viewSenderIds');

        $request = Craft::$app->getRequest();
        $settings = SmsManager::$plugin->getSettings();
        $isDefaultFromConfig = SmsManager::$plugin->senderIds->isDefaultSenderIdFromConfig();

        // Get filter parameters
        $providerFilter = $request->getQueryParam('provider', 'all');

        $senderIds = SmsManager::$plugin->senderIds->getAllSenderIds();
        $providers = SmsManager::$plugin->providers->getAllProviders();

        // Auto-assign default if needed (only if not set via config file)
        if (!$isDefaultFromConfig) {
            $defaultHandle = $settings->defaultSenderIdHandle;
            $needsReassign = false;

            if (empty($defaultHandle)) {
                $needsReassign = true;
            } else {
                $defaultSenderId = SmsManager::$plugin->senderIds->getSenderIdByHandle($defaultHandle);
                if (!$defaultSenderId || !$defaultSenderId->enabled) {
                    $needsReassign = true;
                }
            }

            if ($needsReassign && !empty($senderIds)) {
                foreach ($senderIds as $senderId) {
                    if ($senderId->enabled) {
                        $settings->defaultSenderIdHandle = $senderId->handle;
                        $settings->saveToDatabase();

                        $this->logInfo('Auto-assigned default sender ID', [
                            'handle' => $senderId->handle,
                            'reason' => empty($defaultHandle) ? 'no default set' : 'previous default invalid',
                        ]);
                        break;
                    }
                }
            }
        }

        return $this->renderTemplate('sms-manager/senderids/index', [
            'senderIds' => $senderIds,
            'providers' => $providers,
            'settings' => $settings,
            'providerFilter' => $providerFilter,
            'defaultSenderIdHandle' => $settings->defaultSenderIdHandle,
            'isDefaultFromConfig' => $isDefaultFromConfig,
        ]);
    }

    /**
     * View a sender ID (read-only, works for both config and database sender IDs)
     *
     * @param string|null $handle Sender ID handle
     * @return Response
     */
    public function actionView(?string $handle = null): Response
    {
        $this->requirePermission('smsManager:viewSenderIds');

        if (!$handle) {
            throw new NotFoundHttpException('Sender ID handle required');
        }

        $senderId = SenderIdRecord::findByHandleWithConfig($handle);

        if (!$senderId) {
            throw new NotFoundHttpException('Sender ID not found');
        }

        $providers = SmsManager::$plugin->providers->getAllProviders(true);
        $providerOptions = [];
        foreach ($providers as $provider) {
            $providerOptions[] = [
                'label' => $provider->name,
                'value' => $provider->id,
            ];
        }
        $senderIdCount = SenderIdRecord::find()->count();
        $settings = SmsManager::$plugin->getSettings();

        return $this->renderTemplate('sms-manager/senderids/edit', [
            'senderId' => $senderId,
            'providerOptions' => $providerOptions,
            'isNew' => false,
            'senderIdCount' => $senderIdCount,
            'defaultSenderIdHandle' => $settings->defaultSenderIdHandle,
            'isDefaultFromConfig' => SmsManager::$plugin->senderIds->isDefaultSenderIdFromConfig(),
        ]);
    }

    /**
     * Edit a sender ID
     *
     * @param int|null $senderIdId
     * @return Response
     */
    public function actionEdit(?int $senderIdId = null): Response
    {
        $this->requirePermission($senderIdId ? 'smsManager:editSenderIds' : 'smsManager:createSenderIds');

        $senderId = null;

        if ($senderIdId) {
            $senderId = SenderIdRecord::findOne($senderIdId);

            if (!$senderId) {
                throw new NotFoundHttpException('Sender ID not found');
            }
        }

        $providers = SmsManager::$plugin->providers->getAllProviders(true);
        $providerOptions = [];
        foreach ($providers as $provider) {
            $providerOptions[] = [
                'label' => $provider->name,
                'value' => $provider->id,
            ];
        }
        $senderIdCount = SenderIdRecord::find()->count();
        $settings = SmsManager::$plugin->getSettings();

        return $this->renderTemplate('sms-manager/senderids/edit', [
            'senderId' => $senderId,
            'providerOptions' => $providerOptions,
            'isNew' => $senderId === null,
            'senderIdCount' => $senderIdCount,
            'defaultSenderIdHandle' => $settings->defaultSenderIdHandle,
            'isDefaultFromConfig' => SmsManager::$plugin->senderIds->isDefaultSenderIdFromConfig(),
        ]);
    }

    /**
     * Save a sender ID
     *
     * @return Response|null
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $senderIdId = $request->getBodyParam('senderIdId');

        $this->requirePermission($senderIdId ? 'smsManager:editSenderIds' : 'smsManager:createSenderIds');

        $senderId = $senderIdId ? SenderIdRecord::findOne($senderIdId) : new SenderIdRecord();

        if ($senderIdId && !$senderId) {
            throw new NotFoundHttpException('Sender ID not found');
        }

        // Set attributes
        $senderId->providerId = (int)$request->getBodyParam('providerId');
        $senderId->name = $request->getBodyParam('name');
        $senderId->handle = $request->getBodyParam('handle') ?: StringHelper::toHandle($senderId->name);
        $senderId->senderId = $request->getBodyParam('senderId');
        $senderId->description = $request->getBodyParam('description');
        $senderId->enabled = (bool)$request->getBodyParam('enabled', true);
        $senderId->isDefault = (bool)$request->getBodyParam('isDefault', false);
        $senderId->isTest = (bool)$request->getBodyParam('isTest', false);
        $senderId->sortOrder = (int)$request->getBodyParam('sortOrder', 0);

        if (SmsManager::$plugin->senderIds->saveSenderId($senderId)) {
            Craft::$app->getSession()->setNotice(Craft::t('sms-manager', 'Sender ID saved.'));
            return $this->redirectToPostedUrl($senderId);
        }

        Craft::$app->getSession()->setError(Craft::t('sms-manager', 'Could not save sender ID.'));

        // Re-render edit form with submitted data
        $providers = SmsManager::$plugin->providers->getAllProviders(true);
        $providerOptions = [];
        foreach ($providers as $provider) {
            $providerOptions[] = [
                'label' => $provider->name,
                'value' => $provider->id,
            ];
        }
        $senderIdCount = SenderIdRecord::find()->count();
        $settings = SmsManager::$plugin->getSettings();

        return $this->renderTemplate('sms-manager/senderids/edit', [
            'senderId' => $senderId,
            'providerOptions' => $providerOptions,
            'isNew' => !$senderIdId,
            'senderIdCount' => $senderIdCount,
            'defaultSenderIdHandle' => $settings->defaultSenderIdHandle,
            'isDefaultFromConfig' => SmsManager::$plugin->senderIds->isDefaultSenderIdFromConfig(),
        ]);
    }

    /**
     * Delete a sender ID
     *
     * @return Response
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('smsManager:deleteSenderIds');

        $senderIdId = Craft::$app->getRequest()->getRequiredBodyParam('senderIdId');

        $result = SmsManager::$plugin->senderIds->deleteSenderId($senderIdId);

        return $this->asJson($result);
    }

    /**
     * Toggle sender ID enabled status
     *
     * @return Response
     */
    public function actionToggleEnabled(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('smsManager:editSenderIds');

        $request = Craft::$app->getRequest();
        $senderIdId = $request->getRequiredBodyParam('senderIdId');
        $enabled = (bool)$request->getRequiredBodyParam('enabled');

        $senderId = SenderIdRecord::findOne($senderIdId);
        if (!$senderId) {
            return $this->asJson(['success' => false, 'error' => 'Sender ID not found']);
        }

        // Cannot toggle config sender IDs
        if ($senderId->isFromConfig()) {
            return $this->asJson(['success' => false, 'error' => Craft::t('sms-manager', 'Cannot modify config-based sender ID.')]);
        }

        $senderId->enabled = $enabled;
        if ($senderId->save(false)) {
            return $this->asJson(['success' => true]);
        }

        return $this->asJson(['success' => false, 'error' => 'Could not update sender ID']);
    }

    /**
     * Set a sender ID as the default
     *
     * @return Response
     */
    public function actionSetDefault(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('smsManager:editSenderIds');
        $this->requireAcceptsJson();

        // Check if default is set via config
        if (SmsManager::$plugin->senderIds->isDefaultSenderIdFromConfig()) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('sms-manager', 'Default sender ID is set via config file and cannot be changed here.'),
            ]);
        }

        $senderIdId = Craft::$app->getRequest()->getBodyParam('senderIdId');

        // Find the sender ID - try by ID first, then by handle
        if (is_numeric($senderIdId)) {
            $senderId = SenderIdRecord::findOne((int)$senderIdId);
        } else {
            $senderId = SenderIdRecord::findByHandleWithConfig((string)$senderIdId);
        }

        if (!$senderId) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('sms-manager', 'Sender ID not found.'),
            ]);
        }

        if (SmsManager::$plugin->senderIds->setDefaultSenderIdByHandle($senderId->handle)) {
            $this->logInfo('Default sender ID changed', [
                'handle' => $senderId->handle,
                'name' => $senderId->name,
            ]);

            return $this->asJson([
                'success' => true,
                'message' => Craft::t('sms-manager', 'Default sender ID updated.'),
            ]);
        }

        return $this->asJson([
            'success' => false,
            'error' => Craft::t('sms-manager', 'Failed to update default sender ID.'),
        ]);
    }

    /**
     * Get sender IDs for a provider (AJAX)
     *
     * @return Response
     */
    public function actionGetByProvider(): Response
    {
        $this->requireAcceptsJson();

        $providerId = Craft::$app->getRequest()->getQueryParam('providerId');

        if (!$providerId) {
            return $this->asJson(['senderIds' => []]);
        }

        $senderIds = SmsManager::$plugin->senderIds->getSenderIdsByProvider((int)$providerId, true);
        $options = [];

        foreach ($senderIds as $senderId) {
            $options[] = [
                'id' => $senderId->id,
                'name' => $senderId->name,
                'handle' => $senderId->handle,
                'senderId' => $senderId->senderId,
                'isDefault' => $senderId->isDefault,
            ];
        }

        return $this->asJson(['senderIds' => $options]);
    }

    /**
     * Bulk enable sender IDs
     *
     * @return Response
     */
    public function actionBulkEnable(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('smsManager:editSenderIds');

        $senderIdIds = Craft::$app->getRequest()->getRequiredBodyParam('senderIdIds');
        $count = 0;
        $errors = [];

        foreach ($senderIdIds as $id) {
            $senderId = SenderIdRecord::findOne($id);
            if ($senderId) {
                // Cannot modify config sender IDs
                if ($senderId->isFromConfig()) {
                    $errors[] = Craft::t('sms-manager', 'Cannot modify config-based sender ID "{name}".', ['name' => $senderId->name]);
                    continue;
                }
                $senderId->enabled = true;
                if ($senderId->save(false)) {
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
     * Bulk disable sender IDs
     *
     * @return Response
     */
    public function actionBulkDisable(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('smsManager:editSenderIds');

        $senderIdIds = Craft::$app->getRequest()->getRequiredBodyParam('senderIdIds');
        $settings = SmsManager::$plugin->getSettings();
        $count = 0;
        $errors = [];

        foreach ($senderIdIds as $id) {
            $senderId = SenderIdRecord::findOne($id);
            if ($senderId) {
                // Cannot modify config sender IDs
                if ($senderId->isFromConfig()) {
                    $errors[] = Craft::t('sms-manager', 'Cannot modify config-based sender ID "{name}".', ['name' => $senderId->name]);
                    continue;
                }
                // Cannot disable default sender ID
                if ($senderId->handle === $settings->defaultSenderIdHandle) {
                    $errors[] = Craft::t('sms-manager', 'Cannot disable default sender ID "{name}".', ['name' => $senderId->name]);
                    continue;
                }
                $senderId->enabled = false;
                if ($senderId->save(false)) {
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
     * Bulk delete sender IDs
     *
     * @return Response
     */
    public function actionBulkDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('smsManager:deleteSenderIds');

        $senderIdIds = Craft::$app->getRequest()->getRequiredBodyParam('senderIdIds');
        $count = 0;
        $errors = [];

        foreach ($senderIdIds as $id) {
            $result = SmsManager::$plugin->senderIds->deleteSenderId($id);
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
