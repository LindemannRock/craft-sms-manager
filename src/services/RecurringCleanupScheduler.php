<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\queue\BaseJob;
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\PluginHelper;
use lindemannrock\base\helpers\RecurringQueueResult;
use lindemannrock\base\helpers\ScheduleHelper;
use lindemannrock\base\queue\PortableQueueScheduler;
use lindemannrock\smsmanager\jobs\CleanupAnalyticsJob;
use lindemannrock\smsmanager\jobs\CleanupLogsJob;
use lindemannrock\smsmanager\models\Settings;
use yii\db\Expression;

/**
 * Owns the independent recurring analytics and SMS-log cleanup families.
 *
 * @since 5.16.0
 */
final class RecurringCleanupScheduler extends Component
{
    public const PLUGIN_TOKEN = 'smsmanager';

    public const ANALYTICS_OWNER = 'sms-manager:analytics-cleanup:daily';
    public const ANALYTICS_LIFECYCLE_MUTEX = 'sms-manager:analytics-cleanup:schedule';
    public const ANALYTICS_PORTABLE_MUTEX = 'sms-manager:analytics-cleanup:portable';

    public const LOGS_OWNER = 'sms-manager:logs-cleanup:daily';
    public const LOGS_LIFECYCLE_MUTEX = 'sms-manager:logs-cleanup:schedule';
    public const LOGS_PORTABLE_MUTEX = 'sms-manager:logs-cleanup:portable';

    private const MUTEX_TIMEOUT = 5;
    private const PRIORITY = 1024;
    private const TTR = 1800;

    /** Reconcile both families during a non-console plugin bootstrap. */
    public function synchronize(?Settings $settings = null): void
    {
        $settings ??= $this->loadEffectiveSettings();

        $this->runIndependently([
            fn() => $this->synchronizeAnalytics($settings),
            fn() => $this->synchronizeLogs($settings),
        ]);
    }

    /** Reconcile the analytics family during plugin bootstrap. */
    public function synchronizeAnalytics(Settings $settings): RecurringQueueResult
    {
        $target = $this->analyticsEnabled($settings) ? $this->nextDailyRun() : null;

        return $this->withMutationLocks(
            self::ANALYTICS_LIFECYCLE_MUTEX,
            self::ANALYTICS_PORTABLE_MUTEX,
            'analytics cleanup',
            fn(): RecurringQueueResult => $target === null
                ? $this->cancelLocked(CleanupAnalyticsJob::class, self::ANALYTICS_OWNER)
                : $this->queueAtLocked(
                    CleanupAnalyticsJob::class,
                    self::ANALYTICS_OWNER,
                    self::ANALYTICS_PORTABLE_MUTEX,
                    $target,
                    $this->analyticsJob($settings, $target),
                ),
        );
    }

    /** Reconcile the SMS-log family during plugin bootstrap. */
    public function synchronizeLogs(Settings $settings): RecurringQueueResult
    {
        $target = $this->logsEnabled($settings) ? $this->nextDailyRun() : null;

        return $this->withMutationLocks(
            self::LOGS_LIFECYCLE_MUTEX,
            self::LOGS_PORTABLE_MUTEX,
            'SMS-log cleanup',
            fn(): RecurringQueueResult => $target === null
                ? $this->cancelLocked(CleanupLogsJob::class, self::LOGS_OWNER)
                : $this->queueAtLocked(
                    CleanupLogsJob::class,
                    self::LOGS_OWNER,
                    self::LOGS_PORTABLE_MUTEX,
                    $target,
                    $this->logsJob($settings, $target),
                ),
        );
    }

    /** Reconcile both effective settings transitions without coupling their locks. */
    public function reconcileSettings(bool $analyticsWasEnabled, bool $logsWereEnabled, Settings $settings): void
    {
        $this->runIndependently([
            fn() => $this->reconcileAnalytics($analyticsWasEnabled, $settings),
            fn() => $this->reconcileLogs($logsWereEnabled, $settings),
        ]);
    }

