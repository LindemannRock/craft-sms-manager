<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * SMS gateway and management with multi-provider support
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025 LindemannRock
 */

namespace lindemannrock\smsmanager;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\web\UrlManager;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\logginglibrary\LoggingLibrary;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\smsmanager\models\Settings;
use lindemannrock\smsmanager\services\ProvidersService;
use lindemannrock\smsmanager\services\SenderIdsService;
use lindemannrock\smsmanager\services\SmsService;
use lindemannrock\smsmanager\utilities\SmsManagerUtility;
use yii\base\Event;

/**
 * SMS Manager Plugin
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 *
 * @property-read SmsService $sms
 * @property-read ProvidersService $providers
 * @property-read SenderIdsService $senderIds
 * @property-read Settings $settings
 * @method Settings getSettings()
 */
class SmsManager extends Plugin
{
    use LoggingTrait;

    /**
     * @var SmsManager|null Singleton plugin instance
     */
    public static ?SmsManager $plugin = null;

    /**
     * @var string Plugin schema version for migrations
     */
    public string $schemaVersion = '1.0.0';

    /**
     * @var bool Whether the plugin exposes a control panel settings page
     */
    public bool $hasCpSettings = true;

    /**
     * @var bool Whether the plugin registers a control panel section
     */
    public bool $hasCpSection = true;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        // Bootstrap base module (logging + Twig extension)
        PluginHelper::bootstrap($this, 'smsHelper', ['smsManager:viewLogs']);
        PluginHelper::applyPluginNameFromConfig($this);

        // Configure logging library with download permissions
        $settings = $this->getSettings();
        LoggingLibrary::configure([
            'pluginHandle' => $this->handle,
            'pluginName' => $settings->getFullName(),
            'logLevel' => $settings->logLevel ?? 'error',
            'itemsPerPage' => $settings->itemsPerPage ?? 100,
            'viewPermissions' => ['smsManager:viewLogs'],
            'downloadPermissions' => ['smsManager:downloadLogs'],
        ]);

        // Register services
        $this->setComponents([
            'sms' => SmsService::class,
            'providers' => ProvidersService::class,
            'senderIds' => SenderIdsService::class,
        ]);

        // Register translations
        Craft::$app->i18n->translations['sms-manager'] = [
            'class' => \craft\i18n\PhpMessageSource::class,
            'sourceLanguage' => 'en',
            'basePath' => __DIR__ . '/translations',
            'forceTranslation' => true,
            'allowOverrides' => true,
        ];

