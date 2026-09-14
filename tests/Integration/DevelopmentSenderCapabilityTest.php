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
use craft\web\Response;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as PsrResponse;
use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;
use lindemannrock\smsmanager\controllers\SenderIdsController;
use lindemannrock\smsmanager\controllers\SettingsController;
use lindemannrock\smsmanager\providers\DevelopmentSenderProviderInterface;
use lindemannrock\smsmanager\providers\MppSmsProvider;
use lindemannrock\smsmanager\providers\ProviderInterface;
use lindemannrock\smsmanager\providers\TwilioProvider;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\records\SenderIdRecord;
use lindemannrock\smsmanager\services\ProvidersService;
use lindemannrock\smsmanager\SmsManager;
use lindemannrock\smsmanager\tests\Stubs\ProviderPrivacyConfig;
use lindemannrock\smsmanager\tests\Stubs\StubBareProvider;
use lindemannrock\smsmanager\tests\Stubs\StubProvider;
use lindemannrock\smsmanager\tests\TestCase;
use ReflectionClass;

/**
 * Provider capability and effective development-sender behavior.
 *
 * @since 5.16.0
 */
final class DevelopmentSenderCapabilityTest extends TestCase
{
    private object $originalRequest;

    private object $originalUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalRequest = Craft::$app->get('request');
        $this->originalUser = Craft::$app->get('user');
        $this->seedConfigCache([]);
        CapabilityTwilioProvider::reset();
        ProviderPrivacyConfig::reset();
    }

    protected function tearDown(): void
    {
        Craft::$app->set('request', $this->originalRequest);
        Craft::$app->set('user', $this->originalUser);
        $this->seedConfigCache([]);
        BaseConfigFileHelper::clearCache('sms-manager');
        CapabilityTwilioProvider::reset();
        parent::tearDown();
    }

    public function testBuiltInAndCustomCapabilityMatrixPreservesDirectImplementations(): void
    {
        $service = new ProvidersService();
        $service->registerProviderType(StubBareProvider::class);
        $service->registerProviderType(DirectCapabilityProvider::class);
        $service->registerProviderType(OptInDirectCapabilityProvider::class);

        self::assertTrue($service->supportsDevelopmentSenders(MppSmsProvider::handle()));
        self::assertFalse($service->supportsDevelopmentSenders(TwilioProvider::handle()));
        self::assertFalse($service->supportsDevelopmentSenders(StubBareProvider::handle()));
        self::assertFalse($service->supportsDevelopmentSenders(DirectCapabilityProvider::handle()));
        self::assertTrue($service->supportsDevelopmentSenders(OptInDirectCapabilityProvider::handle()));
        self::assertFalse($service->supportsDevelopmentSenders('__unknown_provider'));

        self::assertInstanceOf(
            DirectCapabilityProvider::class,
            $service->createProviderByType(DirectCapabilityProvider::handle()),
        );
        self::assertTrue($service->getProviderTypeMetadata(MppSmsProvider::handle())['supportsDevelopmentSenders'] ?? false);
        self::assertFalse($service->getProviderTypeMetadata(TwilioProvider::handle())['supportsDevelopmentSenders'] ?? true);
    }

    public function testDatabaseAndConfigSendersExposeOnlyEffectiveDevelopmentState(): void
    {
        $this->registerCapabilityProviders();

        $mpp = $this->seedProvider(['type' => MppSmsProvider::handle()]);
        $twilio = $this->seedProvider(['type' => CapabilityTwilioProvider::handle()]);
        $custom = $this->seedProvider(['type' => DirectCapabilityProvider::handle()]);
        $optIn = $this->seedProvider(['type' => OptInDirectCapabilityProvider::handle()]);

        $mppSender = $this->seedSenderId($mpp, ['isDev' => true]);
        $twilioSender = $this->seedSenderId($twilio, ['isDev' => true]);
        $customSender = $this->seedSenderId($custom, ['isDev' => true]);
        $optInSender = $this->seedSenderId($optIn, ['isDev' => true]);

        $before = $this->resourceState();
        self::assertTrue($this->senderIds->isDevelopmentSender($mppSender));
        self::assertFalse($this->senderIds->isDevelopmentSender($twilioSender));
        self::assertFalse($this->senderIds->isDevelopmentSender($customSender));
        self::assertTrue($this->senderIds->isDevelopmentSender($optInSender));

        $configProvider = self::MARKER . 'config_twilio_provider';
        $configSender = self::MARKER . 'config_twilio_sender';
        $this->seedConfigCache([
            'providers' => [
                $configProvider => [
                    'name' => 'Config Twilio',
                    'type' => CapabilityTwilioProvider::handle(),
                    'enabled' => true,
                ],
            ],
            'senderIds' => [
                $configSender => [
                    'name' => 'Stale Config Sender',
                    'senderId' => 'ConfigSender',
                    'provider' => $configProvider,
                    'enabled' => true,
                    'isDev' => true,
                ],
            ],
        ]);

        $resolvedConfigSender = $this->senderIds->getSenderIdByHandle($configSender);
        self::assertInstanceOf(SenderIdRecord::class, $resolvedConfigSender);
        self::assertTrue((bool)$resolvedConfigSender->isDev, 'The config projection remains faithful to the file.');
        self::assertFalse($this->senderIds->isDevelopmentSender($resolvedConfigSender));

        Craft::$app->set('request', new CapabilityRequest());
        Craft::$app->set('user', new CapabilityUser(['smsManager:manageSenderIds']));
        $controller = new CapturingCapabilitySenderController('sender-ids', SmsManager::$plugin);
        $controller->actionView($configSender);
        self::assertFalse($controller->variables['effectiveIsDev'] ?? true);
        self::assertFalse($controller->variables['providerDevelopmentCapabilities'][$configProvider] ?? true);
        self::assertSame($before, $this->resourceState(), 'Capability reads must not mutate database rows.');
    }

    public function testServicePassesTrueOnlyToOptedInProviders(): void
    {
        $this->registerCapabilityProviders();
        $this->settings()->enableSmsLogs = false;
        $this->settings()->enableAnalytics = false;

        $optInProvider = $this->seedProvider(['type' => StubProvider::handle()]);
        $optInSender = $this->seedSenderId($optInProvider, ['isDev' => true]);
        self::assertTrue($this->sms->send(
            $this->markerRecipient('-opt-in'),
            'Opt-in capability',
            providerId: (int)$optInProvider->id,
            senderIdId: (int)$optInSender->id,
        ));
        self::assertTrue(StubProvider::$sentCalls[0]['settings']['isDev'] ?? false);

        $twilioProvider = $this->seedProvider(['type' => CapabilityTwilioProvider::handle()]);
        $twilioSender = $this->seedSenderId($twilioProvider, ['isDev' => true]);
        self::assertTrue($this->sms->send(
            $this->markerRecipient('-twilio'),
            'Unsupported capability',
            providerId: (int)$twilioProvider->id,
            senderIdId: (int)$twilioSender->id,
        ));
        self::assertCount(1, CapabilityTwilioProvider::$sentSettings);
        self::assertFalse(CapabilityTwilioProvider::$sentSettings[0]['isDev'] ?? true);
    }

    public function testMppDevelopmentFlagStillSelectsTheDevelopmentKey(): void
    {
        ProviderPrivacyConfig::$handler = new MockHandler([
            new PsrResponse(200, [], 'OK,smsid:development-key,mobiles:1'),
        ]);
        Craft::$app->set('config', new ProviderPrivacyConfig());

        $result = (new MppSmsProvider())->send(
            '96594400999',
            'Development key selection',
            'DevelopmentSender',
            'en',
            [
                'apiKey' => '',
                'devApiKey' => '__sm_test_development_key',
                'apiUrl' => 'https://gateway.example.test/send.aspx',
                'allowedCountries' => ['*'],
                'isDev' => true,
            ],
        );

        self::assertTrue($result['success']);
        self::assertSame('development-key', $result['messageId']);
    }

    public function testForgedUnsupportedPostIsNormalizedOnAuthorizedSave(): void
    {
        $this->providers->registerProviderType(CapabilityTwilioProvider::class);
        $provider = $this->seedProvider(['type' => CapabilityTwilioProvider::handle()]);
        $handle = self::MARKER . 'forged_sender';
        $senderValue = self::MARKER . 'forged_sender_value';
        Craft::$app->set('request', new CapabilityRequest([
            'providerHandle' => $provider->handle,
            'name' => 'Forged Sender',
            'handle' => $handle,
            'senderId' => $senderValue,
            'enabled' => '1',
            'isDev' => '1',
        ]));
        Craft::$app->set('user', new CapabilityUser(['smsManager:createSenderIds']));

        try {
            (new CapturingCapabilitySenderController('sender-ids', SmsManager::$plugin))->actionSave();
        } catch (\craft\errors\MissingComponentException) {
            // The console test app has no web session; persistence happens
            // before the controller attempts to set its success notice.
        }

        $saved = SenderIdRecord::findOne(['senderId' => $senderValue]);
        self::assertInstanceOf(SenderIdRecord::class, $saved);
        $this->trackSenderIdForCleanup($saved);
        self::assertFalse((bool)$saved->isDev);

        $stale = $this->seedSenderId($provider, ['isDev' => true]);
        Craft::$app->set('request', new CapabilityRequest([
            'senderIdId' => (string)$stale->id,
            'providerHandle' => $provider->handle,
            'name' => $stale->name,
            'handle' => $stale->handle,
            'senderId' => $stale->senderId,
            'enabled' => '1',
            'isDev' => '1',
        ]));
        Craft::$app->set('user', new CapabilityUser(['smsManager:editSenderIds']));

        try {
            (new CapturingCapabilitySenderController('sender-ids', SmsManager::$plugin))->actionSave();
        } catch (\craft\errors\MissingComponentException) {
            // See the create path above: the web-session notice follows save.
        }

        $stale->refresh();
        self::assertFalse((bool)$stale->isDev);
    }

    public function testReadOnlySenderAndTestViewsUseEffectiveStateWithoutMutation(): void
    {
        $this->providers->registerProviderType(CapabilityTwilioProvider::class);
        $provider = $this->seedProvider(['type' => CapabilityTwilioProvider::handle()]);
        $sender = $this->seedSenderId($provider, ['isDev' => true]);
        $before = $this->resourceState();
        Craft::$app->set('request', new CapabilityRequest());
        Craft::$app->set('user', new CapabilityUser([
            'smsManager:manageSenderIds',
            'smsManager:editSenderIds',
        ]));

        $senderController = new CapturingCapabilitySenderController('sender-ids', SmsManager::$plugin);
        $senderController->actionIndex();
        self::assertFalse($senderController->variables['developmentSenderStates'][$sender->handle] ?? true);

        $senderController->actionEdit((int)$sender->id);
        self::assertFalse($senderController->variables['effectiveIsDev'] ?? true);
        self::assertFalse($senderController->variables['providerDevelopmentCapabilities'][$provider->handle] ?? true);

        $settingsController = new CapturingCapabilitySettingsController('settings', SmsManager::$plugin);
        $settingsController->actionTest();
        $senderData = $settingsController->variables['senderIdsByProvider'][$provider->handle][0] ?? [];
        self::assertFalse($senderData['isDev'] ?? true);
        self::assertFalse($settingsController->variables['providerDevelopmentCapabilities'][$provider->handle] ?? true);
        self::assertSame($before, $this->resourceState());

        $editTemplate = file_get_contents(dirname(__DIR__, 2) . '/src/templates/senderids/edit.twig');
        $indexTemplate = file_get_contents(dirname(__DIR__, 2) . '/src/templates/senderids/index.twig');
        $testTemplate = file_get_contents(dirname(__DIR__, 2) . '/src/templates/settings/test.twig');
        self::assertIsString($editTemplate);
        self::assertIsString($indexTemplate);
        self::assertIsString($testTemplate);
        self::assertStringContainsString('providerDevelopmentCapabilities', $editTemplate);
        self::assertStringContainsString('developmentSenderStates[item.handle]', $indexTemplate);
        self::assertStringContainsString('supportsDevelopmentSenders: supportsDevelopmentSenders', $testTemplate);
        self::assertMatchesRegularExpression(
            '/function updateApiKeyInfo\(\).*?const supportsDevelopmentSenders = providerDevelopmentCapabilities\[providerHandle\] === true;.*?renderApiKeyInfo/s',
            $testTemplate,
        );
    }

    private function registerCapabilityProviders(): void
    {
        $this->registerStubProvider();
        $this->providers->registerProviderType(CapabilityTwilioProvider::class);
        $this->providers->registerProviderType(DirectCapabilityProvider::class);
        $this->providers->registerProviderType(OptInDirectCapabilityProvider::class);
    }

    /** @param array<string, mixed> $config */
    private function seedConfigCache(array $config): void
    {
        $reflection = new ReflectionClass(BaseConfigFileHelper::class);
        $property = $reflection->getProperty('_configCache');
        $property->setAccessible(true);
        $property->setValue(null, ['sms-manager' => $config]);
    }

    /** @return array{providers: array<int, array<string, mixed>>, senders: array<int, array<string, mixed>>} */
    private function resourceState(): array
    {
        return [
            'providers' => (new Query())->from(ProviderRecord::tableName())->orderBy(['id' => SORT_ASC])->all(),
            'senders' => (new Query())->from(SenderIdRecord::tableName())->orderBy(['id' => SORT_ASC])->all(),
        ];
    }
}