    /** Reconcile an analytics eligibility transition. */
    public function reconcileAnalytics(bool $wasEnabled, Settings $settings): bool
    {
        $isEnabled = $this->analyticsEnabled($settings);
        if ($wasEnabled === $isEnabled) {
            return false;
        }

        $target = $isEnabled ? $this->nextDailyRun() : null;
        $this->withMutationLocks(
            self::ANALYTICS_LIFECYCLE_MUTEX,
            self::ANALYTICS_PORTABLE_MUTEX,
            'analytics cleanup',
            function() use ($settings, $target): void {
                $this->cancelRowsLocked(CleanupAnalyticsJob::class, self::ANALYTICS_OWNER);
                if ($target !== null) {
                    $this->pushAtLocked(
                        CleanupAnalyticsJob::class,
                        self::ANALYTICS_OWNER,
                        self::ANALYTICS_PORTABLE_MUTEX,
                        $target,
                        $this->analyticsJob($settings, $target),
                    );
                }
            },
        );

        return true;
    }

    /** Reconcile an SMS-log eligibility transition. */
    public function reconcileLogs(bool $wasEnabled, Settings $settings): bool
    {
        $isEnabled = $this->logsEnabled($settings);
        if ($wasEnabled === $isEnabled) {
            return false;
        }

        $target = $isEnabled ? $this->nextDailyRun() : null;
        $this->withMutationLocks(
            self::LOGS_LIFECYCLE_MUTEX,
            self::LOGS_PORTABLE_MUTEX,
            'SMS-log cleanup',
            function() use ($settings, $target): void {
                $this->cancelRowsLocked(CleanupLogsJob::class, self::LOGS_OWNER);
                if ($target !== null) {
                    $this->pushAtLocked(
                        CleanupLogsJob::class,
                        self::LOGS_OWNER,
                        self::LOGS_PORTABLE_MUTEX,
                        $target,
                        $this->logsJob($settings, $target),
                    );
                }
            },
        );

        return true;
    }

    /**
     * Run one recurring analytics occurrence and queue its successor on success.
     *
     * @param callable(Settings): void $cleanup
     */
    public function runAnalyticsOccurrence(callable $cleanup): bool
    {
        return $this->withLifecycleLock(
            self::ANALYTICS_LIFECYCLE_MUTEX,
            'analytics cleanup',
            function() use ($cleanup): bool {
                $settings = $this->loadEffectiveSettings();
                if (!$this->analyticsEnabled($settings)) {
                    return false;
                }

                $cleanup($settings);
                $target = $this->nextDailyRun();
                if ($target !== null) {
                    $this->withPortableLock(
                        self::ANALYTICS_PORTABLE_MUTEX,
                        'analytics cleanup',
                        fn() => $this->queueAtLocked(
                            CleanupAnalyticsJob::class,
                            self::ANALYTICS_OWNER,
                            self::ANALYTICS_PORTABLE_MUTEX,
                            $target,
                            $this->analyticsJob($settings, $target),
                        ),
                    );
                }

                return true;
            },
        );
    }

    /**
     * Run one recurring SMS-log occurrence and queue its successor on success.
     *
     * @param callable(Settings): void $cleanup
     */
    public function runLogsOccurrence(callable $cleanup): bool
    {
        return $this->withLifecycleLock(
            self::LOGS_LIFECYCLE_MUTEX,
            'SMS-log cleanup',
            function() use ($cleanup): bool {
                $settings = $this->loadEffectiveSettings();
                if (!$this->logsEnabled($settings)) {
                    return false;
                }

                $cleanup($settings);
                $target = $this->nextDailyRun();
                if ($target !== null) {
                    $this->withPortableLock(
                        self::LOGS_PORTABLE_MUTEX,
                        'SMS-log cleanup',
                        fn() => $this->queueAtLocked(
                            CleanupLogsJob::class,
                            self::LOGS_OWNER,
                            self::LOGS_PORTABLE_MUTEX,
                            $target,
                            $this->logsJob($settings, $target),
                        ),
                    );
                }

                return true;
            },
        );
    }

