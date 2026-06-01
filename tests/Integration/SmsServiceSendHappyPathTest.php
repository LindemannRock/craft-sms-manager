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
use lindemannrock\smsmanager\records\AnalyticsRecord;
use lindemannrock\smsmanager\records\SmsLogRecord;
use lindemannrock\smsmanager\tests\Stubs\StubProvider;
use lindemannrock\smsmanager\tests\TestCase;

/**
 * Happy path through {@see \lindemannrock\smsmanager\services\SmsService::send}.
 *
 * Asserts that a successful provider response drives the full bookkeeping
 * tail: returns true, hands the stub the exact (to, message, senderId,
 * language, settings) tuple SmsService receives, writes a `sent` log row
 * with the provider message ID, and writes an analytics row marked as one
 * successful send when `enableAnalytics` is on.
 *
 * The stub is wired in by registering a fake provider type ({@see
 * StubProvider::handle}) and seeding a marker-tagged provider record with
 * that type. The send path resolves the provider class through
 * `createProviderByType()`, so the registry IS the swap point — no
 * ServiceLocator dance needed.
 *
 * @since 5.12.0
 */
final class SmsServiceSendHappyPathTest extends TestCase
{
    public function testSendRoutesToProviderAndPersistsSentLog(): void
    {
        $this->registerStubProvider();
        $provider = $this->seedProvider();
        $senderId = $this->seedSenderId($provider, ['senderId' => 'TestBrand', 'isDev' => true]);
        $recipient = $this->markerRecipient();
        $sourcePlugin = $this->markerSourcePlugin();
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $ok = $this->sms->send(
            to: $recipient,
            message: 'Hello stub',
            language: 'en',
            providerId: $provider->id,
            senderIdId: $senderId->id,
            sourcePlugin: $sourcePlugin,
            siteId: $siteId,
        );

        self::assertTrue($ok, 'send() should return true when the provider returns success=true');

        self::assertCount(1, StubProvider::$sentCalls, 'Stub should record exactly one send');
        $call = StubProvider::$sentCalls[0];
        self::assertSame($recipient, $call['to']);
        self::assertSame('Hello stub', $call['message']);
        self::assertSame('TestBrand', $call['senderId']);
        self::assertSame('en', $call['language']);
        self::assertTrue($call['settings']['isDev'], 'isDev from the sender ID should be merged into the provider settings');

        $logRow = $this->fetchLogRowByRecipient($recipient);
        self::assertNotNull($logRow, 'A log row should have been written for the marker recipient');
        self::assertSame(SmsLogRecord::STATUS_SENT, $logRow['status']);
        self::assertSame(StubProvider::$successMessageId, $logRow['providerMessageId']);
        self::assertSame(StubProvider::$successResponse, $logRow['providerResponse']);
        self::assertNull($logRow['errorMessage']);
        self::assertSame((int) $provider->id, (int) $logRow['providerId']);
        self::assertSame((int) $senderId->id, (int) $logRow['senderIdId']);
        self::assertSame((int) $siteId, (int) $logRow['siteId']);
        self::assertSame('en', $logRow['language']);
        self::assertSame(10, (int) $logRow['messageLength'], 'messageLength should be mb_strlen("Hello stub") = 10');

        $analyticsCount = $this->countRows(AnalyticsRecord::tableName(), [
            'sourcePlugin' => $sourcePlugin,
            'siteId' => $siteId,
            'language' => 'en',
            'totalSent' => 1,
            'totalFailed' => 0,
        ]);
        self::assertSame(1, $analyticsCount, 'A single successful analytics row should have been written');
    }
}