class DirectCapabilityProvider implements ProviderInterface
{
    public static function handle(): string
    {
        return '__sm_test_direct_capability';
    }
    public static function displayName(): string
    {
        return 'Direct Capability Provider';
    }
    public static function description(): string
    {
        return 'Direct provider compatibility fixture.';
    }
    public static function iconUrl(): ?string
    {
        return null;
    }
    public static function shortName(): string
    {
        return 'Direct';
    }
    public static function website(): ?string
    {
        return null;
    }
    public static function docsUrl(): ?string
    {
        return null;
    }
    public static function dashboardUrl(): ?string
    {
        return null;
    }
    public static function supportsUnicode(): bool
    {
        return true;
    }
    public static function supportsDeliveryReports(): bool
    {
        return false;
    }
    public static function supportsConnectionTest(): bool
    {
        return false;
    }
    public function getSettingsHtml(?ProviderRecord $provider = null): string
    {
        return '';
    }
    public function validateSettings(array $settings): array
    {
        return [];
    }
    public function testConnection(array $settings): bool
    {
        return true;
    }
    public function send(string $to, string $message, string $senderId, string $language, array $settings): array
    {
        return ['success' => true, 'messageId' => null, 'response' => null, 'error' => null];
    }
}

final class OptInDirectCapabilityProvider extends DirectCapabilityProvider implements DevelopmentSenderProviderInterface
{
    public static function handle(): string
    {
        return '__sm_test_direct_opt_in';
    }
    public static function supportsDevelopmentSenders(): bool
    {
        return true;
    }
}

