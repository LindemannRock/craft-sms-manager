<?php
/**
 * LindemannRock SMS Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use lindemannrock\smsmanager\controllers\SettingsController;
use lindemannrock\smsmanager\SmsManager;
use lindemannrock\smsmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.13.0
 */
#[CoversClass(SettingsController::class)]
final class SettingsControllerSectionScopeTest extends TestCase
{
    public function testSettingsSectionsMatchRenderedFormScopes(): void
    {
        $controller = new SettingsController('settings', SmsManager::$plugin);
        $method = new \ReflectionMethod($controller, '_validationAttributesForSection');

        $expected = [
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
                'timeFormat',
                'monthFormat',
                'dateOrder',
                'dateSeparator',
                'showSeconds',
                'defaultDateRange',
                'exportsCsv',
                'exportsJson',
                'exportsExcel',
            ],
            'test' => [],
        ];

        foreach ($expected as $section => $attributes) {
            self::assertSame($attributes, $method->invoke($controller, $section), "Unexpected {$section} settings scope.");
        }
    }
}