    /** Load the latest persisted settings and apply configuration overrides. */
    public function loadEffectiveSettings(): Settings
    {
        return PluginHelper::applyConfigOverridesToSettings(
            Settings::loadFromDatabase(),
            'sms-manager',
        );
    }

    public function analyticsEnabled(Settings $settings): bool
    {
        return $settings->enableAnalytics && $settings->analyticsRetention > 0;
    }

    public function logsEnabled(Settings $settings): bool
    {
        return $settings->enableSmsLogs && $settings->smsLogsRetention > 0;
    }

    private function nextDailyRun(): ?\DateTime
    {
        return ScheduleHelper::calculateNext('daily');
    }

    private function analyticsJob(Settings $settings, \DateTime $target): CleanupAnalyticsJob
    {
        return new CleanupAnalyticsJob([
            'reschedule' => true,
            'recurringOwner' => self::ANALYTICS_OWNER,
            'nextRunTime' => $this->formatTarget($settings, $target),
        ]);
    }

    private function logsJob(Settings $settings, \DateTime $target): CleanupLogsJob
    {
        return new CleanupLogsJob([
            'reschedule' => true,
            'recurringOwner' => self::LOGS_OWNER,
            'nextRunTime' => $this->formatTarget($settings, $target),
        ]);
    }

    private function formatTarget(Settings $settings, \DateTime $target): string
    {
        return DateFormatHelper::formatCompactDatetimeFromSettings(
            $target,
            $settings,
            null,
            false,
            pluginHandle: 'sms-manager',
        );
    }

    /**
     * @param class-string<BaseJob> $jobClass
     */
    private function queueAtLocked(
        string $jobClass,
        string $owner,
        string $portableMutex,
        \DateTime $target,
        BaseJob $job,
    ): RecurringQueueResult {
        $legacyRows = $this->legacyRows($jobClass, $owner);
        $healthyLegacyRows = $this->healthyRows($legacyRows);
        if ($healthyLegacyRows !== []) {
            $kept = $healthyLegacyRows[0];
            $duplicates = array_filter(
                $legacyRows,
                static fn(array $row): bool => (string)$row['id'] !== (string)$kept['id'],
            );
            $duplicatesDeleted = $this->deleteRows(array_values($duplicates));
            $duplicatesDeleted += $this->deleteRows($this->ownedRows($jobClass, $owner));

            return new RecurringQueueResult(
                RecurringQueueResult::STATUS_EXISTING,
                (string)$kept['id'],
                $duplicatesDeleted,
            );
        }

        $ownedRows = $this->ownedRows($jobClass, $owner);
        $healthyOwnedRows = $this->healthyRows($ownedRows);
        if ($healthyOwnedRows !== []) {
            $kept = $healthyOwnedRows[0];
            $duplicates = array_filter(
                $ownedRows,
                static fn(array $row): bool => (string)$row['id'] !== (string)$kept['id'],
            );
            $duplicatesDeleted = $this->deleteRows(array_values($duplicates));
            $duplicatesDeleted += $this->deleteRows($legacyRows);

            return new RecurringQueueResult(
                RecurringQueueResult::STATUS_EXISTING,
                (string)$kept['id'],
                $duplicatesDeleted,
            );
        }

        $this->deleteRows($legacyRows);
        $this->deleteRows($ownedRows);

        return $this->pushAtLocked($jobClass, $owner, $portableMutex, $target, $job);
    }

