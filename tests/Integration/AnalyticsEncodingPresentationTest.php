<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use Craft;
use lindemannrock\smsmanager\controllers\AnalyticsController;
use lindemannrock\smsmanager\records\AnalyticsRecord;
use lindemannrock\smsmanager\SmsManager;
use lindemannrock\smsmanager\tests\TestCase;
use ReflectionMethod;

/**
 * Content-derived encoding charts and nullable historical analytics facts.
 *
 * @since 5.16.0
 */
final class AnalyticsEncodingPresentationTest extends TestCase
{
    public function testEncodingChartsSeparateLanguageAndPreserveUnknownHistory(): void
    {
        $source = $this->markerSourcePlugin();
        $this->seedEvent($source, 'en', 'gsm-7', 160, 1);
        $this->seedEvent($source, 'ar', 'ucs-2', 36, 2);
        $this->seedEvent($source, 'ja', null, null, null);
        $this->seedEvent(null, 'en', null, null, null);

        $controller = new AnalyticsController('analytics', SmsManager::$plugin);

        self::assertSame(
            ['labels' => ['GSM-7', 'UCS-2', Craft::t('sms-manager', 'Unknown')], 'values' => [1, 1, 1]],
            $this->invokeChart($controller, 'getEncodingChartData', ['all', 'all', 'all', 'all', $source]),
        );
        self::assertSame(
            [1, 0, 0],
            $this->invokeChart($controller, 'getEncodingChartData', ['all', 'all', 'en', 'all', $source])['values'],
        );
        self::assertSame(
            [0, 0, 1],
            $this->invokeChart($controller, 'getEncodingChartData', ['all', 'all', 'all', 'all', '__direct__'])['values'],
        );

        $daily = $this->invokeChart($controller, 'getEncodingDailyChartData', ['all', 'all', 'all', 'all', $source]);
        self::assertSame([1], $daily['gsm7']);
        self::assertSame([1], $daily['ucs2']);
        self::assertSame([1], $daily['unknown']);
    }

    public function testSiteFilterIncludesMatchingEventsAndGlobalHistoryRemainsVisible(): void
    {
        $source = $this->markerSourcePlugin();
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $this->seedEvent($source, 'en', 'gsm-7', 1, 1, $siteId);
        $this->seedEvent($source, 'ar', null, null, null);

        $controller = new AnalyticsController('analytics', SmsManager::$plugin);

        self::assertSame(
            [1, 0, 0],
            $this->invokeChart($controller, 'getEncodingChartData', ['all', $siteId, 'all', 'all', $source])['values'],
        );
        self::assertSame(
            [0, 0, 1],
            $this->invokeChart($controller, 'getEncodingChartData', ['all', 'all', 'all', 'all', $source])['values'],
        );
    }

    public function testSummaryDoesNotPresentPartialHistoricalFactSums(): void
    {
        $controller = new AnalyticsController('analytics', SmsManager::$plugin);
        $method = new ReflectionMethod($controller, 'normalizeSummaryStats');

        $summary = $method->invoke($controller, [
            'sent' => '2',
            'failed' => '1',
            'gsm7' => '1',
            'ucs2' => '1',
            'unknownEncoding' => '1',
            'characters' => '196',
            'segments' => '3',
            'eventRows' => '3',
            'knownCharacters' => '2',
            'knownSegments' => '2',
        ]);

        self::assertIsArray($summary);
        self::assertNull($summary['characters']);
        self::assertNull($summary['segments']);
        self::assertSame(1, $summary['unknownEncoding']);
        self::assertSame(3, $summary['total']);
        self::assertSame(66.7, $summary['successRate']);
    }

