<?php
/**
 * LindemannRock SMS Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use Composer\InstalledVersions;
use Craft;
use craft\db\Query;
use craft\errors\MissingComponentException;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use craft\queue\BaseJob;
use craft\queue\Queue;
use craft\services\Config;
use craft\web\Request;
use craft\web\Response;
use lindemannrock\base\helpers\DateFormatHelper;
use lindemannrock\base\helpers\RecurringQueueHelper;
use lindemannrock\base\helpers\ScheduleHelper;
use lindemannrock\base\queue\DeferredQueueJob;
use lindemannrock\base\queue\PortableQueueScheduler;
use lindemannrock\smsmanager\controllers\SettingsController;
use lindemannrock\smsmanager\jobs\CleanupAnalyticsJob;
use lindemannrock\smsmanager\jobs\CleanupLogsJob;
use lindemannrock\smsmanager\models\Settings;
use lindemannrock\smsmanager\services\RecurringCleanupScheduler;
use lindemannrock\smsmanager\SmsManager;
use lindemannrock\smsmanager\tests\Stubs\SmsManagerConfigStub;
use lindemannrock\smsmanager\tests\Support\IsolatedPersistenceQueue;
use lindemannrock\smsmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;
use yii\mutex\Mutex;
use yii\queue\Queue as BaseQueue;
use yii\queue\sqs\Queue as SqsQueue;

/**
 * Pins both independent portable recurring-cleanup queue lifecycles.
 *
 * @since 5.16.0
 */
final class RecurringCleanupQueueTest extends TestCase
{
    private const START_TIMESTAMP = 1_800_000_000;

    private ?RecordingCleanupSqsQueue $proxyQueue = null;
    private bool $timePaused = false;
    private ?object $originalRequest = null;
    private ?object $originalResponse = null;
    private ?string $originalRequestMethod = null;

    protected function setUp(): void
    {
        parent::setUp();
        Craft::$app->set('config', new SmsManagerConfigStub());
    }