    /**
     * @param class-string<BaseJob> $jobClass
     */
    private function pushAtLocked(
        string $jobClass,
        string $owner,
        string $portableMutex,
        \DateTime $target,
        BaseJob $job,
    ): RecurringQueueResult {
        $jobId = PortableQueueScheduler::pushAt(
            job: $job,
            targetTimestamp: $target->getTimestamp(),
            identityTokens: [self::PLUGIN_TOKEN, $jobClass, $owner],
            mutexName: $portableMutex,
            mutexTimeout: self::MUTEX_TIMEOUT,
            priority: self::PRIORITY,
            ttr: self::TTR,
        );

        if ($jobId === null) {
            throw new \RuntimeException('Portable recurring cleanup scheduling did not create a queue row.');
        }

        return new RecurringQueueResult(RecurringQueueResult::STATUS_CREATED, $jobId);
    }

    /**
     * @param class-string<BaseJob> $jobClass
     */
    private function cancelLocked(string $jobClass, string $owner): RecurringQueueResult
    {
        $deleted = $this->cancelRowsLocked($jobClass, $owner);

        return new RecurringQueueResult(
            RecurringQueueResult::STATUS_SKIPPED,
            null,
            $deleted,
        );
    }

    /**
     * @param class-string<BaseJob> $jobClass
     */
    private function cancelRowsLocked(string $jobClass, string $owner): int
    {
        return $this->deleteRows(array_merge(
            $this->ownedRows($jobClass, $owner),
            $this->legacyRows($jobClass, $owner),
        ));
    }

    /**
     * @param class-string<BaseJob> $jobClass
     * @return list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string, timeUpdated: int|string|null, fail: bool|int|string}>
     */
    private function ownedRows(string $jobClass, string $owner): array
    {
        return $this->filterRows(
            $this->familyRows($jobClass),
            fn(string $payload): bool => $this->isOwnedPayload($payload, $jobClass, $owner),
        );
    }

    /**
     * @param class-string<BaseJob> $jobClass
     * @return list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string, timeUpdated: int|string|null, fail: bool|int|string}>
     */
    private function legacyRows(string $jobClass, string $owner): array
    {
        return $this->filterRows(
            $this->familyRows($jobClass),
            fn(string $payload): bool => $this->isLegacyPayload($payload, $jobClass, $owner),
        );
    }

    /**
     * @param class-string<BaseJob> $jobClass
     * @return list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string, timeUpdated: int|string|null, fail: bool|int|string}>
     */
    private function familyRows(string $jobClass): array
    {
        /** @var list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string, timeUpdated: int|string|null, fail: bool|int|string}> $rows */
        $rows = (new Query())
            ->from('{{%queue}}')
            ->select(['id', 'job', 'timePushed', 'delay', 'priority', 'timeUpdated', 'fail'])
            ->where(['like', 'job', self::PLUGIN_TOKEN])
            ->andWhere(['like', 'job', $this->jobClassToken($jobClass)])
            ->orderBy(new Expression('[[timePushed]] + [[delay]] ASC'))
            ->addOrderBy(['priority' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return $rows;
    }

    /**
     * @param list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string, timeUpdated: int|string|null, fail: bool|int|string}> $rows
     * @return list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string, timeUpdated: int|string|null, fail: bool|int|string}>
     */
    private function filterRows(array $rows, callable $predicate): array
    {
        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => $predicate((string)$row['job']),
        ));
    }

