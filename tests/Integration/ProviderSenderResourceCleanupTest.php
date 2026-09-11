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
use lindemannrock\smsmanager\records\ProviderRecord;
use lindemannrock\smsmanager\records\SenderIdRecord;
use lindemannrock\smsmanager\tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Throwable;

/**
 * Pins exact ownership cleanup for provider and sender test resources.
 *
 * @since 5.16.0
 */
final class ProviderSenderResourceCleanupTest extends TestCase
{
    private string $handleToken = '';

    /** @var array<int, true> */
    private array $lookalikeProviderIds = [];

    /** @var array<int, true> */
    private array $lookalikeSenderIdIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->handleToken = bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupLookalikeRows();
        } finally {
            parent::tearDown();
        }
    }

    public function testOwnedRowsAreRemovedWithoutDeletingLookalikes(): void
    {
        [$lookalikeProviderId, $lookalikeSenderIdId] = $this->seedLookalikeRows();
        $provider = $this->seedProvider(['handle' => $this->handle('cleanup-provider')]);
        $senderId = $this->seedSenderId($provider, ['handle' => $this->handle('cleanup-sender')]);

        $this->cleanupOwnedProviderAndSenderRows();

        self::assertNull(ProviderRecord::findOne($provider->id));
        self::assertNull(SenderIdRecord::findOne($senderId->id));
        self::assertNotNull(ProviderRecord::findOne($lookalikeProviderId));
        self::assertNotNull(SenderIdRecord::findOne($lookalikeSenderIdId));
    }

    public function testFinishedTestCleanupRemovesOwnedRowsAfterAssertionFailure(): void
    {
        [$lookalikeProviderId, $lookalikeSenderIdId] = $this->seedLookalikeRows();
        $provider = $this->seedProvider(['handle' => $this->handle('failure-provider')]);
        $senderId = $this->seedSenderId($provider, ['handle' => $this->handle('failure-sender')]);
        $failure = null;

        try {
            self::fail('Forced assertion failure for cleanup verification.');
        } catch (AssertionFailedError $exception) {
            $failure = $exception;
        } finally {
            self::finishActiveTestIsolation();
        }

        self::assertInstanceOf(AssertionFailedError::class, $failure);
        self::assertNull(ProviderRecord::findOne($provider->id));
        self::assertNull(SenderIdRecord::findOne($senderId->id));
        self::assertNotNull(ProviderRecord::findOne($lookalikeProviderId));
        self::assertNotNull(SenderIdRecord::findOne($lookalikeSenderIdId));
    }

    public function testFinishedTestCleanupRemovesProviderAfterPartialSetup(): void
    {
        [$lookalikeProviderId, $lookalikeSenderIdId] = $this->seedLookalikeRows();
        $provider = $this->seedProvider(['handle' => $this->handle('partial-provider')]);
        $failure = null;

        try {
            throw new RuntimeException('Forced sender setup failure.');
        } catch (RuntimeException $exception) {
            $failure = $exception;
        } finally {
            self::finishActiveTestIsolation();
        }

        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertNull(ProviderRecord::findOne($provider->id));
        self::assertNotNull(ProviderRecord::findOne($lookalikeProviderId));
        self::assertNotNull(SenderIdRecord::findOne($lookalikeSenderIdId));
    }

    private function handle(string $suffix): string
    {
        return 'owner-sm-test-' . $this->handleToken . '-' . $suffix;
    }

    /** @return array{int, int} */
    private function seedLookalikeRows(): array
    {
        $provider = new ProviderRecord();
        $provider->name = 'Unrelated Lookalike Provider';
        $provider->handle = $this->handle('lookalike-provider');
        $provider->type = self::STUB_TYPE;
        $provider->enabled = true;
        $provider->settings = (string)json_encode(['allowedCountries' => ['*']]);
        $provider->source = 'database';

        $providerSaved = $provider->save(false);
        if ($providerSaved && $provider->id !== null) {
            $this->lookalikeProviderIds[(int)$provider->id] = true;
        }
        self::assertTrue($providerSaved);
        self::assertNotNull($provider->id);

        $senderId = new SenderIdRecord();
        $senderId->providerId = $provider->id;
        $senderId->providerHandle = $provider->handle;
        $senderId->name = 'Unrelated Lookalike Sender';
        $senderId->handle = $this->handle('lookalike-sender');
        $senderId->senderId = 'TestBrand';
        $senderId->enabled = true;
        $senderId->isDev = false;
        $senderId->source = 'database';

        $senderSaved = $senderId->save(false);
        if ($senderSaved && $senderId->id !== null) {
            $this->lookalikeSenderIdIds[(int)$senderId->id] = true;
        }
        self::assertTrue($senderSaved);
        self::assertNotNull($senderId->id);

        return [(int)$provider->id, (int)$senderId->id];
    }

    private function cleanupLookalikeRows(): void
    {
        $errors = [];

        if ($this->lookalikeSenderIdIds !== []) {
            try {
                Craft::$app->getDb()->createCommand()
                    ->delete(SenderIdRecord::tableName(), ['id' => array_keys($this->lookalikeSenderIdIds)])
                    ->execute();
                $this->lookalikeSenderIdIds = [];
            } catch (Throwable $exception) {
                $errors[] = $exception;
            }
        }

        if ($this->lookalikeProviderIds !== []) {
            try {
                Craft::$app->getDb()->createCommand()
                    ->delete(ProviderRecord::tableName(), ['id' => array_keys($this->lookalikeProviderIds)])
                    ->execute();
                $this->lookalikeProviderIds = [];
            } catch (Throwable $exception) {
                $errors[] = $exception;
            }
        }

        if ($errors !== []) {
            throw new RuntimeException('Lookalike fixture cleanup failed.', 0, $errors[0]);
        }
    }
}
