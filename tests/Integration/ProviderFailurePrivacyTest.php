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
use craft\console\Request as ConsoleRequest;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as PsrResponse;
use lindemannrock\smsmanager\controllers\SettingsController;
use lindemannrock\smsmanager\helpers\SmsPrivacyHelper;
use lindemannrock\smsmanager\providers\MppSmsProvider;
use lindemannrock\smsmanager\providers\TwilioProvider;
use lindemannrock\smsmanager\records\SmsLogRecord;
use lindemannrock\smsmanager\SmsManager;
use lindemannrock\smsmanager\tests\Stubs\ProviderPrivacyConfig;
use lindemannrock\smsmanager\tests\Stubs\StubProvider;
use lindemannrock\smsmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Provider failures must remain useful without disclosing send payloads.
 *
 * @since 5.16.0
 */
final class ProviderFailurePrivacyTest extends TestCase
{
    private const TRANSPORT_AUTHORIZATION_CANARY = '__sm_test_transport_authorization_canary';

    private const TRANSPORT_CONTEXT_CANARY = '__sm_test_handler_context_canary';

    private object $originalRequest;

    /** @var list<array{mixed, int, string, float, array<mixed>, int}> */
    private array $originalLoggerMessages = [];

    private int $originalLoggerFlushInterval;

    protected function setUp(): void
    {
        $logger = Craft::getLogger();
        $this->originalLoggerMessages = $logger->messages;
        $this->originalLoggerFlushInterval = $logger->flushInterval;
        parent::setUp();
        $this->originalRequest = Craft::$app->get('request');
    }

    protected function tearDown(): void
    {
        try {
            ProviderPrivacyConfig::reset();
            Craft::$app->set('request', $this->originalRequest);
            parent::tearDown();
        } finally {
            $logger = Craft::getLogger();
            $logger->messages = $this->originalLoggerMessages;
            $logger->flushInterval = $this->originalLoggerFlushInterval;
        }
    }

    public function testMppTransportFailureDoesNotExposeRequestCanaries(): void
    {
        $canaries = $this->mppCanaries();
        $uri = $this->mppUri($canaries);
        $this->installTransportFailure($uri);

        [$result, $logs] = $this->captureLogs(fn(): array => (new MppSmsProvider())->send(
            $canaries['recipient'],
            $canaries['message'],
            $canaries['sender'],
            'en',
            $this->mppSettings($canaries),
        ));

        $providerOutput = $this->serialize($result);
        $transportCanaries = [
            ...array_values($canaries),
            $uri,
            self::TRANSPORT_AUTHORIZATION_CANARY,
            self::TRANSPORT_CONTEXT_CANARY,
        ];
        $this->assertCanariesAbsent($providerOutput, $transportCanaries);
        $this->assertCanariesAbsent($logs, $transportCanaries);
        $this->assertFailure($result, 'mpp-sms', 'transport');
        self::assertStringContainsString('hmac-sha256:', $logs);
    }

