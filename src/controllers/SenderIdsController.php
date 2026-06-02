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
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\smsmanager\helpers\ConfigFileHelper;
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
        $this->setLoggingHandle(SmsManager::$plugin->id);
    }

    /**
     * List all sender IDs.
     *
     * Follows the canonical CP table index-page pattern (in-memory variant) —
     * see plugins/base/docs/template-guides/cp-table-index-pattern.md.
     * Controller owns query-param parsing, allowlist validation, filter, sort,
     * and pagination; the Twig template stays presentational.
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('smsManager:manageSenderIds');

        $request = Craft::$app->getRequest();
        $settings = SmsManager::$plugin->getSettings();
        $isDefaultFromConfig = SmsManager::$plugin->senderIds->isDefaultSenderIdFromConfig();

        $senderIds = SmsManager::$plugin->senderIds->getAllSenderIds();
        $providers = SmsManager::$plugin->providers->getAllProviders();

        // Index providers by handle so the template can do an O(1) lookup
        // per row instead of an inner `{% for p in providers %}` loop.
        $providersByHandle = [];
        foreach ($providers as $provider) {
            $providersByHandle[$provider->handle] = $provider;
        }

        // Whether the install has any sender IDs at all — referenced by the
        // template's "no default sender ID configured" warning, which must
        // survive a narrowed filter. Cached now before filter shrinks $senderIds.
        $hasAnySenderIds = !empty($senderIds);

        // Detect handle collisions between config and database
        $configHandles = ConfigFileHelper::getHandles('senderIds');
        $databaseHandles = (new Query())
            ->select(['handle'])
            ->from('{{%smsmanager_senderids}}')
            ->column();
        $collisionHandles = array_values(array_intersect($configHandles, $databaseHandles));

        // Auto-assign default if needed (only if not set via config file).
        // Runs against the full sender ID list, not the filtered subset, so a
        // narrowed filter never accidentally promotes a default.
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

        // ---- Param parsing + allowlist validation -------------------------

        $statusFilter = (string) $request->getQueryParam('status', 'all');
        $validStatuses = ['all', 'enabled', 'disabled'];
        if (!in_array($statusFilter, $validStatuses, true)) {
            $statusFilter = 'all';
        }

        $sourceFilter = (string) $request->getQueryParam('source', 'all');
        $validSources = ['all', 'config', 'database'];
        if (!in_array($sourceFilter, $validSources, true)) {
            $sourceFilter = 'all';
        }

        $testFilter = (string) $request->getQueryParam('test', 'all');
        $validTestModes = ['all', 'test', 'production'];
        if (!in_array($testFilter, $validTestModes, true)) {
            $testFilter = 'all';
        }

        // Provider filter — value is a provider handle from the unfiltered
        // providers list; off-list values snap to 'all'.
        $providerFilter = (string) $request->getQueryParam('provider', 'all');
        $validProviderHandles = ['all'];
        foreach ($providers as $provider) {
            $validProviderHandles[] = (string) $provider->handle;
        }
        if (!in_array($providerFilter, $validProviderHandles, true)) {
            $providerFilter = 'all';
        }

        $search = trim((string) $request->getQueryParam('search', ''));
        if (mb_strlen($search) > 64) {
            $search = mb_substr($search, 0, 64);
        }

        $validSortFields = ['name', 'handle', 'senderId', 'provider', 'source', 'isDev', 'enabled'];
        $sort = (string) $request->getParam('sort', 'name');
        if (!in_array($sort, $validSortFields, true)) {
            $sort = 'name';
        }
        $dir = strtolower((string) $request->getParam('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        // ---- Filter -------------------------------------------------------

        if ($statusFilter === 'enabled') {
            $senderIds = array_values(array_filter($senderIds, fn($s): bool => $s->enabled));
        } elseif ($statusFilter === 'disabled') {
            $senderIds = array_values(array_filter($senderIds, fn($s): bool => !$s->enabled));
        }

        if ($sourceFilter === 'config') {
            $senderIds = array_values(array_filter($senderIds, fn($s): bool => $s->source === 'config'));
        } elseif ($sourceFilter === 'database') {
            $senderIds = array_values(array_filter($senderIds, fn($s): bool => $s->source !== 'config'));
        }

        if ($testFilter === 'test') {
            $senderIds = array_values(array_filter($senderIds, fn($s): bool => (bool) $s->isDev));
        } elseif ($testFilter === 'production') {
            $senderIds = array_values(array_filter($senderIds, fn($s): bool => !$s->isDev));
        }

        if ($providerFilter !== 'all') {
            $senderIds = array_values(array_filter(
                $senderIds,
                fn($s): bool => $s->providerHandle === $providerFilter
            ));
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $senderIds = array_values(array_filter($senderIds, function($s) use ($needle): bool {
                return str_contains(mb_strtolower((string) $s->name), $needle)
                    || str_contains(mb_strtolower((string) $s->handle), $needle)
                    || str_contains(mb_strtolower((string) $s->senderId), $needle);
            }));
        }

        // ---- Sort + paginate ----------------------------------------------

        $senderIds = $this->sortSenderIds($senderIds, $sort, $dir);

        // Total count reflects the filtered subset so the pager matches the
        // visible list — not the unfiltered sender ID list size.
        $totalCount = count($senderIds);
        $page = max(1, (int) $request->getParam('page', 1));
        $limit = max(1, (int) $settings->itemsPerPage);
        $offset = ($page - 1) * $limit;
        $senderIds = array_slice($senderIds, $offset, $limit);

        // Resolve the default sender ID once (against the full set, not the
        // filtered/paginated $senderIds) so beforeTable warnings render
        // consistently regardless of the current filter state.
        $defaultSenderIdHandle = $settings->defaultSenderIdHandle;
        $defaultSenderId = !empty($defaultSenderIdHandle)
            ? SmsManager::$plugin->senderIds->getSenderIdByHandle($defaultSenderIdHandle)
            : null;

        return $this->renderTemplate('sms-manager/senderids/index', [
            'senderIds' => $senderIds,
            'hasAnySenderIds' => $hasAnySenderIds,
            'providers' => $providers,
            'providersByHandle' => $providersByHandle,
            'settings' => $settings,
            'statusFilter' => $statusFilter,
            'sourceFilter' => $sourceFilter,
            'providerFilter' => $providerFilter,
            'testFilter' => $testFilter,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'limit' => $limit,
            'totalCount' => $totalCount,
            'defaultSenderIdHandle' => $defaultSenderIdHandle,
            'defaultSenderId' => $defaultSenderId,
            'isDefaultFromConfig' => $isDefaultFromConfig,
            'collisionHandles' => $collisionHandles,
            'canCreate' => Craft::$app->getUser()->checkPermission('smsManager:createSenderIds'),
            'canEdit' => Craft::$app->getUser()->checkPermission('smsManager:editSenderIds'),
            'canDelete' => Craft::$app->getUser()->checkPermission('smsManager:deleteSenderIds'),
        ]);
    }

    /**
     * Sort the loaded sender IDs array in PHP. Small dataset → array-side sort
     * is fine. The sort key allowlist is enforced in actionIndex() before we
     * land here, so the default branch is reached only on a logic bug.
     *
     * @param array<int, mixed> $senderIds
     * @return array<int, mixed>
     */
    private function sortSenderIds(array $senderIds, string $sort, string $dir): array
    {
        $multiplier = $dir === 'desc' ? -1 : 1;

        usort($senderIds, function($a, $b) use ($sort, $multiplier): int {
            $cmp = match ($sort) {
                'handle' => strcasecmp((string) $a->handle, (string) $b->handle),
                'senderId' => strcasecmp((string) $a->senderId, (string) $b->senderId),
                'provider' => strcasecmp((string) ($a->providerHandle ?? ''), (string) ($b->providerHandle ?? '')),
                'source' => strcmp((string) ($a->source ?? ''), (string) ($b->source ?? '')),
                'isDev' => ((int) $a->isDev) <=> ((int) $b->isDev),
                'enabled' => ((int) $a->enabled) <=> ((int) $b->enabled),
                default => strcasecmp((string) $a->name, (string) $b->name),
            };

            // Stable tie-break by name so equal primary keys don't shuffle
            // between requests — keeps pagination predictable.
            if ($cmp === 0 && $sort !== 'name') {
                $cmp = strcasecmp((string) $a->name, (string) $b->name);
            }

            return $cmp * $multiplier;
        });

        return $senderIds;
    }

    /**
     * View a sender ID (read-only, works for both config and database sender IDs)
     *
     * @param string|null $handle Sender ID handle
     * @return Response
     */
    public function actionView(?string $handle = null): Response
    {
        $this->requirePermission('smsManager:manageSenderIds');

        if (!$handle) {
            throw new NotFoundHttpException(Craft::t('sms-manager', 'Sender ID handle required'));
        }

        $senderId = SenderIdRecord::findByHandleWithConfig($handle);

        if (!$senderId) {
            throw new NotFoundHttpException(Craft::t('sms-manager', 'Sender ID not found'));
        }

        $providers = SmsManager::$plugin->providers->getAllProviders(true);
        $providerOptions = [['label' => Craft::t('sms-manager', 'Select a provider...'), 'value' => '']];
        foreach ($providers as $provider) {
            $providerOptions[] = [
                'label' => $provider->name,
                'value' => $provider->handle,
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
                throw new NotFoundHttpException(Craft::t('sms-manager', 'Sender ID not found'));
            }
        }

        $providers = SmsManager::$plugin->providers->getAllProviders(true);
        $providerOptions = [['label' => Craft::t('sms-manager', 'Select a provider...'), 'value' => '']];
        foreach ($providers as $provider) {
            $providerOptions[] = [
                'label' => $provider->name,
                'value' => $provider->handle,
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
            throw new NotFoundHttpException(Craft::t('sms-manager', 'Sender ID not found'));
        }

        // Set attributes
        $providerHandle = $request->getBodyParam('providerHandle');
        $senderId->providerHandle = $providerHandle;

        // Resolve provider ID from handle (for database providers)
        if ($providerHandle) {
            $provider = SmsManager::$plugin->providers->getProviderByHandle($providerHandle);
            $senderId->providerId = $provider?->id;
        }

        $senderId->name = $request->getBodyParam('name');
        $senderId->handle = $request->getBodyParam('handle');
        $senderId->senderId = $request->getBodyParam('senderId');
        $senderId->description = $request->getBodyParam('description');
        $senderId->enabled = (bool)$request->getBodyParam('enabled', true);
        $senderId->isDev = (bool)$request->getBodyParam('isDev', false);

        // Handle isDefault via settings, not on the record
        $setAsDefault = (bool)$request->getBodyParam('isDefault', false);

        if (SmsManager::$plugin->senderIds->saveSenderId($senderId)) {
            // Set as default if requested (and not controlled by config)
            if ($setAsDefault && !SmsManager::$plugin->senderIds->isDefaultSenderIdFromConfig()) {
                SmsManager::$plugin->senderIds->setDefaultSenderIdByHandle($senderId->handle);
            }
            Craft::$app->getSession()->setNotice(Craft::t('sms-manager', 'Sender ID saved.'));
            return $this->redirectToPostedUrl($senderId);
        }

        Craft::$app->getSession()->setError(Craft::t('sms-manager', 'Could not save sender ID.'));

        // Re-render edit form with submitted data
        $providers = SmsManager::$plugin->providers->getAllProviders(true);
        $providerOptions = [['label' => Craft::t('sms-manager', 'Select a provider...'), 'value' => '']];
        foreach ($providers as $provider) {
            $providerOptions[] = [
                'label' => $provider->name,
                'value' => $provider->handle,
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

        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return $this->asJson($result);
        }

        if ($result['success']) {
            Craft::$app->getSession()->setNotice(Craft::t('sms-manager', 'Sender ID deleted.'));
        } else {
            Craft::$app->getSession()->setError($result['error'] ?? Craft::t('sms-manager', 'Could not delete sender ID.'));
        }

        return $this->redirect('sms-manager/sender-ids');
    }

    /**
     * Toggle sender ID enabled status
     *
     * @return Response
     */
    public function actionToggleEnabled(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
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
                'error' => Craft::t('sms-manager', 'Sender ID not found'),
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
        $this->requirePermission('smsManager:manageSenderIds');
        $this->requireAcceptsJson();

        $providerId = Craft::$app->getRequest()->getQueryParam('providerId');

        if (!$providerId) {
            return $this->asJson(['senderIds' => []]);
        }

        $senderIds = SmsManager::$plugin->senderIds->getSenderIdsByProvider((int)$providerId, true);
        $defaultHandle = SmsManager::$plugin->getSettings()->defaultSenderIdHandle;
        $options = [];

        foreach ($senderIds as $senderId) {
            $options[] = [
                'id' => $senderId->id,
                'name' => $senderId->name,
                'handle' => $senderId->handle,
                'senderId' => $senderId->senderId,
                'isDefault' => $senderId->handle === $defaultHandle,
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
        $this->requireAcceptsJson();
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
        $this->requireAcceptsJson();
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
        $this->requireAcceptsJson();
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
