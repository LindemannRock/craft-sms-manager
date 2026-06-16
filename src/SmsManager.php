<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * SMS gateway and management with multi-provider support
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
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
use lindemannrock\base\helpers\ColorHelper;
use lindemannrock\base\helpers\CpNavHelper;
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\base\helpers\RecurringQueueHelper;
use lindemannrock\base\helpers\ScheduleHelper;
use lindemannrock\logginglibrary\LoggingLibrary;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\smsmanager\jobs\CleanupAnalyticsJob;
use lindemannrock\smsmanager\jobs\CleanupLogsJob;
use lindemannrock\smsmanager\models\Settings;
use lindemannrock\smsmanager\services\IntegrationsService;
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
 * @property-read IntegrationsService $integrations
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
     * @var bool Whether the plugin settings page is accessible when allowAdminChanges is false
     */
    public bool $hasReadOnlyCpSettings = true;

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

        // Bootstrap base module (logging + Twig extension + colors)
        PluginHelper::bootstrap(
            $this,
            'smsHelper',
            ['smsManager:viewSystemLogs'],
            ['smsManager:downloadSystemLogs'],
            [
                'logMenu' => [
                    'label' => Craft::t('sms-manager', 'Logs'),
                    'items' => [
                        'system' => [
                            'label' => Craft::t('sms-manager', 'System'),
                            'url' => $this->handle . '/logs',
                        ],
                        'sms' => [
                            'label' => Craft::t('sms-manager', 'SMS'),
                            'url' => $this->handle . '/logs/sms',
                        ],
                    ],
                ],
                'colorSets' => [
                    'smsStatus' => [
                        'sent' => ColorHelper::getPaletteColor('teal'),
                        'failed' => ColorHelper::getPaletteColor('red'),
                        'pending' => ColorHelper::getPaletteColor('orange'),
                    ],
                    'smsProviderType' => [
                        'mpp-sms' => ColorHelper::getPaletteColor('purple'),
                        'twilio' => ColorHelper::getPaletteColor('red'),
                    ],
                ],
                'installExperience' => [
                    'headline' => Craft::t('sms-manager', 'SMS Manager'),
                    'body' => Craft::t('sms-manager', 'Configure providers, manage sender IDs, and monitor messaging activity from one control panel workspace.'),
                    'ctaLabel' => Craft::t('sms-manager', 'Open SMS Manager'),
                    'ctaUrl' => 'sms-manager',
                    'redirectUri' => 'sms-manager',
                    'confettiPreset' => 'surprise',
                ],
            ]
        );
        PluginHelper::applyPluginNameFromConfig($this);

        // Register services
        $this->setComponents([
            'sms' => SmsService::class,
            'providers' => ProvidersService::class,
            'senderIds' => SenderIdsService::class,
            'integrations' => IntegrationsService::class,
        ]);

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

        // Schedule cleanup jobs (only on non-console requests to avoid running during migrations)
        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->scheduleAnalyticsCleanup();
            $this->scheduleLogsCleanup();
        }
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $user = Craft::$app->getUser();

        if ($item) {
            $settings = $this->getSettings();

            $item['label'] = $settings->getFullName();

            $sections = $this->getCpSections($settings);
            $item['subnav'] = CpNavHelper::buildSubnav($user, $settings, $sections);

            // System Logs (using logging library)
            if (PluginHelper::isPluginEnabled('logging-library')) {
                $item = LoggingLibrary::addLogsNav($item, $this->handle, [
                    'smsManager:viewSystemLogs',
                    'smsManager:viewSmsLogs',
                ]);
            }

            // Hide from nav if no accessible subnav items
            if (empty($item['subnav'])) {
                return null;
            }
        }

        return $item;
    }

    /**
     * Get CP sections for nav + default route resolution
     *
     * @param Settings $settings
     * @param bool $includeDashboard
     * @return array
     * @since 5.9.0
     */
    public function getCpSections(Settings $settings, bool $includeDashboard = true): array
    {
        $sections = [];

        if ($includeDashboard) {
            $sections[] = [
                'key' => 'dashboard',
                'label' => Craft::t('sms-manager', 'Dashboard'),
                'url' => 'sms-manager',
                'permissionsAll' => ['smsManager:viewSmsLogs'],
                'settingsFlag' => 'enableSmsLogs',
            ];
        }

        $sections[] = [
            'key' => 'providers',
            'label' => Craft::t('sms-manager', 'Providers'),
            'url' => 'sms-manager/providers',
            'permissionsAll' => ['smsManager:manageProviders'],
        ];

        $sections[] = [
            'key' => 'sender-ids',
            'label' => Craft::t('sms-manager', 'Sender IDs'),
            'url' => 'sms-manager/sender-ids',
            'permissionsAll' => ['smsManager:manageSenderIds'],
        ];

        $sections[] = [
            'key' => 'analytics',
            'label' => Craft::t('sms-manager', 'Analytics'),
            'url' => 'sms-manager/analytics',
            'permissionsAll' => ['smsManager:viewAnalytics'],
            'settingsFlag' => 'enableAnalytics',
        ];

        $sections[] = [
            'key' => 'settings',
            'label' => Craft::t('sms-manager', 'Settings'),
            'url' => 'sms-manager/settings',
            'permissionsAll' => ['smsManager:manageSettings'],
        ];

        return $sections;
    }

    /**
     * @inheritdoc
     */
    public function setSettings(array|Model $settings): void
    {
        // No-op: settings come from loadFromDatabase() in createSettingsModel()
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
            PluginHelper::applyConfigOverridesToSettings($settings, 'sms-manager');
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
     * @inheritdoc
     */
    public function getReadOnlySettingsResponse(): mixed
    {
        return Craft::$app->controller->redirect('sms-manager/settings');
    }

    /**
     * Get CP URL rules
     */
    private function getCpUrlRules(): array
    {
        return [
            // Dashboard (main landing page)
            'sms-manager' => 'sms-manager/dashboard/index',

            // Logs section (sidebar: System via logging-library, SMS via sms-logs controller)
            'sms-manager/logs' => 'logging-library/logs/index',
            'sms-manager/logs/sms' => 'sms-manager/sms-logs/index',
            'sms-manager/logs/sms/export' => 'sms-manager/sms-logs/export',

            // Providers
            'sms-manager/providers' => 'sms-manager/providers/index',
            'sms-manager/providers/new' => 'sms-manager/providers/edit',
            'sms-manager/providers/view/<handle:[^\/]+>' => 'sms-manager/providers/view',
            'sms-manager/providers/<providerId:\d+>' => 'sms-manager/providers/edit',

            // Sender IDs
            'sms-manager/sender-ids' => 'sms-manager/sender-ids/index',
            'sms-manager/sender-ids/new' => 'sms-manager/sender-ids/edit',
            'sms-manager/sender-ids/view/<handle:[^\/]+>' => 'sms-manager/sender-ids/view',
            'sms-manager/sender-ids/<senderIdId:\d+>' => 'sms-manager/sender-ids/edit',

            // Analytics
            'sms-manager/analytics' => 'sms-manager/analytics/index',
            'sms-manager/analytics/export' => 'sms-manager/analytics/export',

            // Settings
            'sms-manager/settings' => 'sms-manager/settings/index',
            'sms-manager/settings/<section:\w+>' => 'sms-manager/settings/<section>',

            // Utilities
            'sms-manager/utilities/clear-all-analytics' => 'sms-manager/utilities/clear-all-analytics',
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
                    'smsManager:viewSystemLogs' => [
                        'label' => Craft::t('sms-manager', 'View system logs'),
                        'nested' => [
                            'smsManager:downloadSystemLogs' => [
                                'label' => Craft::t('sms-manager', 'Download system logs'),
                            ],
                        ],
                    ],
                    'smsManager:viewSmsLogs' => [
                        'label' => Craft::t('sms-manager', 'View SMS logs'),
                        'nested' => [
                            'smsManager:exportSmsLogs' => [
                                'label' => Craft::t('sms-manager', 'Export SMS logs'),
                            ],
                            'smsManager:deleteSmsLogs' => [
                                'label' => Craft::t('sms-manager', 'Delete SMS logs'),
                            ],
                        ],
                    ],
                ],
            ],
            // Settings
            'smsManager:manageSettings' => [
                'label' => Craft::t('sms-manager', 'Manage settings'),
            ],
        ];
    }

    /**
     * Schedule analytics cleanup job
     */
    private function scheduleAnalyticsCleanup(): void
    {
        $settings = $this->getSettings();

        if (!$settings->enableAnalytics || $settings->analyticsRetention <= 0) {
            return;
        }

        $nextRun = ScheduleHelper::calculateNext('daily');
        if ($nextRun === null) {
            return;
        }

        $delay = max(0, $nextRun->getTimestamp() - DateFormatHelper::now()->getTimestamp());
        $nextRunTime = DateFormatHelper::formatCompactDatetimeFromSettings(
            $nextRun,
            $settings,
            null,
            false,
            pluginHandle: 'sms-manager',
        );

        RecurringQueueHelper::ensurePending(
            pluginToken: 'smsmanager',
            jobClass: CleanupAnalyticsJob::class,
            delay: $delay,
            jobFactory: fn() => new CleanupAnalyticsJob([
                'reschedule' => true,
                'nextRunTime' => $nextRunTime,
            ]),
        );
    }

    /**
     * Schedule logs cleanup job
     */
    private function scheduleLogsCleanup(): void
    {
        $settings = $this->getSettings();

        if (!$settings->enableSmsLogs || $settings->smsLogsRetention <= 0) {
            return;
        }

        $nextRun = ScheduleHelper::calculateNext('daily');
        if ($nextRun === null) {
            return;
        }

        $delay = max(0, $nextRun->getTimestamp() - DateFormatHelper::now()->getTimestamp());
        $nextRunTime = DateFormatHelper::formatCompactDatetimeFromSettings(
            $nextRun,
            $settings,
            null,
            false,
            pluginHandle: 'sms-manager',
        );

        RecurringQueueHelper::ensurePending(
            pluginToken: 'smsmanager',
            jobClass: CleanupLogsJob::class,
            delay: $delay,
            jobFactory: fn() => new CleanupLogsJob([
                'reschedule' => true,
                'nextRunTime' => $nextRunTime,
            ]),
        );
    }
}