    /**
     * @param list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string, timeUpdated: int|string|null, fail: bool|int|string}> $rows
     * @return list<array{id: int|string, job: string, timePushed: int|string, delay: int|string, priority: int|string, timeUpdated: int|string|null, fail: bool|int|string}>
     */
    private function healthyRows(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => !(bool)$row['fail'] && $row['timeUpdated'] === null,
        ));
    }

    /** @param class-string<BaseJob> $jobClass */
    private function isOwnedPayload(string $payload, string $jobClass, string $owner): bool
    {
        return $this->hasExactPluginToken($payload)
            && $this->hasExactJobClass($payload, $jobClass)
            && $this->hasExactToken($payload, $owner);
    }

    /** @param class-string<BaseJob> $jobClass */
    private function isLegacyPayload(string $payload, string $jobClass, string $owner): bool
    {
        if ($payload === '' || $this->hasExactToken($payload, $owner)) {
            return false;
        }

        $phpReschedule = str_contains($payload, 's:10:"reschedule";b:1;');
        $jsonReschedule = preg_match('/"reschedule"\s*:\s*true/', $payload) === 1;

        return $this->hasExactPluginToken($payload)
            && $this->hasExactJobClass($payload, $jobClass)
            && ($phpReschedule || $jsonReschedule);
    }

    private function hasExactPluginToken(string $payload): bool
    {
        return preg_match(
            '/(?<![A-Za-z0-9_-])' . preg_quote(self::PLUGIN_TOKEN, '/') . '(?![A-Za-z0-9_-])/',
            $payload,
        ) === 1;
    }

    /** @param class-string<BaseJob> $jobClass */
    private function hasExactJobClass(string $payload, string $jobClass): bool
    {
        $normalizedPayload = str_replace('\\\\', '\\', $payload);

        return preg_match(
            '/(?<![A-Za-z0-9_\\\\])' . preg_quote($jobClass, '/') . '(?![A-Za-z0-9_])/',
            $normalizedPayload,
        ) === 1;
    }

    private function hasExactToken(string $payload, string $token): bool
    {
        return preg_match(
            '/(?<![A-Za-z0-9_:-])' . preg_quote($token, '/') . '(?![A-Za-z0-9_:-])/',
            $payload,
        ) === 1;
    }

    /** @param class-string<BaseJob> $jobClass */
    private function jobClassToken(string $jobClass): string
    {
        $parts = explode('\\', $jobClass);

        return end($parts) ?: $jobClass;
    }

    /** @param list<array{id: int|string}> $rows */
    private function deleteRows(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $ids = array_map(static fn(array $row): string => (string)$row['id'], $rows);
        $deleted = Craft::$app->getDb()->createCommand()
            ->delete('{{%queue}}', ['id' => $ids])
            ->execute();

        if ($deleted !== count($ids)) {
            throw new \RuntimeException('Recurring cleanup queue cancellation was incomplete.');
        }

        return $deleted;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withMutationLocks(
        string $lifecycleMutex,
        string $portableMutex,
        string $familyLabel,
        callable $callback,
    ): mixed {
        return $this->withLifecycleLock(
            $lifecycleMutex,
            $familyLabel,
            fn() => $this->withPortableLock($portableMutex, $familyLabel, $callback),
        );
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withLifecycleLock(string $mutexName, string $familyLabel, callable $callback): mixed
    {
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire($mutexName, self::MUTEX_TIMEOUT)) {
            throw new \RuntimeException("Unable to acquire the {$familyLabel} lifecycle lock.");
        }

        try {
            return $callback();
        } finally {
            $mutex->release($mutexName);
        }
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withPortableLock(string $mutexName, string $familyLabel, callable $callback): mixed
    {
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire($mutexName, self::MUTEX_TIMEOUT)) {
            throw new \RuntimeException("Unable to acquire the {$familyLabel} portable lock.");
        }

        try {
            return $callback();
        } finally {
            $mutex->release($mutexName);
        }
    }

    /** @param list<callable(): mixed> $operations */
    private function runIndependently(array $operations): void
    {
        $errors = [];
        foreach ($operations as $operation) {
            try {
                $operation();
            } catch (\Throwable $exception) {
                $errors[] = $exception;
            }
        }

        if (count($errors) === 1) {
            throw $errors[0];
        }

        if ($errors !== []) {
            throw new \RuntimeException(
                'Multiple recurring cleanup reconciliations failed: ' . implode(' | ', array_map(
                    static fn(\Throwable $error): string => $error->getMessage(),
                    $errors,
                )),
                0,
                $errors[0],
            );
        }
    }
}
