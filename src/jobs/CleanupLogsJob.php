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
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\ScheduleHelper;
use lindemannrock\base\traits\QueueTtrTrait;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\smsmanager\models\Settings;
use lindemannrock\smsmanager\records\SmsLogRecord;
use lindemannrock\smsmanager\services\RecurringCleanupScheduler;
use lindemannrock\smsmanager\SmsManager;
use yii\queue\RetryableJobInterface;

/**
 * Cleanup Logs Job
 *
 * Automatically cleans up old SMS logs based on retention settings
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.2.0
 */
class CleanupLogsJob extends BaseJob implements RetryableJobInterface
{
    use QueueTtrTrait;
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
     * @var string|null Stable recurring-family owner
     * @since 5.16.0
     */
    public ?string $recurringOwner = null;

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle(SmsManager::$plugin->id);
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $scheduler = SmsManager::$plugin->recurringCleanup;

        if ($this->reschedule && ($this->recurringOwner === null || $this->recurringOwner === RecurringCleanupScheduler::LOGS_OWNER)) {
            $scheduler->runLogsOccurrence(fn(Settings $settings) => $this->runCleanup($settings));
            return;
        }

        $settings = $scheduler->loadEffectiveSettings();

        // Only run if retention is enabled
        if ($settings->smsLogsRetention <= 0) {
            return;
        }

        $this->runCleanup($settings);
    }

    /** Run date retention followed by the optional count trim. */
    private function runCleanup(Settings $settings): void
    {

        // Clean up old logs by date
        $deleted = $this->cleanupOldLogs($settings);

        // Also trim by count if auto-trim is enabled
        $trimmed = 0;
        if ($settings->autoTrimSmsLogs) {
            $trimmed = $this->trimLogs($settings);
        }

        $this->logInfo('Logs cleanup completed', [
            'deleted' => $deleted,
            'trimmed' => $trimmed,
        ]);
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

        $nextRunTime = $this->nextRunTime;
        if ($nextRunTime === null && $this->reschedule) {
            $nextRun = $this->calculateNextRun();
            if ($nextRun !== null) {
                $nextRunTime = DateFormatHelper::formatCompactDatetimeFromSettings(
                    $nextRun,
                    $settings,
                    null,
                    false,
                    pluginHandle: 'sms-manager',
                );
            }
        }

        if ($nextRunTime) {
            $description .= " ({$nextRunTime})";
        }

        return $description;
    }

    /**
     * Clean up logs older than retention period
     */
    private function cleanupOldLogs(Settings $settings): int
    {
        $retention = $settings->smsLogsRetention;

        if ($retention <= 0) {
            return 0;
        }

        $date = (clone DateFormatHelper::now())->modify("-{$retention} days");

        $deleted = Craft::$app->getDb()->createCommand()
            ->delete(
                SmsLogRecord::tableName(),
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
    private function trimLogs(Settings $settings): int
    {
        $limit = $settings->smsLogsLimit;

        // Get current count
        $currentCount = (new Query())
            ->from(SmsLogRecord::tableName())
            ->count();

        if ($currentCount <= $limit) {
            return 0;
        }

        // Get IDs to delete (oldest by dateCreated)
        $idsToDelete = (new Query())
            ->select(['id'])
            ->from(SmsLogRecord::tableName())
            ->orderBy(['dateCreated' => SORT_ASC])
            ->limit($currentCount - $limit)
            ->column();

        if (empty($idsToDelete)) {
            return 0;
        }

        $deleted = Craft::$app->getDb()->createCommand()
            ->delete(SmsLogRecord::tableName(), ['id' => $idsToDelete])
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
     * Calculate the next cleanup run.
     */
    private function calculateNextRun(): ?\DateTime
    {
        return ScheduleHelper::calculateNext('daily');
    }
}
