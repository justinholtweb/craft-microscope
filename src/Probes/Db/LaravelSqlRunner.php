<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Probes\Db;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs Microscope's read-only diagnostic SQL on a Laravel database connection — Craft's own,
 * unless another is given.
 *
 * A diagnostic query failing is normal (managed databases restrict `SHOW GLOBAL STATUS` and
 * friends), so failures are logged at debug level and answered with an empty result, never thrown.
 */
class LaravelSqlRunner implements SqlRunnerInterface
{
    private ConnectionInterface $db;

    public function __construct(?ConnectionInterface $db = null)
    {
        $this->db = $db ?? DB::connection();
    }

    public function queryAll(string $sql, array $params = []): array
    {
        try {
            return array_map(fn (mixed $row) => (array) $row, $this->db->select($sql, $params));
        } catch (Throwable $e) {
            Log::channel(self::channel())->debug(sprintf('Microscope: diagnostic query failed (%s): %s', $sql, $e->getMessage()));

            return [];
        }
    }

    public function queryScalar(string $sql, array $params = []): mixed
    {
        try {
            $row = $this->db->selectOne($sql, $params);
        } catch (Throwable $e) {
            Log::channel(self::channel())->debug(sprintf('Microscope: diagnostic query failed (%s): %s', $sql, $e->getMessage()));

            return null;
        }

        if ($row === null) {
            return null;
        }

        $values = array_values((array) $row);

        return $values[0] ?? null;
    }

    public function driver(): string
    {
        return method_exists($this->db, 'getDriverName') ? (string) $this->db->getDriverName() : 'unknown';
    }

    public function databaseName(): ?string
    {
        $name = method_exists($this->db, 'getDatabaseName') ? $this->db->getDatabaseName() : null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function tablePrefix(): string
    {
        return method_exists($this->db, 'getTablePrefix') ? (string) $this->db->getTablePrefix() : '';
    }

    /** Microscope's own log channel when the plugin has registered it, else the default. */
    private static function channel(): ?string
    {
        return config('logging.channels.microscope') !== null ? 'microscope' : null;
    }
}
