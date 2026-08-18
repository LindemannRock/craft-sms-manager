<?php
/**
 * LindemannRock SMS Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests;

use Craft;
use craft\db\Query;
use craft\queue\Queue;
use lindemannrock\base\testing\IntegrationTestCase;
use lindemannrock\smsmanager\models\Settings;
use lindemannrock\smsmanager\records\AnalyticsRecord;
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\records\SenderIdRecord;
use lindemannrock\smsmanager\records\SmsLogRecord;
use lindemannrock\smsmanager\services\ProvidersService;
use lindemannrock\smsmanager\services\SenderIdsService;
use lindemannrock\smsmanager\services\SmsService;
use lindemannrock\smsmanager\SmsManager;
use lindemannrock\smsmanager\tests\Stubs\StubProvider;
use lindemannrock\smsmanager\tests\Support\IsolatedPersistenceQueue;
use Throwable;

/**
 * Base test case for sms-manager integration tests.
 *
 * Layers plugin-specific shorthand on top of {@see IntegrationTestCase}:
 *  - direct accessors for `sms` / `providers` / `senderIds` services
 *  - marker-prefixed seeders for provider + sender ID rows (the live DB may
 *    already host real CP-created records, so every test-owned row goes in
 *    under the `__sm_test_` namespace and gets purged on setUp/tearDown)
 *  - the `__sm_test_` marker rides along through `recipient` (logs) and
 *    `sourcePlugin` (analytics) so the same prefix drains every table the
 *    SMS pipeline writes to
 *  - {@see registerStubProvider()} convenience for registering the in-suite
 *    {@see StubProvider} as a provider type — the service constructs new
 *    provider instances via `createProviderByType()`, so the registry is
 *    where the stub gets wired in
 *
 * @since 5.12.0
 */
abstract class TestCase extends IntegrationTestCase
{
    private static ?self $activeTest = null;

    /**
     * Marker prefix used for every test-seeded row.
     *
     * Applied to `handle` columns on the providers + sender_ids tables, to
     * the `recipient` column on the logs table, and to the `sourcePlugin`
     * column on the analytics table. Plain ASCII so `purgeRowsByMarker`'s
     * LIKE wildcard isn't tripped by any unintended regex characters.
     */
    protected const MARKER = '__sm_test_';

    /**
     * Stub provider type handle. Distinct enough from any real provider
     * (`mpp-sms`, `twilio`, ...) that registering it can't shadow CP data.
     */
    protected const STUB_TYPE = '__sm_test_stub';

    protected SmsService $sms;

    protected ProvidersService $providers;

    protected SenderIdsService $senderIds;

    private int $seedCounter = 0;

    /** @var array<string, mixed>|null */
    private ?array $settingsSnapshot = null;

    /** @var array<string, mixed>|null */
    private ?array $settingsRowSnapshot = null;

    /** @var array<string, object> */
    private array $appComponentSnapshots = [];

    private ?object $originalQueue = null;

    private bool $isolationFinished = false;

    private bool $baseStateInitialised = false;

