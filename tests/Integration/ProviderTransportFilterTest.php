<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use Craft;
use craft\console\User;
use craft\web\Request;
use craft\web\Response;
use lindemannrock\smsmanager\controllers\ProvidersController;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\services\ProvidersService;
use lindemannrock\smsmanager\services\TransportService;
use lindemannrock\smsmanager\SmsManager;
use lindemannrock\smsmanager\tests\Support\TransportIsolation;
use lindemannrock\smsmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Provider transport filters compose with existing list controls without sending.
 *
 * @since 5.18.1
 */
final class ProviderTransportFilterTest extends TestCase
{
    #[DataProvider('transportFilters')]
    public function testTransportStatesFilterIndependently(string $filter, string $expectedFilter, int $expectedCount): void
    {
        $variables = $this->index(['transport' => $filter]);

        self::assertSame($expectedFilter, $variables['transportFilter']);
        self::assertSame($expectedCount, $variables['totalCount']);
        self::assertCount($expectedCount, $variables['providers']);
        foreach ($variables['providers'] as $provider) {
            if ($expectedFilter !== 'all') {
                self::assertSame($expectedFilter, $variables['transportStatuses'][$provider->handle]['state']);
            }
        }
    }

    public static function transportFilters(): iterable
    {
        yield 'available' => ['available', 'available', 1];
        yield 'on hold' => ['on-hold', 'on-hold', 3];
        yield 'unavailable' => ['unavailable', 'unavailable', 1];
        yield 'not configured' => ['not-configured', 'not-configured', 1];
        yield 'all' => ['all', 'all', 6];
        yield 'invalid falls back to all' => ['invalid', 'all', 6];
    }

    public function testCombinedFiltersCountBeforePaginationAndPreserveSorting(): void
    {
        $this->settings()->itemsPerPage = 1;
        $variables = $this->index([
            'transport' => 'on-hold', 'status' => 'enabled', 'source' => 'database',
            'search' => 'Synthetic', 'sort' => 'name', 'dir' => 'desc', 'page' => 2,
        ]);

        self::assertSame(2, $variables['totalCount']);
        self::assertCount(1, $variables['providers']);
        self::assertSame('Synthetic hold A', $variables['providers'][0]->name);
        self::assertSame(2, $variables['page']);
        self::assertTrue($variables['hasAnyProviders']);
    }

    private function index(array $params): array
    {
        $providers = [];
        $states = [];
        foreach ([
            ['available', 'available', true],
            ['hold A', 'on-hold', true],
            ['hold B', 'on-hold', true],
            ['hold disabled', 'on-hold', false],
            ['unavailable', 'unavailable', true],
            ['not configured', 'not-configured', true],
        ] as [$name, $state, $enabled]) {
            $provider = $this->seedProvider(['name' => 'Synthetic ' . $name, 'enabled' => $enabled]);
            $providers[] = $provider;
            $states[$provider->handle] = ['state' => $state, 'holds' => []];
        }

        $providerService = $this->createMock(ProvidersService::class);
        $providerService->method('getAllProviders')->willReturn($providers);
        $this->swapPluginComponent('sms-manager', 'providers', $providerService);
        $reads = [];
        $transport = $this->createMock(TransportService::class);
        $transport->expects(self::never())->method('send');
        $transport->method('statusForProvider')->willReturnCallback(static function(ProviderRecord $provider) use ($states, &$reads): array {
            $reads[$provider->handle] = ($reads[$provider->handle] ?? 0) + 1;
            return $states[$provider->handle];
        });
        $this->swapPluginComponent('sms-manager', 'transport', $transport);

        $request = $this->createMock(Request::class);
        foreach (['getQueryParam', 'getParam'] as $method) {
            $request->method($method)->willReturnCallback(static fn($name, $default = null) => $params[$name] ?? $default);
        }
        $user = $this->createMock(User::class);
        $user->method('checkPermission')->willReturn(true);
        $originalRequest = Craft::$app->getRequest();
        $originalUser = Craft::$app->getUser();
        $variables = [];
        $controller = $this->getMockBuilder(ProvidersController::class)
            ->setConstructorArgs(['providers', SmsManager::$plugin])
            ->onlyMethods(['renderTemplate'])->getMock();
        $controller->method('renderTemplate')->willReturnCallback(static function($template, $context) use (&$variables): Response {
            $variables = $context;
            return new Response();
        });
        $before = TransportIsolation::$interceptedRequests;
        try {
            Craft::$app->set('request', $request);
            Craft::$app->set('user', $user);
            $controller->actionIndex();
        } finally {
            Craft::$app->set('request', $originalRequest);
            Craft::$app->set('user', $originalUser);
        }
        self::assertSame($before, TransportIsolation::$interceptedRequests);
        foreach ($reads as $count) {
            self::assertSame(1, $count, 'Filtering and row badges must share one status snapshot.');
        }
        return $variables;
    }
}
