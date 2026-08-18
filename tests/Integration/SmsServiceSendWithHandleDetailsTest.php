<?php
/**
 * LindemannRock SMS Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use lindemannrock\base\helpers\ConfigFileHelper as BaseConfigFileHelper;
use lindemannrock\smsmanager\records\SmsLogRecord;
use lindemannrock\smsmanager\tests\Stubs\StubProvider;
use lindemannrock\smsmanager\tests\TestCase;
use ReflectionClass;

/**
 * Coverage for {@see \lindemannrock\smsmanager\services\SmsService::sendWithHandleDetails}.
 *
 * Handle-based diagnostic counterpart of `sendWithDetails()`. Drives the CP
 * "Send Test SMS" page, which now POSTs a sender ID handle instead of an int
 * id so config-only resources (where id=null) can be selected without the
 * form silently falling through to the default sender — the bug surfaced
 * during the audit 7.1 smoke test.
 *
 * @since 5.13.0
 */
final class SmsServiceSendWithHandleDetailsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BaseConfigFileHelper::clearCache('sms-manager');
    }

    protected function tearDown(): void
    {
        $this->seedConfigCache([]);
        BaseConfigFileHelper::clearCache('sms-manager');
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function seedConfigCache(array $config): void
    {
        $ref = new ReflectionClass(BaseConfigFileHelper::class);
        $prop = $ref->getProperty('_configCache');
        $prop->setAccessible(true);
        $prop->setValue(null, ['sms-manager' => $config]);
    }

    public function testReturnsRichResultForConfigOnlyProviderAndSender(): void
    {
        $this->registerStubProvider();

        $providerHandle = self::MARKER . 'config_provider_d';
        $senderHandle = self::MARKER . 'config_sender_d';
        $this->seedConfigCache([
            'providers' => [
                $providerHandle => [
                    'name' => 'Config Provider D',
                    'type' => self::STUB_TYPE,
                    'enabled' => true,
                    'settings' => ['allowedCountries' => ['*']],
                ],
            ],
            'senderIds' => [
                $senderHandle => [
                    'name' => 'Config Sender D',
                    'senderId' => 'CfgSenderD',
                    'provider' => $providerHandle,
                    'enabled' => true,
                ],
            ],
        ]);

        $recipient = $this->markerRecipient();

        $result = $this->sms->sendWithHandleDetails(
            to: $recipient,
            message: 'Details path',
            senderIdHandle: $senderHandle,
            language: 'en',
            sourcePlugin: $this->markerSourcePlugin(),
        );

        self::assertTrue($result['success'], 'sendWithHandleDetails() must succeed for a config-only sender + provider');

        self::assertSame('Config Provider D', $result['providerName'], 'Response must carry the config-resolved provider name');
        self::assertSame('Config Sender D', $result['senderIdName'], 'Response must carry the config-resolved sender name');
        self::assertSame('CfgSenderD', $result['senderIdValue'], 'Response must carry the config-resolved sender value');
        self::assertSame($recipient, $result['recipient']);
        self::assertNull($result['error']);
        self::assertIsInt($result['executionTime']);
        self::assertGreaterThanOrEqual(0, $result['executionTime']);

        self::assertCount(1, StubProvider::$sentCalls);
        self::assertSame('CfgSenderD', StubProvider::$sentCalls[0]['senderId']);

        $logRow = $this->fetchLogRowByRecipient($recipient);
        self::assertNotNull($logRow);
        self::assertSame(SmsLogRecord::STATUS_SENT, $logRow['status']);
        self::assertNull($logRow['providerId']);
        self::assertNull($logRow['senderIdId']);
    }

    public function testReturnsErrorArrayForUnknownHandleWithoutContactingProvider(): void
    {
        $this->registerStubProvider();
        $recipient = $this->markerRecipient();

        $result = $this->sms->sendWithHandleDetails(
            to: $recipient,
            message: 'Should not send',
            senderIdHandle: self::MARKER . 'nope_handle_d',
        );

        self::assertFalse($result['success']);
        self::assertNotNull($result['error']);
        self::assertStringContainsString('Sender ID not found', (string) $result['error']);
        self::assertNull($result['providerName']);
        self::assertNull($result['senderIdName']);
        self::assertSame($recipient, $result['recipient']);

        self::assertCount(0, StubProvider::$sentCalls, 'No provider call should have been made for an unknown handle');
        self::assertNull($this->fetchLogRowByRecipient($recipient), 'No log row should be written when the handle does not resolve');
    }
}
