<?php

namespace justinholtweb\microscope\probes\db;

use Craft;
use Throwable;
use yii\db\Connection;

/**
 * Runs Microscope's diagnostic SQL through Craft's own connection.
 *
 * Failures are swallowed and turned into empty results. That is deliberate: `SHOW VARIABLES` and
 * `information_schema` are routinely restricted on managed database services, and a permission
 * error there should cost the site one skipped check, not a failed scan.
 */
class CraftSqlRunner implements SqlRunnerInterface
{
    private Connection $db;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?? Craft::$app->getDb();
    }

    public function queryAll(string $sql, array $params = []): array
    {
        try {
            return $this->db->createCommand($sql, $params)->queryAll();
        } catch (Throwable $e) {
            Craft::info(
                sprintf('Diagnostic query failed (%s): %s', $sql, $e->getMessage()),
                'microscope',
            );

            return [];
        }
    }

    public function queryScalar(string $sql, array $params = []): mixed
    {
        try {
            return $this->db->createCommand($sql, $params)->queryScalar();
        } catch (Throwable $e) {
            Craft::info(
                sprintf('Diagnostic query failed (%s): %s', $sql, $e->getMessage()),
                'microscope',
            );

            return null;
        }
    }

    public function driver(): string
    {
        return $this->db->getDriverName();
    }

    public function databaseName(): ?string
    {
        try {
            return (string)$this->db->createCommand('SELECT DATABASE()')->queryScalar() ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    public function tablePrefix(): string
    {
        return $this->db->tablePrefix;
    }
}
