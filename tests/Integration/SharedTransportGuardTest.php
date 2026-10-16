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
use craft\db\Query;
use craft\web\View;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\smsmanager\models\TransportContext;
use lindemannrock\smsmanager\providers\BaseProvider;
use lindemannrock\smsmanager\providers\MppSmsProvider;
use lindemannrock\smsmanager\providers\TransportProviderInterface;
use lindemannrock\smsmanager\providers\TwilioProvider;
use lindemannrock\smsmanager\services\ProvidersService;
use lindemannrock\smsmanager\services\TransportService;
use lindemannrock\smsmanager\SmsManager;
use lindemannrock\smsmanager\tests\Stubs\ProviderPrivacyConfig;
use lindemannrock\smsmanager\tests\Support\TransportIsolation;
use lindemannrock\smsmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Provider-owned identities and classifications share one guard implementation.
 *
 * @since 5.19.0
 */
final class SharedTransportGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        ProviderPrivacyConfig::reset();
        parent::tearDown();
    }

    #[DataProvider('twilioFailures')]
    public function testTwilioFailuresHoldOnlyTheDeclaredScope(int $status, string $body, string $code, string $scope, ?int $delay): void
    {
        ProviderPrivacyConfig::$handler = new MockHandler([new Response($status, [], $body)]);
        Craft::$app->set('config', new ProviderPrivacyConfig());
        $before = TransportIsolation::$interceptedRequests;
        $provider = new TwilioProvider();
        $settings = ['accountSid' => 'synthetic-account', 'authToken' => 'synthetic-token', 'allowedCountries' => ['*']];
        $result = $provider->send('+12025550123', 'Synthetic', 'Example', 'en', $settings);
        self::assertSame($code, $result['failureCode']);
        self::assertSame($scope, $result['failureScope']);
        self::assertSame($delay, $result['retryAfterSeconds']);
        self::assertSame('cooldown-active', $provider->send('+12025550124', 'Synthetic', 'Example', 'en', $settings)['failureCode']);
        self::assertSame(1, TransportIsolation::$interceptedRequests - $before);
        $guard = SmsManager::$plugin->transport;
        self::assertSame('available', $guard->status((new MppSmsProvider())->getTransportContext(['apiKey' => 'synthetic-account']))['state']);
        self::assertSame($scope === 'transport' ? 'on-hold' : 'available', $guard->status($provider->getTransportContext(['accountSid' => 'synthetic-other']))['state']);
        self::assertStringNotContainsString('synthetic-private', json_encode($result));
    }

    public static function twilioFailures(): iterable
    {
        yield 'authentication' => [401, '{"code":20003,"message":"synthetic-private"}', 'account-unavailable', 'account', null];
        yield 'inactive account' => [403, '{"code":20005}', 'account-unavailable', 'account', null];
        yield 'rate limit' => [429, '{"code":20429}', 'rate-limited', 'account', 60];
        yield 'service unavailable HTML' => [503, '<p>synthetic-private</p>', 'provider-unavailable', 'transport', 60];
        yield 'server error' => [500, '{"code":20500}', 'provider-unavailable', 'transport', 60];
    }

    public function testRecipientErrorDoesNotCreateAHoldOrChangePayload(): void
    {
        $requests = [];
        ProviderPrivacyConfig::$handler = new MockHandler([
            static function($request) use (&$requests): Response {
                $requests[] = $request;
                return new Response(400, [], '{"code":21211,"message":"synthetic-private"}');
            }, new Response(201, [], '{"sid":"SMsynthetic"}'),
        ]);
        Craft::$app->set('config', new ProviderPrivacyConfig());
        $provider = new TwilioProvider();
        $settings = ['accountSid' => 'synthetic-account', 'authToken' => 'synthetic-token', 'allowedCountries' => ['*']];
        self::assertSame('provider-error', $provider->send('+12025550123', 'Synthetic body', 'Example', 'en', $settings)['failureCode']);
        self::assertSame('available', SmsManager::$plugin->transport->status($provider->getTransportContext($settings))['state']);
        self::assertTrue($provider->send('+12025550124', 'Synthetic', 'Example', 'en', $settings)['success']);
        self::assertSame('POST', $requests[0]->getMethod());
        parse_str((string)$requests[0]->getBody(), $payload);
        self::assertSame(['To' => '+12025550123', 'From' => 'Example', 'Body' => 'Synthetic body'], $payload);
    }

    public function testCustomTransportCanChooseTimedAccountRecoveryWithoutMppRules(): void
    {
        $guard = new SharedTransportClock();
        $context = new TransportContext('synthetic-custom', 'custom', ['main' => 'synthetic-account']);
        $calls = 0;
        $failure = static function() use (&$calls): array {
            $calls++;
            return ['success' => false, 'failureCode' => 'rate-limited', 'failureScope' => 'account', 'retryAfterSeconds' => 45];
        };
        self::assertSame('rate-limited', $guard->send($context, $failure)['failureCode']);
        $guard->time += 44;
        self::assertSame(1, $guard->send($context, $failure)['retryAfterSeconds']);
        self::assertSame(1, $calls);
        $guard->time++;
        self::assertSame('available', $guard->status($context)['state']);
        self::assertSame('rate-limited', $guard->send($context, $failure)['failureCode']);
        self::assertSame(2, $calls);
    }

    public function testCustomProviderAndTwilioUseAllPublicApisWithoutCountingSuppressedCalls(): void
    {
        $providers = new ProvidersService();
        $this->swapPluginComponent('sms-manager', 'providers', $providers);
        $providers->registerProviderType(GuardedSyntheticProvider::class);
        $this->settings()->enableSmsLogs = true;
        $this->settings()->enableAnalytics = true;
        ProviderPrivacyConfig::$handler = new MockHandler([new Response(401, [], '{"code":20003}')]);
        Craft::$app->set('config', new ProviderPrivacyConfig());
        $before = TransportIsolation::$interceptedRequests;
        $queue = (new Query())->from('{{%queue}}')->all();
        foreach ([GuardedSyntheticProvider::handle(), 'twilio'] as $type) {
            $provider = $this->seedProvider(['type' => $type, 'settings' => json_encode(['accountSid' => 'synthetic-account', 'authToken' => 'synthetic-token'])]);
            $sender = $this->seedSenderId($provider);
            self::assertFalse($this->sms->send('+12025550123', 'Synthetic', providerId: $provider->id, senderIdId: $sender->id));
            self::assertFalse($this->sms->sendWithHandle('+12025550123', 'Synthetic', $sender->handle));
            self::assertSame('cooldown-active', $this->sms->sendWithDetails('+12025550123', 'Synthetic', providerId: $provider->id, senderIdId: $sender->id)['failureCode']);
            self::assertSame('cooldown-active', $this->sms->sendWithHandleDetails('+12025550123', 'Synthetic', $sender->handle)['failureCode']);
            self::assertSame('on-hold', SmsManager::$plugin->transport->statusForProvider($provider)['state']);
        }
        self::assertSame(1, TransportIsolation::$interceptedRequests - $before);
        self::assertSame(2, (int)(new Query())->from('{{%smsmanager_logs}}')->count());
        self::assertSame(2, (int)(new Query())->from('{{%smsmanager_analytics}}')->count());
        self::assertSame($queue, (new Query())->from('{{%queue}}')->all());
    }

    public function testListDistinguishesDisabledFromHeldAndUnprotectedProviders(): void
    {
        $held = $this->seedProvider(['type' => 'twilio', 'enabled' => false, 'settings' => json_encode(['accountSid' => 'synthetic-list'])]);
        $unprotected = $this->seedProvider();
        $guard = SmsManager::$plugin->transport;
        $guard->send($guard->contextForProvider($held), static fn(): array => ['success' => false, 'failureCode' => 'account-unavailable', 'failureScope' => 'account']);
        $states = [$held->handle => $guard->statusForProvider($held), $unprotected->handle => $guard->statusForProvider($unprotected)];
        self::assertSame('not-configured', $states[$unprotected->handle]['state']);
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_CP);
            $template = $view->getTwig()->load('sms-manager/providers/index');
            $row = $template->renderBlock('tableRow', ['item' => $held, 'defaultProviderHandle' => '', 'transportStatuses' => $states]);
            self::assertStringContainsString('Disabled', $row);
            self::assertStringContainsString('On hold', $row);
            $row = $template->renderBlock('tableRow', ['item' => $unprotected, 'defaultProviderHandle' => '', 'transportStatuses' => $states]);
            self::assertStringContainsString('Not configured', $row);
            self::assertStringNotContainsString('synthetic-list', $row);
        } finally {
            $view->setTemplateMode($mode);
        }
    }

    public function testProviderLocksAndReleaseAreIsolated(): void
    {
        $guard = SmsManager::$plugin->transport;
        $first = new TransportContext('synthetic-first', 'same', ['main' => 'same-identity']);
        $second = new TransportContext('synthetic-second', 'same', ['main' => 'same-identity']);
        $result = $guard->send($first, static function() use ($guard, $second): array {
            self::assertTrue($guard->send($second, static fn(): array => ['success' => true, 'messageId' => null, 'response' => null])['success']);
            return ['success' => false, 'failureCode' => 'provider-unavailable', 'failureScope' => 'transport', 'retryAfterSeconds' => 60];
        });
        self::assertSame('provider-unavailable', $result['failureCode']);
        self::assertSame('available', $guard->status($second)['state']);
        $hold = $guard->status($first)['holds'][0];
        self::assertFalse($guard->release($second, 'transport', $hold['token'], 1));
        self::assertSame('on-hold', $guard->status($first)['state']);
        self::assertTrue($guard->release($first, 'transport', $hold['token'], 1));
        self::assertSame('synthetic-first', (new Query())->select('providerType')->from(TransportService::RELEASE_TABLE)->scalar());
    }

    public function testStatusShowsRecoveryDatesAndRemovesReleaseActionAfterExpiry(): void
    {
        $guard = new SharedTransportClock();
        $context = (new MppSmsProvider())->getTransportContext(['apiKey' => 'synthetic-main']);
        $guard->send($context, static fn(): array => ['success' => false, 'failureCode' => 'ip-blocked', 'failureScope' => 'transport', 'retryAfterSeconds' => 120]);
        $status = $guard->status($context);
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_CP);
            $html = $view->renderTemplate('sms-manager/providers/_transport-status', ['transportStatus' => $status, 'providerHandle' => 'synthetic-provider', 'canRelease' => true]);
            self::assertStringContainsString(DateFormatHelper::formatDatetime(new \DateTime('@' . $guard->time)), $html);
            self::assertStringContainsString(DateFormatHelper::formatDatetime(new \DateTime('@' . ($guard->time + 120))), $html);
            self::assertStringContainsString('On hold', $html);
            self::assertStringNotContainsString('synthetic-main', $html);
            $guard->time += 120;
            $html = $view->renderTemplate('sms-manager/providers/_transport-status', ['transportStatus' => $guard->status($context), 'providerHandle' => 'synthetic-provider', 'canRelease' => true]);
            self::assertStringContainsString('Expired', $html);
            self::assertStringNotContainsString('data-action="release-transport-hold"', $html);
        } finally {
            $view->setTemplateMode($mode);
        }
    }
}

/** Clock shared by tests of provider-neutral timed recovery. */
final class SharedTransportClock extends TransportService
{
    public int $time = 2000000000;

    protected function now(): int
    {
        return $this->time;
    }
}

/** Synthetic opt-in provider exercising the public service without any network. */
final class GuardedSyntheticProvider extends BaseProvider implements TransportProviderInterface
{
    public static function handle(): string
    {
        return 'synthetic-guarded';
    }

    public static function displayName(): string
    {
        return 'Synthetic guarded provider';
    }

    public static function description(): string
    {
        return 'Synthetic transport fixture';
    }

    public function validateSettings(array $settings): array
    {
        return [];
    }

    public function getTransportContext(array $settings): TransportContext
    {
        return new TransportContext(self::handle(), 'synthetic', ['main' => 'synthetic-account']);
    }

    public function send(string $to, string $message, string $senderId, string $language, array $settings): array
    {
        return SmsManager::$plugin->transport->send($this->getTransportContext($settings), static fn(): array => [
            'success' => false, 'failureCode' => 'account-unavailable', 'failureScope' => 'account',
        ]);
    }
}