    #[DataProvider('smsLogSettingProvider')]
    public function testMppFailureIsSanitizedAcrossTestSmsAndPersistence(bool $enableSmsLogs): void
    {
        $canaries = $this->mppCanaries();
        $rawRecipient = self::MARKER . $canaries['recipient'];
        $uri = $this->mppUri($canaries);
        $this->installTransportFailure($uri);
        $this->settings()->enableSmsLogs = $enableSmsLogs;
        $this->settings()->enableAnalytics = false;

        $provider = $this->seedProvider([
            'type' => MppSmsProvider::handle(),
            'settings' => json_encode($this->mppSettings($canaries), JSON_THROW_ON_ERROR),
        ]);
        $senderId = $this->seedSenderId($provider, ['senderId' => $canaries['sender']]);
        Craft::$app->set('request', new ProviderFailureRequest([
            'senderIdHandle' => $senderId->handle,
            'recipient' => $rawRecipient,
            'message' => $canaries['message'],
            'language' => 'en',
        ]));

        [$result, $logs] = $this->captureLogs(function(): array {
            $response = (new ProviderFailureSettingsController('settings', SmsManager::$plugin))->actionTestSms();
            self::assertIsArray($response->data);
            return $response->data;
        });

        $publicOutput = $this->serialize($result);
        $transportCanaries = [
            ...array_values($canaries),
            $rawRecipient,
            $uri,
            self::TRANSPORT_AUTHORIZATION_CANARY,
            self::TRANSPORT_CONTEXT_CANARY,
        ];
        $this->assertCanariesAbsent($publicOutput, $transportCanaries);
        $this->assertCanariesAbsent($logs, $transportCanaries);
        $this->assertFailure($result, 'mpp-sms', 'transport');
        self::assertSame('[redacted]', $result['senderIdValue']);
        self::assertSame(SmsPrivacyHelper::recipientReference($rawRecipient), $result['recipient']);
        self::assertStringContainsString($result['recipient'], $logs);
        self::assertStringContainsString((string) $result['error'], $logs);

        $logRow = $this->fetchLogRowByRecipient($rawRecipient);
        if ($enableSmsLogs) {
            self::assertNotNull($logRow);
            self::assertSame($rawRecipient, $logRow['recipient']);
            self::assertSame($canaries['message'], $logRow['message']);
            self::assertSame(SmsLogRecord::STATUS_FAILED, $logRow['status']);
            self::assertNull($logRow['providerResponse']);
            $error = (string) $logRow['errorMessage'];
            $this->assertCanariesAbsent($error, $transportCanaries);
            self::assertSame($result['error'], $error);
            $this->assertCanariesAbsent($this->serialize($logRow), [
                $canaries['apiKey'],
                $canaries['sender'],
                $canaries['endpoint'],
                $uri,
                self::TRANSPORT_AUTHORIZATION_CANARY,
                self::TRANSPORT_CONTEXT_CANARY,
            ]);
        } else {
            self::assertNull($logRow);
        }
    }

    public function testTwilioHttpExceptionDiscardsUriAuthorizationAndPayloadData(): void
    {
        $recipient = '+96594400999';
        $sender = '__sm_test_twilio_sender_canary';
        $message = '__sm_test_twilio_message_canary';
        $accountSid = 'AC__sm_test_account_canary';
        $authToken = '__sm_test_auth_token_canary';
        $uri = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($accountSid) . '/Messages.json';
        $request = new Request('POST', $uri, [
            'Authorization' => 'Basic ' . base64_encode($accountSid . ':' . $authToken),
        ]);
        ProviderPrivacyConfig::$handler = new MockHandler([
            new RequestException(
                implode(' ', [$uri, $recipient, $sender, $message, $authToken]),
                $request,
                new PsrResponse(401, [], json_encode(['message' => $message], JSON_THROW_ON_ERROR)),
            ),
        ]);
        Craft::$app->set('config', new ProviderPrivacyConfig());

        [$result, $logs] = $this->captureLogs(fn(): array => (new TwilioProvider())->send(
            $recipient,
            $message,
            $sender,
            'en',
            [
                'accountSid' => $accountSid,
                'authToken' => $authToken,
                'allowedCountries' => ['*'],
            ],
        ));

        $canaries = [$recipient, $sender, $message, $accountSid, $authToken, $uri, $request->getHeaderLine('Authorization')];
        $this->assertCanariesAbsent($this->serialize($result), $canaries);
        $this->assertCanariesAbsent($logs, $canaries);
        $this->assertFailure($result, 'twilio', 'http', 401);
        self::assertStringContainsString('hmac-sha256:', $logs);
    }

    public function testTwilioConnectExceptionUsesTransportCategoryWithoutExposingContext(): void
    {
        $recipient = '+96594400888';
        $sender = '__sm_test_twilio_connect_sender_canary';
        $message = '__sm_test_twilio_connect_message_canary';
        $accountSid = 'AC__sm_test_connect_account_canary';
        $authToken = '__sm_test_connect_auth_token_canary';
        $uri = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($accountSid) . '/Messages.json';
        $authorization = 'Basic ' . base64_encode($accountSid . ':' . $authToken);
        $request = new Request('POST', $uri, [
            'Authorization' => $authorization,
        ], http_build_query([
            'To' => $recipient,
            'From' => $sender,
            'Body' => $message,
        ]));
        ProviderPrivacyConfig::$handler = new MockHandler([
            new ConnectException(
                implode(' ', [$uri, $recipient, $sender, $message, $authToken]),
                $request,
                null,
                ['debug' => self::TRANSPORT_CONTEXT_CANARY],
            ),
        ]);
        Craft::$app->set('config', new ProviderPrivacyConfig());

        [$result, $logs] = $this->captureLogs(fn(): array => (new TwilioProvider())->send(
            $recipient,
            $message,
            $sender,
            'en',
            [
                'accountSid' => $accountSid,
                'authToken' => $authToken,
                'allowedCountries' => ['*'],
            ],
        ));

        $canaries = [
            $recipient,
            $sender,
            $message,
            $accountSid,
            $authToken,
            $uri,
            $authorization,
            self::TRANSPORT_CONTEXT_CANARY,
        ];
        $this->assertCanariesAbsent($this->serialize($result), $canaries);
        $this->assertCanariesAbsent($logs, $canaries);
        $this->assertFailure($result, 'twilio', 'transport');
        self::assertStringContainsString('hmac-sha256:', $logs);
    }

