<?php

/**
 * Isolated process used only against the runner-owned disposable database.
 *
 * @link https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */
declare(strict_types=1);

$root = $_SERVER['CRAFT_TEST_PROJECT_ROOT'] ?? '';
if (!is_string($root) || !preg_match('#/sms-manager-fixture-[a-f0-9]{16}$#', $root)) {
    exit(91);
}
require $root . '/bootstrap.php';
require dirname(__DIR__, 2) . '/Support/TransportIsolation.php';
require dirname(__DIR__, 2) . '/Stubs/ProviderPrivacyConfig.php';
function craft_modify_app_config(array &$config, string $appType): void
{
    \lindemannrock\smsmanager\tests\Support\TransportIsolation::install();
}
$app = require $root . '/vendor/craftcms/cms/bootstrap/console.php';
if (($argv[1] ?? '') === '--prove-deny') {
    \Craft::createGuzzleClient()->get('https://gateway.example.test/forbidden-fallback');
    exit(93);
}
\lindemannrock\smsmanager\tests\Stubs\ProviderPrivacyConfig::$handler = new \GuzzleHttp\Handler\MockHandler([
    static function() {
        fwrite(STDOUT, "ADMITTED\n");
        fflush(STDOUT);
        if (trim((string)fgets(STDIN)) !== 'complete') {
            exit(92);
        }
        return new \GuzzleHttp\Psr7\Response(200, [], 'error,user is inactive');
    },
]);
$app->set('config', new \lindemannrock\smsmanager\tests\Stubs\ProviderPrivacyConfig());
$result = (new \lindemannrock\smsmanager\providers\MppSmsProvider())->send(
    '+12025550123', 'Synthetic concurrency', 'Example', 'en',
    ['apiKey' => 'synthetic-concurrent-account', 'apiUrl' => 'https://gateway.example.test/send', 'allowedCountries' => ['*']],
);
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
