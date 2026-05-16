<?php
/**
 * LindemannRock SMS Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use lindemannrock\smsmanager\records\SmsLogRecord;
use lindemannrock\smsmanager\tests\Stubs\StubProvider;
use lindemannrock\smsmanager\tests\TestCase;

/**
 * Coverage for {@see \lindemannrock\smsmanager\services\SmsService::sendWithHandle}
 * (5.7.0+).
 *
 * Asserts the convenience method resolves a sender ID by handle and then
 * delegates to `send()` with the correct provider + sender IDs derived from
 * that handle, and that an unknown handle returns false without contacting a
 * provider.
 *
 * @since 5.13.0
 */
final class SmsServiceSendWithHandleTest extends TestCase
{
    public function testSendWithHandleResolvesByHandleAndPersistsSentLog(): void
    {
        $this->registerStubProvider();
        $provider = $this->seedProvider();
        $senderId = $this->seedSenderId($provider, ['senderId' => 'TestBrand']);
        $recipient = $this->markerRecipient();

        $ok = $this->sms->sendWithHandle(
            to: $recipient,
            message: 'Handle path',
            senderIdHandle: (string) $senderId->handle,
            language: 'en',
            sourcePlugin: $this->markerSourcePlugin(),
        );

        self::assertTrue($ok, 'sendWithHandle() should return true when the provider succeeds');

        self::assertCount(1, StubProvider::$sentCalls, 'Stub should record exactly one send');
        $call = StubProvider::$sentCalls[0];
        self::assertSame($recipient, $call['to']);
        self::assertSame('Handle path', $call['message']);
        self::assertSame('TestBrand', $call['senderId']);

        $logRow = $this->fetchLogRowByRecipient($recipient);
        self::assertNotNull($logRow);
        self::assertSame(SmsLogRecord::STATUS_SENT, $logRow['status']);
        self::assertSame((int) $provider->id, (int) $logRow['providerId']);
        self::assertSame((int) $senderId->id, (int) $logRow['senderIdId']);
    }

    public function testSendWithHandleReturnsFalseWhenHandleUnknown(): void
    {
        $this->registerStubProvider();
        $recipient = $this->markerRecipient();

        $ok = $this->sms->sendWithHandle(
            to: $recipient,
            message: 'Should not send',
            senderIdHandle: self::MARKER . 'nope_handle',
        );

        self::assertFalse($ok, 'Unknown sender ID handle must short-circuit before any provider call');
        self::assertCount(0, StubProvider::$sentCalls, 'No provider call should have been made');
        self::assertNull($this->fetchLogRowByRecipient($recipient), 'No log row should be written when the handle does not resolve');
    }
}