    public function testTwilioSuccessPreservesResultWithoutLoggingPayloads(): void
    {
        $body = json_encode([
            'sid' => 'SMprivacy-success',
            'status' => 'queued',
            'error_code' => null,
        ], JSON_THROW_ON_ERROR);
        ProviderPrivacyConfig::$handler = new MockHandler([
            new PsrResponse(201, [], $body),
        ]);
        Craft::$app->set('config', new ProviderPrivacyConfig());
        $recipient = '+96594400999';
        $message = '__sm_test_twilio_success_message_canary';
        $sender = '__sm_test_twilio_success_sender_canary';
        $accountSid = 'AC__sm_test_twilio_success_account_canary';
        $authToken = '__sm_test_twilio_success_token_canary';

        [$result, $logs] = $this->captureLogs(fn(): array => (new TwilioProvider())->send(
            $recipient,
            $message,
            $sender,
            'en',
            [
                'accountSid' => $accountSid,
                'authToken' => $authToken,
                'allowedCountries' => ['*'],
            ],
        ));

        self::assertSame([
            'success' => true,
            'messageId' => 'SMprivacy-success',
            'response' => $body,
            'error' => null,
        ], $result);
        $this->assertCanariesAbsent($logs, [$recipient, $message, $sender, $accountSid, $authToken]);
        self::assertStringContainsString('hmac-sha256:', $logs);
        self::assertStringContainsString('SMprivacy-success', $logs);
    }

    public function testCustomProviderFreeTextCannotEscapeTheServiceBoundary(): void
    {
        $this->registerStubProvider();
        StubProvider::$failSend = true;
        StubProvider::$failMessageId = '__sm_test_message_id_canary';
        StubProvider::$failResponse = '__sm_test_response_canary https://custom.example.test/?token=secret';
        StubProvider::$failError = '__sm_test_error_canary Authorization: Bearer secret';
        $provider = $this->seedProvider();
        $senderId = $this->seedSenderId($provider, ['senderId' => '__sm_test_sender_canary']);
        $recipient = $this->markerRecipient('96594400999');
        $message = '__sm_test_custom_message_canary';

        [$result, $logs] = $this->captureLogs(fn(): array => $this->sms->sendWithDetails(
            to: $recipient,
            message: $message,
            providerId: $provider->id,
            senderIdId: $senderId->id,
            sourcePlugin: $this->markerSourcePlugin('direct'),
        ));

        $canaries = [
            $recipient,
            $message,
            $senderId->senderId,
            StubProvider::$failMessageId,
            StubProvider::$failResponse,
            StubProvider::$failError,
            'Bearer secret',
        ];
        $this->assertCanariesAbsent($this->serialize($result), $canaries);
        $this->assertCanariesAbsent($logs, $canaries);
        $this->assertFailure($result, 'sm_test_stub', 'provider');
        self::assertSame('[redacted]', $result['senderIdValue']);
        self::assertSame(SmsPrivacyHelper::recipientReference($recipient), $result['recipient']);

        $logRow = $this->fetchLogRowByRecipient($recipient);
        self::assertNotNull($logRow);
        self::assertNull($logRow['providerMessageId']);
        self::assertNull($logRow['providerResponse']);
        self::assertSame($result['error'], $logRow['errorMessage']);
    }

