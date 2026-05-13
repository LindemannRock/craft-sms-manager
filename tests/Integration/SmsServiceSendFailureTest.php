<?php

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use lindemannrock\smsmanager\records\AnalyticsRecord;
use lindemannrock\smsmanager\records\SmsLogRecord;
use lindemannrock\smsmanager\tests\Stubs\StubProvider;
use lindemannrock\smsmanager\tests\TestCase;

/**
 * Failure path through {@see \lindemannrock\smsmanager\services\SmsService::send}.
 *
 * When the provider returns `success=false`, the service must still write a
 * log row (so a failed send is auditable), mark it `failed`, and persist the
 * provider's error string. The analytics row must show one `totalFailed` and
 * zero `totalSent` so the dashboards don't double-count the attempt as a
 * success.
 *
 * @since 5.12.0
 */
final class SmsServiceSendFailureTest extends TestCase
{
    public function testSendReturnsFalseAndPersistsFailedLog(): void
    {
        $this->registerStubProvider();
        StubProvider::$failSend = true;
        StubProvider::$failError = 'mocked downstream timeout';

        $provider = $this->seedProvider();
        $senderId = $this->seedSenderId($provider);
        $recipient = $this->markerRecipient();
        $sourcePlugin = $this->markerSourcePlugin();

        $ok = $this->sms->send(
            to: $recipient,
            message: 'will fail',
            providerId: $provider->id,
            senderIdId: $senderId->id,
            sourcePlugin: $sourcePlugin,
        );

        self::assertFalse($ok, 'send() should return false when the provider returns success=false');

        $logRow = $this->fetchLogRowByRecipient($recipient);
        self::assertNotNull($logRow, 'A log row should still be written on failure for auditability');
        self::assertSame(SmsLogRecord::STATUS_FAILED, $logRow['status']);
        self::assertSame('mocked downstream timeout', $logRow['errorMessage']);

        $analyticsCount = $this->countRows(AnalyticsRecord::tableName(), [
            'sourcePlugin' => $sourcePlugin,
            'totalSent' => 0,
            'totalFailed' => 1,
        ]);
        self::assertSame(1, $analyticsCount, 'A single failed analytics row should have been written');
    }
}
