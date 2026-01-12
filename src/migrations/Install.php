<?php
/**
 * SMS Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025 LindemannRock
 */

namespace lindemannrock\smsmanager\migrations;

use craft\db\Migration;
use craft\helpers\Db;
use craft\helpers\StringHelper;

/**
 * Install Migration
 *
 * @author    LindemannRock
 * @package   SmsManager
 * @since     5.0.0
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createSettingsTable();
        $this->createProvidersTable();
        $this->createSenderIdsTable();
        $this->createLogsTable();
        $this->createAnalyticsTable();
        $this->createTemplatesTable();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // Drop tables in reverse order (respecting foreign keys)
        $this->dropTableIfExists('{{%smsmanager_templates}}');
        $this->dropTableIfExists('{{%smsmanager_analytics}}');
        $this->dropTableIfExists('{{%smsmanager_logs}}');
        $this->dropTableIfExists('{{%smsmanager_senderids}}');
        $this->dropTableIfExists('{{%smsmanager_providers}}');
        $this->dropTableIfExists('{{%smsmanager_settings}}');

        return true;
    }

    /**
     * Create settings table
     */
    private function createSettingsTable(): void
    {
        if ($this->db->tableExists('{{%smsmanager_settings}}')) {
            return;
        }

        $this->createTable('{{%smsmanager_settings}}', [
            'id' => $this->primaryKey(),
            // Plugin settings
            'pluginName' => $this->string(255)->notNull()->defaultValue('SMS Manager'),
            'defaultProviderId' => $this->integer()->null(),
            'defaultSenderIdId' => $this->integer()->null(),
            // Analytics settings
            'enableAnalytics' => $this->boolean()->notNull()->defaultValue(true),
            'analyticsLimit' => $this->integer()->notNull()->defaultValue(1000),
            'analyticsRetention' => $this->integer()->notNull()->defaultValue(30),
            'autoTrimAnalytics' => $this->boolean()->notNull()->defaultValue(true),
            // Logs settings
            'enableLogs' => $this->boolean()->notNull()->defaultValue(true),
            'logsLimit' => $this->integer()->notNull()->defaultValue(10000),
            'logsRetention' => $this->integer()->notNull()->defaultValue(30),
            'autoTrimLogs' => $this->boolean()->notNull()->defaultValue(true),
            // Interface settings
            'itemsPerPage' => $this->integer()->notNull()->defaultValue(100),
            'refreshIntervalSecs' => $this->integer()->notNull()->defaultValue(30),
            // Logging library
            'logLevel' => $this->string(20)->notNull()->defaultValue('error'),
            // Standard columns
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Insert default settings row (always id=1)
        $this->insert('{{%smsmanager_settings}}', [
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            'uid' => StringHelper::UUID(),
        ]);
    }

    /**
     * Create providers table
     */
    private function createProvidersTable(): void
    {
        if ($this->db->tableExists('{{%smsmanager_providers}}')) {
            return;
        }

        $this->createTable('{{%smsmanager_providers}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'handle' => $this->string(64)->notNull(),
            'type' => $this->string(64)->notNull(), // Provider class type (e.g., 'mpp-sms', 'twilio')
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'isDefault' => $this->boolean()->notNull()->defaultValue(false),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            // Provider-specific settings (JSON)
            'settings' => $this->text()->null()->comment('JSON provider settings'),
            // Standard columns
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Indexes
        $this->createIndex(null, '{{%smsmanager_providers}}', ['handle'], true);
        $this->createIndex(null, '{{%smsmanager_providers}}', ['type'], false);
        $this->createIndex(null, '{{%smsmanager_providers}}', ['enabled'], false);
        $this->createIndex(null, '{{%smsmanager_providers}}', ['isDefault'], false);
        $this->createIndex(null, '{{%smsmanager_providers}}', ['sortOrder'], false);
    }

    /**
     * Create sender IDs table
     */
    private function createSenderIdsTable(): void
    {
        if ($this->db->tableExists('{{%smsmanager_senderids}}')) {
            return;
        }

        $this->createTable('{{%smsmanager_senderids}}', [
            'id' => $this->primaryKey(),
            'providerId' => $this->integer()->notNull(),
            'name' => $this->string(255)->notNull(), // Display name (e.g., "A. Alghanim")
            'handle' => $this->string(64)->notNull(), // Internal handle (e.g., "alghanim")
            'senderId' => $this->string(64)->notNull(), // Actual sender ID for API (e.g., "AliAlghanim")
            'description' => $this->text()->null(), // Optional description
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'isDefault' => $this->boolean()->notNull()->defaultValue(false),
            'isTest' => $this->boolean()->notNull()->defaultValue(false),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            // Standard columns
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Indexes
        $this->createIndex(null, '{{%smsmanager_senderids}}', ['providerId'], false);
        $this->createIndex(null, '{{%smsmanager_senderids}}', ['handle'], false);
        $this->createIndex(null, '{{%smsmanager_senderids}}', ['enabled'], false);
        $this->createIndex(null, '{{%smsmanager_senderids}}', ['isDefault'], false);
        $this->createIndex(null, '{{%smsmanager_senderids}}', ['isTest'], false);
        $this->createIndex(null, '{{%smsmanager_senderids}}', ['sortOrder'], false);

        // Foreign key to providers
        $this->addForeignKey(
            null,
            '{{%smsmanager_senderids}}',
            ['providerId'],
            '{{%smsmanager_providers}}',
            ['id'],
            'CASCADE',
            'CASCADE'
        );
    }

    /**
     * Create logs table (delivery tracking)
     */
    private function createLogsTable(): void
    {
        if ($this->db->tableExists('{{%smsmanager_logs}}')) {
            return;
        }

        $this->createTable('{{%smsmanager_logs}}', [
            'id' => $this->primaryKey(),
            'providerId' => $this->integer()->null(),
            'senderIdId' => $this->integer()->null(),
            // Message details
            'recipient' => $this->string(64)->notNull(),
            'message' => $this->text()->null(),
            'language' => $this->string(10)->null(), // 'en', 'ar', etc.
            'messageLength' => $this->integer()->null(),
            // Status tracking
            'status' => $this->string(32)->notNull()->defaultValue('pending'), // pending, sent, delivered, failed
            'providerMessageId' => $this->string(255)->null(), // Provider's message ID
            'providerResponse' => $this->text()->null(), // Raw provider response
            'errorMessage' => $this->text()->null(),
            // Source tracking
            'sourcePlugin' => $this->string(64)->null(), // 'formie-mpp-sms', 'formie-campaigns', etc.
            'sourceElementId' => $this->integer()->null(), // Reference to form/campaign/etc.
            // Standard columns
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Indexes for performance
        $this->createIndex(null, '{{%smsmanager_logs}}', ['providerId'], false);
        $this->createIndex(null, '{{%smsmanager_logs}}', ['senderIdId'], false);
        $this->createIndex(null, '{{%smsmanager_logs}}', ['recipient'], false);
        $this->createIndex(null, '{{%smsmanager_logs}}', ['status'], false);
        $this->createIndex(null, '{{%smsmanager_logs}}', ['sourcePlugin'], false);
        $this->createIndex(null, '{{%smsmanager_logs}}', ['dateCreated'], false);

        // Foreign keys
        $this->addForeignKey(
            null,
            '{{%smsmanager_logs}}',
            ['providerId'],
            '{{%smsmanager_providers}}',
            ['id'],
            'SET NULL',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            '{{%smsmanager_logs}}',
            ['senderIdId'],
            '{{%smsmanager_senderids}}',
            ['id'],
            'SET NULL',
            'CASCADE'
        );
    }

    /**
     * Create analytics table (aggregated stats)
     */
    private function createAnalyticsTable(): void
    {
        if ($this->db->tableExists('{{%smsmanager_analytics}}')) {
            return;
        }

        $this->createTable('{{%smsmanager_analytics}}', [
            'id' => $this->primaryKey(),
            'providerId' => $this->integer()->null(),
            'senderIdId' => $this->integer()->null(),
            // Aggregation period
            'date' => $this->date()->notNull(), // Date of stats (daily aggregation)
            // Counts
            'totalSent' => $this->integer()->notNull()->defaultValue(0),
            'totalDelivered' => $this->integer()->notNull()->defaultValue(0),
            'totalFailed' => $this->integer()->notNull()->defaultValue(0),
            'totalPending' => $this->integer()->notNull()->defaultValue(0),
            // Character/message stats
            'totalCharacters' => $this->integer()->notNull()->defaultValue(0),
            'totalMessages' => $this->integer()->notNull()->defaultValue(0), // SMS segments
            // Language breakdown
            'englishCount' => $this->integer()->notNull()->defaultValue(0),
            'arabicCount' => $this->integer()->notNull()->defaultValue(0),
            'otherCount' => $this->integer()->notNull()->defaultValue(0),
            // Source tracking
            'sourcePlugin' => $this->string(64)->null(),
            // Standard columns
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Indexes for performance
        $this->createIndex(null, '{{%smsmanager_analytics}}', ['providerId'], false);
        $this->createIndex(null, '{{%smsmanager_analytics}}', ['senderIdId'], false);
        $this->createIndex(null, '{{%smsmanager_analytics}}', ['date'], false);
        $this->createIndex(null, '{{%smsmanager_analytics}}', ['sourcePlugin'], false);
        // Unique index for daily aggregation per provider/senderId/source
        $this->createIndex(
            null,
            '{{%smsmanager_analytics}}',
            ['date', 'providerId', 'senderIdId', 'sourcePlugin'],
            true
        );

        // Foreign keys
        $this->addForeignKey(
            null,
            '{{%smsmanager_analytics}}',
            ['providerId'],
            '{{%smsmanager_providers}}',
            ['id'],
            'SET NULL',
            'CASCADE'
        );

        $this->addForeignKey(
            null,
            '{{%smsmanager_analytics}}',
            ['senderIdId'],
            '{{%smsmanager_senderids}}',
            ['id'],
            'SET NULL',
            'CASCADE'
        );
    }

    /**
     * Create templates table (Phase 2 - empty for now)
     */
    private function createTemplatesTable(): void
    {
        if ($this->db->tableExists('{{%smsmanager_templates}}')) {
            return;
        }

        $this->createTable('{{%smsmanager_templates}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'handle' => $this->string(64)->notNull(),
            'language' => $this->string(10)->notNull()->defaultValue('en'),
            'message' => $this->text()->notNull(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            // Standard columns
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Indexes
        $this->createIndex(null, '{{%smsmanager_templates}}', ['handle'], true);
        $this->createIndex(null, '{{%smsmanager_templates}}', ['language'], false);
        $this->createIndex(null, '{{%smsmanager_templates}}', ['enabled'], false);
        $this->createIndex(null, '{{%smsmanager_templates}}', ['sortOrder'], false);
    }
}
