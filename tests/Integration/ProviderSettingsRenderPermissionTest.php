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
use craft\console\Request as ConsoleRequest;
use craft\console\User as ConsoleUser;
use craft\db\Query;
use craft\web\View;
use lindemannrock\smsmanager\controllers\ProvidersController;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\records\SenderIdRecord;
use lindemannrock\smsmanager\services\ProvidersService;
use lindemannrock\smsmanager\SmsManager;
use lindemannrock\smsmanager\tests\Stubs\StubBareProvider;
use lindemannrock\smsmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;
use yii\web\Response;

/**
 * Protects credential-bearing provider settings forms with create/edit permissions.
 *
 * @since 5.16.0
 */
final class ProviderSettingsRenderPermissionTest extends TestCase
{
    private object $originalRequest;

    private object $originalUser;

    private string $originalTemplateMode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalRequest = Craft::$app->get('request');
        $this->originalUser = Craft::$app->get('user');
        $this->originalTemplateMode = Craft::$app->getView()->getTemplateMode();
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);

        $providers = new ProvidersService();
        $providers->registerProviderType(StubBareProvider::class);
        $this->swapPluginComponent('sms-manager', 'providers', $providers);
        $this->providers = $providers;
    }

    protected function tearDown(): void
    {
        Craft::$app->set('request', $this->originalRequest);
        Craft::$app->set('user', $this->originalUser);
        Craft::$app->getView()->setTemplateMode($this->originalTemplateMode);
        parent::tearDown();
    }

    #[DataProvider('readOnlyRequestProvider')]
    public function testReadOnlyProviderAccessCannotRenderStoredOrBlankSettings(string $type, bool $includeProviderId): void
    {
        [$literalCanary, $environmentCanary] = $this->credentialCanaries();
        $provider = $this->seedProvider([
            'type' => $type,
            'settings' => json_encode($this->providerSettings($type, $literalCanary, $environmentCanary), JSON_THROW_ON_ERROR),
        ]);
        $this->seedSenderId($provider);
        $before = $this->completeState();
        $bodyParams = ['type' => $type];
        if ($includeProviderId) {
            $bodyParams['providerId'] = $provider->id;
        }
        $this->installRequest(bodyParams: $bodyParams);
        $this->installUser(['smsManager:manageProviders']);

        $deniedOutput = $this->captureForbiddenResponse(
            fn(): Response => $this->controller()->actionRenderSettings(),
        );

        $this->assertSecretsAbsent($deniedOutput, [$literalCanary, $environmentCanary]);
        self::assertSame($before, $this->completeState());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function readOnlyRequestProvider(): iterable
    {
        yield 'existing MPP provider' => ['mpp-sms', true];
        yield 'existing Twilio provider' => ['twilio', true];
        yield 'existing generic provider' => [StubBareProvider::handle(), true];
        yield 'blank generic provider form' => [StubBareProvider::handle(), false];
    }

    #[DataProvider('crossedChildPermissionProvider')]
    public function testProviderChildPermissionsDoNotAuthorizeTheOtherFormMode(array $permissions, bool $includeProviderId): void
    {
        [$literalCanary, $environmentCanary] = $this->credentialCanaries();
        $provider = $this->seedProvider([
            'type' => StubBareProvider::handle(),
            'settings' => json_encode(
                $this->providerSettings(StubBareProvider::handle(), $literalCanary, $environmentCanary),
                JSON_THROW_ON_ERROR,
            ),
        ]);
        $before = $this->completeState();
        $bodyParams = ['type' => StubBareProvider::handle()];
        if ($includeProviderId) {
            $bodyParams['providerId'] = $provider->id;
        }
        $this->installRequest(bodyParams: $bodyParams);
        $this->installUser($permissions);

        $deniedOutput = $this->captureForbiddenResponse(
            fn(): Response => $this->controller()->actionRenderSettings(),
        );

        $this->assertSecretsAbsent($deniedOutput, [$literalCanary, $environmentCanary]);
        self::assertSame($before, $this->completeState());
    }

    /** @return iterable<string, array{list<string>, bool}> */
    public static function crossedChildPermissionProvider(): iterable
    {
        yield 'create cannot render existing provider' => [['smsManager:createProviders'], true];
        yield 'edit cannot render blank provider' => [['smsManager:editProviders'], false];
    }

    #[DataProvider('providerTypeProvider')]
    public function testEditPermissionRendersExistingProviderSettings(string $type, string $expectedField): void
    {
        [$literalCanary, $environmentCanary] = $this->credentialCanaries();
        $provider = $this->seedProvider([
            'type' => $type,
            'settings' => json_encode($this->providerSettings($type, $literalCanary, $environmentCanary), JSON_THROW_ON_ERROR),
        ]);
        $before = $this->completeState();
        $this->installRequest(bodyParams: ['type' => $type, 'providerId' => $provider->id]);
        $this->installUser(['smsManager:editProviders']);

        $data = $this->responseData($this->controller()->actionRenderSettings());

        self::assertStringContainsString($expectedField, $data['settingsHtml']);
        self::assertStringContainsString($literalCanary, $data['settingsHtml']);
        self::assertStringContainsString($environmentCanary, $data['settingsHtml']);
        self::assertSame($before, $this->completeState());
    }

    #[DataProvider('providerTypeProvider')]
    public function testCreatePermissionRendersBlankProviderSettings(string $type, string $expectedField): void
    {
        $before = $this->completeState();
        $this->installRequest(bodyParams: ['type' => $type]);
        $this->installUser(['smsManager:createProviders']);

        $data = $this->responseData($this->controller()->actionRenderSettings());

        self::assertStringContainsString($expectedField, $data['settingsHtml']);
        self::assertSame($before, $this->completeState());
    }

    /** @return iterable<string, array{string, string}> */
    public static function providerTypeProvider(): iterable
    {
        yield 'MPP settings' => ['mpp-sms', 'providerSettings-apiKey'];
        yield 'Twilio settings' => ['twilio', 'providerSettings-authToken'];
        yield 'generic settings' => [StubBareProvider::handle(), 'providerSettings-password'];
    }

    public function testEditPermissionCanSwitchAnExistingProviderToAnotherSettingsType(): void
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
        $this->installRequest(bodyParams: ['type' => 'twilio', 'providerId' => $provider->id]);
        $this->installUser(['smsManager:editProviders']);

        $data = $this->responseData($this->controller()->actionRenderSettings());

        self::assertStringContainsString('providerSettings-accountSid', $data['settingsHtml']);
        self::assertStringContainsString('providerSettings-authToken', $data['settingsHtml']);
        self::assertStringNotContainsString($literalCanary, $data['settingsHtml']);
        self::assertStringNotContainsString($environmentCanary, $data['settingsHtml']);
        self::assertSame($before, $this->completeState());
    }

    #[DataProvider('invalidProviderIdProvider')]
    public function testInvalidProviderIdsStayDeterministicAndSecretFree(mixed $providerId): void
    {
        [$literalCanary, $environmentCanary] = $this->credentialCanaries();
        $this->seedProvider([
            'type' => StubBareProvider::handle(),
            'settings' => json_encode(
                $this->providerSettings(StubBareProvider::handle(), $literalCanary, $environmentCanary),
                JSON_THROW_ON_ERROR,
            ),
        ]);
        $before = $this->completeState();
        $this->installUser(['smsManager:editProviders']);

        $responses = [];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->installRequest(bodyParams: [
                'type' => StubBareProvider::handle(),
                'providerId' => $providerId,
            ]);
            $responses[] = $this->responseData($this->controller()->actionRenderSettings())['settingsHtml'];
        }

        self::assertSame($responses[0], $responses[1]);
        $this->assertSecretsAbsent($responses[0], [$literalCanary, $environmentCanary]);
        self::assertSame($before, $this->completeState());
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidProviderIdProvider(): iterable
    {
        yield 'non-numeric ID' => ['not-a-provider-id'];
        yield 'missing numeric ID' => [PHP_INT_MAX];
    }

    #[DataProvider('invalidTypeProvider')]
    public function testInvalidOrMissingProviderTypesFailWithoutCredentials(bool $includeType, bool $includeProviderId): void
    {
        [$literalCanary, $environmentCanary] = $this->credentialCanaries();
        $provider = $this->seedProvider([
            'type' => 'twilio',
            'settings' => json_encode($this->providerSettings('twilio', $literalCanary, $environmentCanary), JSON_THROW_ON_ERROR),
        ]);
        $before = $this->completeState();
        $bodyParams = [];
        if ($includeType) {
            $bodyParams['type'] = '__sm_test_invalid_provider_type';
        }
        if ($includeProviderId) {
            $bodyParams['providerId'] = $provider->id;
            $permissions = ['smsManager:editProviders'];
        } else {
            $permissions = ['smsManager:createProviders'];
        }
        $this->installRequest(bodyParams: $bodyParams);
        $this->installUser($permissions);

        $errorOutput = $this->captureBadRequestResponse(
            fn(): Response => $this->controller()->actionRenderSettings(),
        );

        $this->assertSecretsAbsent($errorOutput, [$literalCanary, $environmentCanary]);
        self::assertSame($before, $this->completeState());
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function invalidTypeProvider(): iterable
    {
        yield 'invalid type for existing provider' => [true, true];
        yield 'invalid type for blank provider' => [true, false];
        yield 'missing type for existing provider' => [false, true];
    }

    #[DataProvider('requestGuardProvider')]
    public function testSettingsRenderingStillRequiresPostAndJson(bool $post, bool $acceptsJson, int $expectedStatus): void
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
        $this->installRequest(
            post: $post,
            acceptsJson: $acceptsJson,
            bodyParams: ['type' => 'mpp-sms', 'providerId' => $provider->id],
        );
        $this->installUser(['smsManager:editProviders']);

        $errorOutput = $this->captureRequestErrorResponse(
            fn(): Response => $this->controller()->actionRenderSettings(),
            $expectedStatus,
        );

        $this->assertSecretsAbsent($errorOutput, [$literalCanary, $environmentCanary]);
        self::assertSame($before, $this->completeState());
    }

    /** @return iterable<string, array{bool, bool, int}> */
    public static function requestGuardProvider(): iterable
    {
        yield 'non-POST request' => [false, true, 405];
        yield 'non-JSON request' => [true, false, 400];
    }

    /** @return array{string, string} */
    private function credentialCanaries(): array
    {
        $suffix = strtoupper(bin2hex(random_bytes(8)));

        return [
            '__sms_settings_literal_secret_' . strtolower($suffix),
            '$SMS_MANAGER_SETTINGS_SECRET_' . $suffix,
        ];
    }

    /** @return array<string, mixed> */
    private function providerSettings(string $type, string $literalCanary, string $environmentCanary): array
    {
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
            ],
        };
    }

    /** @param list<string> $permissions */
    private function installUser(array $permissions): void
    {
        Craft::$app->set('user', new SettingsRenderUser($permissions));
    }

    /** @param array<string, mixed> $bodyParams */
    private function installRequest(
        bool $post = true,
        bool $acceptsJson = true,
        array $bodyParams = [],
    ): void {
        Craft::$app->set('request', new SettingsRenderRequest($post, $acceptsJson, $bodyParams));
    }

    private function controller(): SettingsRenderProvidersController
    {
        return new SettingsRenderProvidersController('providers', SmsManager::$plugin);
    }

    /** @return array{settingsHtml: string, headHtml: string, bodyHtml: string} */
    private function responseData(Response $response): array
    {
        self::assertSame(Response::FORMAT_JSON, $response->format);
        self::assertIsArray($response->data);
        self::assertSame(['settingsHtml', 'headHtml', 'bodyHtml'], array_keys($response->data));
        self::assertIsString($response->data['settingsHtml']);
        self::assertIsString($response->data['headHtml']);
        self::assertIsString($response->data['bodyHtml']);

        return $response->data;
    }

    private function captureForbiddenResponse(callable $action): string
    {
        try {
            $action();
        } catch (ForbiddenHttpException $exception) {
            self::assertSame(403, $exception->statusCode);

            return json_encode([
                'status' => $exception->statusCode,
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }

        self::fail('The provider settings request should have been forbidden.');
    }

    private function captureBadRequestResponse(callable $action): string
    {
        try {
            $action();
        } catch (BadRequestHttpException $exception) {
            self::assertSame(400, $exception->statusCode);

            return json_encode([
                'status' => $exception->statusCode,
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }

        self::fail('The provider settings request should have failed validation.');
    }

    private function captureRequestErrorResponse(callable $action, int $expectedStatus): string
    {
        try {
            $action();
        } catch (HttpException $exception) {
            self::assertSame($expectedStatus, $exception->statusCode);

            return json_encode([
                'status' => $exception->statusCode,
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }

        self::fail('The provider settings request should have failed its request guard.');
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
}

final class SettingsRenderUser extends ConsoleUser
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

final class SettingsRenderRequest extends ConsoleRequest
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

final class SettingsRenderProvidersController extends ProvidersController
{
    public function asJson($data): Response
    {
        return new Response(['format' => Response::FORMAT_JSON, 'data' => $data]);
    }
}
