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
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\ScheduleHelper;
use lindemannrock\smsmanager\jobs\CleanupAnalyticsJob;
use lindemannrock\smsmanager\jobs\CleanupLogsJob;
use lindemannrock\smsmanager\SmsManager;
use lindemannrock\smsmanager\tests\TestCase;
use ReflectionMethod;

/**
 * Verifies cleanup jobs push their next occurrence from the execute-time
 * reschedule path even while the current queue row still exists.
 *
 * @since 5.13.0
 */
final class RecurringCleanupJobsRescheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteSmsManagerQueueRows();
    }

    protected function tearDown(): void
    {
        $this->deleteSmsManagerQueueRows();
        parent::tearDown();
    }

    public function testAnalyticsCleanupReschedulesWhenExistingCleanupRowExists(): void
    {
        Craft::$app->getQueue()->delay(300)->push(new CleanupAnalyticsJob([
            'reschedule' => true,
        ]));
        $this->assertSame(1, $this->countQueueRows('CleanupAnalyticsJob'));

        $job = new CleanupAnalyticsJob([
            'reschedule' => true,
        ]);
        $this->invokePrivate($job, 'scheduleNextCleanup');

        $this->assertSame(2, $this->countQueueRows('CleanupAnalyticsJob'));
    }

    public function testLogsCleanupReschedulesWhenExistingCleanupRowExists(): void
    {
        Craft::$app->getQueue()->delay(300)->push(new CleanupLogsJob([
            'reschedule' => true,
        ]));
        $this->assertSame(1, $this->countQueueRows('CleanupLogsJob'));

        $job = new CleanupLogsJob([
            'reschedule' => true,
        ]);
        $this->invokePrivate($job, 'scheduleNextCleanup');

        $this->assertSame(2, $this->countQueueRows('CleanupLogsJob'));
    }

    public function testAnalyticsCleanupBootstrapDoesNotDuplicateExistingDelayedCleanupRow(): void
    {
        $settings = SmsManager::$plugin->getSettings();
        $settings->enableAnalytics = true;
        $settings->analyticsRetention = 30;

        Craft::$app->getQueue()->delay(300)->push(new CleanupAnalyticsJob([
            'reschedule' => true,
        ]));
        $this->assertSame(1, $this->countQueueRows('CleanupAnalyticsJob'));

        $this->invokePrivate(SmsManager::$plugin, 'scheduleAnalyticsCleanup');

        $this->assertSame(1, $this->countQueueRows('CleanupAnalyticsJob'));
    }

    public function testAnalyticsCleanupBootstrapUsesCanonicalDailyRun(): void
    {
        $settings = SmsManager::$plugin->getSettings();
        $settings->enableAnalytics = true;
        $settings->analyticsRetention = 30;

        $this->invokePrivate(SmsManager::$plugin, 'scheduleAnalyticsCleanup');

        $row = $this->latestQueueRow('CleanupAnalyticsJob');

        self::assertNotNull($row);
        self::assertStringContainsString($this->expectedDailyRunTime(), (string) $row['description']);
    }

    public function testAnalyticsCleanupBootstrapCollapsesDuplicatePendingRows(): void
    {
        $settings = SmsManager::$plugin->getSettings();
        $settings->enableAnalytics = true;
        $settings->analyticsRetention = 30;

        Craft::$app->getQueue()->delay(300)->push(new CleanupAnalyticsJob([
            'reschedule' => true,
        ]));
        Craft::$app->getQueue()->delay(300)->push(new CleanupAnalyticsJob([
            'reschedule' => true,
        ]));
        $this->assertSame(2, $this->countQueueRows('CleanupAnalyticsJob'));

        $this->invokePrivate(SmsManager::$plugin, 'scheduleAnalyticsCleanup');

        $this->assertSame(1, $this->countQueueRows('CleanupAnalyticsJob'));
    }

    public function testLogsCleanupBootstrapDoesNotDuplicateExistingDelayedCleanupRow(): void
    {
        $settings = SmsManager::$plugin->getSettings();
        $settings->enableSmsLogs = true;
        $settings->smsLogsRetention = 30;

        Craft::$app->getQueue()->delay(300)->push(new CleanupLogsJob([
            'reschedule' => true,
        ]));
        $this->assertSame(1, $this->countQueueRows('CleanupLogsJob'));

        $this->invokePrivate(SmsManager::$plugin, 'scheduleLogsCleanup');

        $this->assertSame(1, $this->countQueueRows('CleanupLogsJob'));
    }

    public function testLogsCleanupBootstrapUsesCanonicalDailyRun(): void
    {
        $settings = SmsManager::$plugin->getSettings();
        $settings->enableSmsLogs = true;
        $settings->smsLogsRetention = 30;

        $this->invokePrivate(SmsManager::$plugin, 'scheduleLogsCleanup');

        $row = $this->latestQueueRow('CleanupLogsJob');

        self::assertNotNull($row);
        self::assertStringContainsString($this->expectedDailyRunTime(), (string) $row['description']);
    }

    public function testLogsCleanupBootstrapCollapsesDuplicatePendingRows(): void
    {
        $settings = SmsManager::$plugin->getSettings();
        $settings->enableSmsLogs = true;
        $settings->smsLogsRetention = 30;

        Craft::$app->getQueue()->delay(300)->push(new CleanupLogsJob([
            'reschedule' => true,
        ]));
        Craft::$app->getQueue()->delay(300)->push(new CleanupLogsJob([
            'reschedule' => true,
        ]));
        $this->assertSame(2, $this->countQueueRows('CleanupLogsJob'));

        $this->invokePrivate(SmsManager::$plugin, 'scheduleLogsCleanup');

        $this->assertSame(1, $this->countQueueRows('CleanupLogsJob'));
    }

    private function invokePrivate(object $object, string $method): void
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->invoke($object);
    }

    private function countQueueRows(string $jobClass): int
    {
        return (int) (new \craft\db\Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', 'smsmanager'])
            ->andWhere(['like', 'job', $jobClass])
            ->count();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestQueueRow(string $jobClass): ?array
    {
        $row = (new \craft\db\Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', 'smsmanager'])
            ->andWhere(['like', 'job', $jobClass])
            ->orderBy(['id' => SORT_DESC])
            ->one();

        return is_array($row) ? $row : null;
    }

    private function expectedDailyRunTime(): string
    {
        $nextRun = ScheduleHelper::calculateNext('daily');
        self::assertNotNull($nextRun);

        return DateFormatHelper::formatCompactDatetimeFromSettings(
            $nextRun,
            SmsManager::$plugin->getSettings(),
            false,
            false,
        );
    }

    private function deleteSmsManagerQueueRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete('{{%queue}}', [
                'and',
                ['like', 'job', 'smsmanager'],
                [
                    'or',
                    ['like', 'job', 'CleanupAnalyticsJob'],
                    ['like', 'job', 'CleanupLogsJob'],
                ],
            ])
            ->execute();
    }
}