        // Register CP routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules = array_merge($event->rules, $this->getCpUrlRules());
            }
        );

        // Register permissions
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $settings = $this->getSettings();
                $event->permissions[] = [
                    'heading' => $settings->getFullName(),
                    'permissions' => $this->getPluginPermissions($settings),
                ];
            }
        );

        // Register utilities
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = SmsManagerUtility::class;
            }
        );
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $user = Craft::$app->getUser();

        // Check permissions
        $hasProvidersAccess = $user->checkPermission('smsManager:viewProviders');
        $hasSenderIdsAccess = $user->checkPermission('smsManager:viewSenderIds');
        $hasLogsAccess = $user->checkPermission('smsManager:viewLogs');
        $hasAnalyticsAccess = $user->checkPermission('smsManager:viewAnalytics');
        $hasSettingsAccess = $user->checkPermission('smsManager:manageSettings');

        // If no access at all, hide from nav
        if (!$hasProvidersAccess && !$hasSenderIdsAccess && !$hasLogsAccess && !$hasAnalyticsAccess && !$hasSettingsAccess) {
            return null;
        }

        if ($item) {
            $item['label'] = $this->getSettings()->getFullName();
            $item['icon'] = '@appicons/paper-plane.svg';

            $item['subnav'] = [];

            // Dashboard
            if ($hasAnalyticsAccess) {
                $item['subnav']['dashboard'] = [
                    'label' => Craft::t('sms-manager', 'Dashboard'),
                    'url' => 'sms-manager',
                ];
            }

            // Providers
            if ($hasProvidersAccess) {
                $item['subnav']['providers'] = [
                    'label' => Craft::t('sms-manager', 'Providers'),
                    'url' => 'sms-manager/providers',
                ];
            }

            // Sender IDs
            if ($hasSenderIdsAccess) {
                $item['subnav']['sender-ids'] = [
                    'label' => Craft::t('sms-manager', 'Sender IDs'),
                    'url' => 'sms-manager/sender-ids',
                ];
            }

            // Analytics
            if ($this->getSettings()->enableAnalytics && $hasAnalyticsAccess) {
                $item['subnav']['analytics'] = [
                    'label' => Craft::t('sms-manager', 'Analytics'),
                    'url' => 'sms-manager/analytics',
                ];
            }

            // SMS Logs (custom transaction logs)
            if ($this->getSettings()->enableLogs && $hasLogsAccess) {
                $item['subnav']['sms-logs'] = [
                    'label' => Craft::t('sms-manager', 'SMS Logs'),
                    'url' => 'sms-manager/sms-logs',
                ];
            }

            // System Logs (using logging library)
            if (Craft::$app->getPlugins()->isPluginInstalled('logging-library') &&
                Craft::$app->getPlugins()->isPluginEnabled('logging-library')) {
                $item = LoggingLibrary::addLogsNav($item, $this->handle, [
                    'smsManager:viewLogs',
                ]);
            }

            // Settings
            if ($hasSettingsAccess) {
                $item['subnav']['settings'] = [
                    'label' => Craft::t('sms-manager', 'Settings'),
                    'url' => 'sms-manager/settings',
                ];
            }
        }

        return $item;
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        try {
            return Settings::loadFromDatabase();
        } catch (\Exception $e) {
            $this->logInfo('Could not load settings from database', ['error' => $e->getMessage()]);
            return new Settings();
        }
    }

    /**
     * @inheritdoc
     */
    public function getSettings(): ?Model
    {
        $settings = parent::getSettings();

        if ($settings) {
            $config = Craft::$app->getConfig()->getConfigFromFile('sms-manager');
            if (!empty($config) && is_array($config)) {
                foreach ($config as $key => $value) {
                    if (property_exists($settings, $key)) {
                        $settings->$key = $value;
                    }
                }
            }
        }

        return $settings;
    }

    /**
     * @inheritdoc
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->controller->redirect('sms-manager/settings');
    }

    /**
     * Get CP URL rules
     */
    private function getCpUrlRules(): array
    {
        return [
            // Dashboard
            'sms-manager' => 'sms-manager/dashboard/index',
            'sms-manager/dashboard' => 'sms-manager/dashboard/index',

            // Providers
            'sms-manager/providers' => 'sms-manager/providers/index',
            'sms-manager/providers/new' => 'sms-manager/providers/edit',
            'sms-manager/providers/<providerId:\d+>' => 'sms-manager/providers/edit',

            // Sender IDs
            'sms-manager/sender-ids' => 'sms-manager/sender-ids/index',
            'sms-manager/sender-ids/new' => 'sms-manager/sender-ids/edit',
            'sms-manager/sender-ids/<senderIdId:\d+>' => 'sms-manager/sender-ids/edit',

            // Analytics
            'sms-manager/analytics' => 'sms-manager/analytics/index',

            // Settings
            'sms-manager/settings' => 'sms-manager/settings/index',
            'sms-manager/settings/<section:\w+>' => 'sms-manager/settings/<section>',

            // Utilities
            'sms-manager/utilities/clear-all-analytics' => 'sms-manager/utilities/clear-all-analytics',

            // SMS Logs (custom transaction logs)
            'sms-manager/sms-logs' => 'sms-manager/logs/index',
            'sms-manager/sms-logs/<logId:\d+>' => 'sms-manager/logs/view',
            'sms-manager/sms-logs/export' => 'sms-manager/logs/export',
            'sms-manager/sms-logs/clear' => 'sms-manager/logs/clear',

            // System Logs (logging library)
            'sms-manager/logs' => 'logging-library/logs/index',
            'sms-manager/logs/download' => 'logging-library/logs/download',
        ];
    }

    /**
     * Get plugin permissions
     */
    private function getPluginPermissions(Settings $settings): array
    {
        return [
            // Providers
            'smsManager:manageProviders' => [
                'label' => Craft::t('sms-manager', 'Manage providers'),
                'nested' => [
                    'smsManager:viewProviders' => [
                        'label' => Craft::t('sms-manager', 'View providers'),
                    ],
                    'smsManager:createProviders' => [
                        'label' => Craft::t('sms-manager', 'Create providers'),
                    ],
                    'smsManager:editProviders' => [
                        'label' => Craft::t('sms-manager', 'Edit providers'),
                    ],
                    'smsManager:deleteProviders' => [
                        'label' => Craft::t('sms-manager', 'Delete providers'),
                    ],
                ],
            ],
            // Sender IDs
            'smsManager:manageSenderIds' => [
                'label' => Craft::t('sms-manager', 'Manage sender IDs'),
                'nested' => [
                    'smsManager:viewSenderIds' => [
                        'label' => Craft::t('sms-manager', 'View sender IDs'),
                    ],
                    'smsManager:createSenderIds' => [
                        'label' => Craft::t('sms-manager', 'Create sender IDs'),
                    ],
                    'smsManager:editSenderIds' => [
                        'label' => Craft::t('sms-manager', 'Edit sender IDs'),
                    ],
                    'smsManager:deleteSenderIds' => [
                        'label' => Craft::t('sms-manager', 'Delete sender IDs'),
                    ],
                ],
            ],
            // Analytics
            'smsManager:viewAnalytics' => [
                'label' => Craft::t('sms-manager', 'View analytics'),
                'nested' => [
                    'smsManager:exportAnalytics' => [
                        'label' => Craft::t('sms-manager', 'Export analytics'),
                    ],
                    'smsManager:clearAnalytics' => [
                        'label' => Craft::t('sms-manager', 'Clear analytics'),
                    ],
                ],
            ],
            // Logs
            'smsManager:viewLogs' => [
                'label' => Craft::t('sms-manager', 'View logs'),
                'nested' => [
                    'smsManager:downloadLogs' => [
                        'label' => Craft::t('sms-manager', 'Download logs'),
                    ],
                ],
            ],
            // Settings
            'smsManager:manageSettings' => [
                'label' => Craft::t('sms-manager', 'Manage settings'),
            ],
        ];
    }
}
