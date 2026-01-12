<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025 LindemannRock
 */

namespace lindemannrock\smsmanager\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\StringHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\smsmanager\records\AnalyticsRecord;
use lindemannrock\smsmanager\records\LogRecord;
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
        $this->setLoggingHandle('sms-manager');
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
        $settings = $plugin->getSettings();

        // Get provider
        $provider = $providerId
            ? $plugin->providers->getProviderById($providerId)
            : $plugin->providers->getDefaultProvider();

        if (!$provider) {
            $this->logError('No provider configured', ['providerId' => $providerId]);
            return false;
        }

        if (!$provider->enabled) {
            $this->logError('Provider is disabled', ['providerId' => $provider->id, 'name' => $provider->name]);
            return false;
        }

        // Get sender ID
        $senderId = $senderIdId
            ? $plugin->senderIds->getSenderIdById($senderIdId)
            : $plugin->senderIds->getDefaultSenderId($provider->id);

        if (!$senderId) {
            $this->logError('No sender ID configured', ['senderIdId' => $senderIdId, 'providerId' => $provider->id]);
            return false;
        }

        if (!$senderId->enabled) {
            $this->logError('Sender ID is disabled', ['senderIdId' => $senderId->id, 'name' => $senderId->name]);
            return false;
        }

        // Create log record
        $log = new LogRecord([
            'providerId' => $provider->id,
            'senderIdId' => $senderId->id,
            'recipient' => $to,
            'message' => $message,
            'language' => $language,
            'messageLength' => mb_strlen($message),
            'status' => LogRecord::STATUS_PENDING,
            'sourcePlugin' => $sourcePlugin,
            'sourceElementId' => $sourceElementId,
            'uid' => StringHelper::UUID(),
            'dateCreated' => new \DateTime(),
            'dateUpdated' => new \DateTime(),
        ]);

        // Save log if logging is enabled
        if ($settings->enableLogs) {
            $log->save(false);

            // Trim logs if auto-trim is enabled
            if ($settings->autoTrimLogs) {
                $this->trimLogs();
            }
        }

        // Get provider instance
        $providerInstance = $plugin->providers->createProviderByType($provider->type);

        if (!$providerInstance) {
            $this->logError('Unknown provider type', ['type' => $provider->type]);
            $this->updateLogStatus($log, LogRecord::STATUS_FAILED, 'Unknown provider type');
            return false;
        }

        // Send via provider
        $result = $providerInstance->send(
            $to,
            $message,
            $senderId->senderId,
            $language,
            $provider->getSettingsArray()
        );

        // Update log with result
        if ($result['success']) {
            $this->updateLogStatus(
                $log,
                LogRecord::STATUS_SENT,
                null,
                $result['messageId'],
                $result['response']
            );

            // Update analytics
            if ($settings->enableAnalytics) {
                $this->updateAnalytics($provider->id, $senderId->id, $language, true, $sourcePlugin);
            }

            $this->logInfo('SMS sent successfully', [
                'to' => $to,
                'provider' => $provider->name,
                'senderId' => $senderId->name,
            ]);

            return true;
        } else {
            $this->updateLogStatus(
                $log,
                LogRecord::STATUS_FAILED,
                $result['error'],
                $result['messageId'],
                $result['response']
            );

            // Update analytics
            if ($settings->enableAnalytics) {
                $this->updateAnalytics($provider->id, $senderId->id, $language, false, $sourcePlugin);
            }

            $this->logError('SMS sending failed', [
                'to' => $to,
                'provider' => $provider->name,
                'error' => $result['error'],
            ]);

            return false;
        }
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
        $settings = $plugin->getSettings();

        // Get provider
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

        if (!$provider->enabled) {
            $this->logError('Provider is disabled', ['providerId' => $provider->id, 'name' => $provider->name]);
            return [
                'success' => false,
                'messageId' => null,
                'response' => null,
                'error' => 'Provider is disabled',
                'executionTime' => (int)round((microtime(true) - $startTime) * 1000),
                'providerName' => $provider->name,
                'senderIdName' => null,
                'senderIdValue' => null,
                'recipient' => $to,
            ];
        }

        // Get sender ID
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

        // Create log record
        $log = new LogRecord([
            'providerId' => $provider->id,
            'senderIdId' => $senderId->id,
            'recipient' => $to,
            'message' => $message,
            'language' => $language,
            'messageLength' => mb_strlen($message),
            'status' => LogRecord::STATUS_PENDING,
            'sourcePlugin' => $sourcePlugin,
            'sourceElementId' => $sourceElementId,
            'uid' => StringHelper::UUID(),
            'dateCreated' => new \DateTime(),
            'dateUpdated' => new \DateTime(),
        ]);

        // Save log if logging is enabled
        if ($settings->enableLogs) {
            $log->save(false);

            // Trim logs if auto-trim is enabled
            if ($settings->autoTrimLogs) {
                $this->trimLogs();
            }
        }

        // Get provider instance
        $providerInstance = $plugin->providers->createProviderByType($provider->type);

        if (!$providerInstance) {
            $this->logError('Unknown provider type', ['type' => $provider->type]);
            $this->updateLogStatus($log, LogRecord::STATUS_FAILED, 'Unknown provider type');
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

        // Send via provider
        $result = $providerInstance->send(
            $to,
            $message,
            $senderId->senderId,
            $language,
            $provider->getSettingsArray()
        );

        $executionTime = (int)round((microtime(true) - $startTime) * 1000);

        // Update log with result
        if ($result['success']) {
            $this->updateLogStatus(
                $log,
                LogRecord::STATUS_SENT,
                null,
                $result['messageId'],
                $result['response']
            );

            // Update analytics
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
                LogRecord::STATUS_FAILED,
                $result['error'],
                $result['messageId'],
                $result['response']
            );

            // Update analytics
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
     * Send an SMS using sender ID handle (convenience method)
     *
     * @param string $to Recipient phone number
     * @param string $message Message content
     * @param string $senderIdHandle Sender ID handle
     * @param string $language Message language
     * @param string|null $sourcePlugin Source plugin handle
     * @return bool
     */
    public function sendWithHandle(
        string $to,
        string $message,
        string $senderIdHandle,
        string $language = 'en',
        ?string $sourcePlugin = null,
    ): bool {
        $senderId = SmsManager::$plugin->senderIds->getSenderIdByHandle($senderIdHandle);

        if (!$senderId) {
            $this->logError('Sender ID not found', ['handle' => $senderIdHandle]);
            return false;
        }

        return $this->send(
            $to,
            $message,
            $language,
            $senderId->providerId,
            $senderId->id,
            $sourcePlugin
        );
    }

    /**
     * Update log record status
     *
     * @param LogRecord $log Log record
     * @param string $status New status
     * @param string|null $errorMessage Error message (for failures)
     * @param string|null $messageId Provider message ID
     * @param string|null $response Raw provider response
     */
    private function updateLogStatus(
        LogRecord $log,
        string $status,
        ?string $errorMessage = null,
        ?string $messageId = null,
        ?string $response = null,
    ): void {
        if (!SmsManager::$plugin->getSettings()->enableLogs) {
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
     * @param int $providerId Provider ID
     * @param int $senderIdId Sender ID
     * @param string $language Message language
     * @param bool $success Whether send was successful
     * @param string|null $sourcePlugin Source plugin
     */
    private function updateAnalytics(
        int $providerId,
        int $senderIdId,
        string $language,
        bool $success,
        ?string $sourcePlugin,
    ): void {
        $today = (new \DateTime())->format('Y-m-d');

        // Find or create analytics record for today
        $analytics = AnalyticsRecord::findOne([
            'date' => $today,
            'providerId' => $providerId,
            'senderIdId' => $senderIdId,
            'sourcePlugin' => $sourcePlugin,
        ]);

        if (!$analytics) {
            $analytics = new AnalyticsRecord([
                'date' => $today,
                'providerId' => $providerId,
                'senderIdId' => $senderIdId,
                'sourcePlugin' => $sourcePlugin,
                'totalSent' => 0,
                'totalDelivered' => 0,
                'totalFailed' => 0,
                'totalPending' => 0,
                'totalCharacters' => 0,
                'totalMessages' => 0,
                'englishCount' => 0,
                'arabicCount' => 0,
                'otherCount' => 0,
                'uid' => StringHelper::UUID(),
                'dateCreated' => new \DateTime(),
                'dateUpdated' => new \DateTime(),
            ]);
        }

        // Update counts
        if ($success) {
            $analytics->totalSent++;
        } else {
            $analytics->totalFailed++;
        }

        // Update language counts
        match ($language) {
            'en' => $analytics->englishCount++,
            'ar' => $analytics->arabicCount++,
            default => $analytics->otherCount++,
        };

        $analytics->dateUpdated = new \DateTime();
        $analytics->save(false);

        // Trim analytics if auto-trim is enabled
        $settings = SmsManager::$plugin->getSettings();
        if ($settings->autoTrimAnalytics) {
            $this->trimAnalytics();
        }
    }

    /**
     * Trim logs to stay within limit
     */
    private function trimLogs(): void
    {
        $settings = SmsManager::$plugin->getSettings();
        $limit = $settings->logsLimit;

        // Get current count
        $currentCount = (new Query())
            ->from(LogRecord::tableName())
            ->count();

        if ($currentCount <= $limit) {
            return;
        }

        // Get IDs to delete (oldest by dateCreated)
        $idsToDelete = (new Query())
            ->select(['id'])
            ->from(LogRecord::tableName())
            ->orderBy(['dateCreated' => SORT_ASC])
            ->limit($currentCount - $limit)
            ->column();

        if (!empty($idsToDelete)) {
            Craft::$app->getDb()->createCommand()
                ->delete(LogRecord::tableName(), ['id' => $idsToDelete])
                ->execute();
        }
    }

    /**
     * Trim analytics to stay within limit
     */
    private function trimAnalytics(): void
    {
        $settings = SmsManager::$plugin->getSettings();
        $limit = $settings->analyticsLimit;

        // Get current count
        $currentCount = (new Query())
            ->from(AnalyticsRecord::tableName())
            ->count();

        if ($currentCount <= $limit) {
            return;
        }

        // Get IDs to delete (oldest by date)
        $idsToDelete = (new Query())
            ->select(['id'])
            ->from(AnalyticsRecord::tableName())
            ->orderBy(['date' => SORT_ASC])
            ->limit($currentCount - $limit)
            ->column();

        if (!empty($idsToDelete)) {
            Craft::$app->getDb()->createCommand()
                ->delete(AnalyticsRecord::tableName(), ['id' => $idsToDelete])
                ->execute();
        }
    }
}
