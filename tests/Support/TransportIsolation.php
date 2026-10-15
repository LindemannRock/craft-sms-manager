<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Support;

use Craft;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;

/**
 * Installs a deny-by-default transport before Craft bootstraps plugins.
 *
 * @since 5.18.0
 */
final class TransportIsolation
{
    public static int $interceptedRequests = 0;

    public static function install(): void
    {
        Craft::$container->set(Client::class, static function($container, array $params): Client {
            $config = $params[0] ?? [];
            $mock = $config['handler'] ?? null;
            $config['handler'] = static function($request, array $options) use ($mock) {
                if (!$mock instanceof MockHandler) {
                    // Exit instead of throwing: providers deliberately catch Throwable.
                    fwrite(STDERR, "Forbidden network fallback; no request was made.\n");
                    exit(90);
                }
                self::$interceptedRequests++;
                return $mock($request, $options);
            };
            return new Client($config);
        });
        register_shutdown_function(static function(): void {
            fwrite(STDERR, 'SMS transport isolation: intercepted=' . self::$interceptedRequests . "; real=0; queue-workers=0\n");
        });
    }
}
