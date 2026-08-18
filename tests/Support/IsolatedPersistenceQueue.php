<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\smsmanager\tests\Support;

use craft\queue\Queue;
use Throwable;

/**
 * Hides permanent queue and cleanup data before enabled plugins bootstrap.
 *
 * @since 5.16.0
 */
final class IsolatedPersistenceQueue extends Queue
{
    private const EMPTY_SHADOWS = [
        '{{%queue}}',
        '{{%smsmanager_analytics}}',
        '{{%smsmanager_logs}}',
    ];

    private const COPIED_SHADOWS = [
        '{{%smsmanager_settings}}',
    ];

    /** @var list<string> */
    private array $rawShadowTables = [];

    public function init(): void
    {
        parent::init();

        if ($this->db->getDriverName() !== 'mysql') {
            throw new \RuntimeException('The disposable SMS Manager suite currently requires MySQL.');
        }

        foreach (self::EMPTY_SHADOWS as $tableName) {
            $this->installShadow($tableName, false);
        }
        foreach (self::COPIED_SHADOWS as $tableName) {
            $this->installShadow($tableName, true);
        }

        register_shutdown_function(function(): void {
            foreach (array_reverse($this->rawShadowTables) as $rawTable) {
                try {
                    $this->db->createCommand(
                        'DROP TEMPORARY TABLE IF EXISTS ' . $this->db->quoteTableName($rawTable),
                    )->execute();
                } catch (Throwable $exception) {
                    fwrite(STDERR, 'SMS Manager shadow cleanup failed: ' . $exception->getMessage() . PHP_EOL);
                }
            }
        });
    }

    /** Clear only the connection-local queue and cleanup-data shadows. */
    public function clearTransientShadowRows(): void
    {
        foreach (self::EMPTY_SHADOWS as $tableName) {
            $this->db->createCommand()->delete($tableName)->execute();
        }
    }

    /** @return list<string> */
    public function rawShadowTables(): array
    {
        return $this->rawShadowTables;
    }

    private function installShadow(string $tableName, bool $copyRows): void
    {
        $rawTable = $this->db->getSchema()->getRawTableName($tableName);
        $stagingTable = $rawTable . '_sms_test_' . bin2hex(random_bytes(8));
        $quotedStaging = $this->db->quoteTableName($stagingTable);
        $quotedOriginal = $this->db->quoteTableName($rawTable);

        $this->db->createCommand("CREATE TEMPORARY TABLE {$quotedStaging} LIKE {$quotedOriginal}")->execute();
        if ($copyRows) {
            $this->db->createCommand("INSERT INTO {$quotedStaging} SELECT * FROM {$quotedOriginal}")->execute();
        }
        $this->db->createCommand("ALTER TABLE {$quotedStaging} RENAME TO {$quotedOriginal}")->execute();

        $this->rawShadowTables[] = $rawTable;
        $this->db->getSchema()->refreshTableSchema($tableName);
    }
}
