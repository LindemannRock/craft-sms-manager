<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\models;

use Craft;
use craft\base\Model;
use lindemannrock\base\traits\DateFormatSettingsTrait;
use lindemannrock\base\traits\DateRangeSettingsTrait;
use lindemannrock\base\traits\ExportFormatSettingsTrait;
use lindemannrock\base\traits\ItemsPerPageSettingsTrait;
use lindemannrock\base\traits\LogLevelSettingsTrait;
use lindemannrock\base\traits\PluginNameSettingsTrait;
use lindemannrock\base\traits\SettingsConfigTrait;
use lindemannrock\base\traits\SettingsDisplayNameTrait;
use lindemannrock\base\traits\SettingsPersistenceTrait;
use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * Settings Model
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
class Settings extends Model
{
    use LoggingTrait;
    use SettingsDisplayNameTrait;
    use SettingsPersistenceTrait;
    use SettingsConfigTrait;
    use PluginNameSettingsTrait;
    use LogLevelSettingsTrait;
    use ItemsPerPageSettingsTrait;
    use DateFormatSettingsTrait;
    use DateRangeSettingsTrait;
    use ExportFormatSettingsTrait;

    // =========================================================================
    // PLUGIN SETTINGS
    // =========================================================================

    /**
     * @var string The name of the plugin as it appears in the Control Panel menu
     */
    public string $pluginName = 'SMS Manager';

    /**
     * @var int|null Default provider ID (deprecated, use defaultProviderHandle)
     * @deprecated Use defaultProviderHandle instead
     */
    public ?int $defaultProviderId = null;

    /**
     * @var int|null Default sender ID (deprecated, use defaultSenderIdHandle)
     * @deprecated Use defaultSenderIdHandle instead
     */
    public ?int $defaultSenderIdId = null;

    /**
     * @var string|null Default provider handle
     */
    public ?string $defaultProviderHandle = null;

    /**
     * @var string|null Default sender ID handle
     */
    public ?string $defaultSenderIdHandle = null;

    // =========================================================================
    // ANALYTICS SETTINGS
    // =========================================================================

    /**
     * @var bool Enable analytics tracking
     */
    public bool $enableAnalytics = true;

    /**
     * @var int Maximum number of analytics records to retain
     */
    public int $analyticsLimit = 1000;

    /**
     * @var int Number of days to retain analytics (0 = keep forever)
     */
    public int $analyticsRetention = 30;

    /**
     * @var bool Whether analytics should be automatically trimmed
     */
    public bool $autoTrimAnalytics = true;

    // =========================================================================
    // SMS LOGS SETTINGS
    // =========================================================================

    /**
     * @var bool Enable SMS delivery logs
     */
    public bool $enableSmsLogs = true;

    /**
     * @var int Maximum number of SMS log records to retain
     */
    public int $smsLogsLimit = 10000;

    /**
     * @var int Number of days to retain SMS logs (0 = keep forever)
     */
    public int $smsLogsRetention = 30;

    /**
     * @var bool Whether SMS logs should be automatically trimmed
     */
    public bool $autoTrimSmsLogs = true;

    // =========================================================================
    // INTERFACE SETTINGS
    // =========================================================================

    /**
     * @var int|null Dashboard refresh interval in seconds (null = disabled)
     */
    public ?int $refreshIntervalSecs = null;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle(static::pluginHandle());
    }

    // =========================================================================
    // TRAIT CONFIGURATION
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected static function tableName(): string
    {
        return 'smsmanager_settings';
    }

    /**
     * @inheritdoc
     */
    protected static function pluginHandle(): string
    {
        return 'sms-manager';
    }

    /**
     * @inheritdoc
     */
    protected static function booleanFields(): array
    {
        return [
            'enableAnalytics',
            'autoTrimAnalytics',
            'enableSmsLogs',
            'autoTrimSmsLogs',
            'showSeconds',
            'exportsCsv',
            'exportsJson',
            'exportsExcel',
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function integerFields(): array
    {
        return [
            'defaultProviderId',
            'defaultSenderIdId',
            'analyticsLimit',
            'analyticsRetention',
            'smsLogsLimit',
            'smsLogsRetention',
            'itemsPerPage',
            'refreshIntervalSecs',
        ];
    }

    /**
     * @inheritdoc
     */
    protected static function jsonFields(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    protected static function stringFields(): array
    {
        return [
            'pluginName',
            'logLevel',
            'defaultProviderHandle',
            'defaultSenderIdHandle',
        ];
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return array_merge([
            ['pluginName', 'default', 'value' => 'SMS Manager'],
            [
                [
                    'enableAnalytics',
                    'autoTrimAnalytics',
                    'enableSmsLogs',
                    'autoTrimSmsLogs',
                ],
                'boolean',
            ],
            [['defaultProviderId', 'defaultSenderIdId'], 'integer'],
            [['defaultProviderHandle', 'defaultSenderIdHandle'], 'string', 'max' => 64],
            ['analyticsLimit', 'required'],
            ['analyticsLimit', 'integer', 'min' => 1, 'max' => 100000],
            ['analyticsLimit', 'default', 'value' => 1000],
            ['analyticsRetention', 'required'],
            ['analyticsRetention', 'integer', 'min' => 0, 'max' => 3650],
            ['analyticsRetention', 'default', 'value' => 30],
            ['smsLogsLimit', 'required'],
            ['smsLogsLimit', 'integer', 'min' => 1, 'max' => 100000],
            ['smsLogsLimit', 'default', 'value' => 10000],
            ['smsLogsRetention', 'required'],
            ['smsLogsRetention', 'integer', 'min' => 0, 'max' => 3650],
            ['smsLogsRetention', 'default', 'value' => 30],
            ['refreshIntervalSecs', 'integer', 'min' => 0, 'skipOnEmpty' => true],
            ['refreshIntervalSecs', 'default', 'value' => null],
        ], $this->pluginNameSettingsRules(), $this->logLevelSettingsRules(), $this->itemsPerPageSettingsRules(), $this->dateFormatSettingsRules(), $this->dateRangeSettingsRules(), $this->exportFormatSettingsRules());
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return array_merge([
            'defaultProviderId' => Craft::t('sms-manager', 'Default Provider'),
            'defaultSenderIdId' => Craft::t('sms-manager', 'Default Sender ID'),
            'defaultProviderHandle' => Craft::t('sms-manager', 'Default Provider'),
            'defaultSenderIdHandle' => Craft::t('sms-manager', 'Default Sender ID'),
            'enableAnalytics' => Craft::t('sms-manager', 'Enable Analytics'),
            'analyticsLimit' => Craft::t('sms-manager', 'Analytics Limit'),
            'analyticsRetention' => Craft::t('sms-manager', 'Analytics Retention (Days)'),
            'autoTrimAnalytics' => Craft::t('sms-manager', 'Auto Trim Analytics'),
            'enableSmsLogs' => Craft::t('sms-manager', 'Enable Delivery Logs'),
            'smsLogsLimit' => Craft::t('sms-manager', 'Logs Limit'),
            'smsLogsRetention' => Craft::t('sms-manager', 'Logs Retention (Days)'),
            'autoTrimSmsLogs' => Craft::t('sms-manager', 'Auto Trim Logs'),
            'refreshIntervalSecs' => Craft::t('sms-manager', 'Dashboard Refresh Interval'),
        ], $this->pluginNameSettingsLabel(), $this->logLevelSettingsLabel(), $this->itemsPerPageSettingsLabel(), $this->dateFormatSettingsLabels(), $this->dateRangeSettingsLabel(), $this->exportFormatSettingsLabels());
    }
}
