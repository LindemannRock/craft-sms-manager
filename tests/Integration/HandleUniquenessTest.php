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

/**
 * Pins provider and sender ID handle normalization and duplicate handling.
 *
 * @since 5.13.0
 */
final class HandleUniquenessTest extends TestCase
{
    private const HANDLE_PREFIX = 'sm-test-';

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerStubProvider();
        $this->deleteHandleRows();
    }

    protected function tearDown(): void
    {
        $this->deleteHandleRows();
        parent::tearDown();
    }

    public function testNewDuplicateProviderHandleAutoSuffixes(): void
    {
        $this->seedProvider(['handle' => self::HANDLE_PREFIX . 'provider']);

        $provider = $this->makeProvider('Provider', self::HANDLE_PREFIX . 'provider');

        self::assertTrue($this->providers->saveProvider($provider), implode(', ', $provider->getFirstErrors()));
        self::assertSame(self::HANDLE_PREFIX . 'provider-1', $provider->handle);
    }

    public function testExistingProviderDuplicateHandleRejects(): void
    {
        $this->seedProvider(['handle' => self::HANDLE_PREFIX . 'provider-one']);
        $provider = $this->seedProvider(['handle' => self::HANDLE_PREFIX . 'provider-two']);

        $provider->handle = self::HANDLE_PREFIX . 'provider-one';

        self::assertFalse($this->providers->saveProvider($provider));
        self::assertSame('Handle must be unique.', $provider->getFirstError('handle'));
    }

    public function testProviderHandleNormalizesToKebabSlug(): void
    {
        $provider = $this->makeProvider('Provider', 'SM Test Mixed Case');

        self::assertTrue($this->providers->saveProvider($provider), implode(', ', $provider->getFirstErrors()));
        self::assertSame('sm-test-mixed-case', $provider->handle);
    }

    public function testNewDuplicateSenderIdHandleAutoSuffixes(): void
    {
        $provider = $this->seedProvider(['handle' => self::HANDLE_PREFIX . 'provider']);
        $this->seedSenderId($provider, ['handle' => self::HANDLE_PREFIX . 'sender']);

        $senderId = $this->makeSenderId($provider, 'Sender', self::HANDLE_PREFIX . 'sender');

        self::assertTrue($this->senderIds->saveSenderId($senderId), implode(', ', $senderId->getFirstErrors()));
        self::assertSame(self::HANDLE_PREFIX . 'sender-1', $senderId->handle);
    }

    public function testExistingSenderIdDuplicateHandleRejects(): void
    {
        $provider = $this->seedProvider(['handle' => self::HANDLE_PREFIX . 'provider']);
        $this->seedSenderId($provider, ['handle' => self::HANDLE_PREFIX . 'sender-one']);
        $senderId = $this->seedSenderId($provider, ['handle' => self::HANDLE_PREFIX . 'sender-two']);

        $senderId->handle = self::HANDLE_PREFIX . 'sender-one';

        self::assertFalse($this->senderIds->saveSenderId($senderId));
        self::assertSame('Handle must be unique.', $senderId->getFirstError('handle'));
    }

    public function testSenderIdHandleNormalizesToKebabSlug(): void
    {
        $provider = $this->seedProvider(['handle' => self::HANDLE_PREFIX . 'provider']);
        $senderId = $this->makeSenderId($provider, 'Sender', 'SM Test Sender Mixed Case');

        self::assertTrue($this->senderIds->saveSenderId($senderId), implode(', ', $senderId->getFirstErrors()));
        self::assertSame('sm-test-sender-mixed-case', $senderId->handle);
    }

    private function makeProvider(string $name, string $handle = ''): ProviderRecord
    {
        $provider = new ProviderRecord();
        $provider->name = $name;
        $provider->handle = $handle;
        $provider->type = self::STUB_TYPE;
        $provider->enabled = true;
        $provider->settings = (string)json_encode(['allowedCountries' => ['*']]);
        $provider->source = 'database';

        return $provider;
    }

    private function makeSenderId(ProviderRecord $provider, string $name, string $handle = ''): SenderIdRecord
    {
        $senderId = new SenderIdRecord();
        $senderId->providerId = $provider->id;
        $senderId->providerHandle = $provider->handle;
        $senderId->name = $name;
        $senderId->handle = $handle;
        $senderId->senderId = 'TestBrand';
        $senderId->enabled = true;
        $senderId->isDev = false;
        $senderId->source = 'database';

        return $senderId;
    }

    private function deleteHandleRows(): void
    {
        Craft::$app->getDb()->createCommand()
            ->delete(SenderIdRecord::tableName(), ['like', 'handle', self::HANDLE_PREFIX])
            ->execute();

        Craft::$app->getDb()->createCommand()
            ->delete(ProviderRecord::tableName(), ['like', 'handle', self::HANDLE_PREFIX])
            ->execute();
    }
}
