<?php

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use lindemannrock\smsmanager\records\SmsLogRecord;
use lindemannrock\smsmanager\tests\Stubs\StubProvider;
use lindemannrock\smsmanager\tests\TestCase;

/**
 * Guard rails on {@see \lindemannrock\smsmanager\services\SmsService::send}.
 *
 * Three early-exit paths the service must enforce before any provider
 * instance is constructed:
 *  - the resolved provider record is disabled
 *  - the resolved sender ID record is disabled
 *  - the provider's `type` is not registered with `ProvidersService`
 *
 * The disabled-provider and disabled-sender cases short-circuit BEFORE the
 * provider class is resolved, so the StubProvider must not record any call.
 * The unknown-type case happens AFTER the log row is written (so a `failed`
 * log row IS created with the diagnostic error). All three return false.
 *
 * @since 5.12.0
 */
final class SmsServiceSendGuardsTest extends TestCase
{
    public function testSendReturnsFalseWhenProviderDisabled(): void
    {
        $this->registerStubProvider();
        $provider = $this->seedProvider(['enabled' => false]);
        $senderId = $this->seedSenderId($provider);
        $recipient = $this->markerRecipient();

        $ok = $this->sms->send(
            to: $recipient,
            message: 'should never send',
            providerId: $provider->id,
            senderIdId: $senderId->id,
            sourcePlugin: $this->markerSourcePlugin(),
        );

        self::assertFalse($ok);
        self::assertSame([], StubProvider::$sentCalls, 'Disabled provider must short-circuit before stub is invoked');
        self::assertNull($this->fetchLogRowByRecipient($recipient), 'No log row should be written when the provider is disabled');
    }

    public function testSendReturnsFalseWhenSenderIdDisabled(): void
    {
        $this->registerStubProvider();
        $provider = $this->seedProvider();
        $senderId = $this->seedSenderId($provider, ['enabled' => false]);
        $recipient = $this->markerRecipient();

        $ok = $this->sms->send(
            to: $recipient,
            message: 'should never send',
            providerId: $provider->id,
            senderIdId: $senderId->id,
            sourcePlugin: $this->markerSourcePlugin(),
        );

        self::assertFalse($ok);
        self::assertSame([], StubProvider::$sentCalls, 'Disabled sender ID must short-circuit before stub is invoked');
        self::assertNull($this->fetchLogRowByRecipient($recipient), 'No log row should be written when the sender ID is disabled');
    }

    public function testSendReturnsFalseWhenProviderTypeUnknown(): void
    {
        // Seeded provider points at a type the registry has never seen and
        // can never see (the marker prefix is reserved for tests, and no
        // production provider class returns one as its handle). The stub
        // may have been registered by a sibling test already — using a
        // dedicated bogus type makes this assertion ordering-independent.
        $provider = $this->seedProvider(['type' => self::MARKER . 'unknown']);
        $senderId = $this->seedSenderId($provider);
        $recipient = $this->markerRecipient();

        $ok = $this->sms->send(
            to: $recipient,
            message: 'unknown-type',
            providerId: $provider->id,
            senderIdId: $senderId->id,
            sourcePlugin: $this->markerSourcePlugin(),
        );

        self::assertFalse($ok);

        $logRow = $this->fetchLogRowByRecipient($recipient);
        self::assertNotNull($logRow, 'Unknown provider type writes a failed log row before short-circuiting');
        self::assertSame(SmsLogRecord::STATUS_FAILED, $logRow['status']);
        self::assertSame('Unknown provider type', $logRow['errorMessage']);
    }
}
