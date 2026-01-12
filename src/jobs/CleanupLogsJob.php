<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\smsmanager\jobs;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\queue\BaseJob;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\smsmanager\records\LogRecord;
use lindemannrock\smsmanager\SmsManager;

/**
 * Cleanup Logs Job
 *
 * Automatically cleans up old SMS logs based on retention settings
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
class CleanupLogsJob extends BaseJob
{
    use LoggingTrait;

    /**
     * @var bool Whether to reschedule cleanup after completion
     */
    public bool $reschedule = false;

    /**
     * @var string|null Next run time display string for queued jobs
     */
    public ?string $nextRunTime = null;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('sms-manager');

        // Calculate and set next run time if not already set
        if ($this->reschedule && !$this->nextRunTime) {
            $delay = $this->calculateNextRunDelay();
            if ($delay > 0) {
                $this->nextRunTime = date('M j, g:ia', time() + $delay);
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $settings = SmsManager::$plugin->getSettings();

        // Only run if retention is enabled
        if ($settings->logsRetention <= 0) {
            return;
        }

        // Clean up old logs by date
        $deleted = $this->cleanupOldLogs();

        // Also trim by count if auto-trim is enabled
        $trimmed = 0;
        if ($settings->autoTrimLogs) {
            $trimmed = $this->trimLogs();
        }

        $this->logInfo('Logs cleanup completed', [
            'deleted' => $deleted,
            'trimmed' => $trimmed,
        ]);

        // Reschedule if needed
        if ($this->reschedule) {
            $this->scheduleNextCleanup();
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        $settings = SmsManager::$plugin->getSettings();
        $description = Craft::t('sms-manager', '{pluginName}: Cleaning up old SMS logs', [
            'pluginName' => $settings->getDisplayName(),
        ]);

        if ($this->nextRunTime) {
            $description .= " ({$this->nextRunTime})";
        }

        return $description;
    }

    /**
     * Clean up logs older than retention period
     */
    private function cleanupOldLogs(): int
    {
        $settings = SmsManager::$plugin->getSettings();
        $retention = $settings->logsRetention;

        if ($retention <= 0) {
            return 0;
        }

        $date = (new \DateTime())->modify("-{$retention} days");

        $deleted = Craft::$app->getDb()->createCommand()
            ->delete(
                LogRecord::tableName(),
                ['<', 'dateCreated', Db::prepareDateForDb($date)]
            )
            ->execute();

        if ($deleted > 0) {
            $this->logInfo('Cleaned up old SMS logs', [
                'deleted' => $deleted,
                'retention' => $retention,
            ]);
        }

        return $deleted;
    }

    /**
     * Trim logs to stay within limit
     */
    private function trimLogs(): int
    {
        $settings = SmsManager::$plugin->getSettings();
        $limit = $settings->logsLimit;

        // Get current count
        $currentCount = (new Query())
            ->from(LogRecord::tableName())
            ->count();

        if ($currentCount <= $limit) {
            return 0;
        }

        // Get IDs to delete (oldest by dateCreated)
        $idsToDelete = (new Query())
            ->select(['id'])
            ->from(LogRecord::tableName())
            ->orderBy(['dateCreated' => SORT_ASC])
            ->limit($currentCount - $limit)
            ->column();

        if (empty($idsToDelete)) {
            return 0;
        }

        $deleted = Craft::$app->getDb()->createCommand()
            ->delete(LogRecord::tableName(), ['id' => $idsToDelete])
            ->execute();

        if ($deleted > 0) {
            $this->logInfo('Trimmed SMS logs to limit', [
                'deleted' => $deleted,
                'limit' => $limit,
            ]);
        }

        return $deleted;
    }

    /**
     * Schedule the next cleanup (runs every 24 hours)
     */
    private function scheduleNextCleanup(): void
    {
        $settings = SmsManager::$plugin->getSettings();

        // Only reschedule if logs are enabled and retention is set
        if (!$settings->enableLogs || $settings->logsRetention <= 0) {
            return;
        }

        $delay = $this->calculateNextRunDelay();

        if ($delay > 0) {
            $nextRunTime = date('M j, g:ia', time() + $delay);

            $job = new self([
                'reschedule' => true,
                'nextRunTime' => $nextRunTime,
            ]);

            Craft::$app->getQueue()->delay($delay)->push($job);
        }
    }

    /**
     * Calculate the delay in seconds for the next cleanup (24 hours)
     */
    private function calculateNextRunDelay(): int
    {
        return 86400; // 24 hours
    }
}
