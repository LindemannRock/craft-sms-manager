<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\services;

use craft\base\Component;
use craft\helpers\StringHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\smsmanager\records\AnalyticsRecord;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\records\SenderIdRecord;
use lindemannrock\smsmanager\records\SmsLogRecord;
use lindemannrock\smsmanager\SmsManager;

/**
 * SMS Service
 *
 * Main service for sending SMS messages.
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
class SmsService extends Component
{
    use LoggingTrait;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle(SmsManager::$plugin->id);
    }

    /**
     * Send an SMS message
     *
     * @param string $to Recipient phone number
     * @param string $message Message content
     * @param string $language Message language ('en', 'ar')
     * @param int|null $providerId Provider ID (uses default if null)
     * @param int|null $senderIdId Sender ID (uses default if null)
     * @param string|null $sourcePlugin Source plugin handle
     * @param int|null $sourceElementId Source element ID
     * @return bool True if sent successfully
     */
    public function send(
        string $to,
        string $message,
        string $language = 'en',
        ?int $providerId = null,
        ?int $senderIdId = null,
        ?string $sourcePlugin = null,
        ?int $sourceElementId = null,
    ): bool {
        $plugin = SmsManager::$plugin;

        $provider = $providerId
            ? $plugin->providers->getProviderById($providerId)
            : $plugin->providers->getDefaultProvider();

        if (!$provider) {
            $this->logError('No provider configured', ['providerId' => $providerId]);
            return false;
        }

        $senderId = $senderIdId
            ? $plugin->senderIds->getSenderIdById($senderIdId)
            : $plugin->senderIds->getDefaultSenderId($provider->id);

        if (!$senderId) {
            $this->logError('No sender ID configured', ['senderIdId' => $senderIdId, 'providerId' => $provider->id]);
            return false;
        }

        return $this->dispatchSms($provider, $senderId, $to, $message, $language, $sourcePlugin, $sourceElementId)['success'];
    }

    /**
     * Send an SMS message and return detailed result
     *
     * Same as send() but returns full details instead of just bool.
     * Useful for testing and integrations that need response details.
     *
     * @param string $to Recipient phone number
     * @param string $message Message content
     * @param string $language Message language ('en', 'ar')
     * @param int|null $providerId Provider ID (uses default if null)
     * @param int|null $senderIdId Sender ID (uses default if null)
     * @param string|null $sourcePlugin Source plugin handle
     * @param int|null $sourceElementId Source element ID
     * @return array{success: bool, messageId: string|null, response: string|null, error: string|null, executionTime: int, providerName: string|null, senderIdName: string|null, senderIdValue: string|null, recipient: string}
     */
    public function sendWithDetails(
        string $to,
        string $message,
        string $language = 'en',
        ?int $providerId = null,
        ?int $senderIdId = null,
        ?string $sourcePlugin = null,
        ?int $sourceElementId = null,
    ): array {
        $startTime = microtime(true);
        $plugin = SmsManager::$plugin;

        $provider = $providerId
            ? $plugin->providers->getProviderById($providerId)
            : $plugin->providers->getDefaultProvider();

        if (!$provider) {
            $this->logError('No provider configured', ['providerId' => $providerId]);
            return [
                'success' => false,
                'messageId' => null,
                'response' => null,
                'error' => 'No provider configured',
                'executionTime' => (int)round((microtime(true) - $startTime) * 1000),
                'providerName' => null,
                'senderIdName' => null,
                'senderIdValue' => null,
                'recipient' => $to,
            ];
        }

        $senderId = $senderIdId
            ? $plugin->senderIds->getSenderIdById($senderIdId)
            : $plugin->senderIds->getDefaultSenderId($provider->id);

        if (!$senderId) {
            $this->logError('No sender ID configured', ['senderIdId' => $senderIdId, 'providerId' => $provider->id]);
            return [
                'success' => false,
                'messageId' => null,
                'response' => null,
                'error' => 'No sender ID configured',
                'executionTime' => (int)round((microtime(true) - $startTime) * 1000),
                'providerName' => $provider->name,
                'senderIdName' => null,
                'senderIdValue' => null,
                'recipient' => $to,
            ];
        }

        return $this->dispatchSms($provider, $senderId, $to, $message, $language, $sourcePlugin, $sourceElementId);
    }

    /**
     * Send an SMS using sender ID handle (convenience method)
     *
     * @param string $to Recipient phone number
     * @param string $message Message content
     * @param string $senderIdHandle Sender ID handle
     * @param string $language Message language
     * @param string|null $sourcePlugin Source plugin handle
     * @param int|null $sourceElementId Source element ID (e.g., Formie submission id) for log attribution
     * @return bool
     * @since 5.7.0
     */
    public function sendWithHandle(
        string $to,
        string $message,
        string $senderIdHandle,
        string $language = 'en',
        ?string $sourcePlugin = null,
        ?int $sourceElementId = null,
    ): bool {
        $plugin = SmsManager::$plugin;

        $senderId = $plugin->senderIds->getSenderIdByHandle($senderIdHandle);

        if (!$senderId) {
            $this->logError('Sender ID not found', ['handle' => $senderIdHandle]);
            return false;
        }

        // Resolve the provider via providerHandle (always populated for both
        // DB and config-based sender IDs). providerId is null for config-only
        // providers, so going through send()'s ID interface would silently
        // fall back to the default provider — masking the caller's intent.
        $provider = $senderId->providerHandle
            ? $plugin->providers->getProviderByHandle($senderId->providerHandle)
            : null;

        if (!$provider) {
            $this->logError('Provider not found for sender ID', [
                'senderIdHandle' => $senderIdHandle,
                'providerHandle' => $senderId->providerHandle,
            ]);
            return false;
        }

        return $this->dispatchSms($provider, $senderId, $to, $message, $language, $sourcePlugin, $sourceElementId)['success'];
    }

    /**
     * Send an SMS using sender ID handle and return detailed result.
     *
     * Handle-based counterpart of {@see sendWithDetails()}. Unlike the ID-based
     * variant, a handle survives the DB/config split: every selectable resource
     * has one, so callers driven by a UI dropdown (Test SMS page) can route to
     * config-only resources without the null-ID trap that drops the call onto
     * the default provider/sender.
     *
     * @param string $to Recipient phone number
     * @param string $message Message content
     * @param string $senderIdHandle Sender ID handle
     * @param string $language Message language
     * @param string|null $sourcePlugin Source plugin handle
     * @param int|null $sourceElementId Source element ID for log attribution
     * @return array{success: bool, messageId: string|null, response: string|null, error: string|null, executionTime: int, providerName: string|null, senderIdName: string|null, senderIdValue: string|null, recipient: string}
     * @since 5.12.0
     */
    public function sendWithHandleDetails(
        string $to,
        string $message,
        string $senderIdHandle,
        string $language = 'en',
        ?string $sourcePlugin = null,
        ?int $sourceElementId = null,
    ): array {
        $startTime = microtime(true);
        $plugin = SmsManager::$plugin;

        $senderId = $plugin->senderIds->getSenderIdByHandle($senderIdHandle);

        if (!$senderId) {
            $this->logError('Sender ID not found', ['handle' => $senderIdHandle]);
            return [
                'success' => false,
                'messageId' => null,
                'response' => null,
                'error' => 'Sender ID not found: ' . $senderIdHandle,
                'executionTime' => (int)round((microtime(true) - $startTime) * 1000),
                'providerName' => null,
                'senderIdName' => null,
                'senderIdValue' => null,
                'recipient' => $to,
            ];
        }

        $provider = $senderId->providerHandle
            ? $plugin->providers->getProviderByHandle($senderId->providerHandle)
            : null;

        if (!$provider) {
            $this->logError('Provider not found for sender ID', [
                'senderIdHandle' => $senderIdHandle,
                'providerHandle' => $senderId->providerHandle,
            ]);
            return [
                'success' => false,
                'messageId' => null,
                'response' => null,
                'error' => 'Provider not found for sender ID handle: ' . $senderIdHandle,
                'executionTime' => (int)round((microtime(true) - $startTime) * 1000),
                'providerName' => null,
                'senderIdName' => $senderId->name,
                'senderIdValue' => $senderId->senderId,
                'recipient' => $to,
            ];
        }

        return $this->dispatchSms($provider, $senderId, $to, $message, $language, $sourcePlugin, $sourceElementId);
    }

    /**
     * Dispatch an SMS through the resolved provider and sender records.
     *
     * Single source of truth for the send pipeline. All public send methods
     * resolve their inputs to records and delegate here, so the guard checks,
     * log creation, provider invocation, and analytics update only exist once.
     *
     * Accepts records rather than IDs so callers with config-based resources
     * (where id is null) route through the same path as DB-based callers.
     *
     * @return array{success: bool, messageId: string|null, response: string|null, error: string|null, executionTime: int, providerName: string, senderIdName: string, senderIdValue: string, recipient: string}
     */
    private function dispatchSms(
        ProviderRecord $provider,
        SenderIdRecord $senderId,
        string $to,
        string $message,
        string $language,
        ?string $sourcePlugin,
        ?int $sourceElementId,
    ): array {
        $startTime = microtime(true);
        $plugin = SmsManager::$plugin;
        $settings = $plugin->getSettings();

        if (!$provider->enabled) {
            $this->logError('Provider is disabled', ['providerId' => $provider->id, 'name' => $provider->name]);
            return [
                'success' => false,
                'messageId' => null,
                'response' => null,
                'error' => 'Provider is disabled',
                'executionTime' => (int)round((microtime(true) - $startTime) * 1000),
                'providerName' => $provider->name,
                'senderIdName' => $senderId->name,
                'senderIdValue' => $senderId->senderId,
                'recipient' => $to,
            ];
        }

        if (!$senderId->enabled) {
            $this->logError('Sender ID is disabled', ['senderIdId' => $senderId->id, 'name' => $senderId->name]);
            return [
                'success' => false,
                'messageId' => null,
                'response' => null,
                'error' => 'Sender ID is disabled',
                'executionTime' => (int)round((microtime(true) - $startTime) * 1000),
                'providerName' => $provider->name,
                'senderIdName' => $senderId->name,
                'senderIdValue' => $senderId->senderId,
                'recipient' => $to,
            ];
        }

        $log = new SmsLogRecord([
            'providerId' => $provider->id,
            'senderIdId' => $senderId->id,
            // Handle snapshots — captured alongside the int FKs so the
            // logs UI can still identify config-only resources (where
            // `id` is null because the record has no DB row) and resources
            // that get deleted later (where the int FK becomes null via
            // the SET NULL constraint but the handle survives). Audit 8.6.
            'providerHandle' => $provider->handle,
            'senderIdHandle' => $senderId->handle,
            'recipient' => $to,
            'message' => $message,
            'language' => $language,
            'messageLength' => mb_strlen($message),
            'status' => SmsLogRecord::STATUS_PENDING,
            'sourcePlugin' => $sourcePlugin,
            'sourceElementId' => $sourceElementId,
            'uid' => StringHelper::UUID(),
            'dateCreated' => new \DateTime(),
            'dateUpdated' => new \DateTime(),
        ]);

        // Auto-trim runs on a 24h schedule via CleanupLogsJob — keeping it
        // off the send path so SMS dispatch isn't paying for COUNT + DELETE
        // on every message.
        if ($settings->enableSmsLogs) {
            $log->save(false);
        }

        $providerInstance = $plugin->providers->createProviderByType($provider->type);

        if (!$providerInstance) {
            $this->logError('Unknown provider type', ['type' => $provider->type]);
            $this->updateLogStatus($log, SmsLogRecord::STATUS_FAILED, 'Unknown provider type');
            return [
                'success' => false,
                'messageId' => null,
                'response' => null,
                'error' => 'Unknown provider type: ' . $provider->type,
                'executionTime' => (int)round((microtime(true) - $startTime) * 1000),
                'providerName' => $provider->name,
                'senderIdName' => $senderId->name,
                'senderIdValue' => $senderId->senderId,
                'recipient' => $to,
            ];
        }

        $providerSettings = $provider->getSettingsArray();
        $providerSettings['isDev'] = (bool)$senderId->isDev;

        $result = $providerInstance->send(
            $to,
            $message,
            $senderId->senderId,
            $language,
            $providerSettings,
        );

        $executionTime = (int)round((microtime(true) - $startTime) * 1000);

        if ($result['success']) {
            $this->updateLogStatus(
                $log,
                SmsLogRecord::STATUS_SENT,
                null,
                $result['messageId'],
                $result['response'],
            );

            if ($settings->enableAnalytics) {
                $this->updateAnalytics($provider->id, $senderId->id, $language, true, $sourcePlugin);
            }

            $this->logInfo('SMS sent successfully', [
                'to' => $to,
                'provider' => $provider->name,
                'senderId' => $senderId->name,
            ]);
        } else {
            $this->updateLogStatus(
                $log,
                SmsLogRecord::STATUS_FAILED,
                $result['error'],
                $result['messageId'],
                $result['response'],
            );

            if ($settings->enableAnalytics) {
                $this->updateAnalytics($provider->id, $senderId->id, $language, false, $sourcePlugin);
            }

            $this->logError('SMS sending failed', [
                'to' => $to,
                'provider' => $provider->name,
                'error' => $result['error'],
            ]);
        }

        return [
            'success' => $result['success'],
            'messageId' => $result['messageId'],
            'response' => $result['response'],
            'error' => $result['error'] ?? null,
            'executionTime' => $executionTime,
            'providerName' => $provider->name,
            'senderIdName' => $senderId->name,
            'senderIdValue' => $senderId->senderId,
            'recipient' => $to,
        ];
    }

    /**
     * Update log record status
     *
     * @param SmsLogRecord $log Log record
     * @param string $status New status
     * @param string|null $errorMessage Error message (for failures)
     * @param string|null $messageId Provider message ID
     * @param string|null $response Raw provider response
     */
    private function updateLogStatus(
        SmsLogRecord $log,
        string $status,
        ?string $errorMessage = null,
        ?string $messageId = null,
        ?string $response = null,
    ): void {
        if (!SmsManager::$plugin->getSettings()->enableSmsLogs) {
            return;
        }

        $log->status = $status;
        $log->errorMessage = $errorMessage;
        $log->providerMessageId = $messageId;
        $log->providerResponse = $response;
        $log->dateUpdated = new \DateTime();

        $log->save(false);
    }

    /**
     * Update analytics for a sent message
     *
     * @param int|null $providerId Provider ID (null for config-only providers)
     * @param int|null $senderIdId Sender ID (null for config-only senders)
     * @param string $language Message language
     * @param bool $success Whether send was successful
     * @param string|null $sourcePlugin Source plugin
     */
    private function updateAnalytics(
        ?int $providerId,
        ?int $senderIdId,
        string $language,
        bool $success,
        ?string $sourcePlugin,
    ): void {
        $now = new \DateTime();

        // Create analytics record for this event (one row per SMS)
        $analytics = new AnalyticsRecord([
            'date' => $now,
            'providerId' => $providerId,
            'senderIdId' => $senderIdId,
            'sourcePlugin' => $sourcePlugin,
            'totalSent' => $success ? 1 : 0,
            'totalDelivered' => 0,
            'totalFailed' => $success ? 0 : 1,
            'totalPending' => 0,
            'totalCharacters' => 0,
            'totalMessages' => 0,
            'englishCount' => $language === 'en' ? 1 : 0,
            'arabicCount' => $language === 'ar' ? 1 : 0,
            'otherCount' => ($language !== 'en' && $language !== 'ar') ? 1 : 0,
            'uid' => StringHelper::UUID(),
            'dateCreated' => $now,
            'dateUpdated' => $now,
        ]);

        $analytics->save(false);
    }
}
