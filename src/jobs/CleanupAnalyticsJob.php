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
use lindemannrock\smsmanager\records\AnalyticsRecord;
use lindemannrock\smsmanager\SmsManager;

/**
 * Cleanup Analytics Job
 *
 * Automatically cleans up old analytics based on retention settings
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
class CleanupAnalyticsJob extends BaseJob
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
        if ($settings->analyticsRetention <= 0) {
            return;
        }

        // Clean up old analytics by date
        $deleted = $this->cleanupOldAnalytics();

        // Also trim by count if auto-trim is enabled
        $trimmed = 0;
        if ($settings->autoTrimAnalytics) {
            $trimmed = $this->trimAnalytics();
        }

        $this->logInfo('Analytics cleanup completed', [
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
        $description = Craft::t('sms-manager', '{pluginName}: Cleaning up old analytics', [
            'pluginName' => $settings->getDisplayName(),
        ]);

        if ($this->nextRunTime) {
            $description .= " ({$this->nextRunTime})";
        }

        return $description;
    }

    /**
     * Clean up analytics older than retention period
     */
    private function cleanupOldAnalytics(): int
    {
        $settings = SmsManager::$plugin->getSettings();
        $retention = $settings->analyticsRetention;

        if ($retention <= 0) {
            return 0;
        }

        $date = (new \DateTime())->modify("-{$retention} days");

        $deleted = Craft::$app->getDb()->createCommand()
            ->delete(
                AnalyticsRecord::tableName(),
                ['<', 'date', Db::prepareDateForDb($date)]
            )
            ->execute();

        if ($deleted > 0) {
            $this->logInfo('Cleaned up old analytics', [
                'deleted' => $deleted,
                'retention' => $retention,
            ]);
        }

        return $deleted;
    }

    /**
     * Trim analytics to stay within limit
     */
    private function trimAnalytics(): int
    {
        $settings = SmsManager::$plugin->getSettings();
        $limit = $settings->analyticsLimit;

        // Get current count
        $currentCount = (new Query())
            ->from(AnalyticsRecord::tableName())
            ->count();

        if ($currentCount <= $limit) {
            return 0;
        }

        // Get IDs to delete (oldest by date)
        $idsToDelete = (new Query())
            ->select(['id'])
            ->from(AnalyticsRecord::tableName())
            ->orderBy(['date' => SORT_ASC])
            ->limit($currentCount - $limit)
            ->column();

        if (empty($idsToDelete)) {
            return 0;
        }

        $deleted = Craft::$app->getDb()->createCommand()
            ->delete(AnalyticsRecord::tableName(), ['id' => $idsToDelete])
            ->execute();

        if ($deleted > 0) {
            $this->logInfo('Trimmed analytics to limit', [
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

        // Only reschedule if analytics is enabled and retention is set
        if (!$settings->enableAnalytics || $settings->analyticsRetention <= 0) {
            return;
        }

        // Prevent duplicate scheduling - check if another cleanup job already exists
        // This prevents fan-out if multiple jobs end up in the queue (manual runs, retries, etc.)
        $existingJob = (new \craft\db\Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', 'smsmanager'])
            ->andWhere(['like', 'job', 'CleanupAnalyticsJob'])
            ->exists();

        if ($existingJob) {
            $this->logDebug('Skipping reschedule - analytics cleanup job already exists');
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

            $this->logDebug('Scheduled next analytics cleanup', [
                'delay' => $delay,
                'nextRun' => $nextRunTime,
            ]);
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