    public function testThreeKeyCustomProviderSuccessRemainsCompatibleAcrossServiceConsumers(): void
    {
        $providerResult = [
            'success' => true,
            'messageId' => 'custom-123',
            'response' => 'OK',
        ];
        self::assertSame(
            [...$providerResult, 'error' => null],
            SmsPrivacyHelper::sanitizeProviderResult($providerResult, StubProvider::handle()),
        );

        $unsafeSuccess = [...$providerResult, 'error' => '__sm_test_success_error_canary'];
        $sanitizedUnsafeSuccess = SmsPrivacyHelper::sanitizeProviderResult($unsafeSuccess, StubProvider::handle());
        $this->assertFailure($sanitizedUnsafeSuccess, 'sm_test_stub', 'malformed-response');
        $this->assertCanariesAbsent($this->serialize($sanitizedUnsafeSuccess), [$unsafeSuccess['error']]);

        $this->registerStubProvider();
        StubProvider::$successMessageId = $providerResult['messageId'];
        StubProvider::$successResponse = $providerResult['response'];
        StubProvider::$omitSuccessError = true;
        $provider = $this->seedProvider();
        $senderId = $this->seedSenderId($provider, ['senderId' => '__sm_test_success_sender_canary']);
        $recipients = [
            'details' => $this->markerRecipient('details'),
            'handleDetails' => $this->markerRecipient('handle_details'),
            'booleanId' => $this->markerRecipient('boolean_id'),
            'booleanHandle' => $this->markerRecipient('boolean_handle'),
        ];
        $message = '__sm_test_three_key_success_message_canary';
        $this->settings()->enableSmsLogs = true;
        $this->settings()->enableAnalytics = false;

        [$results, $logs] = $this->captureLogs(function() use ($recipients, $message, $provider, $senderId): array {
            return [
                'details' => $this->sms->sendWithDetails(
                    to: $recipients['details'],
                    message: $message,
                    providerId: $provider->id,
                    senderIdId: $senderId->id,
                    sourcePlugin: $this->markerSourcePlugin('details'),
                ),
                'handleDetails' => $this->sms->sendWithHandleDetails(
                    to: $recipients['handleDetails'],
                    message: $message,
                    senderIdHandle: $senderId->handle,
                    sourcePlugin: $this->markerSourcePlugin('handle_details'),
                ),
                'booleanId' => $this->sms->send(
                    to: $recipients['booleanId'],
                    message: $message,
                    providerId: $provider->id,
                    senderIdId: $senderId->id,
                    sourcePlugin: $this->markerSourcePlugin('boolean_id'),
                ),
                'booleanHandle' => $this->sms->sendWithHandle(
                    to: $recipients['booleanHandle'],
                    message: $message,
                    senderIdHandle: $senderId->handle,
                    sourcePlugin: $this->markerSourcePlugin('boolean_handle'),
                ),
            ];
        });

        foreach (['details', 'handleDetails'] as $key) {
            self::assertTrue($results[$key]['success']);
            self::assertSame('custom-123', $results[$key]['messageId']);
            self::assertSame('OK', $results[$key]['response']);
            self::assertNull($results[$key]['error']);
        }
        self::assertTrue($results['booleanId']);
        self::assertTrue($results['booleanHandle']);
        self::assertCount(4, StubProvider::$sentCalls);
        $this->assertCanariesAbsent($logs, [...array_values($recipients), $message, $senderId->senderId]);

        foreach ($recipients as $recipient) {
            $logRow = $this->fetchLogRowByRecipient($recipient);
            self::assertNotNull($logRow);
            self::assertSame(SmsLogRecord::STATUS_SENT, $logRow['status']);
            self::assertSame('custom-123', $logRow['providerMessageId']);
            self::assertSame('OK', $logRow['providerResponse']);
            self::assertNull($logRow['errorMessage']);
            self::assertSame($recipient, $logRow['recipient']);
            self::assertSame($message, $logRow['message']);
        }
    }

    public function testThrownCustomProviderFailureIsSafeForJobStyleBooleanConsumer(): void
    {
        $this->registerStubProvider();
        StubProvider::$throwOnSend = true;
        StubProvider::$failError = '__sm_test_throw_canary recipient=96594400999 message=secret';
        $provider = $this->seedProvider();
        $senderId = $this->seedSenderId($provider, ['senderId' => '__sm_test_job_sender_canary']);
        $recipient = $this->markerRecipient('job_96594400999');
        $message = '__sm_test_job_message_canary';
        $this->settings()->enableSmsLogs = false;
        $this->settings()->enableAnalytics = false;

        [$result, $logs] = $this->captureLogs(fn(): bool => $this->sms->send(
            to: $recipient,
            message: $message,
            providerId: $provider->id,
            senderIdId: $senderId->id,
            sourcePlugin: $this->markerSourcePlugin('job'),
        ));

        self::assertFalse($result);
        $this->assertCanariesAbsent($logs, [
            $recipient,
            $message,
            $senderId->senderId,
            StubProvider::$failError,
            '96594400999',
        ]);
        self::assertStringContainsString('provider=sm_test_stub', $logs);
        self::assertStringContainsString('category=provider-exception', $logs);
        self::assertMatchesRegularExpression('/reference=[0-9a-f-]{36}/', $logs);
        self::assertNull($this->fetchLogRowByRecipient($recipient));
    }

