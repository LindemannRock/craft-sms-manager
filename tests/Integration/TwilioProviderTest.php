<?php
/**
 * LindemannRock SMS Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use lindemannrock\smsmanager\providers\TwilioProvider;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\tests\TestCase;
use ReflectionMethod;

/**
 * Twilio provider contract, pinned without hitting the network.
 *
 * The HTTP send itself isn't exercised here (it needs a live Twilio account);
 * what's pinned is the pure, regression-prone logic around it via an
 * in-test subclass that re-exposes the protected helpers:
 *
 *  1. **E.164 formatting**: Twilio rejects numbers without a leading `+`,
 *     but the shared `normalizeAndValidatePhone` strips it. `formatRecipient`
 *     must re-add it — the single most likely Twilio-specific regression.
 *  2. **Response parsing**: a 2xx with a `sid` is success (messageId = sid);
 *     a Twilio error body (`{code, message}`) is a failure carrying `message`.
 *  3. **Settings validation**: Account SID + Auth Token are both required.
 *
 * @since 5.13.0
 */
final class TwilioProviderTest extends TestCase
{
    private TwilioProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new TwilioProvider();
    }

    public function testFormatsRecipientToE164WithLeadingPlus(): void
    {
        $result = $this->formatRecipient('94400999', ['KW']);

        self::assertTrue($result['valid']);
        self::assertTrue($result['fixed'], 'Missing dial code should be repaired and flagged');
        self::assertSame('+96594400999', $result['number'], 'Twilio numbers must be E.164 with a leading +');
    }

    public function testInvalidRecipientIsNotPrefixed(): void
    {
        $result = $this->formatRecipient('123', ['KW']);

        self::assertFalse($result['valid']);
        self::assertStringStartsNotWith('+', $result['number'], 'Invalid numbers must not be E.164-prefixed');
        self::assertNotNull($result['error']);
    }

    public function testParseResponseSuccessExtractsSid(): void
    {
        $body = json_encode([
            'sid' => 'SM1234567890abcdef',
            'status' => 'queued',
            'error_code' => null,
        ]);

        $result = $this->parseResponse(201, $body);

        self::assertTrue($result['success']);
        self::assertSame('SM1234567890abcdef', $result['messageId']);
        self::assertNull($result['error']);
    }

    public function testParseResponseErrorUsesMessage(): void
    {
        $body = json_encode([
            'code' => 21211,
            'message' => "The 'To' number is not a valid phone number.",
            'status' => 400,
        ]);

        $result = $this->parseResponse(400, $body);

        self::assertFalse($result['success']);
        self::assertNull($result['messageId']);
        self::assertSame("The 'To' number is not a valid phone number.", $result['error']);
    }

    public function testParseResponseTreatsErrorCodeAsFailure(): void
    {
        // A 2xx body that still carries an error_code is a failure.
        $body = json_encode([
            'sid' => 'SMfailed',
            'status' => 'failed',
            'error_code' => 30008,
        ]);

        $result = $this->parseResponse(201, $body);

        self::assertFalse($result['success'], 'A present error_code must override the 2xx status');
    }

    public function testValidateSettingsRequiresCredentials(): void
    {
        $errors = $this->provider->validateSettings([]);

        self::assertArrayHasKey('accountSid', $errors);
        self::assertArrayHasKey('authToken', $errors);
    }

    public function testValidateSettingsPassesWithCredentials(): void
    {
        $errors = $this->provider->validateSettings([
            'accountSid' => 'AC0000000000000000000000000000000',
            'authToken' => 'token-value',
        ]);

        self::assertSame([], $errors);
    }

    public function testRegisteredAsProviderType(): void
    {
        $types = $this->providers->getProviderTypes();

        self::assertArrayHasKey('twilio', $types);
        self::assertSame(TwilioProvider::class, $types['twilio']);
    }

    public function testCapabilityFlags(): void
    {
        self::assertSame('twilio', TwilioProvider::handle());
        self::assertTrue(TwilioProvider::supportsUnicode());
        self::assertTrue(TwilioProvider::supportsDeliveryReports());
        self::assertFalse(TwilioProvider::supportsConnectionTest());
    }

    public function testGetSettingsHtmlRendersWithoutProvider(): void
    {
        // Smoke-test the settings template path resolves and renders.
        $html = (new TwilioProvider())->getSettingsHtml(null);

        self::assertStringContainsString('accountSid', $html);
        self::assertStringContainsString('authToken', $html);
    }

    public function testGetSettingsHtmlReadsExistingProvider(): void
    {
        $record = new ProviderRecord();
        $record->settings = json_encode(['accountSid' => 'ACxxx', 'authToken' => 'tok']);

        $html = (new TwilioProvider())->getSettingsHtml($record);

        self::assertStringContainsString('ACxxx', $html);
    }

    /**
     * @param list<string> $countries
     * @return array{number: string, valid: bool, error: string|null, fixed: bool}
     */
    private function formatRecipient(string $to, array $countries): array
    {
        $method = new ReflectionMethod(TwilioProvider::class, 'formatRecipient');
        /** @var array{number: string, valid: bool, error: string|null, fixed: bool} */
        return $method->invoke($this->provider, $to, $countries);
    }

    /** @return array{success: bool, messageId: string|null, response: string, error: string|null} */
    private function parseResponse(int $statusCode, string $body): array
    {
        $method = new ReflectionMethod(TwilioProvider::class, 'parseResponse');
        /** @var array{success: bool, messageId: string|null, response: string, error: string|null} */
        return $method->invoke($this->provider, $statusCode, $body);
    }
}
