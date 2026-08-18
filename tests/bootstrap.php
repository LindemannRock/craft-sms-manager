<?php

/**
 * PHPUnit bootstrap for the sms-manager plugin.
 *
 * Delegates to the shared base-plugin bootstrap, which initialises Craft as a
 * console application. The permanent Craft queue, analytics, logs, and plugin
 * settings are hidden by connection-local temporary tables before enabled
 * plugins bootstrap. Other cleanup remains marker-owned.
 *
 * @since 5.12.0
 */

declare(strict_types=1);

use lindemannrock\smsmanager\tests\Support\IsolatedPersistenceQueue;

if (!function_exists('craft_modify_app_config')) {
    /** Install the persistence shadows before Craft bootstraps enabled plugins. */
    function craft_modify_app_config(array &$config, string $appType): void
    {
        if ($appType !== 'console') {
            throw new \RuntimeException('SMS Manager tests require Craft\'s console application.');
        }

        $queueConfig = $config['components']['queue'] ?? [];
        if (!is_array($queueConfig)) {
            throw new \RuntimeException('SMS Manager tests require an array-configured Craft queue.');
        }
        $queueConfig['class'] = IsolatedPersistenceQueue::class;
        $queueConfig['proxyQueue'] = null;
        $config['components']['queue'] = $queueConfig;
    }
}

$baseBootstrap = dirname(__DIR__, 3) . '/vendor/lindemannrock/craft-plugin-base/src/testing/bootstrap.php';

if (!file_exists($baseBootstrap)) {
    fwrite(STDERR, "Base plugin testing bootstrap not found at {$baseBootstrap}\n");
    fwrite(STDERR, "Run `composer install` and ensure lindemannrock/craft-plugin-base ^5.0 is present.\n");
    exit(1);
}

require_once $baseBootstrap;

\lindemannrock\base\testing\bootstrap();