    public function testProviderAndSenderFiltersAreValidatedAndAppliedIndependently(): void
    {
        $providerA = $this->seedProvider();
        $senderA = $this->seedSenderId($providerA);
        $providerB = $this->seedProvider();
        $senderB = $this->seedSenderId($providerB);
        $source = $this->markerSourcePlugin();

        $this->seedEvent($source, 'en', 'gsm-7', 1, 1, null, (int)$providerA->id, (int)$senderA->id);
        $this->seedEvent($source, 'en', 'ucs-2', 1, 1, null, (int)$providerB->id, (int)$senderB->id);

        $controller = new AnalyticsController('analytics', SmsManager::$plugin);
        self::assertSame((string)$providerA->id, $this->invokePrivate($controller, 'resolveProviderFilter', [(string)$providerA->id]));
        self::assertSame('all', $this->invokePrivate($controller, 'resolveProviderFilter', ['999999999']));
        self::assertSame((string)$senderB->id, $this->invokePrivate($controller, 'resolveSenderIdFilter', [(string)$senderB->id]));
        self::assertSame('all', $this->invokePrivate($controller, 'resolveSenderIdFilter', ['999999999']));

        self::assertSame(
            [1, 0, 0],
            $this->invokeChart($controller, 'getEncodingChartData', [(string)$providerA->id, 'all', 'all', 'all', $source])['values'],
        );
        self::assertSame(
            [0, 1, 0],
            $this->invokeChart($controller, 'getEncodingChartData', ['all', 'all', 'all', (string)$senderB->id, $source])['values'],
        );
    }

    public function testExportRowsKeepHistoricalFactsUnknownAndNewFactsExact(): void
    {
        $source = $this->markerSourcePlugin();
        $this->seedEvent($source, 'ja', null, null, null);
        $this->seedEvent($source, 'en', 'gsm-7', 160, 1);

        $data = AnalyticsRecord::find()->where(['sourcePlugin' => $source])->orderBy(['id' => SORT_ASC])->asArray()->all();
        $controller = new AnalyticsController('analytics', SmsManager::$plugin);
        $rows = $this->invokePrivate($controller, 'formatAnalyticsExportRows', [$data]);

        self::assertIsArray($rows);
        self::assertCount(2, $rows);
        self::assertSame(Craft::t('sms-manager', 'Unknown'), $rows[0]['encoding']);
        self::assertSame(Craft::t('sms-manager', 'Unknown'), $rows[0]['totalCharacters']);
        self::assertSame(Craft::t('sms-manager', 'Unknown'), $rows[0]['totalMessages']);
        self::assertSame('ja', $rows[0]['language']);
        self::assertSame('gsm-7', $rows[1]['encoding']);
        self::assertSame(160, $rows[1]['totalCharacters']);
        self::assertSame(1, $rows[1]['totalMessages']);
    }

    /**
     * @param list<string|int> $filters provider, site, language, sender, source
     * @return array<string, mixed>
     */
    private function invokeChart(AnalyticsController $controller, string $methodName, array $filters): array
    {
        $result = $this->invokePrivate($controller, $methodName, [null, null, ...$filters]);

        self::assertIsArray($result);
        return $result;
    }

    /** @param list<mixed> $arguments */
    private function invokePrivate(AnalyticsController $controller, string $methodName, array $arguments): mixed
    {
        $method = new ReflectionMethod($controller, $methodName);
        return $method->invoke($controller, ...$arguments);
    }

    private function seedEvent(
        ?string $source,
        string $language,
        ?string $encoding,
        ?int $characters,
        ?int $segments,
        ?int $siteId = null,
        ?int $providerId = null,
        ?int $senderIdId = null,
    ): void {
        $record = new AnalyticsRecord();
        $record->providerId = $providerId;
        $record->senderIdId = $senderIdId;
        $record->siteId = $siteId;
        $record->language = $language;
        $record->encoding = $encoding;
        $record->date = new \DateTime('now', new \DateTimeZone('UTC'));
        $record->totalSent = 1;
        $record->totalDelivered = 0;
        $record->totalFailed = 0;
        $record->totalPending = 0;
        $record->totalCharacters = $characters;
        $record->totalMessages = $segments;
        $record->sourcePlugin = $source;

        self::assertTrue($record->save(false));
    }
}
