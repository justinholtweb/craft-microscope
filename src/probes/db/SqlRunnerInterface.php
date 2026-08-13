<?php

namespace justinholtweb\microscope\probes\db;

/**
 * The narrowest possible database interface: run read-only SQL, get rows back.
 *
 * {@see \justinholtweb\microscope\probes\DatabaseProbe} talks to this rather than to Craft's
 * connection, which is what lets the database checks be exercised against a real MariaDB in CI
 * without booting Craft — see `tests/Integration`. It also keeps the surface small enough to be
 * obviously read-only.
 */
interface SqlRunnerInterface
{
    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function queryAll(string $sql, array $params = []): array;

    /**
     * @param array<string, mixed> $params
     */
    public function queryScalar(string $sql, array $params = []): mixed;

    /**
     * `mysql` or `pgsql`. Microscope's database checks are MySQL/MariaDB-specific, and knowing the
     * driver is how they decide to skip rather than to run MySQL syntax against Postgres.
     */
    public function driver(): string;

    /**
     * The name of the schema Craft's tables live in.
     */
    public function databaseName(): ?string;

    /**
     * The prefix on Craft's table names, if the install uses one.
     */
    public function tablePrefix(): string;
}
