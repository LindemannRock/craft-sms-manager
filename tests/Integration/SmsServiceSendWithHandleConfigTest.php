<?php

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use lindemannrock\smsmanager\helpers\ConfigFileHelper;
use lindemannrock\smsmanager\records\SmsLogRecord;
use lindemannrock\smsmanager\tests\Stubs\StubProvider;
use lindemannrock\smsmanager\tests\TestCase;
use ReflectionClass;

/**
 * Regression coverage for audit finding 7.1.
 *
 * Before the fix, `sendWithHandle()` delegated through `send()`'s ID-based
 * interface. Config-defined sender IDs (and config-only providers) carry
 * `id = null` (and sometimes `providerId = null`), so the call silently fell
 * back to the default provider / default sender ID and discarded the caller's
 * specified handle. After the fix, `sendWithHandle()` resolves the provider
 * via the always-populated `providerHandle` field and dispatches with the
 * records directly — IDs never enter the path.
 *
 * @since 5.13.0
 */
final class SmsServiceSendWithHandleConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ConfigFileHelper::clearCache();
    }

    protected function tearDown(): void
    {
        $this->seedConfigCache([]);
        ConfigFileHelper::clearCache();
        parent::tearDown();
    }

    /**
     * Stuff the static config cache directly so the test exercises the
     * config-resolved branch of `ProviderRecord::findByHandleWithConfig` /
     * `SenderIdRecord::findByHandleWithConfig` without touching disk.
     *
     * @param array<string, mixed> $config
     */
    private function seedConfigCache(array $config): void
    {
        $ref = new ReflectionClass(ConfigFileHelper::class);
        $prop = $ref->getProperty('_configCache');
        $prop->setAccessible(true);
        $prop->setValue(null, $config);
    }

    public function testSendWithHandleRoutesConfigSenderToItsDbProvider(): void
    {
        $this->registerStubProvider();

        // DB provider — the config sender below points at it by handle.
        $provider = $this->seedProvider();

        // Sender ID lives in config only; the resolved record will have id=null.
        $senderHandle = self::MARKER . 'config_sender_a';
        $this->seedConfigCache([
            'senderIds' => [
                $senderHandle => [
                    'name' => 'Config Sender A',
                    'senderId' => 'ConfigBrandA',
                    'provider' => $provider->handle,
                    'enabled' => true,
                ],
            ],
        ]);

        $recipient = $this->markerRecipient();

        $ok = $this->sms->sendWithHandle(
            to: $recipient,
            message: 'Config sender path',
            senderIdHandle: $senderHandle,
            language: 'en',
            sourcePlugin: $this->markerSourcePlugin(),
        );

        self::assertTrue($ok, 'sendWithHandle() must succeed for a config-only sender backed by a DB provider');

        self::assertCount(1, StubProvider::$sentCalls, 'Stub should record exactly one send');
        $call = StubProvider::$sentCalls[0];
        self::assertSame($recipient, $call['to']);
        self::assertSame(
            'ConfigBrandA',
            $call['senderId'],
            'Provider must receive the config-specified sender ID, not the default',
        );

        $logRow = $this->fetchLogRowByRecipient($recipient);
        self::assertNotNull($logRow, 'A log row must be written for the dispatched SMS');
        self::assertSame(SmsLogRecord::STATUS_SENT, $logRow['status']);
        self::assertSame(
            (int) $provider->id,
            (int) $logRow['providerId'],
            'Log row must reference the DB provider the config sender points at',
        );
        self::assertNull(
            $logRow['senderIdId'],
            'Config-only sender has no DB id — log row must record null instead of a default sender id',
        );
    }

    public function testSendWithHandleRoutesConfigOnlyProviderAndSender(): void
    {
        $this->registerStubProvider();

        $providerHandle = self::MARKER . 'config_provider_b';
        $senderHandle = self::MARKER . 'config_sender_b';
        $this->seedConfigCache([
            'providers' => [
                $providerHandle => [
                    'name' => 'Config Provider B',
                    'type' => self::STUB_TYPE,
                    'enabled' => true,
                    'settings' => ['allowedCountries' => ['*']],
                ],
            ],
            'senderIds' => [
                $senderHandle => [
                    'name' => 'Config Sender B',
                    'senderId' => 'ConfigOnlyB',
                    'provider' => $providerHandle,
                    'enabled' => true,
                ],
            ],
        ]);

        $recipient = $this->markerRecipient();

        $ok = $this->sms->sendWithHandle(
            to: $recipient,
            message: 'Pure config path',
            senderIdHandle: $senderHandle,
            language: 'en',
            sourcePlugin: $this->markerSourcePlugin(),
        );

        self::assertTrue($ok, 'sendWithHandle() must succeed when both provider and sender are config-only');

        self::assertCount(1, StubProvider::$sentCalls);
        self::assertSame(
            'ConfigOnlyB',
            StubProvider::$sentCalls[0]['senderId'],
            'Provider must receive the config-specified sender ID, not the default',
        );

        $logRow = $this->fetchLogRowByRecipient($recipient);
        self::assertNotNull($logRow);
        self::assertSame(SmsLogRecord::STATUS_SENT, $logRow['status']);
        self::assertNull(
            $logRow['providerId'],
            'Config-only provider has no DB id — log row must record null instead of a default provider id',
        );
        self::assertNull(
            $logRow['senderIdId'],
            'Config-only sender has no DB id — log row must record null instead of a default sender id',
        );
    }
}
