<?php
/**
 * LindemannRock SMS Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use Craft;
use craft\config\BaseConfig;
use craft\console\Request as ConsoleRequest;
use craft\console\User as ConsoleUser;
use craft\db\Query;
use craft\services\Config;
use craft\web\Response;
use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;
use lindemannrock\smsmanager\controllers\ProvidersController;
use lindemannrock\smsmanager\controllers\SenderIdsController;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\records\SenderIdRecord;
use lindemannrock\smsmanager\SmsManager;
use lindemannrock\smsmanager\tests\Stubs\StubProvider;
use lindemannrock\smsmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use yii\web\ForbiddenHttpException;

/**
 * Pins list reads and explicit default writes to their permission and request-method contracts.
 *
 * @since 5.16.0
 */
final class DefaultListRoutingTest extends TestCase
{
    private object $originalRequest;

    private object $originalUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalRequest = Craft::$app->get('request');
        $this->originalUser = Craft::$app->get('user');
        $this->installConfig([]);
    }

    protected function tearDown(): void
    {
        Craft::$app->set('request', $this->originalRequest);
        Craft::$app->set('user', $this->originalUser);
        BaseConfigFileHelper::clearCache('sms-manager');
        $this->dropCachedSettings();
        parent::tearDown();
    }

    #[DataProvider('readOnlyDefaultProvider')]
    public function testProviderIndexReadsNeverChangeRoutingState(string $scenario): void
    {
        $expected = $this->prepareProviderScenario($scenario);
        $before = $this->routingState();
        $this->installRequest();
        $this->installUser(['smsManager:manageProviders']);

        $controller = new CapturingProvidersController('providers', SmsManager::$plugin);
        $controller->actionIndex();

        self::assertSame($before, $this->routingState());
        self::assertSame($expected['handle'], $controller->variables['defaultProviderHandle'] ?? null);
        self::assertSame($expected['resolved'], ($controller->variables['defaultProvider'] ?? null) instanceof ProviderRecord);
        if (($controller->variables['defaultProvider'] ?? null) instanceof ProviderRecord) {
            self::assertSame($expected['enabled'], (bool)$controller->variables['defaultProvider']->enabled);
        }
    }

    #[DataProvider('readOnlyDefaultProvider')]
    public function testSenderIndexReadsNeverChangeRoutingState(string $scenario): void
    {
        $expected = $this->prepareSenderScenario($scenario);
        $before = $this->routingState();
        $this->installRequest();
        $this->installUser(['smsManager:manageSenderIds']);

        $controller = new CapturingSenderIdsController('sender-ids', SmsManager::$plugin);
        $controller->actionIndex();

        self::assertSame($before, $this->routingState());
        self::assertSame($expected['handle'], $controller->variables['defaultSenderIdHandle'] ?? null);
        self::assertSame($expected['resolved'], ($controller->variables['defaultSenderId'] ?? null) instanceof SenderIdRecord);
        if (($controller->variables['defaultSenderId'] ?? null) instanceof SenderIdRecord) {
            self::assertSame($expected['enabled'], (bool)$controller->variables['defaultSenderId']->enabled);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function readOnlyDefaultProvider(): iterable
    {
        yield 'missing default' => ['missing'];
        yield 'disabled database default' => ['disabled'];
        yield 'deleted database default' => ['deleted'];
        yield 'valid database default' => ['database'];
        yield 'valid config default' => ['config'];
        yield 'invalid config default' => ['config-invalid'];
    }

    #[DataProvider('resourceProvider')]
    public function testParentAccessCannotUseDefaultWriteActions(string $resource): void
    {
        [$record, $field, $parentPermission] = $this->seedDefaultResource($resource);
        $this->persistDefault($field, null);
        $before = $this->routingState();
        $this->installRequest(post: true, bodyParams: [$resource === 'provider' ? 'providerId' : 'senderIdId' => $record->id]);
        $this->installUser([$parentPermission]);

        $this->expectException(ForbiddenHttpException::class);

        try {
            $this->defaultController($resource)->actionSetDefault();
        } finally {
            self::assertSame($before, $this->routingState());
        }
    }

    #[DataProvider('resourceProvider')]
    public function testEditAccessCanChangeDefaultsThroughExplicitPostActions(string $resource): void
    {
        [$record, $field, $parentPermission, $editPermission] = $this->seedDefaultResource($resource);
        $this->persistDefault($field, null);
        $this->installRequest(post: true, bodyParams: [$resource === 'provider' ? 'providerId' : 'senderIdId' => $record->id]);
        $this->installUser([$parentPermission, $editPermission]);

        $response = $this->defaultController($resource)->actionSetDefault();

        self::assertTrue($response->data['success'] ?? false);
        self::assertSame($record->handle, $this->settingsRow()[$field] ?? null);
    }

    /** @return iterable<string, array{string}> */
    public static function resourceProvider(): iterable
    {
        yield 'provider default' => ['provider'];
        yield 'sender default' => ['sender'];
    }

    public function testInvalidDefaultsStopDefaultSendsBeforeAProviderCall(): void
    {
        $this->registerStubProvider();
        $provider = $this->seedProvider();
        $sender = $this->seedSenderId($provider);

        $this->persistDefault('defaultProviderHandle', self::MARKER . 'missing-provider');
        $this->persistDefault('defaultSenderIdHandle', (string)$sender->handle);
        $providerFailure = $this->sms->send(
            to: $this->markerRecipient('-provider'),
            message: 'must fail before dispatch',
            sourcePlugin: $this->markerSourcePlugin('-provider'),
        );

        self::assertFalse($providerFailure);
        self::assertSame([], StubProvider::$sentCalls);

        $this->persistDefault('defaultProviderHandle', (string)$provider->handle);
        $this->persistDefault('defaultSenderIdHandle', self::MARKER . 'missing-sender');
        $senderFailure = $this->sms->send(
            to: $this->markerRecipient('-sender'),
            message: 'must fail before dispatch',
            sourcePlugin: $this->markerSourcePlugin('-sender'),
        );

        self::assertFalse($senderFailure);
        self::assertSame([], StubProvider::$sentCalls);
    }

    /** @return array{handle: string|null, resolved: bool, enabled: bool|null} */
    private function prepareProviderScenario(string $scenario): array
    {
        $this->seedProvider();

        if ($scenario === 'config' || $scenario === 'config-invalid') {
            $handle = $scenario === 'config' ? self::MARKER . 'config-provider' : self::MARKER . 'missing-config-provider';
            $this->installConfig([
                'defaultProviderHandle' => $handle,
                'providers' => [
                    self::MARKER . 'config-provider' => [
                        'name' => 'Config Provider',
                        'type' => self::STUB_TYPE,
                        'enabled' => true,
                        'settings' => ['allowedCountries' => ['*']],
                    ],
                ],
            ]);

            return ['handle' => $handle, 'resolved' => $scenario === 'config', 'enabled' => $scenario === 'config' ? true : null];
        }

        if ($scenario === 'missing') {
            $this->persistDefault('defaultProviderHandle', null);
            return ['handle' => null, 'resolved' => false, 'enabled' => null];
        }

        $target = $this->seedProvider(['enabled' => $scenario !== 'disabled']);
        $handle = (string)$target->handle;
        $this->persistDefault('defaultProviderHandle', $handle);
        if ($scenario === 'deleted') {
            self::assertSame(1, $target->delete());
        }

        return [
            'handle' => $handle,
            'resolved' => $scenario !== 'deleted',
            'enabled' => $scenario === 'disabled' ? false : ($scenario === 'deleted' ? null : true),
        ];
    }

    /** @return array{handle: string|null, resolved: bool, enabled: bool|null} */
    private function prepareSenderScenario(string $scenario): array
    {
        $provider = $this->seedProvider();
        $this->seedSenderId($provider);

        if ($scenario === 'config' || $scenario === 'config-invalid') {
            $providerHandle = self::MARKER . 'config-provider';
            $senderHandle = self::MARKER . 'config-sender';
            $handle = $scenario === 'config' ? $senderHandle : self::MARKER . 'missing-config-sender';
            $this->installConfig([
                'defaultSenderIdHandle' => $handle,
                'providers' => [
                    $providerHandle => [
                        'name' => 'Config Provider',
                        'type' => self::STUB_TYPE,
                        'enabled' => true,
                    ],
                ],
                'senderIds' => [
                    $senderHandle => [
                        'name' => 'Config Sender',
                        'senderId' => 'ConfigSender',
                        'provider' => $providerHandle,
                        'enabled' => true,
                    ],
                ],
            ]);

            return ['handle' => $handle, 'resolved' => $scenario === 'config', 'enabled' => $scenario === 'config' ? true : null];
        }

        if ($scenario === 'missing') {
            $this->persistDefault('defaultSenderIdHandle', null);
            return ['handle' => null, 'resolved' => false, 'enabled' => null];
        }

        $target = $this->seedSenderId($provider, ['enabled' => $scenario !== 'disabled']);
        $handle = (string)$target->handle;
        $this->persistDefault('defaultSenderIdHandle', $handle);
        if ($scenario === 'deleted') {
            self::assertSame(1, $target->delete());
        }

        return [
            'handle' => $handle,
            'resolved' => $scenario !== 'deleted',
            'enabled' => $scenario === 'disabled' ? false : ($scenario === 'deleted' ? null : true),
        ];
    }

    /** @return array{0: ProviderRecord|SenderIdRecord, 1: string, 2: string, 3: string} */
    private function seedDefaultResource(string $resource): array
    {
        if ($resource === 'provider') {
            return [
                $this->seedProvider(),
                'defaultProviderHandle',
                'smsManager:manageProviders',
                'smsManager:editProviders',
            ];
        }

        $provider = $this->seedProvider();
        return [
            $this->seedSenderId($provider),
            'defaultSenderIdHandle',
            'smsManager:manageSenderIds',
            'smsManager:editSenderIds',
        ];
    }

    private function defaultController(string $resource): CapturingProvidersController|CapturingSenderIdsController
    {
        return $resource === 'provider'
            ? new CapturingProvidersController('providers', SmsManager::$plugin)
            : new CapturingSenderIdsController('sender-ids', SmsManager::$plugin);
    }

    private function persistDefault(string $field, ?string $handle): void
    {
        $settings = $this->settings();
        $settings->{$field} = $handle;
        self::assertTrue($settings->saveToDatabase([$field]));
    }

    private function installConfig(array $config): void
    {
        Craft::$app->set('config', new RoutingConfig(['smsManagerConfig' => $config]));
        BaseConfigFileHelper::clearCache('sms-manager');
        $this->dropCachedSettings();
    }

    /** @param list<string> $permissions */
    private function installUser(array $permissions): void
    {
        Craft::$app->set('user', new RoutingUser($permissions));
    }

    /** @param array<string, mixed> $bodyParams */
    private function installRequest(bool $post = false, array $bodyParams = []): void
    {
        Craft::$app->set('request', new RoutingRequest($post, $bodyParams));
    }

    private function dropCachedSettings(): void
    {
        $reflection = new ReflectionClass(\craft\base\Plugin::class);
        $property = $reflection->getProperty('_settings');
        $property->setAccessible(true);
        $property->setValue(SmsManager::$plugin, null);
    }

    /** @return array<string, mixed> */
    private function routingState(): array
    {
        return [
            'providers' => (new Query())->from(ProviderRecord::tableName())->orderBy(['id' => SORT_ASC])->all(),
            'senders' => (new Query())->from(SenderIdRecord::tableName())->orderBy(['id' => SORT_ASC])->all(),
            'settings' => $this->settingsRow(),
        ];
    }

    /** @return array<string, mixed> */
    private function settingsRow(): array
    {
        $row = (new Query())->from('{{%smsmanager_settings}}')->where(['id' => 1])->one();
        self::assertIsArray($row);
        return $row;
    }
}

final class RoutingConfig extends Config
{
    /** @var array<string, mixed> */
    public array $smsManagerConfig = [];

    public function getConfigFromFile(string $filename): array|callable|BaseConfig
    {
        return $filename === 'sms-manager' ? $this->smsManagerConfig : parent::getConfigFromFile($filename);
    }
}

final class RoutingUser extends ConsoleUser
{
    /** @var array<string, true> */
    private array $permissions;

    /** @param list<string> $permissions */
    public function __construct(array $permissions)
    {
        $this->permissions = array_fill_keys($permissions, true);
        parent::__construct();
    }

    public function checkPermission(string $permissionName): bool
    {
        return isset($this->permissions[$permissionName]);
    }
}

final class RoutingRequest extends ConsoleRequest
{
    /** @param array<string, mixed> $bodyParams */
    public function __construct(
        private readonly bool $post = false,
        private readonly array $bodyParams = [],
    ) {
        parent::__construct();
    }

    public function getIsPost(): bool
    {
        return $this->post;
    }

    public function getAcceptsJson(): bool
    {
        return true;
    }

    public function getIsOptions(): bool
    {
        return false;
    }

    public function getQueryParam($name, $defaultValue = null): mixed
    {
        return $defaultValue;
    }

    public function getParam($name, $defaultValue = null): mixed
    {
        return $this->bodyParams[$name] ?? $defaultValue;
    }

    public function getBodyParam($name, $defaultValue = null): mixed
    {
        return $this->bodyParams[$name] ?? $defaultValue;
    }

    public function getRequiredBodyParam(string $name): mixed
    {
        if (!array_key_exists($name, $this->bodyParams)) {
            throw new \yii\web\BadRequestHttpException("Missing required body parameter: {$name}");
        }

        return $this->bodyParams[$name];
    }
}

final class CapturingProvidersController extends ProvidersController
{
    /** @var array<string, mixed> */
    public array $variables = [];

    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        $this->variables = $variables;
        return new Response(['data' => $variables]);
    }

    public function asJson($data): Response
    {
        return new Response(['format' => Response::FORMAT_JSON, 'data' => $data]);
    }
}

final class CapturingSenderIdsController extends SenderIdsController
{
    /** @var array<string, mixed> */
    public array $variables = [];

    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        $this->variables = $variables;
        return new Response(['data' => $variables]);
    }

    public function asJson($data): Response
    {
        return new Response(['format' => Response::FORMAT_JSON, 'data' => $data]);
    }
}
