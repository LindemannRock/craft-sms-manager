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
use craft\web\View;
use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;
use lindemannrock\smsmanager\controllers\ProvidersController;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\records\SenderIdRecord;
use lindemannrock\smsmanager\services\ProvidersService;
use lindemannrock\smsmanager\SmsManager;
use lindemannrock\smsmanager\tests\Stubs\ConnectionTestSpyProvider;
use lindemannrock\smsmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Protects stored provider credentials and credential-backed connection actions.
 *
 * @since 5.16.0
 */
final class ProviderCredentialAccessPermissionTest extends TestCase
{
    private object $originalRequest;

    private object $originalUser;

    private object $originalConfig;

    private string $originalTemplateMode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalRequest = Craft::$app->get('request');
        $this->originalUser = Craft::$app->get('user');
        $this->originalConfig = Craft::$app->get('config');
        $this->originalTemplateMode = Craft::$app->getView()->getTemplateMode();
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);

        $providers = new ProvidersService();
        $providers->registerProviderType(ConnectionTestSpyProvider::class);
        $this->swapPluginComponent('sms-manager', 'providers', $providers);
        $this->providers = $providers;
        ConnectionTestSpyProvider::reset();
    }

    protected function tearDown(): void
    {
        try {
            self::assertSame([], ConnectionTestSpyProvider::$sendCalls, 'Connection checks must never send SMS.');
        } finally {
            ConnectionTestSpyProvider::reset();
            Craft::$app->set('request', $this->originalRequest);
            Craft::$app->set('user', $this->originalUser);
            Craft::$app->set('config', $this->originalConfig);
            Craft::$app->getView()->setTemplateMode($this->originalTemplateMode);
            BaseConfigFileHelper::clearCache('sms-manager');
            $this->dropCachedSettings();
            parent::tearDown();
        }
    }

    #[DataProvider('nonEditProviderPermissions')]
    public function testDatabaseCredentialViewRequiresEditPermission(array $permissions): void
    {
        [$literalCanary, $environmentCanary] = $this->credentialCanaries();
        $provider = $this->seedProvider([
            'type' => ConnectionTestSpyProvider::handle(),
            'settings' => json_encode(
                $this->providerSettings(ConnectionTestSpyProvider::handle(), $literalCanary, $environmentCanary),
                JSON_THROW_ON_ERROR,
            ),
        ]);
        $this->seedSenderId($provider);
        $before = $this->completeState();
        $this->installUser($permissions);
        $controller = $this->controller();

        [$status, $output] = $this->captureHttpOutcome(function() use ($controller, $provider): string {
            $controller->actionView($provider->handle);

            return $controller->viewProjection();
        });

        self::assertSame([
            'status' => 403,
            'literalCredentialPresent' => false,
            'environmentCredentialPresent' => false,
            'providerConstructions' => 0,
            'connectionCalls' => 0,
            'stateUnchanged' => true,
        ], [
            'status' => $status,
            'literalCredentialPresent' => str_contains($output, $literalCanary),
            'environmentCredentialPresent' => str_contains($output, $environmentCanary),
            'providerConstructions' => ConnectionTestSpyProvider::$constructionCount,
            'connectionCalls' => count(ConnectionTestSpyProvider::$connectionCalls),
            'stateUnchanged' => $before === $this->completeState(),
        ]);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function nonEditProviderPermissions(): iterable
    {
        yield 'parent read access' => [['smsManager:manageProviders']];
        yield 'parent and create access' => [['smsManager:manageProviders', 'smsManager:createProviders']];
    }

    #[DataProvider('providerFamilyProvider')]
    public function testProviderEditorsCanViewStoredSettings(string $type, string $expectedField): void
    {
        [$literalCanary, $environmentCanary] = $this->credentialCanaries();
        $settings = $this->providerSettings($type, $literalCanary, $environmentCanary);
        $provider = $this->seedProvider([
            'type' => $type,
            'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
        ]);
        $this->seedSenderId($provider);
        $before = $this->completeState();
        $this->installUser(['smsManager:manageProviders', 'smsManager:editProviders']);
        $controller = $this->controller();

        $response = $controller->actionView($provider->handle);

        self::assertSame('sms-manager/providers/edit', $controller->template);
        self::assertSame($controller->variables, $response->data);
        self::assertSame([
            'provider',
            'providerSettings',
            'providerTypes',
            'countryOptions',
            'settingsHtml',
            'isNew',
            'providerCount',
            'defaultProviderHandle',
            'isDefaultFromConfig',
            'providerMeta',
        ], array_keys($controller->variables));
        self::assertSame($settings, $controller->variables['providerSettings']);
        self::assertStringContainsString($expectedField, $controller->variables['settingsHtml']);
        self::assertStringContainsString($literalCanary, $controller->variables['settingsHtml']);
        self::assertStringContainsString($environmentCanary, $controller->variables['settingsHtml']);
        self::assertSame($before, $this->completeState());
    }

    /** @return iterable<string, array{string, string}> */
    public static function providerFamilyProvider(): iterable
    {
        yield 'MPP provider' => ['mpp-sms', 'providerSettings-apiKey'];
        yield 'Twilio provider' => ['twilio', 'providerSettings-authToken'];
        yield 'generic custom provider' => [ConnectionTestSpyProvider::handle(), 'providerSettings-password'];
    }

    public function testEditByIdFlowRemainsAvailableToProviderEditors(): void
    {
        [$literalCanary, $environmentCanary] = $this->credentialCanaries();
        $provider = $this->seedProvider([
            'type' => 'mpp-sms',
            'settings' => json_encode(
                $this->providerSettings('mpp-sms', $literalCanary, $environmentCanary),
                JSON_THROW_ON_ERROR,
            ),
        ]);
        $before = $this->completeState();
        $this->installUser(['smsManager:editProviders']);
        $controller = $this->controller();

        $controller->actionEdit((int)$provider->id);

        self::assertSame('sms-manager/providers/edit', $controller->template);
        self::assertSame($provider->id, $controller->variables['provider']->id);
        self::assertStringContainsString($literalCanary, $controller->variables['settingsHtml']);
        self::assertStringContainsString($environmentCanary, $controller->variables['settingsHtml']);
        self::assertSame($before, $this->completeState());
    }

    public function testReadOnlyConfigViewRemainsSanitized(): void
    {
        [$literalCanary, $environmentCanary] = $this->credentialCanaries();
        $handle = $this->installConfigProvider(ConnectionTestSpyProvider::handle(), [
            'apiUrl' => 'https://gateway.example.test/send',
            'apiKey' => $literalCanary,
            'username' => 'visible-config-user',
            'password' => $environmentCanary,
        ]);
        $before = $this->completeState();
        $this->installUser(['smsManager:manageProviders']);
        $controller = $this->controller();

        $controller->actionView($handle);
        $output = $controller->viewProjection();

        self::assertSame('config', $controller->variables['provider']->source);
        self::assertSame([], $controller->variables['providerSettings']);
        self::assertSame('', $controller->variables['settingsHtml']);
        self::assertStringContainsString('visible-config-user', $output);
        self::assertStringContainsString('********', $output);
        $this->assertSecretsAbsent($output, [$literalCanary, $environmentCanary]);
        self::assertSame(0, ConnectionTestSpyProvider::$constructionCount);
        self::assertSame([], ConnectionTestSpyProvider::$connectionCalls);
        self::assertSame($before, $this->completeState());
    }

    #[DataProvider('nonEditConnectionRequests')]
    public function testConnectionTestingRequiresEditPermission(string $source, array $permissions): void
    {
        [$literalCanary, $environmentCanary] = $this->credentialCanaries();
        $settings = $this->providerSettings(
            ConnectionTestSpyProvider::handle(),
            $literalCanary,
            $environmentCanary,
        );
        if ($source === 'database') {
            $provider = $this->seedProvider([
                'type' => ConnectionTestSpyProvider::handle(),
                'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
            ]);
            $this->seedSenderId($provider);
            $target = $provider->id;
        } else {
            $target = $this->installConfigProvider(ConnectionTestSpyProvider::handle(), $settings);
        }
        $before = $this->completeState();
        $this->installRequest(bodyParams: ['providerId' => $target]);
        $this->installUser($permissions);
        $controller = $this->controller();

        [$status, $output] = $this->captureHttpOutcome(function() use ($controller): string {
            $response = $controller->actionTestConnection();

            return json_encode($response->data, JSON_THROW_ON_ERROR);
        });

        self::assertSame([
            'status' => 403,
            'literalCredentialPresent' => false,
            'environmentCredentialPresent' => false,
            'providerConstructions' => 0,
            'connectionCalls' => 0,
            'stateUnchanged' => true,
        ], [
            'status' => $status,
            'literalCredentialPresent' => str_contains($output, $literalCanary),
            'environmentCredentialPresent' => str_contains($output, $environmentCanary),
            'providerConstructions' => ConnectionTestSpyProvider::$constructionCount,
            'connectionCalls' => count(ConnectionTestSpyProvider::$connectionCalls),
            'stateUnchanged' => $before === $this->completeState(),
        ]);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function nonEditConnectionRequests(): iterable
    {
        yield 'parent access with database provider' => ['database', ['smsManager:manageProviders']];
        yield 'parent access with config provider' => ['config', ['smsManager:manageProviders']];
        yield 'parent and create access with database provider' => ['database', ['smsManager:manageProviders', 'smsManager:createProviders']];
        yield 'parent and create access with config provider' => ['config', ['smsManager:manageProviders', 'smsManager:createProviders']];
    }

    #[DataProvider('authorizedConnectionProvider')]
    public function testProviderEditorsRetainConnectionResults(
        string $source,
        string $type,
        bool $connectionResult,
        int $expectedSpyCalls,
    ): void {
        [$literalCanary, $environmentCanary] = $this->credentialCanaries();
        $settings = $this->providerSettings($type, $literalCanary, $environmentCanary, $connectionResult);
        if ($source === 'database') {
            $provider = $this->seedProvider([
                'type' => $type,
                'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
            ]);
            $target = $provider->id;
        } else {
            $target = $this->installConfigProvider($type, $settings);
        }
        $before = $this->completeState();
        $this->installRequest(bodyParams: ['providerId' => $target]);
        $this->installUser(['smsManager:editProviders']);

        $response = $this->controller()->actionTestConnection();

        self::assertSame(Response::FORMAT_JSON, $response->format);
        self::assertSame($connectionResult, $response->data);
        self::assertSame($expectedSpyCalls, count(ConnectionTestSpyProvider::$connectionCalls));
        if ($expectedSpyCalls === 1) {
            self::assertSame($settings, ConnectionTestSpyProvider::$connectionCalls[0]);
        }
        self::assertSame($before, $this->completeState());
    }

    /** @return iterable<string, array{string, string, bool, int}> */
    public static function authorizedConnectionProvider(): iterable
    {
        yield 'database MPP provider' => ['database', 'mpp-sms', true, 0];
        yield 'config MPP provider' => ['config', 'mpp-sms', true, 0];
        yield 'database Twilio provider' => ['database', 'twilio', true, 0];
        yield 'config Twilio provider' => ['config', 'twilio', true, 0];
        yield 'database custom provider' => ['database', ConnectionTestSpyProvider::handle(), true, 1];
        yield 'config custom provider' => ['config', ConnectionTestSpyProvider::handle(), false, 1];
    }

    #[DataProvider('invalidViewHandleProvider')]
    public function testMissingAndUnknownViewHandlesFailWithoutCredentials(?string $handle): void
    {
        [$literalCanary, $environmentCanary] = $this->credentialCanaries();
        $this->seedProvider([
            'type' => ConnectionTestSpyProvider::handle(),
            'settings' => json_encode(
                $this->providerSettings(ConnectionTestSpyProvider::handle(), $literalCanary, $environmentCanary),
                JSON_THROW_ON_ERROR,
            ),
        ]);
        $before = $this->completeState();
        $this->installUser(['smsManager:manageProviders', 'smsManager:editProviders']);

        $outcomes = [];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $controller = $this->controller();
            $outcomes[] = $this->captureHttpOutcome(function() use ($controller, $handle): string {
                $controller->actionView($handle);

                return $controller->viewProjection();
            });
        }

        self::assertSame($outcomes[0], $outcomes[1]);
        self::assertSame(404, $outcomes[0][0]);
        $this->assertSecretsAbsent($outcomes[0][1], [$literalCanary, $environmentCanary]);
        self::assertSame(0, ConnectionTestSpyProvider::$constructionCount);
        self::assertSame([], ConnectionTestSpyProvider::$connectionCalls);
        self::assertSame($before, $this->completeState());
    }

    /** @return iterable<string, array{string|null}> */
    public static function invalidViewHandleProvider(): iterable
    {
        yield 'missing handle' => [null];
        yield 'unknown handle' => ['__sm_test_missing_provider'];
    }

    #[DataProvider('invalidConnectionTargetProvider')]
    public function testMissingAndUnknownConnectionTargetsRemainSafe(array $bodyParams, int $expectedStatus): void
    {
        [$literalCanary, $environmentCanary] = $this->credentialCanaries();
        $this->seedProvider([
            'type' => ConnectionTestSpyProvider::handle(),
            'settings' => json_encode(
                $this->providerSettings(ConnectionTestSpyProvider::handle(), $literalCanary, $environmentCanary),
                JSON_THROW_ON_ERROR,
            ),
        ]);
        $before = $this->completeState();
        $this->installUser(['smsManager:editProviders']);

        $outcomes = [];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->installRequest(bodyParams: $bodyParams);
            $controller = $this->controller();
            $outcomes[] = $this->captureHttpOutcome(function() use ($controller): string {
                $response = $controller->actionTestConnection();

                return json_encode($response->data, JSON_THROW_ON_ERROR);
            });
        }

        self::assertSame($outcomes[0], $outcomes[1]);
        self::assertSame($expectedStatus, $outcomes[0][0]);
        $this->assertSecretsAbsent($outcomes[0][1], [$literalCanary, $environmentCanary]);
        self::assertSame(0, ConnectionTestSpyProvider::$constructionCount);
        self::assertSame([], ConnectionTestSpyProvider::$connectionCalls);
        self::assertSame($before, $this->completeState());
    }

    /** @return iterable<string, array{array<string, mixed>, int}> */
    public static function invalidConnectionTargetProvider(): iterable
    {
        yield 'missing target' => [[], 400];
        yield 'unknown numeric ID' => [['providerId' => PHP_INT_MAX], 200];
        yield 'unknown handle' => [['providerId' => '__sm_test_missing_provider'], 200];
    }

    #[DataProvider('connectionRequestGuardProvider')]
    public function testConnectionTestingStillRequiresPostAndJson(
        bool $post,
        bool $acceptsJson,
        int $expectedStatus,
    ): void {
        [$literalCanary, $environmentCanary] = $this->credentialCanaries();
        $provider = $this->seedProvider([
            'type' => ConnectionTestSpyProvider::handle(),
            'settings' => json_encode(
                $this->providerSettings(ConnectionTestSpyProvider::handle(), $literalCanary, $environmentCanary),
                JSON_THROW_ON_ERROR,
            ),
        ]);
        $before = $this->completeState();
        $this->installRequest($post, $acceptsJson, ['providerId' => $provider->id]);
        $this->installUser(['smsManager:editProviders']);
        $controller = $this->controller();

        [$status, $output] = $this->captureHttpOutcome(function() use ($controller): string {
            $response = $controller->actionTestConnection();

            return json_encode($response->data, JSON_THROW_ON_ERROR);
        });

        self::assertSame($expectedStatus, $status);
        $this->assertSecretsAbsent($output, [$literalCanary, $environmentCanary]);
        self::assertSame(0, ConnectionTestSpyProvider::$constructionCount);
        self::assertSame([], ConnectionTestSpyProvider::$connectionCalls);
        self::assertSame($before, $this->completeState());
    }

    /** @return iterable<string, array{bool, bool, int}> */
    public static function connectionRequestGuardProvider(): iterable
    {
        yield 'non-POST request' => [false, true, 405];
        yield 'non-JSON request' => [true, false, 400];
    }

    public function testProviderActionsRetainCsrfValidation(): void
    {
        self::assertTrue($this->controller()->enableCsrfValidation);
    }

    /** @return array{string, string} */
    private function credentialCanaries(): array
    {
        $suffix = strtoupper(bin2hex(random_bytes(8)));

        return [
            '__sms_provider_literal_secret_' . strtolower($suffix),
            '$SMS_MANAGER_PROVIDER_SECRET_' . $suffix,
        ];
    }

    /** @return array<string, mixed> */
    private function providerSettings(
        string $type,
        string $literalCanary,
        string $environmentCanary,
        bool $connectionResult = true,
    ): array {
        return match ($type) {
            'mpp-sms' => [
                'apiUrl' => 'https://api.example.test/send',
                'apiKey' => $literalCanary,
                'devApiKey' => $environmentCanary,
                'allowedCountries' => ['KW'],
            ],
            'twilio' => [
                'accountSid' => $literalCanary,
                'authToken' => $environmentCanary,
                'allowedCountries' => ['AE'],
            ],
            default => [
                'apiUrl' => 'https://gateway.example.test/send',
                'apiKey' => $literalCanary,
                'username' => 'visible-user',
                'password' => $environmentCanary,
                'connectionResult' => $connectionResult,
            ],
        };
    }

    /** @param list<string> $permissions */
    private function installUser(array $permissions): void
    {
        Craft::$app->set('user', new ProviderAccessUser($permissions));
    }

    /** @param array<string, mixed> $bodyParams */
    private function installRequest(
        bool $post = true,
        bool $acceptsJson = true,
        array $bodyParams = [],
    ): void {
        Craft::$app->set('request', new ProviderAccessRequest($post, $acceptsJson, $bodyParams));
    }

    /** @param array<string, mixed> $settings */
    private function installConfigProvider(string $type, array $settings): string
    {
        $handle = '__sm_test_config_' . bin2hex(random_bytes(6));
        Craft::$app->set('config', new ProviderAccessConfig([
            'smsManagerConfig' => [
                'providers' => [
                    $handle => [
                        'name' => 'Test Config Provider',
                        'type' => $type,
                        'enabled' => true,
                        'settings' => $settings,
                    ],
                ],
            ],
        ]));
        BaseConfigFileHelper::clearCache('sms-manager');
        $this->dropCachedSettings();

        return $handle;
    }

    private function controller(): ProviderAccessController
    {
        return new ProviderAccessController('providers', SmsManager::$plugin);
    }

    /** @return array{int, string} */
    private function captureHttpOutcome(callable $action): array
    {
        try {
            return [200, $action()];
        } catch (ForbiddenHttpException|BadRequestHttpException|NotFoundHttpException $exception) {
            return [
                $exception->statusCode,
                json_encode([
                    'status' => $exception->statusCode,
                    'message' => $exception->getMessage(),
                ], JSON_THROW_ON_ERROR),
            ];
        } catch (HttpException $exception) {
            return [
                $exception->statusCode,
                json_encode([
                    'status' => $exception->statusCode,
                    'message' => $exception->getMessage(),
                ], JSON_THROW_ON_ERROR),
            ];
        }
    }

    /** @param list<string> $secrets */
    private function assertSecretsAbsent(string $output, array $secrets): void
    {
        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $output);
        }
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function completeState(): array
    {
        return [
            'providers' => (new Query())->from(ProviderRecord::tableName())->orderBy(['id' => SORT_ASC])->all(),
            'senders' => (new Query())->from(SenderIdRecord::tableName())->orderBy(['id' => SORT_ASC])->all(),
            'settings' => (new Query())->from('{{%smsmanager_settings}}')->orderBy(['id' => SORT_ASC])->all(),
        ];
    }

    private function dropCachedSettings(): void
    {
        $reflection = new ReflectionClass(\craft\base\Plugin::class);
        $property = $reflection->getProperty('_settings');
        $property->setAccessible(true);
        $property->setValue(SmsManager::$plugin, null);
    }
}

