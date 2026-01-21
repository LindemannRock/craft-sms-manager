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

    // =========================================================================
    // PLUGIN SETTINGS
    // =========================================================================

    /**
     * @var string The public-facing name of the plugin
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
    // LOGS SETTINGS
    // =========================================================================

    /**
     * @var bool Enable delivery logs
     */
    public bool $enableLogs = true;

    /**
     * @var int Maximum number of log records to retain
     */
    public int $logsLimit = 10000;

    /**
     * @var int Number of days to retain logs (0 = keep forever)
     */
    public int $logsRetention = 30;

    /**
     * @var bool Whether logs should be automatically trimmed
     */
    public bool $autoTrimLogs = true;

    // =========================================================================
    // INTERFACE SETTINGS
    // =========================================================================

    /**
     * @var int Items per page in list views
     */
    public int $itemsPerPage = 100;

    /**
     * @var int Dashboard refresh interval in seconds
     */
    public int $refreshIntervalSecs = 30;

    // =========================================================================
    // LOGGING LIBRARY SETTINGS
    // =========================================================================

    /**
     * @var string Log level for the logging library
     */
    public string $logLevel = 'error';

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('sms-manager');
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
            'enableLogs',
            'autoTrimLogs',
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
            'logsLimit',
            'logsRetention',
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
        return [
            ['pluginName', 'string'],
            ['pluginName', 'default', 'value' => 'SMS Manager'],
            [
                [
                    'enableAnalytics',
                    'autoTrimAnalytics',
                    'enableLogs',
                    'autoTrimLogs',
                ],
                'boolean',
            ],
            [['defaultProviderId', 'defaultSenderIdId'], 'integer'],
            [['defaultProviderHandle', 'defaultSenderIdHandle'], 'string', 'max' => 64],
            ['analyticsLimit', 'integer', 'min' => 1],
            ['analyticsLimit', 'default', 'value' => 1000],
            ['analyticsRetention', 'integer', 'min' => 0],
            ['analyticsRetention', 'default', 'value' => 30],
            ['logsLimit', 'integer', 'min' => 1],
            ['logsLimit', 'default', 'value' => 10000],
            ['logsRetention', 'integer', 'min' => 0],
            ['logsRetention', 'default', 'value' => 30],
            ['itemsPerPage', 'integer', 'min' => 10, 'max' => 500],
            ['itemsPerPage', 'default', 'value' => 100],
            ['refreshIntervalSecs', 'integer', 'min' => 5],
            ['refreshIntervalSecs', 'default', 'value' => 30],
            [['logLevel'], 'in', 'range' => ['debug', 'info', 'warning', 'error']],
            [['logLevel'], 'validateLogLevel'],
        ];
    }

    /**
     * Validate log level - debug requires devMode
     */
    public function validateLogLevel(string $attribute): void
    {
        $logLevel = $this->$attribute;

        if (Craft::$app->getConfig()->getGeneral()->devMode && !Craft::$app->getRequest()->getIsConsoleRequest()) {
            Craft::$app->getSession()->remove('sms_debug_config_warning');
        }

        if ($logLevel === 'debug' && !Craft::$app->getConfig()->getGeneral()->devMode) {
            $this->$attribute = 'info';

            if ($this->isOverriddenByConfig('logLevel')) {
                if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
                    if (Craft::$app->getSession()->get('sms_debug_config_warning') === null) {
                        $this->logWarning('Log level "debug" from config file changed to "info" because devMode is disabled', [
                            'configFile' => 'config/sms-manager.php',
                        ]);
                        Craft::$app->getSession()->set('sms_debug_config_warning', true);
                    }
                } else {
                    $this->logWarning('Log level "debug" from config file changed to "info" because devMode is disabled', [
                        'configFile' => 'config/sms-manager.php',
                    ]);
                }
            } else {
                $this->logWarning('Log level automatically changed from "debug" to "info" because devMode is disabled');
                $this->saveToDatabase();
            }
        }
    }
}