    public function testMppSuccessInvalidAndAutomaticCorrectionLogsNeverContainPayloads(): void
    {
        ProviderPrivacyConfig::$handler = new MockHandler([
            new PsrResponse(200, [], 'OK,smsid:privacy-success,mobiles:1'),
        ]);
        Craft::$app->set('config', new ProviderPrivacyConfig());
        $provider = new MppSmsProvider();
        $localRecipient = '94400999';
        $normalizedRecipient = '96594400999';
        $invalidRecipient = '123456789';
        $message = '__sm_test_success_message_canary';
        $sender = '__sm_test_success_sender_canary';
        $settings = [
            'apiKey' => '__sm_test_success_key_canary',
            'apiUrl' => 'https://gateway.example.test/send.aspx',
            'allowedCountries' => ['KW'],
        ];

        [$results, $logs] = $this->captureLogs(fn(): array => [
            'invalid' => $provider->send($invalidRecipient, $message, $sender, 'en', $settings),
            'success' => $provider->send($localRecipient, $message, $sender, 'en', $settings),
        ]);

        $this->assertFailure($results['invalid'], 'mpp-sms', 'invalid-recipient');
        self::assertSame([
            'success' => true,
            'messageId' => 'privacy-success',
            'response' => 'OK,smsid:privacy-success,mobiles:1',
            'error' => null,
        ], $results['success']);
        $this->assertCanariesAbsent($logs, [
            $invalidRecipient,
            $localRecipient,
            $normalizedRecipient,
            $message,
            $sender,
            $settings['apiKey'],
        ]);
        self::assertStringContainsString('Phone number fixed: added country code', $logs);
        self::assertStringContainsString('Phone number was auto-corrected', $logs);
        self::assertStringContainsString('hmac-sha256:', $logs);
    }

    public function testMppMalformedResponseUsesSafeFailureMetadata(): void
    {
        ProviderPrivacyConfig::$handler = new MockHandler([
            new PsrResponse(200, [], ''),
        ]);
        Craft::$app->set('config', new ProviderPrivacyConfig());
        $message = '__sm_test_malformed_message_canary';
        $recipient = '96594400999';

        [$result, $logs] = $this->captureLogs(fn(): array => (new MppSmsProvider())->send(
            $recipient,
            $message,
            '__sm_test_malformed_sender_canary',
            'en',
            [
                'apiKey' => '__sm_test_malformed_key_canary',
                'apiUrl' => 'https://gateway.example.test/send.aspx',
                'allowedCountries' => ['*'],
            ],
        ));

        $this->assertFailure($result, 'mpp-sms', 'malformed-response');
        $this->assertCanariesAbsent($logs, [$recipient, $message, '__sm_test_malformed_sender_canary']);
    }

    public function testCanonicalLookingCustomFailureCannotForgeProviderMetadata(): void
    {
        $forgedRecipient = '96594400999';
        $result = SmsPrivacyHelper::sanitizeProviderResult([
            'success' => false,
            'messageId' => '__sm_test_forged_message_id',
            'response' => '__sm_test_forged_response',
            'error' => 'SMS provider failure [provider=' . $forgedRecipient
                . '; category=transport; reference=00000000-0000-4000-8000-000000000000]',
        ], '__sm_test_stub');

        $this->assertFailure($result, 'sm_test_stub', 'provider');
        $this->assertCanariesAbsent($this->serialize($result), [
            $forgedRecipient,
            '__sm_test_forged_message_id',
            '__sm_test_forged_response',
        ]);
    }

    /** @return iterable<string, array{bool}> */
    public static function smsLogSettingProvider(): iterable
    {
        yield 'SMS delivery logging enabled' => [true];
        yield 'SMS delivery logging disabled' => [false];
    }

