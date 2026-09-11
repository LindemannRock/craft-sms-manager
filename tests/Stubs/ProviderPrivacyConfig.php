<?php
/**
 * LindemannRock SMS Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Stubs;

use craft\config\BaseConfig;
use craft\services\Config;
use GuzzleHttp\Handler\MockHandler;

/**
 * Isolates provider HTTP tests from DNS and live network access.
 *
 * @since 5.16.0
 */
final class ProviderPrivacyConfig extends Config
{
    public static ?MockHandler $handler = null;

    public function getConfigFromFile(string $filename): array|callable|BaseConfig
    {
        if ($filename === 'sms-manager') {
            return [
                'security' => [
                    'requireHttps' => true,
                    'blockPrivateNetworks' => false,
                    'allowRedirects' => false,
                    'allowedPorts' => [443],
                    'allowedApiHosts' => ['gateway.example.test'],
                ],
            ];
        }

        if ($filename === 'guzzle' && self::$handler !== null) {
            return ['handler' => self::$handler];
        }

        return parent::getConfigFromFile($filename);
    }

    public static function reset(): void
    {
        self::$handler = null;
    }
}
