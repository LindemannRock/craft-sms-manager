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
use craft\helpers\App;
use craft\services\Config;
use craft\web\Response;
use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\SmsManager;
use lindemannrock\smsmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * Protects provider read views from exposing supported config credential fields.
 *
 * @since 5.16.0
 */
final class ProviderConfigSecretPresentationTest extends TestCase
{
    private const ENV_NAME = 'SMS_MANAGER_TEST_TWILIO_TOKEN';

    private object $originalRequest;

    private object $originalUser;

    private bool $environmentWasSet = false;

    private mixed $originalEnvironmentValue = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalRequest = Craft::$app->get('request');
        $this->originalUser = Craft::$app->get('user');
        $this->environmentWasSet = array_key_exists(self::ENV_NAME, $_SERVER);
        $this->originalEnvironmentValue = $_SERVER[self::ENV_NAME] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->environmentWasSet) {
            $_SERVER[self::ENV_NAME] = $this->originalEnvironmentValue;
        } else {
            unset($_SERVER[self::ENV_NAME]);
        }
        Craft::$app->set('request', $this->originalRequest);
        Craft::$app->set('user', $this->originalUser);
        BaseConfigFileHelper::clearCache('sms-manager');
        $this->dropCachedSettings();
        parent::tearDown();
    }

    #[DataProvider('permissionProvider')]
    public function testProviderListAndDetailProjectionsMaskEverySupportedCredential(array $permissions): void
    {
        $literalToken = '__sms_literal_auth_token_' . bin2hex(random_bytes(8));
        $environmentToken = '__sms_environment_auth_token_' . bin2hex(random_bytes(8));
        $apiKey = '__sms_api_key_' . bin2hex(random_bytes(8));
        $devApiKey = '__sms_dev_api_key_' . bin2hex(random_bytes(8));
        $password = '__sms_password_' . bin2hex(random_bytes(8));
        $_SERVER[self::ENV_NAME] = $environmentToken;

        $config = [
            'defaultProviderHandle' => 'twilio-literal',
            'providers' => [
                'twilio-literal' => [
                    'name' => 'Twilio Literal',
                    'type' => 'twilio',
                    'enabled' => true,
                    'settings' => [
                        'accountSid' => 'AC-visible-account',
                        'authToken' => $literalToken,
                        'allowedCountries' => ['AE', 'KW'],
                    ],
                ],
                'twilio-environment' => [
                    'name' => 'Twilio Environment',
                    'type' => 'twilio',
                    'enabled' => true,
                    'settings' => [
                        'accountSid' => 'AC-visible-environment-account',
                        'authToken' => App::env(self::ENV_NAME),
                    ],
                ],
                'mpp-config' => [
                    'name' => 'MPP Config',
                    'type' => 'mpp-sms',
                    'enabled' => true,
                    'settings' => [
                        'apiUrl' => 'https://api.example.test/send',
                        'apiKey' => $apiKey,
                        'devApiKey' => $devApiKey,
                    ],
                ],
                'generic-config' => [
                    'name' => 'Generic Config',
                    'type' => 'custom-provider',
                    'enabled' => true,
                    'settings' => [
                        'apiUrl' => 'https://gateway.example.test/send',
                        'username' => 'visible-user',
                        'password' => $password,
                    ],
                ],
            ],
        ];

        Craft::$app->set('config', new SecretPresentationConfig(['smsManagerConfig' => $config]));
        Craft::$app->set('request', new SecretPresentationRequest());
        Craft::$app->set('user', new SecretPresentationUser($permissions));
        BaseConfigFileHelper::clearCache('sms-manager');
        $this->dropCachedSettings();

        $index = new SecretPresentationProvidersController('providers', SmsManager::$plugin);
        $index->actionIndex();

        $displayProjection = array_map(
            static fn(ProviderRecord $provider): array => [
                'name' => $provider->name,
                'handle' => $provider->handle,
                'type' => $provider->type,
                'enabled' => $provider->enabled,
                'source' => $provider->source,
                'config' => $provider->rawConfigDisplay,
            ],
            $index->variables['providers'],
        );
        $listOutput = json_encode($displayProjection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->assertSecretsAbsent($listOutput, [$literalToken, $environmentToken, $apiKey, $devApiKey, $password]);
        self::assertStringContainsString('AC-visible-account', $listOutput);
        self::assertStringContainsString('https://gateway.example.test/send', $listOutput);
        self::assertStringContainsString('visible-user', $listOutput);
        self::assertStringContainsString('********', $listOutput);

        $visibleDetailValues = [
            'twilio-literal' => 'AC-visible-account',
            'twilio-environment' => 'AC-visible-environment-account',
            'mpp-config' => 'https://api.example.test/send',
            'generic-config' => 'visible-user',
        ];
        foreach ($visibleDetailValues as $handle => $visibleValue) {
            $detail = new SecretPresentationProvidersController('providers', SmsManager::$plugin);
            $detail->actionView($handle);
            $detailOutput = json_encode([
                'config' => $detail->variables['provider']->rawConfigDisplay,
                'settings' => $detail->variables['providerSettings'],
                'settingsHtml' => $detail->variables['settingsHtml'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $this->assertSecretsAbsent($detailOutput, [$literalToken, $environmentToken, $apiKey, $devApiKey, $password]);
            self::assertStringContainsString($visibleValue, $detailOutput);
            self::assertSame([], $detail->variables['providerSettings']);
            self::assertSame('', $detail->variables['settingsHtml']);
        }
    }

    /** @return iterable<string, array{list<string>}> */
    public static function permissionProvider(): iterable
    {
        yield 'parent read access' => [['smsManager:manageProviders']];
        yield 'parent and edit access' => [['smsManager:manageProviders', 'smsManager:editProviders']];
    }

    /** @param list<string> $secrets */
    private function assertSecretsAbsent(string $output, array $secrets): void
    {
        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $output);
        }
    }

    private function dropCachedSettings(): void
    {
        $reflection = new ReflectionClass(\craft\base\Plugin::class);
        $property = $reflection->getProperty('_settings');
        $property->setAccessible(true);
        $property->setValue(SmsManager::$plugin, null);
    }
}

final class SecretPresentationConfig extends Config
{
    /** @var array<string, mixed> */
    public array $smsManagerConfig = [];

    public function getConfigFromFile(string $filename): array|callable|BaseConfig
    {
        return $filename === 'sms-manager' ? $this->smsManagerConfig : parent::getConfigFromFile($filename);
    }
}

final class SecretPresentationUser extends ConsoleUser
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

final class SecretPresentationRequest extends ConsoleRequest
{
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
        return $defaultValue;
    }
}

final class SecretPresentationProvidersController extends \lindemannrock\smsmanager\controllers\ProvidersController
{
    /** @var array<string, mixed> */
    public array $variables = [];

    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        $this->variables = $variables;
        return new Response(['data' => $variables]);
    }
}
