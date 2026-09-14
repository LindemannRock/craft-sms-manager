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
use lindemannrock\smsmanager\controllers\AnalyticsController;
use lindemannrock\smsmanager\controllers\SmsLogsController;
use lindemannrock\smsmanager\records\AnalyticsRecord;
use lindemannrock\smsmanager\SmsManager;
use lindemannrock\smsmanager\tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * Locale-aware runtime labels, fallbacks, dates, and export headings.
 *
 * @since 5.16.0
 */
final class RuntimeLocalizationTest extends TestCase
{
    private string $originalLanguage;

    private string $originalFormatterLocale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalLanguage = Craft::$app->language;
        $this->originalFormatterLocale = (string)Craft::$app->getFormatter()->locale;
    }

    protected function tearDown(): void
    {
        Craft::$app->language = $this->originalLanguage;
        Craft::$app->getFormatter()->locale = $this->originalFormatterLocale;
        DateFormatHelper::clearConfigCache();
        parent::tearDown();
    }

    /** @param list<string> $expectedFragments */
    #[DataProvider('localizedDateProvider')]
    public function testChartDatesHonorLocaleAndPluginDateCascade(
        string $locale,
        string $monthFormat,
        string $dateOrder,
        string $separator,
        array $expectedFragments,
    ): void {
        $this->applyLocale($locale);
        $settings = $this->settings();
        $settings->monthFormat = $monthFormat;
        $settings->dateOrder = $dateOrder;
        $settings->dateSeparator = $separator;
        DateFormatHelper::clearConfigCache();

        $label = $this->invokePrivate(
            new AnalyticsController('analytics', SmsManager::$plugin),
            'formatChartDate',
            [new \DateTime('2026-01-02 12:00:00', new \DateTimeZone(Craft::$app->getTimeZone()))],
        );

        self::assertIsString($label);
        foreach ($expectedFragments as $fragment) {
            self::assertStringContainsString($fragment, $label);
        }
        self::assertStringNotContainsString('January', $label);
    }

    /** @return iterable<string, array{string, string, string, string, list<string>}> */
    public static function localizedDateProvider(): iterable
    {
        yield 'German long dmy' => ['de-DE', 'long', 'dmy', '/', ['2', 'Januar']];
        yield 'Japanese long ymd' => ['ja-JP', 'long', 'ymd', '/', ['1月', '2']];
        yield 'Arabic long dmy' => ['ar', 'long', 'dmy', '/', ['يناير']];
        yield 'configured numeric separator' => ['de-DE', 'numeric', 'dmy', '.', ['02.01']];
    }

    public function testDailyChartFeedsSeparateMachineDatesFromLocalizedLabels(): void
    {
        $this->applyLocale('de-DE');
        $settings = $this->settings();
        $settings->monthFormat = 'long';
        $settings->dateOrder = 'dmy';
        DateFormatHelper::clearConfigCache();
        $source = $this->markerSourcePlugin('-localized-chart');
        $record = new AnalyticsRecord([
            'date' => new \DateTime('now', new \DateTimeZone('UTC')),
            'language' => 'de',
            'encoding' => 'gsm-7',
            'totalSent' => 1,
            'totalDelivered' => 0,
            'totalFailed' => 0,
            'totalPending' => 0,
            'totalCharacters' => 4,
            'totalMessages' => 1,
            'sourcePlugin' => $source,
        ]);
        self::assertTrue($record->save(false));

        $controller = new AnalyticsController('analytics', SmsManager::$plugin);
        foreach (['getDailyChartData', 'getEncodingDailyChartData'] as $method) {
            $data = $this->invokePrivate($controller, $method, [null, null, 'all', 'all', 'all', 'all', $source]);
            self::assertIsArray($data);
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $data['dates'][0] ?? '');
            self::assertNotSame($data['dates'][0], $data['labels'][0] ?? null);
        }
    }

    public function testTranslatedFallbacksAndExportHeadersUseSmsManagerCategory(): void
    {
        $this->applyLocale('de-DE');
        $analytics = new AnalyticsController('analytics', SmsManager::$plugin);
        $logs = new SmsLogsController('sms-logs', SmsManager::$plugin);

        $analyticsHeaders = $this->invokePrivate($analytics, 'analyticsExportHeaders', []);
        $logHeaders = $this->invokePrivate($logs, 'smsLogExportHeaders', []);
        self::assertSame('Datum', $analyticsHeaders[0] ?? null);
        self::assertContains('Gesendet gesamt', $analyticsHeaders);
        self::assertSame('Datum', $logHeaders[0] ?? null);
        self::assertContains('Empfänger', $logHeaders);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = [[
            'providerId' => null,
            'providerHandle' => null,
            'senderIdId' => null,
            'senderIdHandle' => null,
        ]];
        $this->invokePrivate($logs, 'enrichLogsWithRelations', [&$rows]);
        self::assertSame('Unbekannt', $rows[0]['providerName']);
        self::assertSame('Unbekannt', $rows[0]['senderIdName']);

        $result = $this->sms->sendWithDetails('123456', 'No provider', providerId: 2147483647);
        self::assertSame('Kein Anbieter konfiguriert', $result['error']);

        $analyticsSource = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/AnalyticsController.php');
        $logsSource = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/SmsLogsController.php');
        $dashboardSource = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/DashboardController.php');
        $providersSource = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/ProvidersController.php');
        $senderIdsSource = file_get_contents(dirname(__DIR__, 2) . '/src/controllers/SenderIdsController.php');
        $testTemplate = file_get_contents(dirname(__DIR__, 2) . '/src/templates/settings/test.twig');
        foreach ([$analyticsSource, $logsSource, $dashboardSource, $providersSource, $senderIdsSource, $testTemplate] as $source) {
            self::assertIsString($source);
        }
        self::assertStringNotContainsString("format('M j')", $analyticsSource);
        self::assertStringContainsString("Craft::t('sms-manager', 'Unknown')", $dashboardSource);
        self::assertStringContainsString("Craft::t('sms-manager', 'Direct')", $logsSource);
        self::assertStringNotContainsString("'error' => 'Provider not found'", $providersSource);
        self::assertStringNotContainsString("'error' => 'Sender ID not found'", $senderIdsSource);
        self::assertStringContainsString("'Unknown error'|t('sms-manager')", $testTemplate);
        self::assertStringContainsString("'N/A'|t('sms-manager')", $testTemplate);
        self::assertStringContainsString("'This is a test SMS from SMS Manager.'|t('sms-manager')", $testTemplate);
    }

    private function applyLocale(string $locale): void
    {
        Craft::$app->language = $locale;
        Craft::$app->getFormatter()->locale = $locale;
    }

    /** @param list<mixed> $arguments */
    private function invokePrivate(object $target, string $methodName, array $arguments): mixed
    {
        return (new ReflectionMethod($target, $methodName))->invokeArgs($target, $arguments);
    }
}
