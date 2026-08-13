<?php

namespace justinholtweb\microscope\probes\db;

use PDO;
use Throwable;

/**
 * A runner over a bare PDO handle, with no Craft anywhere near it.
 *
 * This exists so the database probe and its checks can be tested against a live MySQL or MariaDB
 * — the integration suite in DDEV points it at the `db` service. Server tuning advice that has
 * only ever been tested against a fixture is advice nobody should take.
 */
class PdoSqlRunner implements SqlRunnerInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tablePrefix = '',
    ) {
    }

    public function queryAll(string $sql, array $params = []): array
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $statement->closeCursor();

            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    public function queryScalar(string $sql, array $params = []): mixed
    {
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            $value = $statement->fetchColumn();

            // Reading one column leaves the rest of the result set on the wire. MySQL refuses the
            // *next* query on the same connection until it's drained — "Cannot execute queries
            // while other unbuffered queries are active" — so every diagnostic query after a
            // scalar one would fail. Closing the cursor is what makes them independent.
            $statement->closeCursor();

            return $value === false ? null : $value;
        } catch (Throwable) {
            return null;
        }
    }

    public function driver(): string
    {
        return (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function databaseName(): ?string
    {
        $name = $this->queryScalar('SELECT DATABASE()');

        return $name === null ? null : (string)$name;
    }

    public function tablePrefix(): string
    {
        return $this->tablePrefix;
    }
}
