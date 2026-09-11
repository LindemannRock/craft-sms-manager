<?php
/**
 * LindemannRock SMS Manager
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

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
    private string $handleToken = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerStubProvider();
        $this->handleToken = bin2hex(random_bytes(8));
    }

    public function testNewDuplicateProviderHandleAutoSuffixes(): void
    {
        $handle = $this->handle('provider');
        $this->seedProvider(['handle' => $handle]);

        $provider = $this->makeProvider('Provider', $handle);

        self::assertTrue($this->saveOwnedProvider($provider), implode(', ', $provider->getFirstErrors()));
        self::assertSame($handle . '-1', $provider->handle);
    }

    public function testExistingProviderDuplicateHandleRejects(): void
    {
        $firstHandle = $this->handle('provider-one');
        $this->seedProvider(['handle' => $firstHandle]);
        $provider = $this->seedProvider(['handle' => $this->handle('provider-two')]);

        $provider->handle = $firstHandle;

        self::assertFalse($this->saveOwnedProvider($provider));
        self::assertSame('Handle must be unique.', $provider->getFirstError('handle'));
    }

    public function testProviderHandleNormalizesToKebabSlug(): void
    {
        $provider = $this->makeProvider('Provider', 'SM Test ' . $this->handleToken . ' Mixed Case');

        self::assertTrue($this->saveOwnedProvider($provider), implode(', ', $provider->getFirstErrors()));
        self::assertSame($this->handle('mixed-case'), $provider->handle);
    }

    public function testNewDuplicateSenderIdHandleAutoSuffixes(): void
    {
        $provider = $this->seedProvider(['handle' => $this->handle('provider')]);
        $handle = $this->handle('sender');
        $this->seedSenderId($provider, ['handle' => $handle]);

        $senderId = $this->makeSenderId($provider, 'Sender', $handle);

        self::assertTrue($this->saveOwnedSenderId($senderId), implode(', ', $senderId->getFirstErrors()));
        self::assertSame($handle . '-1', $senderId->handle);
    }

    public function testExistingSenderIdDuplicateHandleRejects(): void
    {
        $provider = $this->seedProvider(['handle' => $this->handle('provider')]);
        $firstHandle = $this->handle('sender-one');
        $this->seedSenderId($provider, ['handle' => $firstHandle]);
        $senderId = $this->seedSenderId($provider, ['handle' => $this->handle('sender-two')]);

        $senderId->handle = $firstHandle;

        self::assertFalse($this->saveOwnedSenderId($senderId));
        self::assertSame('Handle must be unique.', $senderId->getFirstError('handle'));
    }

    public function testSenderIdHandleNormalizesToKebabSlug(): void
    {
        $provider = $this->seedProvider(['handle' => $this->handle('provider')]);
        $senderId = $this->makeSenderId(
            $provider,
            'Sender',
            'SM Test ' . $this->handleToken . ' Sender Mixed Case',
        );

        self::assertTrue($this->saveOwnedSenderId($senderId), implode(', ', $senderId->getFirstErrors()));
        self::assertSame($this->handle('sender-mixed-case'), $senderId->handle);
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

    private function handle(string $suffix): string
    {
        return 'sm-test-' . $this->handleToken . '-' . $suffix;
    }

    private function saveOwnedProvider(ProviderRecord $provider): bool
    {
        $saved = $this->providers->saveProvider($provider);
        if ($saved) {
            $this->trackProviderForCleanup($provider);
        }

        return $saved;
    }

    private function saveOwnedSenderId(SenderIdRecord $senderId): bool
    {
        $saved = $this->senderIds->saveSenderId($senderId);
        if ($saved) {
            $this->trackSenderIdForCleanup($senderId);
        }

        return $saved;
    }
}
