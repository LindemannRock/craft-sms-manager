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
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use lindemannrock\smsmanager\providers\MppSmsProvider;
use lindemannrock\smsmanager\services\TransportService;
use lindemannrock\smsmanager\SmsManager;
use lindemannrock\smsmanager\tests\Stubs\ProviderPrivacyConfig;
use lindemannrock\smsmanager\tests\Support\TransportIsolation;
use lindemannrock\smsmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Account and transport failures stop later MPP admission.
 *
 * @since 5.18.0
 */
final class MppTransportGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        ProviderPrivacyConfig::reset();
        parent::tearDown();
    }

    public function testFirstAccountFailureBlocksAllLaterCallsWithoutHttp(): void
    {
        ProviderPrivacyConfig::$handler = new MockHandler([new Response(200, [], 'error,user is inactive')]);
        Craft::$app->set('config', new ProviderPrivacyConfig());
        $settings = ['apiKey' => 'synthetic-main-account', 'apiUrl' => 'https://gateway.example.test/send', 'allowedCountries' => ['*']];
        $before = TransportIsolation::$interceptedRequests;
        $provider = new MppSmsProvider();
        $first = $provider->send('+12025550123', 'Synthetic message', 'Example', 'en', $settings);
        self::assertSame('account-unavailable', $first['failureCode'] ?? null);
        self::assertSame('account', $first['failureScope']);
        self::assertNull($first['retryAfterSeconds']);
        $later = $provider->send('+12025550124', 'Synthetic message', 'Example', 'en', $settings);
        self::assertSame('cooldown-active', $later['failureCode']);
        self::assertSame(1, TransportIsolation::$interceptedRequests - $before);
        self::assertCount(0, ProviderPrivacyConfig::$handler);
    }

    #[DataProvider('responses')]
    public function testGatewayResponseClassification(string $body, int $status, bool $success, string|null $code, string|null $scope, int|null $seconds): void
    {
        $this->mock([new Response($status, [], $body)]);
        $result = $this->send();
        self::assertSame($success, $result['success']);
        self::assertSame($code, $result['failureCode']);
        self::assertSame($scope, $result['failureScope']);
        self::assertSame($seconds, $result['retryAfterSeconds']);
        if (!$success) {
            self::assertNull($result['response']);
            self::assertStringNotContainsString('192.0.2.1', json_encode($result));
        }
    }

    public static function responses(): iterable
    {
        yield 'inactive on HTTP error' => ['error,user is inactive', 403, false, 'account-unavailable', 'account', null];
        yield 'whitespace' => [" \r\nerror,user is inactive\t", 200, false, 'account-unavailable', 'account', null];
        yield 'IP block' => ['error,your ip is blocked until next 10 minute(s): 192.0.2.1', 200, false, 'ip-blocked', 'transport', 660];
        yield 'zero minutes' => ['error,your ip is blocked until next 0 minute(s): 192.0.2.1', 429, false, 'ip-blocked', 'transport', 120];
        yield 'two hours' => ['error,your ip is blocked until next 120 minute(s): 2001:db8::1', 200, false, 'ip-blocked', 'transport', 7260];
        yield 'oversized duration' => ['error,your ip is blocked until next 999999999999999 minute(s): 192.0.2.1', 200, false, 'ip-blocked', 'transport', null];
        yield 'invalid address' => ['error,your ip is blocked until next 10 minute(s): invalid', 200, false, 'provider-error', 'unknown', null];
        yield 'negative minutes' => ['error,your ip is blocked until next -1 minute(s): 192.0.2.1', 200, false, 'provider-error', 'unknown', null];
        yield 'unknown text containing OK' => ['error,NOT OK', 200, false, 'provider-error', 'unknown', null];
        yield 'blank' => ['', 200, false, 'provider-error', 'unknown', null];
        yield 'success' => ['OK,smsid:synthetic-id,mobiles:1', 200, true, null, null, null];
        yield 'error status cannot succeed' => ['OK,smsid:synthetic-id', 500, false, 'provider-error', 'unknown', null];
        yield 'wrong case' => ['error,User is inactive', 200, false, 'provider-error', 'unknown', null];
    }

    public function testCredentialLanesAndAliasesUseEffectiveCredential(): void
    {
        $this->mock([new Response(200, [], 'error,user is inactive'), new Response(200, [], 'OK,smsid:dev')]);
        self::assertSame('account-unavailable', $this->send()['failureCode']);
        self::assertTrue($this->send(['isDev' => true, 'devApiKey' => 'synthetic-independent-dev'])['success']);
        self::assertSame('cooldown-active', $this->send(['isDev' => true])['failureCode']);
        self::assertSame('cooldown-active', $this->send(['isDev' => true, 'devApiKey' => 'synthetic-main-account'])['failureCode']);
        self::assertCount(0, ProviderPrivacyConfig::$handler);
    }

    public function testTimedHoldExpiresUsingControlledClockAcrossAllAccounts(): void
    {
        $clock = new ControlledTransportService();
        $this->swapPluginComponent('sms-manager', 'transport', $clock);
        $this->mock([new Response(200, [], 'error,your ip is blocked until next 1 minute(s): 192.0.2.1'), new Response(200, [], 'OK,smsid:recovered')]);
        self::assertSame(120, $this->send()['retryAfterSeconds']);
        $clock->time += 119;
        self::assertSame(1, $this->send(['apiKey' => 'synthetic-other-account'])['retryAfterSeconds']);
        $clock->time++;
        self::assertFalse($clock->status((new MppSmsProvider())->getTransportContext($this->settingsArray()))['holds'][0]['active']);
        self::assertTrue($this->send(['apiKey' => 'synthetic-other-account'])['success']);
        self::assertSame([], $clock->status((new MppSmsProvider())->getTransportContext($this->settingsArray()))['holds']);
    }

    public function testAccountReleaseOnlyClearsSelectedHoldAndRecordsSafeActor(): void
    {
        $this->mock([new Response(200, [], 'error,user is inactive'), new Response(200, [], 'error,your ip is blocked until next 5 minute(s): 192.0.2.1')]);
        $this->send();
        $this->send(['apiKey' => 'synthetic-other-account']);
        $service = SmsManager::$plugin->transport;
        $holds = $service->status((new MppSmsProvider())->getTransportContext($this->settingsArray()))['holds'];
        self::assertCount(2, $holds);
        $before = TransportIsolation::$interceptedRequests;
        $queueBefore = (new Query())->from('{{%queue}}')->all();
        self::assertFalse($service->release((new MppSmsProvider())->getTransportContext($this->settingsArray()), 'main', 'stale-token', 1));
        self::assertTrue($service->release((new MppSmsProvider())->getTransportContext($this->settingsArray()), 'main', $holds[1]['token'], 1));
        self::assertCount(1, $service->status((new MppSmsProvider())->getTransportContext($this->settingsArray()))['holds']);
        self::assertSame('cooldown-active', $this->send()['failureCode']);
        self::assertSame($before, TransportIsolation::$interceptedRequests);
        self::assertSame($queueBefore, (new Query())->from('{{%queue}}')->all());
        $audit = (new Query())->from(TransportService::RELEASE_TABLE)->one();
        self::assertSame('account', $audit['scope']);
        self::assertSame('main', $audit['lane']);
        self::assertSame(1, (int)$audit['releasedBy']);
        self::assertGreaterThan(0, (int)$audit['releasedAt']);
        self::assertSame(['id', 'providerType', 'scope', 'lane', 'failureCode', 'releasedBy', 'releasedAt'], array_keys($audit));
    }

    public function testServiceApisPreserveBooleanResultsAndDoNotCountSuppressedAttempts(): void
    {
        $this->mock([new Response(200, [], 'error,user is inactive')]);
        $provider = $this->seedProvider(['type' => 'mpp-sms', 'settings' => json_encode($this->settingsArray())]);
        $sender = $this->seedSenderId($provider);
        $this->settings()->enableSmsLogs = true;
        $this->settings()->enableAnalytics = true;
        self::assertFalse($this->sms->send('+12025550123', 'Synthetic', providerId: $provider->id, senderIdId: $sender->id));
        self::assertFalse($this->sms->sendWithHandle('+12025550123', 'Synthetic', $sender->handle));
        $details = $this->sms->sendWithDetails('+12025550123', 'Synthetic', providerId: $provider->id, senderIdId: $sender->id);
        self::assertSame('cooldown-active', $details['failureCode']);
        self::assertSame('account', $details['failureScope']);
        self::assertSame('cooldown-active', $this->sms->sendWithHandleDetails('+12025550123', 'Synthetic', $sender->handle)['failureCode']);
        self::assertSame(1, (int)(new Query())->from('{{%smsmanager_logs}}')->count());
        self::assertSame(1, (int)(new Query())->from('{{%smsmanager_analytics}}')->count());
    }

    public function testEnclosingTransactionFailsClosedWithoutTransport(): void
    {
        $this->mock([]);
        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            self::assertSame('guard-unavailable', $this->send()['failureCode']);
        } finally {
            $transaction->rollBack();
        }
    }

    public function testEarlyIpReleaseDoesNotContactTransport(): void
    {
        $this->mock([new Response(200, [], 'error,your ip is blocked until next 120 minute(s): 192.0.2.1')]);
        $this->send();
        $guard = SmsManager::$plugin->transport;
        $hold = $guard->status((new MppSmsProvider())->getTransportContext($this->settingsArray()))['holds'][0];
        $before = TransportIsolation::$interceptedRequests;
        self::assertTrue($guard->release((new MppSmsProvider())->getTransportContext($this->settingsArray()), 'transport', $hold['token'], 1));
        self::assertSame([], $guard->status((new MppSmsProvider())->getTransportContext($this->settingsArray()))['holds']);
        self::assertSame($before, TransportIsolation::$interceptedRequests);
    }

    public function testTransportExceptionCleansAdmissionWithoutRetrying(): void
    {
        $this->mock([new \RuntimeException('synthetic-private-transport-text'), new Response(200, [], 'OK,smsid:next')]);
        self::assertSame('provider-error', $this->send()['failureCode']);
        self::assertSame([], SmsManager::$plugin->transport->status((new MppSmsProvider())->getTransportContext($this->settingsArray()))['holds']);
        self::assertCount(1, ProviderPrivacyConfig::$handler);
        self::assertTrue($this->send()['success']);
    }

    public function testCompletionStorageFailureLeavesManualHoldAndReleasesLock(): void
    {
        $guard = new FailingCompletionClock();
        $this->swapPluginComponent('sms-manager', 'transport', $guard);
        $this->mock([new Response(200, [], 'error,user is inactive')]);
        self::assertSame('transport-uncertain', $this->send()['failureCode']);
        $hold = $guard->status((new MppSmsProvider())->getTransportContext($this->settingsArray()))['holds'][0];
        self::assertSame('admission-uncertain', $hold['type']);
        self::assertNull($hold['recoveryAt']);
        self::assertSame('cooldown-active', $this->send()['failureCode']);
        self::assertTrue($guard->release((new MppSmsProvider())->getTransportContext($this->settingsArray()), 'transport', $hold['token'], 1));
    }

    public function testMissingUpgradeStorageFailsClosedBeforeHttp(): void
    {
        $this->mock([]);
        $original = Craft::$app->getDb();
        $db = new \craft\db\Connection(['dsn' => $original->dsn, 'username' => $original->username, 'password' => $original->password, 'tablePrefix' => 'uninstalled_guard_']);
        try {
            Craft::$app->set('db', $db);
            self::assertSame('guard-unavailable', $this->send()['failureCode']);
            self::assertFalse(SmsManager::$plugin->transport->status((new MppSmsProvider())->getTransportContext($this->settingsArray()))['available']);
        } finally {
            Craft::$app->set('db', $original);
            $db->close();
        }
    }

    public function testUntrustedStructuredMetadataIsBounded(): void
    {
        $result = \lindemannrock\smsmanager\helpers\SmsPrivacyHelper::sanitizeProviderResult([
            'success' => false, 'error' => 'synthetic-private', 'response' => 'synthetic-private',
            'failureCode' => 'synthetic-private', 'failureScope' => 'synthetic-private', 'retryAfterSeconds' => PHP_INT_MAX,
        ], 'custom');
        self::assertSame('provider-error', $result['failureCode']);
        self::assertSame('unknown', $result['failureScope']);
        self::assertNull($result['retryAfterSeconds']);
        self::assertStringNotContainsString('synthetic-private', json_encode($result));
    }

    public function testNetworkFallbackTerminatesBeforeAnyRequest(): void
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Fixtures/Transport/worker.php', '--prove-deny'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(90, proc_close($process));
        self::assertSame('', trim($output));
        self::assertStringContainsString('Forbidden network fallback; no request was made.', $errors);
        self::assertStringContainsString('intercepted=0; real=0', $errors);
    }

    private function settingsArray(): array
    {
        return ['apiKey' => 'synthetic-main-account', 'apiUrl' => 'https://gateway.example.test/send', 'allowedCountries' => ['*']];
    }

    private function send(array $overrides = []): array
    {
        return (new MppSmsProvider())->send('+12025550123', 'Synthetic message', 'Example', 'en', array_replace($this->settingsArray(), $overrides));
    }

    private function mock(array $responses): void
    {
        ProviderPrivacyConfig::$handler = new MockHandler($responses);
        Craft::$app->set('config', new ProviderPrivacyConfig());
    }
}

/** Controlled clock without sleeps or production configuration switches. */
final class ControlledTransportService extends TransportService
{
    public int $time = 2000000000;

    protected function now(): int
    {
        return $this->time;
    }
}

/** Simulates loss of authoritative database time after intercepted HTTP. */
final class FailingCompletionClock extends TransportService
{
    private int $calls = 0;

    protected function now(): int
    {
        if (++$this->calls === 2) {
            throw new \RuntimeException('Synthetic unavailable persistence');
        }
        return 2000000000;
    }
}
