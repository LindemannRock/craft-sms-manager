<?php
/**
 * LindemannRock SMS Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use lindemannrock\smsmanager\tests\Stubs\StubProvider;
use lindemannrock\smsmanager\tests\TestCase;

/**
 * Result shape from {@see \lindemannrock\smsmanager\services\SmsService::sendWithDetails}.
 *
 * The shape contract is a public surface, so each field must remain present
 * while failed results avoid echoing provider or send payload data.
 *
 * @since 5.12.0
 */
final class SmsServiceSendWithDetailsTest extends TestCase
{
    public function testSendWithDetailsReturnsFullResultShapeOnSuccess(): void
    {
        $this->registerStubProvider();
        $provider = $this->seedProvider(['name' => '__sm_test_provider_named']);
        $senderId = $this->seedSenderId($provider, [
            'name' => '__sm_test_sender_named',
            'senderId' => 'BrandX',
        ]);
        $recipient = $this->markerRecipient();

        $result = $this->sms->sendWithDetails(
            to: $recipient,
            message: 'details ok',
            providerId: $provider->id,
            senderIdId: $senderId->id,
            sourcePlugin: $this->markerSourcePlugin(),
        );

        self::assertTrue($result['success']);
        self::assertSame(StubProvider::$successMessageId, $result['messageId']);
        self::assertSame(StubProvider::$successResponse, $result['response']);
        self::assertNull($result['error']);
        self::assertSame('__sm_test_provider_named', $result['providerName']);
        self::assertSame('__sm_test_sender_named', $result['senderIdName']);
        self::assertSame('BrandX', $result['senderIdValue']);
        self::assertSame($recipient, $result['recipient']);
        self::assertIsInt($result['executionTime']);
        self::assertGreaterThanOrEqual(0, $result['executionTime']);
    }

    public function testSendWithDetailsReturnsErrorShapeOnProviderFailure(): void
    {
        $this->registerStubProvider();
        StubProvider::$failSend = true;
        StubProvider::$failError = 'gateway 503';

        $provider = $this->seedProvider();
        $senderId = $this->seedSenderId($provider);
        $recipient = $this->markerRecipient();

        $result = $this->sms->sendWithDetails(
            to: $recipient,
            message: 'details fail',
            providerId: $provider->id,
            senderIdId: $senderId->id,
            sourcePlugin: $this->markerSourcePlugin(),
        );

        self::assertFalse($result['success']);
        self::assertMatchesRegularExpression(
            '/^SMS provider failure \[provider=sm_test_stub; category=provider; reference=[0-9a-f-]{36}\]$/',
            (string) $result['error'],
        );
        self::assertStringStartsWith('hmac-sha256:', $result['recipient']);
        self::assertNotSame($recipient, $result['recipient']);
        self::assertSame('[redacted]', $result['senderIdValue']);
        self::assertSame($provider->name, $result['providerName']);
        self::assertSame($senderId->name, $result['senderIdName']);
    }
}