    protected function setUp(): void
    {
        self::$activeTest = $this;
        $this->isolationFinished = false;

        try {
            parent::setUp();
            $this->baseStateInitialised = true;
            $this->snapshotAppComponents();
            $plugin = SmsManager::$plugin;
            $this->settingsSnapshot = $plugin->getSettings()->getAttributes();
            $settingsRow = (new Query())->from('{{%smsmanager_settings}}')->where(['id' => 1])->one();
            $this->settingsRowSnapshot = is_array($settingsRow) ? $settingsRow : null;
            $this->isolatePersistence();
            $this->sms = $plugin->sms;
            $this->providers = $plugin->providers;
            $this->senderIds = $plugin->senderIds;
            $this->seedCounter = 0;
            $this->ensureAnalyticsTestSchema();
            $this->purgeTestRows();
        } catch (Throwable $exception) {
            try {
                $this->finishIsolation();
            } catch (Throwable $cleanupException) {
                fwrite(STDERR, 'SMS Manager setup cleanup failed: ' . $cleanupException->getMessage() . PHP_EOL);
            }
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        $this->finishIsolation();
    }

    /**
     * Runner fallback when child teardown exits before parent cleanup.
     *
     * @since 5.16.0
     */
    public static function finishActiveTestIsolation(): void
    {
        self::$activeTest?->finishIsolation();
    }

    /**
     * Register the in-suite {@see StubProvider} so seeded provider records
     * with `type` = {@see self::STUB_TYPE} resolve to it, and reset every
     * static call counter / failure flag the stub maintains.
     *
     * `ProvidersService::createProviderByType()` builds a fresh `new $class()`
     * per call, so the stub holds its bookkeeping on static properties —
     * every fresh instance the service hands out shares the same log.
     */
    protected function registerStubProvider(): void
    {
        $this->providers->registerProviderType(StubProvider::class);
        StubProvider::reset();
    }

    /**
     * Seed a saved {@see ProviderRecord} backed by the stub provider type.
     *
     * @param array<string, mixed> $overrides
     */
    protected function seedProvider(array $overrides = []): ProviderRecord
    {
        $this->seedCounter++;
        $handle = self::MARKER . 'provider_' . $this->seedCounter;

        $record = new ProviderRecord();
        $record->name = $overrides['name'] ?? $handle;
        $record->handle = $overrides['handle'] ?? $handle;
        $record->type = $overrides['type'] ?? self::STUB_TYPE;
        $record->enabled = $overrides['enabled'] ?? true;
        $record->settings = $overrides['settings'] ?? json_encode([
            'allowedCountries' => ['*'],
        ]);
        $record->source = 'database';

        $this->assertTrue(
            $record->save(false),
            'Seeded provider must save — errors: ' . json_encode($record->getErrors()),
        );

        return $record;
    }

    /**
     * Seed a saved {@see SenderIdRecord} pointing at a previously-seeded
     * provider record.
     *
     * @param array<string, mixed> $overrides
     */
    protected function seedSenderId(ProviderRecord $provider, array $overrides = []): SenderIdRecord
    {
        $this->seedCounter++;
        $handle = self::MARKER . 'sender_' . $this->seedCounter;

        $record = new SenderIdRecord();
        $record->providerId = $provider->id;
        $record->providerHandle = $provider->handle;
        $record->name = $overrides['name'] ?? $handle;
        $record->handle = $overrides['handle'] ?? $handle;
        $record->senderId = $overrides['senderId'] ?? 'TestBrand';
        $record->enabled = $overrides['enabled'] ?? true;
        $record->isDev = $overrides['isDev'] ?? false;
        $record->source = 'database';

        $this->assertTrue(
            $record->save(false),
            'Seeded sender ID must save — errors: ' . json_encode($record->getErrors()),
        );

        return $record;
    }

    /**
     * Fetch the single log row a happy-path or failure-path `send()` writes
     * to `{{%smsmanager_logs}}` against a marker-tagged recipient.
     *
     * @return array<string, mixed>|null
     */
    protected function fetchLogRowByRecipient(string $recipient): ?array
    {
        return $this->fetchRow(SmsLogRecord::tableName(), ['recipient' => $recipient]);
    }

    /**
     * Plugin settings shorthand.
     */
    protected function settings(): Settings
    {
        /** @var Settings $settings */
        $settings = SmsManager::$plugin->getSettings();
        return $settings;
    }

    /** Return the bootstrap-owned connection-local persistence shadow. */
    protected function isolatedPersistence(): IsolatedPersistenceQueue
    {
        if (!$this->originalQueue instanceof IsolatedPersistenceQueue) {
            throw new \RuntimeException('SMS Manager test persistence is not isolated.');
        }

        return $this->originalQueue;
    }

    /**
     * Drain every marker-tagged row across the four tables `SmsService::send`
     * can write to. Done on both setUp and tearDown so a previous failed run
     * can't poison the next one. The logs table has no `handle` column —
     * cleanup pivots on `recipient`, which every test sets to a marker-tagged
     * phone-number-looking string. The analytics table has no `handle`
     * either; we tag `sourcePlugin` instead so analytics rows still hold the
     * marker after `SmsService` propagates it.
     */
    protected function purgeTestRows(): void
    {
        $this->purgeRowsByMarker(SmsLogRecord::tableName(), 'recipient', self::MARKER);
        $this->purgeRowsByMarker(AnalyticsRecord::tableName(), 'sourcePlugin', self::MARKER);
        $this->purgeRowsByMarker(SenderIdRecord::tableName(), 'handle', self::MARKER);
        $this->purgeRowsByMarker(ProviderRecord::tableName(), 'handle', self::MARKER);
    }

    /**
     * Build a marker-tagged recipient string. Used as the LIKE prefix on the
     * logs table at cleanup time.
     */
    protected function markerRecipient(string $suffix = ''): string
    {
        $this->seedCounter++;
        return self::MARKER . 'to_' . $this->seedCounter . $suffix;
    }

    /**
     * Build a marker-tagged sourcePlugin string. Used as the LIKE prefix on
     * the analytics table at cleanup time.
     */
    protected function markerSourcePlugin(string $suffix = ''): string
    {
        $this->seedCounter++;
        return self::MARKER . 'src_' . $this->seedCounter . $suffix;
    }

    /**
     * Keep local pre-release test databases aligned with the current install schema.
     */
    private function ensureAnalyticsTestSchema(): void
    {
        $db = Craft::$app->getDb();

        $this->ensureColumn(SmsLogRecord::tableName(), 'siteId', 'integer NULL');
        $this->ensureColumn(AnalyticsRecord::tableName(), 'siteId', 'integer NULL');
        $this->ensureColumn(AnalyticsRecord::tableName(), 'language', 'varchar(10) NULL');

        $db->getSchema()->refreshTableSchema(SmsLogRecord::tableName());
        $db->getSchema()->refreshTableSchema(AnalyticsRecord::tableName());
    }

    private function ensureColumn(string $tableName, string $columnName, string $definition): void
    {
        $db = Craft::$app->getDb();
        $table = $db->getSchema()->getTableSchema($tableName, true);
        if ($table !== null && $table->getColumn($columnName) !== null) {
            return;
        }

        $db->createCommand()->addColumn($tableName, $columnName, $definition)->execute();
    }

    private function snapshotAppComponents(): void
    {
        foreach (['config', 'mutex'] as $id) {
            if (Craft::$app->has($id)) {
                $component = Craft::$app->get($id);
                if (is_object($component)) {
                    $this->appComponentSnapshots[$id] = $component;
                }
            }
        }
    }

    private function isolatePersistence(): void
    {
        $queue = Craft::$app->getQueue();
        if (!$queue instanceof IsolatedPersistenceQueue) {
            throw new \RuntimeException('SMS Manager tests require bootstrap-isolated persistence.');
        }

        $this->originalQueue = $queue;
        $queue->clearTransientShadowRows();

        Craft::$app->set('queue', new Queue([
            'db' => $queue->db,
            'mutex' => $queue->mutex,
            'tableName' => $queue->tableName,
            'channel' => $queue->channel,
            'mutexTimeout' => $queue->mutexTimeout,
        ]));
    }

    private function finishIsolation(): void
    {
        if ($this->isolationFinished) {
            return;
        }
        $this->isolationFinished = true;
        $errors = [];

        $this->runCleanupStep($errors, fn() => $this->purgeTestRows());
        $this->runCleanupStep($errors, function(): void {
            foreach ($this->appComponentSnapshots as $id => $component) {
                Craft::$app->set($id, $component);
            }
            $this->appComponentSnapshots = [];
        });
        $this->runCleanupStep($errors, function(): void {
            if ($this->settingsSnapshot !== null) {
                SmsManager::$plugin->getSettings()->setAttributes($this->settingsSnapshot, false);
                $this->settingsSnapshot = null;
            }
        });
        $this->runCleanupStep($errors, function(): void {
            Craft::$app->getDb()->createCommand()->delete('{{%smsmanager_settings}}')->execute();
            if ($this->settingsRowSnapshot !== null) {
                Craft::$app->getDb()->createCommand()
                    ->insert('{{%smsmanager_settings}}', $this->settingsRowSnapshot)
                    ->execute();
                $this->settingsRowSnapshot = null;
            }
        });
        $this->runCleanupStep($errors, function(): void {
            if ($this->originalQueue !== null) {
                Craft::$app->set('queue', $this->originalQueue);
                if ($this->originalQueue instanceof IsolatedPersistenceQueue) {
                    $this->originalQueue->clearTransientShadowRows();
                }
                $this->originalQueue = null;
            }
        });

        if ($this->baseStateInitialised) {
            $this->runCleanupStep($errors, fn() => parent::tearDown());
            $this->baseStateInitialised = false;
        }
        self::$activeTest = null;

        if ($errors !== []) {
            $messages = array_map(
                static fn(Throwable $error): string => $error::class . ': ' . $error->getMessage(),
                $errors,
            );
            throw new \RuntimeException(
                'SMS Manager test isolation cleanup failed: ' . implode(' | ', $messages),
                0,
                $errors[0],
            );
        }
    }

    /** @param list<Throwable> $errors */
    private function runCleanupStep(array &$errors, callable $cleanup): void
    {
        try {
            $cleanup();
        } catch (Throwable $exception) {
            $errors[] = $exception;
        }
    }
}