    protected function tearDown(): void
    {
        try {
            $this->restoreSettingsRequest();
            if ($this->timePaused) {
                DateTimeHelper::resume();
                $this->timePaused = false;
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testCleanupPersistenceStartsAsEmptyConnectionLocalShadows(): void
    {
        self::assertInstanceOf(IsolatedPersistenceQueue::class, $this->isolatedPersistence());
        self::assertSame(0, (int)(new Query())->from('{{%queue}}')->count());
        self::assertSame(0, (int)(new Query())->from('{{%smsmanager_analytics}}')->count());
        self::assertSame(0, (int)(new Query())->from('{{%smsmanager_logs}}')->count());
        self::assertCount(4, $this->isolatedPersistence()->rawShadowTables());
    }

    public function testRunnerFallbackRestoresComponentsAndClearsEveryShadowRow(): void
    {
        $mutex = new RecordingCleanupMutex();
        Craft::$app->set('mutex', $mutex);
        $this->insertPayload('{"plugin":"other","class":"OtherJob"}');
        $this->seedAnalytics('2026-01-01 00:00:00');
        $this->seedLog('2026-01-01 00:00:00');

        self::finishActiveTestIsolation();

        self::assertInstanceOf(IsolatedPersistenceQueue::class, Craft::$app->getQueue());
        self::assertNotSame($mutex, Craft::$app->getMutex());
        self::assertSame(0, (int)(new Query())->from('{{%queue}}')->count());
        self::assertSame(0, (int)(new Query())->from('{{%smsmanager_analytics}}')->count());
        self::assertSame(0, (int)(new Query())->from('{{%smsmanager_logs}}')->count());
    }

    public function testApprovedBasePortableQueueRuntimeResolvesFromTheInstalledBasePackage(): void
    {
        $basePath = InstalledVersions::getInstallPath('lindemannrock/craft-plugin-base');
        self::assertIsString($basePath);

        foreach ([
            RecurringQueueHelper::class => '/src/helpers/RecurringQueueHelper.php',
            PortableQueueScheduler::class => '/src/queue/PortableQueueScheduler.php',
            DeferredQueueJob::class => '/src/queue/DeferredQueueJob.php',
        ] as $class => $path) {
            $reflection = new ReflectionClass($class);
            self::assertSame(realpath($basePath . $path), $reflection->getFileName());
        }
    }

    #[DataProvider('familyProvider')]
    public function testDailyTargetsDescriptionsAndQueueExecutionContractRemainCanonical(string $family): void
    {
        $settings = $this->enableFamily($family);
        $target = ScheduleHelper::calculateNext('daily');
        self::assertNotNull($target);

        $this->synchronizeFamily($family, $settings);

        $row = $this->onlyOwnerRow($family);
        $job = $this->unserializeJob($row);
        self::assertInstanceOf($this->jobClass($family), $job);
        self::assertTrue($job instanceof CleanupAnalyticsJob || $job instanceof CleanupLogsJob);
        self::assertStringContainsString(
            DateFormatHelper::formatCompactDatetimeFromSettings(
                $target,
                $settings,
                null,
                false,
                pluginHandle: 'sms-manager',
            ),
            $job->getDescription(),
        );
        self::assertLessThanOrEqual(1, abs($target->getTimestamp() - ((int)$row['timePushed'] + (int)$row['delay'])));
        self::assertSame(1024, (int)$row['priority']);
        self::assertSame(1800, (int)$row['ttr']);
        self::assertSame(1800, $job->getTtr());
        self::assertFalse($job->canRetry(1, new \RuntimeException('test')));
    }

    /** @return iterable<string, array{string}> */
    public static function familyProvider(): iterable
    {
        yield 'analytics' => ['analytics'];
        yield 'SMS logs' => ['logs'];
    }

    #[DataProvider('portableBoundaryProvider')]
    public function testDelayLimitedQueuesUseTheFinalConsumerAtNineHundredSecondsAndAHandoffAtNineHundredOne(
        string $family,
        int $delay,
        string $expectedClass,
    ): void {
        $queue = $this->installPortableQueue(true);
        $this->pauseAt(self::START_TIMESTAMP);

        PortableQueueScheduler::push(
            job: $this->recurringJob($family),
            delay: $delay,
            identityTokens: $this->identityTokens($family),
            mutexName: $this->portableMutex($family),
            priority: 1024,
            ttr: 1800,
            queue: $queue,
        );

        $row = $this->onlyOwnerRow($family);
        self::assertInstanceOf($expectedClass, $this->unserializeJob($row));
        self::assertSame(min($delay, 900), (int)$row['delay']);
        self::assertSame([min($delay, 900)], $this->proxyDelays());
    }

    /** @return iterable<string, array{string, int, class-string}> */
    public static function portableBoundaryProvider(): iterable
    {
        foreach (['analytics', 'logs'] as $family) {
            yield "$family at 900 seconds" => [$family, 900, self::staticJobClass($family)];
            yield "$family at 901 seconds" => [$family, 901, DeferredQueueJob::class];
        }
    }

    #[DataProvider('familyProvider')]
    public function testLongSchedulesUseBoundedHandoffsAndLateHandoffsUseZeroDelay(string $family): void
    {
        $queue = $this->installPortableQueue(true);
        $this->pauseAt(self::START_TIMESTAMP);
        $dataId = $this->seedCleanupRow($family, '-40 days');
        $target = self::START_TIMESTAMP + 2_200;
        $jobId = PortableQueueScheduler::pushAt(
            job: $this->recurringJob($family),
            targetTimestamp: $target,
            identityTokens: $this->identityTokens($family),
            mutexName: $this->portableMutex($family),
            priority: 1024,
            ttr: 1800,
            queue: $queue,
        );
        self::assertNotNull($jobId);

        $this->pauseAt(self::START_TIMESTAMP + 900);
        self::assertTrue($queue->executeJob($jobId));
        $second = $this->onlyOwnerRow($family);
        self::assertInstanceOf(DeferredQueueJob::class, $this->unserializeJob($second));
        self::assertSame([$dataId], $this->cleanupRowIds($family));

        $this->pauseAt($target + 10);
        self::assertTrue($queue->executeJob((string)$second['id']));
        $consumer = $this->onlyOwnerRow($family);
        self::assertInstanceOf($this->jobClass($family), $this->unserializeJob($consumer));
        self::assertSame(0, (int)$consumer['delay']);
        self::assertSame([$dataId], $this->cleanupRowIds($family));
        self::assertSame([900, 900, 0], $this->proxyDelays());
        self::assertLessThanOrEqual(900, max($this->proxyDelays()));
    }

    #[DataProvider('familyProvider')]
    public function testNativeQueuesRetainTheCompleteDelay(string $family): void
    {
        $queue = $this->installPortableQueue(false);
        $this->pauseAt(self::START_TIMESTAMP);

        PortableQueueScheduler::push(
            job: $this->recurringJob($family),
            delay: 86_400,
            identityTokens: $this->identityTokens($family),
            mutexName: $this->portableMutex($family),
            priority: 1024,
            ttr: 1800,
            queue: $queue,
        );

        $row = $this->onlyOwnerRow($family);
        self::assertInstanceOf($this->jobClass($family), $this->unserializeJob($row));
        self::assertSame(86_400, (int)$row['delay']);
        self::assertSame([], $this->proxyDelays());
    }

    #[DataProvider('familyProvider')]
    public function testUnknownNonSqsQueuesRetainTheCompleteDelay(string $family): void
    {
        $proxy = new RecordingUnknownCleanupQueue();
        $queue = $this->installQueueWithProxy($proxy);
        $this->pauseAt(self::START_TIMESTAMP);

        PortableQueueScheduler::push(
            job: $this->recurringJob($family),
            delay: 86_400,
            identityTokens: $this->identityTokens($family),
            mutexName: $this->portableMutex($family),
            priority: 1024,
            ttr: 1800,
            queue: $queue,
        );

        self::assertSame(86_400, (int)$this->onlyOwnerRow($family)['delay']);
        self::assertSame([86_400], array_column($proxy->pushes, 'delay'));
    }

    #[DataProvider('familyProvider')]
    public function testBootstrapCreatesOneChainAndDeterministicallyKeepsTheEarliestHealthyLegacyRow(string $family): void
    {
        $settings = $this->enableFamily($family);
        $legacy = $this->serializeJob($this->legacyJob($family));
        $earliest = $this->insertPayload($legacy, delay: 100);
        $this->insertPayload($legacy, delay: 200);
        $this->insertPayload($this->serializeJob($this->recurringJob($family)), delay: 300);

        $this->synchronizeFamily($family, $settings);

        self::assertSame([$earliest], $this->legacyRowIds($family));
        self::assertSame(0, $this->countOwnerRows($family));

        Craft::$app->getDb()->createCommand()->delete('{{%queue}}')->execute();
        $this->synchronizeFamily($family, $settings);
        $first = $this->onlyOwnerRow($family)['id'];
        $this->synchronizeFamily($family, $settings);
        self::assertSame(1, $this->countOwnerRows($family));
        self::assertSame((string)$first, (string)$this->onlyOwnerRow($family)['id']);
    }

    #[DataProvider('legacyShapeProvider')]
    public function testLegacyRecognitionSupportsExactPhpJsonAndNestedPayloads(string $family, string $shape): void
    {
        $settings = $this->enableFamily($family);
        $payload = match ($shape) {
            'php' => $this->serializeJob($this->legacyJob($family)),
            'json' => json_encode([
                'plugin' => 'smsmanager',
                'class' => $this->jobClass($family),
                'reschedule' => true,
            ], JSON_THROW_ON_ERROR),
            'nested' => $this->serializeJob(new DeferredQueueJob([
                'job' => $this->legacyJob($family),
                'targetTimestamp' => self::START_TIMESTAMP + 2_000,
                'identityTokens' => ['smsmanager', $this->shortJobClass($family)],
                'mutexName' => $this->portableMutex($family),
                'chainId' => "legacy-$family",
            ])),
            default => throw new \InvalidArgumentException("Unknown legacy shape: $shape"),
        };
        $legacyId = $this->insertPayload($payload);

        $this->synchronizeFamily($family, $settings);

        self::assertSame([$legacyId], $this->legacyRowIds($family));
        self::assertSame(0, $this->countOwnerRows($family));
    }

    /** @return iterable<string, array{string, string}> */
    public static function legacyShapeProvider(): iterable
    {
        foreach (['analytics', 'logs'] as $family) {
            foreach (['php', 'json', 'nested'] as $shape) {
                yield "$family $shape" => [$family, $shape];
            }
        }
    }

    #[DataProvider('familyProvider')]
    public function testLegacyRecognitionRequiresExactPluginJobAndRecurringState(string $family): void
    {
        $settings = $this->enableFamily($family);
        $otherFamily = $family === 'analytics' ? 'logs' : 'analytics';
        $preserved = [
            $this->insertPayload(json_encode(['plugin' => 'smsmanager-addon', 'class' => $this->shortJobClass($family), 'reschedule' => true], JSON_THROW_ON_ERROR)),
            $this->insertPayload(json_encode(['plugin' => 'smsmanager', 'class' => 'Not' . $this->shortJobClass($family), 'reschedule' => true], JSON_THROW_ON_ERROR)),
            $this->insertPayload($this->serializeJob($this->oneShotJob($family))),
            $this->insertPayload($this->serializeJob($this->recurringJob($otherFamily))),
        ];

        $this->synchronizeFamily($family, $settings);

        self::assertSame(1, $this->countOwnerRows($family));
        self::assertSame($preserved, $this->existingIds($preserved));
    }

    #[DataProvider('familyProvider')]
    public function testFailedLegacyRowsDoNotBlockRecoveryAndDisabledCancellationCoversEveryOwnedState(string $family): void
    {
        $settings = $this->enableFamily($family);
        $legacy = $this->serializeJob($this->legacyJob($family));
        $this->insertPayload($legacy, fail: true);
        $this->synchronizeFamily($family, $settings);
        self::assertSame([], $this->legacyRowIds($family));
        self::assertSame(1, $this->countOwnerRows($family));

        $ownerPayload = $this->serializeJob($this->recurringJob($family));
        foreach ([
            ['payload' => $ownerPayload, 'reserved' => true, 'fail' => false],
            ['payload' => $ownerPayload, 'reserved' => false, 'fail' => true],
            ['payload' => $legacy, 'reserved' => true, 'fail' => false],
            ['payload' => $legacy, 'reserved' => false, 'fail' => true],
        ] as $row) {
            $this->insertPayload($row['payload'], reserved: $row['reserved'], fail: $row['fail']);
        }
        $settings = $this->disableFamily($family);
        $this->synchronizeFamily($family, $settings);

        self::assertSame(0, $this->countOwnerRows($family));
        self::assertSame([], $this->legacyRowIds($family));
    }

    #[DataProvider('familyProvider')]
    public function testReservedLegacyConsumersFinishIntoOnePortableSuccessor(string $family): void
    {
        $this->enableFamily($family);
        $queue = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $queue);
        $jobId = $queue->push($this->legacyJob($family));
        self::assertNotNull($jobId);

        self::assertTrue($queue->executeJob($jobId));

        self::assertSame([], $this->legacyRowIds($family));
        self::assertSame(1, $this->countOwnerRows($family));
    }

    #[DataProvider('familyProvider')]
    public function testEveryFamilyMutationUsesLifecycleThenPortableLocks(string $family): void
    {
        $mutex = new RecordingCleanupMutex();
        Craft::$app->set('mutex', $mutex);

        $this->synchronizeFamily($family, $this->enableFamily($family));

        self::assertSame([$this->lifecycleMutex($family), $this->portableMutex($family)], $mutex->acquisitions);
        self::assertSame([$this->portableMutex($family), $this->lifecycleMutex($family)], $mutex->releases);
    }

    #[DataProvider('familyProvider')]
    public function testPortableLockFailureOccursBeforeInspectionOrCancellation(string $family): void
    {
        $settings = $this->enableFamily($family);
        $ids = [
            $this->insertPayload($this->serializeJob($this->legacyJob($family))),
            $this->insertPayload($this->serializeJob($this->recurringJob($family))),
        ];
        $mutex = new RecordingCleanupMutex([$this->portableMutex($family)]);
        Craft::$app->set('mutex', $mutex);

        try {
            $this->synchronizeFamily($family, $settings);
            self::fail('Expected the portable lock failure to propagate.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('portable lock', $exception->getMessage());
        }

        self::assertSame($ids, $this->existingIds($ids));
        self::assertSame([$this->lifecycleMutex($family), $this->portableMutex($family)], $mutex->acquisitions);
        self::assertSame([$this->lifecycleMutex($family)], $mutex->releases);
    }

    #[DataProvider('familyProvider')]
    public function testLifecycleAndPushFailuresRemainObservable(string $family): void
    {
        $settings = $this->enableFamily($family);
        Craft::$app->set('mutex', new RecordingCleanupMutex([$this->lifecycleMutex($family)]));
        $this->expectRuntimeFailure(fn() => $this->synchronizeFamily($family, $settings), 'lifecycle lock');
        self::assertSame(0, $this->countOwnerRows($family));

        $this->installPortableQueue(true);
        self::assertNotNull($this->proxyQueue);
        $this->proxyQueue->failPushes = true;
        Craft::$app->set('mutex', new RecordingCleanupMutex());
        $this->expectRuntimeFailure(fn() => $this->synchronizeFamily($family, $settings), 'SMS cleanup proxy failure');
        self::assertSame(1, $this->countOwnerRows($family));
    }

    #[DataProvider('familyProvider')]
    public function testAbsoluteTargetIsCalculatedBeforePortableLockWaiting(string $family): void
    {
        $this->installPortableQueue(true);
        $settings = $this->enableFamily($family);
        $target = ScheduleHelper::calculateNext('daily');
        self::assertNotNull($target);
        Craft::$app->set('mutex', new RecordingCleanupMutex(
            portableTimestamp: $target->getTimestamp() - 1_000,
            portableNames: [$this->portableMutex($family)],
        ));
        $this->timePaused = true;

        $this->synchronizeFamily($family, $settings);

        $handoff = $this->unserializeJob($this->onlyOwnerRow($family));
        self::assertInstanceOf(DeferredQueueJob::class, $handoff);
        self::assertSame($target->getTimestamp(), $handoff->targetTimestamp);
    }

    #[DataProvider('familyProvider')]
    public function testDeferredContinuationsUseOnlyTheirFamilyPortableMutex(string $family): void
    {
        $queue = $this->installPortableQueue(true);
        $this->pauseAt(self::START_TIMESTAMP);
        PortableQueueScheduler::push(
            job: $this->recurringJob($family),
            delay: 901,
            identityTokens: $this->identityTokens($family),
            mutexName: $this->portableMutex($family),
            priority: 1024,
            ttr: 1800,
            queue: $queue,
        );
        $row = $this->onlyOwnerRow($family);
        $handoff = $this->unserializeJob($row);
        self::assertInstanceOf(DeferredQueueJob::class, $handoff);
        self::assertSame($this->portableMutex($family), $handoff->mutexName);

        $mutex = new RecordingCleanupMutex();
        Craft::$app->set('mutex', $mutex);
        $this->markExecuting($queue, (string)$row['id']);
        try {
            $handoff->execute($queue);
        } finally {
            $this->markExecuting($queue, null);
        }

        self::assertSame([$this->portableMutex($family)], $mutex->acquisitions);
        self::assertSame([$this->portableMutex($family)], $mutex->releases);
    }

    public function testFamiliesReconcileSettingsTransitionsWithoutCrossFamilyQueueChurn(): void
    {
        $settings = $this->persistSettings([
            'enableAnalytics' => true,
            'analyticsRetention' => 30,
            'enableSmsLogs' => true,
            'smsLogsRetention' => 30,
        ]);
        $this->scheduler()->synchronize($settings);
        $analyticsId = $this->onlyOwnerRow('analytics')['id'];
        $logsId = $this->onlyOwnerRow('logs')['id'];

        $settings->analyticsRetention = 60;
        $settings->autoTrimAnalytics = !$settings->autoTrimAnalytics;
        $settings->analyticsLimit++;
        self::assertFalse($this->scheduler()->reconcileAnalytics(true, $settings));
        self::assertSame((string)$analyticsId, (string)$this->onlyOwnerRow('analytics')['id']);
        self::assertSame((string)$logsId, (string)$this->onlyOwnerRow('logs')['id']);

        $settings->enableAnalytics = false;
        self::assertTrue($this->scheduler()->reconcileAnalytics(true, $settings));
        self::assertSame(0, $this->countOwnerRows('analytics'));
        self::assertSame((string)$logsId, (string)$this->onlyOwnerRow('logs')['id']);

        $settings->enableAnalytics = true;
        self::assertTrue($this->scheduler()->reconcileAnalytics(false, $settings));
        self::assertSame(1, $this->countOwnerRows('analytics'));
        self::assertSame((string)$logsId, (string)$this->onlyOwnerRow('logs')['id']);

        $settings->smsLogsRetention = 0;
        self::assertTrue($this->scheduler()->reconcileLogs(true, $settings));
        self::assertSame(0, $this->countOwnerRows('logs'));
        self::assertSame(1, $this->countOwnerRows('analytics'));
    }

    public function testConfigOverridesGovernEachEffectiveSettingsTransition(): void
    {
        $settings = $this->persistSettings([
            'enableAnalytics' => true,
            'analyticsRetention' => 30,
            'enableSmsLogs' => false,
            'smsLogsRetention' => 30,
        ]);
        $this->scheduler()->synchronizeAnalytics($settings);
        $config = $this->createMock(Config::class);
        $config->method('getConfigFromFile')->willReturnCallback(
            static fn(string $handle): array => $handle === 'sms-manager'
                ? ['enableAnalytics' => false, 'enableSmsLogs' => true]
                : [],
        );
        Craft::$app->set('config', $config);
        $effective = $this->scheduler()->loadEffectiveSettings();

        $this->scheduler()->reconcileSettings(true, false, $effective);

        self::assertFalse($effective->enableAnalytics);
        self::assertTrue($effective->enableSmsLogs);
        self::assertSame(0, $this->countOwnerRows('analytics'));
        self::assertSame(1, $this->countOwnerRows('logs'));
    }

    public function testSettingsSavePersistsThenCancelsBothFamiliesBeforeTheSuccessNotice(): void
    {
        $settings = $this->persistSettings([
            'enableAnalytics' => true,
            'analyticsRetention' => 30,
            'enableSmsLogs' => true,
            'smsLogsRetention' => 30,
        ]);
        $this->scheduler()->synchronize($settings);
        $this->withSettingsPost([
            'enableAnalytics' => false,
            'analyticsRetention' => 0,
            'enableSmsLogs' => false,
            'smsLogsRetention' => 0,
        ]);

        try {
            (new SettingsController('settings', SmsManager::$plugin))->actionSave();
            self::fail('The console test application should not provide a session.');
        } catch (MissingComponentException $exception) {
            self::assertSame('Session does not exist in a console request.', $exception->getMessage());
        }

        $saved = Settings::loadFromDatabase();
        self::assertFalse($saved->enableAnalytics);
        self::assertSame(0, $saved->analyticsRetention);
        self::assertFalse($saved->enableSmsLogs);
        self::assertSame(0, $saved->smsLogsRetention);
        self::assertSame(0, $this->countOwnerRows('analytics'));
        self::assertSame(0, $this->countOwnerRows('logs'));
    }

    public function testSettingsReconciliationFailurePropagatesBeforeTheSuccessNotice(): void
    {
        $settings = $this->persistSettings([
            'enableAnalytics' => true,
            'analyticsRetention' => 30,
        ]);
        $this->scheduler()->synchronizeAnalytics($settings);
        Craft::$app->set('mutex', new RecordingCleanupMutex([
            RecurringCleanupScheduler::ANALYTICS_LIFECYCLE_MUTEX,
        ]));
        $this->withSettingsPost([
            'enableAnalytics' => false,
            'analyticsRetention' => 0,
        ]);

        $this->expectRuntimeFailure(
            fn() => (new SettingsController('settings', SmsManager::$plugin))->actionSave(),
            'lifecycle lock',
        );

        $saved = Settings::loadFromDatabase();
        self::assertFalse($saved->enableAnalytics);
        self::assertSame(0, $saved->analyticsRetention);
        self::assertSame(1, $this->countOwnerRows('analytics'));
    }

    public function testAnalyticsCleanupDeletesByDateThenTrimsByCountAndCreatesOneSuccessor(): void
    {
        $this->pauseAt(self::START_TIMESTAMP);
        $this->persistSettings([
            'enableAnalytics' => true,
            'analyticsRetention' => 30,
            'autoTrimAnalytics' => true,
            'analyticsLimit' => 1,
        ]);
        $old = $this->seedAnalytics('-40 days');
        $middle = $this->seedAnalytics('-2 days');
        $newest = $this->seedAnalytics('-1 day');

        $this->recurringJob('analytics')->execute(Craft::$app->getQueue());

        self::assertSame([$newest], $this->cleanupRowIds('analytics'));
        self::assertNotContains($old, $this->cleanupRowIds('analytics'));
        self::assertNotContains($middle, $this->cleanupRowIds('analytics'));
        self::assertSame(1, $this->countOwnerRows('analytics'));
        self::assertSame(0, $this->countOwnerRows('logs'));
    }

    public function testLogCleanupDeletesByDateThenTrimsByCountAndCreatesOneSuccessor(): void
    {
        $this->pauseAt(self::START_TIMESTAMP);
        $this->persistSettings([
            'enableSmsLogs' => true,
            'smsLogsRetention' => 30,
            'autoTrimSmsLogs' => true,
            'smsLogsLimit' => 1,
        ]);
        $old = $this->seedLog('-40 days');
        $middle = $this->seedLog('-2 days');
        $newest = $this->seedLog('-1 day');

        $this->recurringJob('logs')->execute(Craft::$app->getQueue());

        self::assertSame([$newest], $this->cleanupRowIds('logs'));
        self::assertNotContains($old, $this->cleanupRowIds('logs'));
        self::assertNotContains($middle, $this->cleanupRowIds('logs'));
        self::assertSame(1, $this->countOwnerRows('logs'));
        self::assertSame(0, $this->countOwnerRows('analytics'));
    }

    #[DataProvider('familyProvider')]
    public function testDisabledReservedOccurrencesNeverDeleteOrCreateSuccessors(string $family): void
    {
        $this->disableFamily($family);
        $rowId = $this->seedCleanupRow($family, '-40 days');

        $this->recurringJob($family)->execute(Craft::$app->getQueue());

        self::assertSame([$rowId], $this->cleanupRowIds($family));
        self::assertSame(0, $this->countOwnerRows($family));
    }

    #[DataProvider('familyProvider')]
    public function testAutoTrimCanBeDisabledWithoutSkippingDateRetention(string $family): void
    {
        $this->pauseAt(self::START_TIMESTAMP);
        $settings = $family === 'analytics'
            ? ['enableAnalytics' => true, 'analyticsRetention' => 30, 'autoTrimAnalytics' => false, 'analyticsLimit' => 1]
            : ['enableSmsLogs' => true, 'smsLogsRetention' => 30, 'autoTrimSmsLogs' => false, 'smsLogsLimit' => 1];
        $this->persistSettings($settings);
        $this->seedCleanupRow($family, '-40 days');
        $kept = [$this->seedCleanupRow($family, '-2 days'), $this->seedCleanupRow($family, '-1 day')];

        $this->recurringJob($family)->execute(Craft::$app->getQueue());

        self::assertSame($kept, $this->cleanupRowIds($family));
        self::assertSame(1, $this->countOwnerRows($family));
    }

    #[DataProvider('familyProvider')]
    public function testOneShotCleanupRunsWithoutJoiningARecurringFamily(string $family): void
    {
        $this->pauseAt(self::START_TIMESTAMP);
        $this->enableFamily($family);
        $old = $this->seedCleanupRow($family, '-40 days');

        $this->oneShotJob($family)->execute(Craft::$app->getQueue());

        self::assertNotContains($old, $this->cleanupRowIds($family));
        self::assertSame(0, $this->countOwnerRows($family));
    }

    #[DataProvider('familyProvider')]
    public function testCleanupFailuresPropagateWithoutCreatingSuccessors(string $family): void
    {
        $this->enableFamily($family);
        $scheduler = $this->scheduler();
        $run = $family === 'analytics'
            ? fn() => $scheduler->runAnalyticsOccurrence(static fn() => throw new \RuntimeException('cleanup failed'))
            : fn() => $scheduler->runLogsOccurrence(static fn() => throw new \RuntimeException('cleanup failed'));

        $this->expectRuntimeFailure($run, 'cleanup failed');

        self::assertSame(0, $this->countOwnerRows($family));
    }

    public function testAnalyticsAndLogCancellationNeverMutateTheOtherFamilyOrUnrelatedRows(): void
    {
        $settings = $this->persistSettings([
            'enableAnalytics' => true,
            'analyticsRetention' => 30,
            'enableSmsLogs' => true,
            'smsLogsRetention' => 30,
        ]);
        $this->scheduler()->synchronize($settings);
        $analyticsId = $this->onlyOwnerRow('analytics')['id'];
        $logsId = $this->onlyOwnerRow('logs')['id'];
        $unrelated = $this->insertPayload('{"plugin":"other-plugin","class":"CleanupAnalyticsJob","reschedule":true}');
        $analyticsData = $this->seedAnalytics('-1 day');
        $logData = $this->seedLog('-1 day');

        $this->synchronizeFamily('analytics', $this->disableFamily('analytics'));
        self::assertSame((string)$logsId, (string)$this->onlyOwnerRow('logs')['id']);
        self::assertSame([$unrelated], $this->existingIds([$unrelated]));
        self::assertSame([$analyticsData], $this->cleanupRowIds('analytics'));
        self::assertSame([$logData], $this->cleanupRowIds('logs'));

        $this->synchronizeFamily('logs', $this->disableFamily('logs'));
        self::assertSame(0, $this->countOwnerRows('logs'));
        self::assertSame(0, $this->countOwnerRows('analytics'));
        self::assertNotSame((string)$analyticsId, (string)$logsId);
        self::assertSame([$unrelated], $this->existingIds([$unrelated]));
    }

    public function testRuntimeHasNoCloudDependencyOrPrivateInfrastructureInspection(): void
    {
        $root = dirname(__DIR__, 2);
        $runtime = file_get_contents($root . '/src/services/RecurringCleanupScheduler.php')
            . file_get_contents($root . '/src/jobs/CleanupAnalyticsJob.php')
            . file_get_contents($root . '/src/jobs/CleanupLogsJob.php');
        $composer = file_get_contents($root . '/composer.json');
        self::assertIsString($runtime);
        self::assertIsString($composer);
        self::assertStringNotContainsString('craft\\cloud', $runtime);
        self::assertStringNotContainsString('craftcms/cloud', $composer);
        self::assertStringNotContainsString('AWS_', $runtime);
        self::assertStringNotContainsString('CLOUD_', $runtime);
    }

    private function scheduler(): RecurringCleanupScheduler
    {
        return SmsManager::$plugin->recurringCleanup;
    }

    private function installPortableQueue(bool $bounded): Queue
    {
        $this->proxyQueue = $bounded ? new RecordingCleanupSqsQueue() : null;

        return $this->installQueueWithProxy($this->proxyQueue);
    }

    private function installQueueWithProxy(?BaseQueue $proxyQueue): Queue
    {
        $current = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $current);
        $queue = new Queue([
            'db' => Craft::$app->getDb(),
            'mutex' => Craft::$app->getMutex(),
            'tableName' => $current->tableName,
            'channel' => $current->channel,
            'mutexTimeout' => $current->mutexTimeout,
            'proxyQueue' => $proxyQueue,
        ]);
        Craft::$app->set('queue', $queue);

        $installed = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $installed);

        return $installed;
    }

    private function pauseAt(int $timestamp): void
    {
        if ($this->timePaused) {
            DateTimeHelper::resume();
        }
        DateTimeHelper::pause(new \DateTime("@$timestamp"));
        $this->timePaused = true;
    }

    private function enableFamily(string $family): Settings
    {
        return $family === 'analytics'
            ? $this->persistSettings(['enableAnalytics' => true, 'analyticsRetention' => 30])
            : $this->persistSettings(['enableSmsLogs' => true, 'smsLogsRetention' => 30]);
    }

    private function disableFamily(string $family): Settings
    {
        return $family === 'analytics'
            ? $this->persistSettings(['enableAnalytics' => false])
            : $this->persistSettings(['enableSmsLogs' => false]);
    }

    /** @param array<string, mixed> $attributes */
    private function persistSettings(array $attributes): Settings
    {
        Craft::$app->getDb()->createCommand()
            ->update('{{%smsmanager_settings}}', $attributes, ['id' => 1])
            ->execute();
        $settings = $this->scheduler()->loadEffectiveSettings();
        SmsManager::$plugin->getSettings()->setAttributes($settings->getAttributes(), false);

        return $settings;
    }

    private function synchronizeFamily(string $family, Settings $settings): void
    {
        if ($family === 'analytics') {
            $this->scheduler()->synchronizeAnalytics($settings);
        } else {
            $this->scheduler()->synchronizeLogs($settings);
        }
    }

    private function recurringJob(string $family): CleanupAnalyticsJob|CleanupLogsJob
    {
        $config = [
            'reschedule' => true,
            'recurringOwner' => $this->owner($family),
            'nextRunTime' => 'test target',
        ];

        return $family === 'analytics' ? new CleanupAnalyticsJob($config) : new CleanupLogsJob($config);
    }

    private function legacyJob(string $family): CleanupAnalyticsJob|CleanupLogsJob
    {
        return $family === 'analytics'
            ? new CleanupAnalyticsJob(['reschedule' => true])
            : new CleanupLogsJob(['reschedule' => true]);
    }

    private function oneShotJob(string $family): CleanupAnalyticsJob|CleanupLogsJob
    {
        return $family === 'analytics'
            ? new CleanupAnalyticsJob(['reschedule' => false])
            : new CleanupLogsJob(['reschedule' => false]);
    }

    /** @return class-string<BaseJob> */
    private function jobClass(string $family): string
    {
        return self::staticJobClass($family);
    }

    /** @return class-string<BaseJob> */
    private static function staticJobClass(string $family): string
    {
        return $family === 'analytics' ? CleanupAnalyticsJob::class : CleanupLogsJob::class;
    }

    private function shortJobClass(string $family): string
    {
        return $family === 'analytics' ? 'CleanupAnalyticsJob' : 'CleanupLogsJob';
    }

    private function owner(string $family): string
    {
        return $family === 'analytics'
            ? RecurringCleanupScheduler::ANALYTICS_OWNER
            : RecurringCleanupScheduler::LOGS_OWNER;
    }

    private function lifecycleMutex(string $family): string
    {
        return $family === 'analytics'
            ? RecurringCleanupScheduler::ANALYTICS_LIFECYCLE_MUTEX
            : RecurringCleanupScheduler::LOGS_LIFECYCLE_MUTEX;
    }

    private function portableMutex(string $family): string
    {
        return $family === 'analytics'
            ? RecurringCleanupScheduler::ANALYTICS_PORTABLE_MUTEX
            : RecurringCleanupScheduler::LOGS_PORTABLE_MUTEX;
    }

    /** @return non-empty-list<string> */
    private function identityTokens(string $family): array
    {
        return [RecurringCleanupScheduler::PLUGIN_TOKEN, $this->jobClass($family), $this->owner($family)];
    }

    private function ownerQuery(string $family): Query
    {
        return (new Query())
            ->from('{{%queue}}')
            ->where(['like', 'job', RecurringCleanupScheduler::PLUGIN_TOKEN])
            ->andWhere(['like', 'job', $this->shortJobClass($family)])
            ->andWhere(['like', 'job', $this->owner($family)]);
    }

    /** @return array<string, mixed> */
    private function onlyOwnerRow(string $family): array
    {
        $rows = $this->ownerQuery($family)->orderBy(['id' => SORT_ASC])->all();
        self::assertCount(1, $rows);

        return $rows[0];
    }

    private function countOwnerRows(string $family): int
    {
        return (int)$this->ownerQuery($family)->count();
    }

    /** @return list<int> */
    private function legacyRowIds(string $family): array
    {
        $ids = [];
        $rows = (new Query())
            ->from('{{%queue}}')
            ->select(['id', 'job'])
            ->where(['like', 'job', 'smsmanager'])
            ->andWhere(['like', 'job', $this->shortJobClass($family)])
            ->andWhere(['not like', 'job', $this->owner($family)])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        foreach ($rows as $row) {
            $payload = (string)$row['job'];
            if (str_contains($payload, 's:10:"reschedule";b:1;') || preg_match('/"reschedule"\s*:\s*true/', $payload)) {
                $ids[] = (int)$row['id'];
            }
        }

        return $ids;
    }

    private function serializeJob(object $job): string
    {
        $queue = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $queue);

        return $queue->serializer->serialize($job);
    }

