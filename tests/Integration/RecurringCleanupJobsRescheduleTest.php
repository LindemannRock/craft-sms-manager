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
use lindemannrock\smsmanager\jobs\CleanupAnalyticsJob;
use lindemannrock\smsmanager\jobs\CleanupLogsJob;
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
