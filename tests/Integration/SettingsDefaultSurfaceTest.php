<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use Craft;
use craft\config\BaseConfig;
use craft\services\Config;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\smsmanager\models\Settings;
use lindemannrock\smsmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @since 5.16.0
 */
#[CoversClass(Settings::class)]
final class SettingsDefaultSurfaceTest extends TestCase
{
    public function testSettingsExposeOnlyHandleBasedDefaults(): void
    {
        $settings = new Settings();

        self::assertTrue($settings->hasProperty('defaultProviderHandle'));
        self::assertTrue($settings->hasProperty('defaultSenderIdHandle'));
        self::assertFalse($settings->hasProperty('defaultProviderId'));
        self::assertFalse($settings->hasProperty('defaultSenderIdId'));
        self::assertArrayHasKey('defaultProviderHandle', $settings->attributeLabels());
        self::assertArrayHasKey('defaultSenderIdHandle', $settings->attributeLabels());
        self::assertArrayNotHasKey('defaultProviderId', $settings->attributeLabels());
        self::assertArrayNotHasKey('defaultSenderIdId', $settings->attributeLabels());
    }

    public function testLegacyConfigKeysAreIgnoredWhileHandleDefaultsStillApply(): void
    {
        $originalConfig = Craft::$app->getConfig();
        Craft::$app->set('config', new class() extends Config {
            public function getConfigFromFile(string $filename): array|callable|BaseConfig
            {
                if ($filename === 'sms-manager') {
                    return [
                        'defaultProviderId' => 91,
                        'defaultSenderIdId' => 92,
                        'defaultProviderHandle' => 'configured-provider',
                        'defaultSenderIdHandle' => 'configured-sender',
                    ];
                }

                return parent::getConfigFromFile($filename);
            }
        });

        try {
            $settings = PluginHelper::applyConfigOverridesToSettings(new Settings(), 'sms-manager');

            self::assertSame('configured-provider', $settings->defaultProviderHandle);
            self::assertSame('configured-sender', $settings->defaultSenderIdHandle);
            self::assertFalse($settings->hasProperty('defaultProviderId'));
            self::assertFalse($settings->hasProperty('defaultSenderIdId'));
        } finally {
            Craft::$app->set('config', $originalConfig);
        }
    }
}