    /** @param array<string, mixed> $row */
    private function unserializeJob(array $row): object
    {
        $queue = Craft::$app->getQueue();
        self::assertInstanceOf(Queue::class, $queue);
        $job = $queue->serializer->unserialize((string)$row['job']);
        self::assertIsObject($job);

        return $job;
    }

    private function insertPayload(
        string $payload,
        int $delay = 300,
        bool $fail = false,
        bool $reserved = false,
    ): int {
        Craft::$app->getDb()->createCommand()->insert('{{%queue}}', [
            'channel' => 'queue',
            'job' => $payload,
            'description' => 'SMS Manager isolated queue test row',
            'timePushed' => DateTimeHelper::currentTimeStamp(),
            'ttr' => 1800,
            'delay' => $delay,
            'priority' => 1024,
            'timeUpdated' => $reserved ? DateTimeHelper::currentTimeStamp() : null,
            'fail' => $fail,
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    /** @param list<int> $ids @return list<int> */
    private function existingIds(array $ids): array
    {
        return array_map('intval', (new Query())
            ->from('{{%queue}}')
            ->select(['id'])
            ->where(['id' => $ids])
            ->orderBy(['id' => SORT_ASC])
            ->column());
    }

    private function seedCleanupRow(string $family, string $date): int
    {
        return $family === 'analytics' ? $this->seedAnalytics($date) : $this->seedLog($date);
    }

    private function seedAnalytics(string $date): int
    {
        $timestamp = $this->testDate($date);
        Craft::$app->getDb()->createCommand()->insert('{{%smsmanager_analytics}}', [
            'date' => $timestamp,
            'sourcePlugin' => self::MARKER . 'cleanup',
            'dateCreated' => $timestamp,
            'dateUpdated' => $timestamp,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function seedLog(string $date): int
    {
        $timestamp = $this->testDate($date);
        Craft::$app->getDb()->createCommand()->insert('{{%smsmanager_logs}}', [
            'recipient' => self::MARKER . 'cleanup',
            'status' => 'sent',
            'dateCreated' => $timestamp,
            'dateUpdated' => $timestamp,
            'uid' => StringHelper::UUID(),
        ])->execute();

        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function testDate(string $date): string
    {
        $now = DateFormatHelper::now();
        $target = str_starts_with($date, '-') ? (clone $now)->modify($date) : new \DateTime($date);

        return $target->format('Y-m-d H:i:s');
    }

    /** @return list<int> */
    private function cleanupRowIds(string $family): array
    {
        $table = $family === 'analytics' ? '{{%smsmanager_analytics}}' : '{{%smsmanager_logs}}';

        return array_map('intval', (new Query())->from($table)->select(['id'])->orderBy(['id' => SORT_ASC])->column());
    }

    /** @return list<int> */
    private function proxyDelays(): array
    {
        return $this->proxyQueue === null ? [] : array_column($this->proxyQueue->pushes, 'delay');
    }

    private function markExecuting(Queue $queue, ?string $jobId): void
    {
        if ($jobId !== null) {
            Craft::$app->getDb()->createCommand()
                ->update('{{%queue}}', ['timeUpdated' => DateTimeHelper::currentTimeStamp()], ['id' => $jobId])
                ->execute();
        }
        $property = new ReflectionProperty(Queue::class, '_executingJobId');
        $property->setValue($queue, $jobId);
    }

    /** @param array<string, mixed> $settings */
    private function withSettingsPost(array $settings): void
    {
        $this->originalRequest ??= Craft::$app->getRequest();
        $this->originalResponse ??= Craft::$app->getResponse();
        $this->originalRequestMethod ??= $_SERVER['REQUEST_METHOD'] ?? 'GET';
        Craft::$app->set('request', new Request([
            'enableCookieValidation' => false,
            'enableCsrfValidation' => false,
            'bodyParams' => [
                'section' => 'analytics',
                'settings' => $settings,
            ],
        ]));
        Craft::$app->set('response', new Response());
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    private function restoreSettingsRequest(): void
    {
        if ($this->originalRequest !== null) {
            Craft::$app->set('request', $this->originalRequest);
            $this->originalRequest = null;
        }
        if ($this->originalResponse !== null) {
            Craft::$app->set('response', $this->originalResponse);
            $this->originalResponse = null;
        }
        if ($this->originalRequestMethod !== null) {
            $_SERVER['REQUEST_METHOD'] = $this->originalRequestMethod;
            $this->originalRequestMethod = null;
        }
    }

    private function expectRuntimeFailure(callable $callback, string $message): void
    {
        try {
            $callback();
            self::fail('Expected a runtime failure.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }
}

/** Records delay-limited proxy pushes without contacting a provider. */
final class RecordingCleanupSqsQueue extends SqsQueue
{
    /** @var list<array{delay: int, priority: mixed, ttr: int}> */
    public array $pushes = [];

    public bool $failPushes = false;

    protected function pushMessage($message, $ttr, $delay, $priority): string
    {
        if ($this->failPushes) {
            throw new \RuntimeException('SMS cleanup proxy failure.');
        }
        $this->pushes[] = ['delay' => (int)$delay, 'priority' => $priority, 'ttr' => (int)$ttr];

        return 'sms-cleanup-proxy-' . count($this->pushes);
    }
}

/** Records pushes through an unknown non-SQS proxy. */
final class RecordingUnknownCleanupQueue extends BaseQueue
{
    /** @var list<array{delay: int, priority: mixed, ttr: int}> */
    public array $pushes = [];

    public function status($id): int
    {
        return self::STATUS_WAITING;
    }

    protected function pushMessage($message, $ttr, $delay, $priority): string
    {
        $this->pushes[] = ['delay' => (int)$delay, 'priority' => $priority, 'ttr' => (int)$ttr];

        return 'sms-cleanup-unknown-' . count($this->pushes);
    }
}

/** Mutex seam that records ordering and fails only explicitly named locks. */
final class RecordingCleanupMutex extends Mutex
{
    /** @var list<string> */
    public array $acquisitions = [];
    /** @var list<string> */
    public array $releases = [];

    /**
     * @param list<string> $failedNames
     * @param list<string> $portableNames
     */
    public function __construct(
        private readonly array $failedNames = [],
        private readonly ?int $portableTimestamp = null,
        private readonly array $portableNames = [],
        array $config = [],
    ) {
        parent::__construct($config);
    }

    protected function acquireLock($name, $timeout = 0): bool
    {
        $name = (string)$name;
        $this->acquisitions[] = $name;
        if ($this->portableTimestamp !== null && in_array($name, $this->portableNames, true)) {
            DateTimeHelper::resume();
            DateTimeHelper::pause(new \DateTime('@' . $this->portableTimestamp));
        }

        return !in_array($name, $this->failedNames, true);
    }

    protected function releaseLock($name): bool
    {
        $this->releases[] = (string)$name;

        return true;
    }
}
