<?php

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Stubs;

use craft\config\BaseConfig;
use craft\services\Config;

/**
 * Test-time replacement for `Craft::$app->config` that hides any on-disk
 * `config/sms-manager.php` overrides while leaving every other plugin /
 * Craft config file (general, db, …) untouched.
 *
 * `SmsManager::getSettings()` always calls
 * `PluginHelper::applyConfigOverridesToSettings($settings, 'sms-manager')`,
 * which loops over `Craft::$app->getConfig()->getConfigFromFile('sms-manager')`
 * and reapplies file-level values to the Settings model. Without this stub,
 * a developer's local `config/sms-manager.php` (e.g. with
 * `'defaultSenderIdHandle' => '…'`) would clobber any mutation a test
 * makes to the cached Settings instance, making the
 * 8.2 fail-loud-on-bad-default behaviour impossible to assert.
 *
 * Tests swap this in via `Craft::$app->set('config', new SmsManagerConfigStub())`
 * in setUp and restore the original service in tearDown.
 *
 * @since 5.13.0
 */
final class SmsManagerConfigStub extends Config
{
    public function getConfigFromFile(string $filename): array|callable|BaseConfig
    {
        if ($filename === 'sms-manager') {
            return [];
        }
        return parent::getConfigFromFile($filename);
    }
}