final class ProviderAccessConfig extends Config
{
    /** @var array<string, mixed> */
    public array $smsManagerConfig = [];

    public function getConfigFromFile(string $filename): array|callable|BaseConfig
    {
        return $filename === 'sms-manager' ? $this->smsManagerConfig : parent::getConfigFromFile($filename);
    }
}

final class ProviderAccessUser extends ConsoleUser
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

final class ProviderAccessRequest extends ConsoleRequest
{
    /** @param array<string, mixed> $bodyParams */
    public function __construct(
        private readonly bool $post,
        private readonly bool $acceptsJson,
        private readonly array $bodyParams,
    ) {
        parent::__construct();
    }

    public function getIsPost(): bool
    {
        return $this->post;
    }

    public function getAcceptsJson(): bool
    {
        return $this->acceptsJson;
    }

    public function getIsOptions(): bool
    {
        return false;
    }

    public function getBodyParam($name, $defaultValue = null): mixed
    {
        return $this->bodyParams[$name] ?? $defaultValue;
    }

    public function getRequiredBodyParam(string $name): mixed
    {
        if (!array_key_exists($name, $this->bodyParams)) {
            throw new BadRequestHttpException("Missing required body parameter: {$name}");
        }

        return $this->bodyParams[$name];
    }
}

final class ProviderAccessController extends ProvidersController
{
    public string $template = '';

    /** @var array<string, mixed> */
    public array $variables = [];

    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        $this->template = $template;
        $this->variables = $variables;

        return new Response(['data' => $variables]);
    }

    public function asJson($data): Response
    {
        return new Response(['format' => Response::FORMAT_JSON, 'data' => $data]);
    }

    public function viewProjection(): string
    {
        return json_encode([
            'rawConfigDisplay' => $this->variables['provider']->rawConfigDisplay ?? null,
            'providerSettings' => $this->variables['providerSettings'] ?? null,
            'settingsHtml' => $this->variables['settingsHtml'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
