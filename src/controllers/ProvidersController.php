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
use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;
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

    private const PLUGIN_HANDLE = 'sms-manager';

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle(SmsManager::$plugin->id);
    }

    /**
     * List all providers.
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
        $this->requirePermission('smsManager:manageProviders');

        $request = Craft::$app->getRequest();
        $settings = SmsManager::$plugin->getSettings();
        $providers = SmsManager::$plugin->providers->getAllProviders();
        $isDefaultFromConfig = SmsManager::$plugin->providers->isDefaultProviderFromConfig();

        // Whether the install has any providers at all — referenced by the
        // template's "no default provider configured" warning, which must
        // survive a narrowed filter. Cached now before filter shrinks $providers.
        $hasAnyProviders = !empty($providers);

        // Detect handle collisions between config and database
        $configHandles = BaseConfigFileHelper::getHandles(self::PLUGIN_HANDLE, 'providers');
        $databaseHandles = (new Query())
            ->select(['handle'])
            ->from('{{%smsmanager_providers}}')
            ->column();
        $collisionHandles = array_values(array_intersect($configHandles, $databaseHandles));

        // Auto-assign default if needed (only if not set via config file).
        // Runs against the full provider list, not the filtered subset, so a
        // narrowed status/source filter never accidentally promotes a default.
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

        $search = trim((string) $request->getQueryParam('search', ''));
        if (mb_strlen($search) > 64) {
            $search = mb_substr($search, 0, 64);
        }

        $validSortFields = ['name', 'handle', 'type', 'source', 'enabled'];
        $sort = (string) $request->getParam('sort', 'name');
        if (!in_array($sort, $validSortFields, true)) {
            $sort = 'name';
        }
        $dir = strtolower((string) $request->getParam('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        // ---- Filter -------------------------------------------------------

        if ($statusFilter === 'enabled') {
            $providers = array_values(array_filter($providers, fn($p): bool => $p->enabled));
        } elseif ($statusFilter === 'disabled') {
            $providers = array_values(array_filter($providers, fn($p): bool => !$p->enabled));
        }

        if ($sourceFilter === 'config') {
            $providers = array_values(array_filter($providers, fn($p): bool => $p->source === 'config'));
        } elseif ($sourceFilter === 'database') {
            $providers = array_values(array_filter($providers, fn($p): bool => $p->source !== 'config'));
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $providers = array_values(array_filter($providers, function($p) use ($needle): bool {
                return str_contains(mb_strtolower((string) $p->name), $needle)
                    || str_contains(mb_strtolower((string) $p->handle), $needle)
                    || str_contains(mb_strtolower((string) $p->type), $needle);
            }));
        }

        // ---- Sort + paginate ----------------------------------------------

        $providers = $this->sortProviders($providers, $sort, $dir);

        // Total count reflects the filtered subset so the pager matches the
        // visible list — not the unfiltered provider list size.
        $totalCount = count($providers);
        $page = max(1, (int) $request->getParam('page', 1));
        $limit = max(1, (int) $settings->itemsPerPage);
        $offset = ($page - 1) * $limit;
        $providers = array_slice($providers, $offset, $limit);

        // Resolve the default provider once (against the full set, not the
        // filtered/paginated $providers) so beforeTable warnings render
        // consistently regardless of the current filter state.
        $defaultProviderHandle = $settings->defaultProviderHandle;
        $defaultProvider = !empty($defaultProviderHandle)
            ? SmsManager::$plugin->providers->getProviderByHandle($defaultProviderHandle)
            : null;

        return $this->renderTemplate('sms-manager/providers/index', [
            'providers' => $providers,
            'hasAnyProviders' => $hasAnyProviders,
            'settings' => $settings,
            'defaultProviderHandle' => $defaultProviderHandle,
            'defaultProvider' => $defaultProvider,
            'isDefaultFromConfig' => $isDefaultFromConfig,
            'collisionHandles' => $collisionHandles,
            'statusFilter' => $statusFilter,
            'sourceFilter' => $sourceFilter,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'page' => $page,
            'limit' => $limit,
            'totalCount' => $totalCount,
            'canCreate' => Craft::$app->getUser()->checkPermission('smsManager:createProviders'),
            'canEdit' => Craft::$app->getUser()->checkPermission('smsManager:editProviders'),
            'canDelete' => Craft::$app->getUser()->checkPermission('smsManager:deleteProviders'),
        ]);
    }

    /**
     * Sort the loaded providers array in PHP. Small dataset → array-side sort
     * is fine. The sort key allowlist is enforced in actionIndex() before we
     * land here, so the default branch is reached only on a logic bug.
     *
     * @param array<int, mixed> $providers
     * @return array<int, mixed>
     */
    private function sortProviders(array $providers, string $sort, string $dir): array
    {
        $multiplier = $dir === 'desc' ? -1 : 1;

        usort($providers, function($a, $b) use ($sort, $multiplier): int {
            $cmp = match ($sort) {
                'handle' => strcasecmp((string) $a->handle, (string) $b->handle),
                'type' => strcasecmp((string) $a->type, (string) $b->type),
                'source' => strcmp((string) ($a->source ?? ''), (string) ($b->source ?? '')),
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

        return $providers;
    }

    /**
     * View a provider (read-only, works for both config and database providers)
     *
     * @param string|null $handle Provider handle
     * @return Response
     */
    public function actionView(?string $handle = null): Response
    {
        $this->requirePermission('smsManager:manageProviders');

        if (!$handle) {
            throw new NotFoundHttpException(Craft::t('sms-manager', 'Provider handle required'));
        }

        $provider = ProviderRecord::findByHandleWithConfig($handle);

        if (!$provider) {
            throw new NotFoundHttpException(Craft::t('sms-manager', 'Provider not found'));
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
            'providerMeta' => SmsManager::$plugin->providers->getProviderTypeMetadata($provider->type),
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
                throw new NotFoundHttpException(Craft::t('sms-manager', 'Provider not found'));
            }

            $providerSettings = $provider->getSettingsArray();
        }

        $providerTypes = SmsManager::$plugin->providers->getProviderTypeOptions();
        $countryOptions = GeoHelper::getCountryDialCodeOptions(true);
        $providerCount = ProviderRecord::find()->count();
        $settings = SmsManager::$plugin->getSettings();

        $providerType = $provider ? $provider->type : 'mpp-sms';

        return $this->renderTemplate('sms-manager/providers/edit', [
            'provider' => $provider,
            'providerSettings' => $providerSettings,
            'providerTypes' => $providerTypes,
            'countryOptions' => $countryOptions,
            'isNew' => $provider === null,
            'providerCount' => $providerCount,
            'defaultProviderHandle' => $settings->defaultProviderHandle,
            'isDefaultFromConfig' => SmsManager::$plugin->providers->isDefaultProviderFromConfig(),
            'providerMeta' => SmsManager::$plugin->providers->getProviderTypeMetadata($providerType),
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
            throw new NotFoundHttpException(Craft::t('sms-manager', 'Provider not found'));
        }

        // Set basic attributes
        $provider->name = $request->getBodyParam('name');
        $provider->handle = $request->getBodyParam('handle');
        $provider->type = $request->getBodyParam('type');
        $provider->enabled = (bool)$request->getBodyParam('enabled', true);

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
            'providerMeta' => SmsManager::$plugin->providers->getProviderTypeMetadata($provider->type),
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
        $this->requireAcceptsJson();
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
        $this->requireAcceptsJson();
        $this->requirePermission('smsManager:manageProviders');

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
                'error' => Craft::t('sms-manager', 'Provider not found'),
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
        $this->requireAcceptsJson();
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
        $this->requireAcceptsJson();
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
        $this->requireAcceptsJson();
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