final class CapabilityTwilioProvider extends TwilioProvider
{
    /** @var list<array<string, mixed>> */
    public static array $sentSettings = [];

    public static function handle(): string
    {
        return '__sm_test_twilio_capability';
    }

    public function send(string $to, string $message, string $senderId, string $language, array $settings): array
    {
        self::$sentSettings[] = $settings;
        return ['success' => true, 'messageId' => 'twilio-spy', 'response' => null, 'error' => null];
    }

    public static function reset(): void
    {
        self::$sentSettings = [];
    }
}

final class CapabilityRequest extends ConsoleRequest
{
    /** @param array<string, mixed> $bodyParams */
    public function __construct(private readonly array $bodyParams = [])
    {
        parent::__construct();
    }
    public function getIsPost(): bool
    {
        return $this->bodyParams !== [];
    }
    public function getIsOptions(): bool
    {
        return false;
    }
    public function getAcceptsJson(): bool
    {
        return true;
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
}

final class CapabilityUser extends ConsoleUser
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

final class CapturingCapabilitySenderController extends SenderIdsController
{
    /** @var array<string, mixed> */
    public array $variables = [];

    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        $this->variables = $variables;
        return new Response(['data' => $variables]);
    }
}

final class CapturingCapabilitySettingsController extends SettingsController
{
    /** @var array<string, mixed> */
    public array $variables = [];

    public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
    {
        $this->variables = $variables;
        return new Response(['data' => $variables]);
    }
}
