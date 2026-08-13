<?php

namespace justinholtweb\microscope\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Creates Microscope's two tables.
 *
 * A scan row is a header — when, how, what score — and the findings hang off it. Storing findings
 * as rows rather than as one JSON blob on the scan is what lets the dashboard answer "has this
 * particular problem been outstanding for three months?" without deserialising every scan ever
 * taken.
 */
class Install extends Migration
{
    public const TABLE_SCANS = '{{%microscope_scans}}';
    public const TABLE_FINDINGS = '{{%microscope_findings}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::TABLE_SCANS)) {
            $this->createTable(self::TABLE_SCANS, [
                'id' => $this->primaryKey(),
                'status' => $this->string(32)->notNull()->defaultValue('pending'),
                'trigger' => $this->string(16)->notNull()->defaultValue('manual'),
                'config' => $this->text(),
                'environment' => $this->text(),
                'score' => $this->integer(),
                'categoryScores' => $this->text(),
                'criticalCount' => $this->integer()->notNull()->defaultValue(0),
                'warningCount' => $this->integer()->notNull()->defaultValue(0),
                'noticeCount' => $this->integer()->notNull()->defaultValue(0),
                'passCount' => $this->integer()->notNull()->defaultValue(0),
                'skipped' => $this->text(),
                'runtime' => $this->double(),
                'error' => $this->text(),
                'creatorId' => $this->integer(),
                'dateStarted' => $this->dateTime(),
                'dateFinished' => $this->dateTime(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            // The dashboard reads "the most recent completed scan" on every load, and the history
            // chart reads completed scans by date — both are covered by this.
            $this->createIndex(null, self::TABLE_SCANS, ['status', 'dateCreated']);
            $this->createIndex(null, self::TABLE_SCANS, ['trigger', 'dateCreated']);

            $this->addForeignKey(
                null,
                self::TABLE_SCANS,
                ['creatorId'],
                Table::USERS,
                ['id'],
                'SET NULL',
                null,
            );
        }

        if (!$this->db->tableExists(self::TABLE_FINDINGS)) {
            $this->createTable(self::TABLE_FINDINGS, [
                'id' => $this->primaryKey(),
                'scanId' => $this->integer()->notNull(),
                'checkHandle' => $this->string(96)->notNull(),
                'category' => $this->string(32)->notNull(),
                'severity' => $this->string(16)->notNull()->defaultValue('notice'),
                'title' => $this->string(255)->notNull(),
                'summary' => $this->text(),
                'observed' => $this->string(255),
                'expected' => $this->string(255),
                'impact' => $this->text(),
                'remediation' => $this->mediumText(),
                'docsUrl' => $this->string(500),
                // Template paths and long ini keys both live here, so it needs more room than a
                // handle would.
                'target' => $this->string(500),
                'occurrences' => $this->integer()->notNull()->defaultValue(1),
                'evidence' => $this->mediumText(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, self::TABLE_FINDINGS, ['scanId', 'severity']);
            $this->createIndex(null, self::TABLE_FINDINGS, ['scanId', 'category']);
            // Powers "how long has this been outstanding" without a scan-by-scan walk.
            $this->createIndex(null, self::TABLE_FINDINGS, ['checkHandle']);

            $this->addForeignKey(
                null,
                self::TABLE_FINDINGS,
                ['scanId'],
                self::TABLE_SCANS,
                ['id'],
                'CASCADE',
                null,
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE_FINDINGS);
        $this->dropTableIfExists(self::TABLE_SCANS);

        return true;
    }
}
