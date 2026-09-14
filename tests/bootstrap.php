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

$baseBootstrap = null;
foreach ([
    dirname(__DIR__) . '/vendor/lindemannrock/craft-plugin-base/src/testing/bootstrap.php',
    dirname(__DIR__, 3) . '/vendor/lindemannrock/craft-plugin-base/src/testing/bootstrap.php',
] as $candidate) {
    if (file_exists($candidate)) {
        $baseBootstrap = $candidate;
        break;
    }
}

if ($baseBootstrap === null) {
    fwrite(STDERR, "Base plugin testing bootstrap not found in the package or workspace vendor.\n");
    fwrite(STDERR, "Run `composer install` and ensure lindemannrock/craft-plugin-base ^5.38.2 is present.\n");
    exit(1);
}

require_once $baseBootstrap;

$projectRoot = $_SERVER['CRAFT_TEST_PROJECT_ROOT'] ?? null;
\lindemannrock\base\testing\bootstrap(is_string($projectRoot) && $projectRoot !== '' ? $projectRoot : null);
