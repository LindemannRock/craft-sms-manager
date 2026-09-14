<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Fresh-install analytics schema and pre-release debt boundary.
 *
 * Runtime database execution is covered by the isolated MySQL/PostgreSQL
 * install proof; this test prevents the portable migration contract drifting.
 *
 * @since 5.16.0
 */
final class InstallSchemaContractTest extends TestCase
{
    public function testFreshInstallUsesOnlyHandleBasedDefaultSettings(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/migrations/Install.php');
        self::assertIsString($source);

        self::assertStringContainsString("'defaultProviderHandle' => \$this->string(64)->null()", $source);
        self::assertStringContainsString("'defaultSenderIdHandle' => \$this->string(64)->null()", $source);
        self::assertStringNotContainsString("'defaultProviderId' =>", $source);
        self::assertStringNotContainsString("'defaultSenderIdId' =>", $source);
    }

    public function testFreshInstallDefinesTruthfulNullableFactsAndNoTemplateTable(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/migrations/Install.php');
        self::assertIsString($source);

        self::assertStringContainsString("'encoding' => \$this->string(10)->null()", $source);
        self::assertStringContainsString("'totalCharacters' => \$this->integer()->null()", $source);
        self::assertStringContainsString("'totalMessages' => \$this->integer()->null()", $source);
        self::assertStringNotContainsString('englishCount', $source);
        self::assertStringNotContainsString('arabicCount', $source);
        self::assertStringNotContainsString('otherCount', $source);
        self::assertStringNotContainsString('smsmanager_templates', $source);
    }

    public function testAnalyticsSiteForeignKeyKeepsGlobalHistoryOnSiteDeletion(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/migrations/Install.php');
        self::assertIsString($source);

        self::assertMatchesRegularExpression(
            "/smsmanager_analytics[\\s\\S]+?\\['siteId'\\][\\s\\S]+?\\{\\{%sites\\}\\}[\\s\\S]+?'SET NULL'[\\s\\S]+?'CASCADE'/",
            $source,
        );
    }
}
