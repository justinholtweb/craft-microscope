<?php

namespace justinholtweb\microscope\probes;

use justinholtweb\microscope\helpers\Version;
use justinholtweb\microscope\probes\db\SqlRunnerInterface;

/**
 * What the database server will tell us about itself, and what Craft has put in it.
 *
 * Two very different kinds of question end up here because they come from the same connection:
 * server *tuning* (`SHOW VARIABLES`, `SHOW GLOBAL STATUS`) and Craft's own *table sizes*. Both are
 * memoized, because a dozen checks will ask for the same variable dump.
 *
 * Everything is read-only. Microscope never issues DDL, never writes, and never runs `ANALYZE` or
 * `OPTIMIZE` — a diagnostic that takes a table lock is not a diagnostic.
 */
class DatabaseProbe
{
    /**
     * @var array<string, string>|null
     */
    private ?array $variables = null;

    /**
     * @var array<string, string>|null
     */
    private ?array $status = null;

    /**
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $tables = null;

    private ?string $versionBanner = null;

    private bool $versionRead = false;

    public function __construct(private readonly SqlRunnerInterface $sql)
    {
    }

    /**
     * Whether the database checks apply at all.
     *
     * Craft supports PostgreSQL, and every tuning check Microscope makes is about InnoDB. Rather
     * than give a Postgres site advice about `innodb_buffer_pool_size`, the whole category skips.
     */
    public function isMysql(): bool
    {
        return $this->sql->driver() === 'mysql';
    }

    /**
     * The raw version banner, e.g. `10.11.6-MariaDB-1:10.11.6+maria~ubu2204`.
     */
    public function versionBanner(): ?string
    {
        if ($this->versionRead) {
            return $this->versionBanner;
        }

        $this->versionRead = true;
        $version = $this->sql->queryScalar('SELECT VERSION()');

        return $this->versionBanner = $version === null ? null : (string)$version;
    }

    public function version(): ?string
    {
        return Version::normalize($this->versionBanner());
    }

    public function isMariaDb(): bool
    {
        return Version::isMariaDb($this->versionBanner());
    }

    /**
     * Which product this is, for report copy that has to name it.
     */
    public function serverName(): string
    {
        return $this->isMariaDb() ? 'MariaDB' : 'MySQL';
    }

    /**
     * Every server variable, keyed by name.
     *
     * @return array<string, string>
     */
    public function variables(): array
    {
        if ($this->variables !== null) {
            return $this->variables;
        }

        $this->variables = [];

        foreach ($this->sql->queryAll('SHOW VARIABLES') as $row) {
            $name = $row['Variable_name'] ?? null;

            if (is_string($name)) {
                $this->variables[strtolower($name)] = (string)($row['Value'] ?? '');
            }
        }

        return $this->variables;
    }

    public function variable(string $name): ?string
    {
        $value = $this->variables()[strtolower($name)] ?? null;

        return $value === '' ? null : $value;
    }

    public function intVariable(string $name): ?int
    {
        $value = $this->variable($name);

        return $value === null || !is_numeric($value) ? null : (int)$value;
    }

    public function boolVariable(string $name): ?bool
    {
        $value = $this->variable($name);

        if ($value === null) {
            return null;
        }

        return in_array(strtoupper($value), ['ON', '1', 'YES', 'TRUE'], true);
    }

    /**
     * Global status counters, keyed by name.
     *
     * These are cumulative since the server started, so a check reading them has to reason in
     * ratios — "what fraction of temporary tables spilled to disk" — rather than absolute counts,
     * which say more about uptime than about tuning.
     *
     * @return array<string, string>
     */
    public function status(): array
    {
        if ($this->status !== null) {
            return $this->status;
        }

        $this->status = [];

        foreach ($this->sql->queryAll('SHOW GLOBAL STATUS') as $row) {
            $name = $row['Variable_name'] ?? null;

            if (is_string($name)) {
                $this->status[strtolower($name)] = (string)($row['Value'] ?? '');
            }
        }

        return $this->status;
    }

    public function statusValue(string $name): ?int
    {
        $value = $this->status()[strtolower($name)] ?? null;

        return $value === null || !is_numeric($value) ? null : (int)$value;
    }

    /**
     * How long the server has been up, in seconds.
     *
     * Every ratio derived from {@see status()} is meaningless on a server that started ten minutes
     * ago, so checks gate on this.
     */
    public function uptime(): ?int
    {
        return $this->statusValue('uptime');
    }

    // ---------------------------------------------------------------------
    // Craft's tables
    // ---------------------------------------------------------------------

    /**
     * Size and row estimates for every table in Craft's schema, largest first.
     *
     * `information_schema` row counts are estimates for InnoDB — they come from the optimizer's
     * statistics, not a `COUNT(*)` — which is exactly what's wanted here. An accurate count of a
     * ten-million-row `searchindex` is a table scan, and Microscope is not going to run one to
     * produce a report about the site being slow.
     *
     * @return array<int, array{name: string, rows: int, data: int, index: int, total: int}>
     */
    public function tables(): array
    {
        if ($this->tables !== null) {
            return $this->tables;
        }

        $database = $this->sql->databaseName();

        if ($database === null || !$this->isMysql()) {
            return $this->tables = [];
        }

        $rows = $this->sql->queryAll(
            'SELECT TABLE_NAME AS name,
                    TABLE_ROWS AS rows_estimate,
                    DATA_LENGTH AS data_length,
                    INDEX_LENGTH AS index_length
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE = \'BASE TABLE\'
             ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC',
            [':schema' => $database],
        );

        $this->tables = array_map(fn(array $row) => [
            'name' => (string)$row['name'],
            'rows' => (int)$row['rows_estimate'],
            'data' => (int)$row['data_length'],
            'index' => (int)$row['index_length'],
            'total' => (int)$row['data_length'] + (int)$row['index_length'],
        ], $rows);

        return $this->tables;
    }

    /**
     * @return array{name: string, rows: int, data: int, index: int, total: int}|null
     */
    public function table(string $name): ?array
    {
        $prefixed = $this->sql->tablePrefix() . $name;

        foreach ($this->tables() as $table) {
            if ($table['name'] === $prefixed || $table['name'] === $name) {
                return $table;
            }
        }

        return null;
    }

    public function tableSize(string $name): int
    {
        return $this->table($name)['total'] ?? 0;
    }

    public function tableRows(string $name): int
    {
        return $this->table($name)['rows'] ?? 0;
    }

    /**
     * Total size of Craft's schema on disk.
     */
    public function totalSize(): int
    {
        return array_sum(array_column($this->tables(), 'total'));
    }

    /**
     * The largest tables, for the report's context.
     *
     * @return array<int, array{name: string, rows: int, data: int, index: int, total: int}>
     */
    public function largestTables(int $limit = 10): array
    {
        return array_slice($this->tables(), 0, $limit);
    }

    /**
     * Tables still using MyISAM.
     *
     * @return string[]
     */
    public function nonInnodbTables(): array
    {
        $database = $this->sql->databaseName();

        if ($database === null || !$this->isMysql()) {
            return [];
        }

        $rows = $this->sql->queryAll(
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = :schema AND ENGINE IS NOT NULL AND ENGINE <> \'InnoDB\'',
            [':schema' => $database],
        );

        return array_map(fn(array $row) => (string)$row['name'], $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'server' => $this->serverName(),
            'version' => $this->version(),
            'banner' => $this->versionBanner(),
            'size' => $this->totalSize(),
            'uptime' => $this->uptime(),
        ];
    }
}
