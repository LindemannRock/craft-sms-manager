<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

use lindemannrock\smsmanager\tests\Support\DisposableCraftProject;

$packageRoot = dirname(__DIR__, 3);
$vendorEnvironment = 'SMS_MANAGER_FIXTURE_SOURCE_VENDOR_ROOT';
$vendorRoot = $_SERVER[$vendorEnvironment] ?? null;
if (!is_string($vendorRoot) || $vendorRoot === '') {
    fwrite(STDERR, $vendorEnvironment . " must be set.\n");
    exit(2);
}
require rtrim($vendorRoot, DIRECTORY_SEPARATOR) . '/autoload.php';

$project = new DisposableCraftProject($packageRoot);
if (!function_exists('pcntl_async_signals')) {
    fwrite(STDERR, "The disposable runner requires PCNTL signal support.\n");
    exit(2);
}
pcntl_async_signals(true);
foreach ([SIGHUP, SIGINT, SIGTERM] as $signal) {
    pcntl_signal($signal, static function(int $received) use ($project): never {
        $project->terminateActiveProcess();
        try {
            $project->cleanup();
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Signal cleanup failed: ' . $exception->getMessage() . PHP_EOL);
        }
        exit(128 + $received);
    });
}

try {
    $result = $project->run(array_slice($argv, 1));
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
    exit($exception->getCode() > 0 && $exception->getCode() < 256 ? $exception->getCode() : 1);
}