    /** @return array{apiKey: string, recipient: string, sender: string, message: string, endpoint: string} */
    private function mppCanaries(): array
    {
        return [
            'apiKey' => '__sm_test_api_key_canary',
            'recipient' => '96594400999',
            'sender' => '__sm_test_sender_canary',
            'message' => '__sm_test_message_canary',
            'endpoint' => 'https://gateway.example.test/send.aspx',
        ];
    }

    /** @param array{apiKey: string, recipient: string, sender: string, message: string, endpoint: string} $canaries */
    private function mppUri(array $canaries): string
    {
        return $canaries['endpoint'] . '?' . implode('&', [
            'apikey=' . urlencode($canaries['apiKey']),
            'language=1',
            'sender=' . urlencode($canaries['sender']),
            'mobile=' . $canaries['recipient'],
            'message=' . urlencode($canaries['message']),
        ]);
    }

    /** @param array{apiKey: string, recipient: string, sender: string, message: string, endpoint: string} $canaries */
    private function mppSettings(array $canaries): array
    {
        return [
            'apiKey' => $canaries['apiKey'],
            'apiUrl' => $canaries['endpoint'],
            'allowedCountries' => ['*'],
        ];
    }

    private function installTransportFailure(string $uri): void
    {
        $request = new Request('GET', $uri, [
            'Authorization' => 'Bearer ' . self::TRANSPORT_AUTHORIZATION_CANARY,
        ]);
        ProviderPrivacyConfig::$handler = new MockHandler([
            new ConnectException(
                'Transport failed for ' . $uri,
                $request,
                null,
                ['debug' => self::TRANSPORT_CONTEXT_CANARY],
            ),
        ]);
        Craft::$app->set('config', new ProviderPrivacyConfig());
    }

    /** @param array<string, mixed> $result */
    private function assertFailure(array $result, string $provider, string $category, ?int $status = null): void
    {
        self::assertFalse($result['success']);
        self::assertNull($result['messageId']);
        self::assertNull($result['response']);
        $statusPattern = $status === null ? '' : '; status=' . $status;
        self::assertMatchesRegularExpression(
            '/^SMS provider failure \[provider=' . preg_quote($provider, '/')
                . '; category=' . preg_quote($category, '/') . preg_quote($statusPattern, '/')
                . '; reference=[0-9a-f-]{36}\]$/',
            (string) $result['error'],
        );
    }

    /** @param list<string|null> $canaries */
    private function assertCanariesAbsent(string $output, array $canaries): void
    {
        foreach ($canaries as $canary) {
            if ($canary !== null && $canary !== '') {
                self::assertStringNotContainsString($canary, $output);
            }
        }
    }

    private function serialize(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{0: mixed, 1: string} */
    private function captureLogs(callable $callback): array
    {
        $logger = Craft::getLogger();
        $messages = $logger->messages;
        $flushInterval = $logger->flushInterval;

        try {
            $logger->messages = [];
            $logger->flushInterval = PHP_INT_MAX;
            $result = $callback();
            $pluginMessages = array_values(array_filter(
                $logger->messages,
                static fn(array $entry): bool => $entry[2] === 'sms-manager',
            ));
            $captured = $this->serialize($pluginMessages);
        } finally {
            $logger->messages = $messages;
            $logger->flushInterval = $flushInterval;
        }

        return [$result, $captured];
    }
}

final class ProviderFailureRequest extends ConsoleRequest
{
    /** @param array<string, mixed> $bodyParams */
    public function __construct(private readonly array $bodyParams)
    {
        parent::__construct();
    }

    public function getIsPost(): bool
    {
        return true;
    }

    public function getAcceptsJson(): bool
    {
        return true;
    }

    public function getIsOptions(): bool
    {
        return false;
    }

    public function getBodyParam($name, $defaultValue = null): mixed
    {
        return $this->bodyParams[$name] ?? $defaultValue;
    }

    public function getRequiredBodyParam(string $name): mixed
    {
        if (!array_key_exists($name, $this->bodyParams)) {
            throw new BadRequestHttpException("Missing required body parameter: {$name}");
        }

        return $this->bodyParams[$name];
    }
}

final class ProviderFailureSettingsController extends SettingsController
{
    public function asJson($data): Response
    {
        return new Response(['format' => Response::FORMAT_JSON, 'data' => $data]);
    }
}
