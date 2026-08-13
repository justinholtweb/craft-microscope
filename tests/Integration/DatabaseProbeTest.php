<?php

declare(strict_types=1);

namespace justinholtweb\microscope\tests\Integration;

use justinholtweb\microscope\probes\DatabaseProbe;
use justinholtweb\microscope\probes\db\PdoSqlRunner;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The database probe, against a real server.
 *
 * These matter more than they look. `SHOW VARIABLES`, `SHOW GLOBAL STATUS` and
 * `information_schema` all return data in shapes that are easy to *assume* and awkward to get
 * right — column names that differ between servers, values that arrive as strings, reserved words
 * that need aliasing, and version banners with vendor suffixes. Server tuning advice that has only
 * ever been tested against a fixture is advice nobody should take.
 *
 * DDEV supplies the database; without one, every test here skips and the suite still passes.
 */
final class DatabaseProbeTest extends TestCase
{
    private static ?PDO $pdo = null;
    private static bool $attempted = false;

    private DatabaseProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = self::connection();

        if ($pdo === null) {
            self::markTestSkipped(
                'No MySQL/MariaDB reachable. Run this inside DDEV (`ddev test`), or set '
                . 'MICROSCOPE_DB_HOST / _PORT / _NAME / _USER / _PASSWORD.',
            );
        }

        $this->probe = new DatabaseProbe(new PdoSqlRunner($pdo));
    }

    private static function connection(): ?PDO
    {
        if (self::$attempted) {
            return self::$pdo;
        }

        self::$attempted = true;

        $host = getenv('MICROSCOPE_DB_HOST') ?: '127.0.0.1';
        $port = getenv('MICROSCOPE_DB_PORT') ?: '3306';
        $name = getenv('MICROSCOPE_DB_NAME') ?: 'db';
        $user = getenv('MICROSCOPE_DB_USER') ?: 'db';
        $password = getenv('MICROSCOPE_DB_PASSWORD');
        $password = $password === false ? 'db' : $password;

        try {
            self::$pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s', $host, $port, $name),
                $user,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 3,
                ],
            );
        } catch (Throwable) {
            self::$pdo = null;
        }

        return self::$pdo;
    }

    public function testIdentifiesTheDriver(): void
    {
        self::assertTrue($this->probe->isMysql());
    }

    public function testReadsAVersionBanner(): void
    {
        $banner = $this->probe->versionBanner();

        self::assertIsString($banner);
        self::assertNotSame('', $banner);
    }

    /**
     * The banner is what `VERSION()` actually returns — for MariaDB something like
     * `10.11.6-MariaDB-1:10.11.6+maria~ubu2204`. Normalising that is the whole point of the
     * Version helper, and this confirms it survives a real one.
     */
    public function testNormalizesTheRealVersion(): void
    {
        $version = $this->probe->version();

        self::assertIsString($version);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
    }

    public function testNamesTheServerProduct(): void
    {
        self::assertContains($this->probe->serverName(), ['MySQL', 'MariaDB']);
        self::assertSame(
            $this->probe->isMariaDb(),
            $this->probe->serverName() === 'MariaDB',
        );
    }

    public function testReadsServerVariables(): void
    {
        $variables = $this->probe->variables();

        self::assertNotEmpty($variables);
        // Keys are lower-cased on the way in, so callers never have to guess the server's casing.
        self::assertArrayHasKey('innodb_buffer_pool_size', $variables);
        self::assertArrayHasKey('max_connections', $variables);
    }

    public function testVariableLookupIsCaseInsensitive(): void
    {
        self::assertSame(
            $this->probe->variable('innodb_buffer_pool_size'),
            $this->probe->variable('INNODB_BUFFER_POOL_SIZE'),
        );
    }

    public function testReadsIntegerVariables(): void
    {
        $poolSize = $this->probe->intVariable('innodb_buffer_pool_size');

        self::assertIsInt($poolSize);
        self::assertGreaterThan(0, $poolSize);
    }

    public function testReadsBooleanVariables(): void
    {
        // `autocommit` is ON by default everywhere, which makes it a safe assertion about the
        // ON/OFF parsing rather than about anyone's configuration.
        self::assertTrue($this->probe->boolVariable('autocommit'));
    }

    public function testUnknownVariablesAreNull(): void
    {
        self::assertNull($this->probe->variable('microscope_not_a_real_variable'));
        self::assertNull($this->probe->intVariable('microscope_not_a_real_variable'));
        self::assertNull($this->probe->boolVariable('microscope_not_a_real_variable'));
    }

    public function testReadsGlobalStatus(): void
    {
        $status = $this->probe->status();

        self::assertNotEmpty($status);
        self::assertArrayHasKey('uptime', $status);
    }

    public function testReadsUptime(): void
    {
        $uptime = $this->probe->uptime();

        self::assertIsInt($uptime);
        self::assertGreaterThanOrEqual(0, $uptime);
    }

    public function testStatusValuesAreIntegers(): void
    {
        $requests = $this->probe->statusValue('innodb_buffer_pool_read_requests');

        self::assertIsInt($requests);
    }

    /**
     * `TABLE_ROWS` and friends need aliasing — `ROWS` is a reserved word in MySQL 8 — and the
     * columns come back as strings. Both are easy to get wrong and silent when you do.
     */
    public function testReadsTableSizes(): void
    {
        $this->withScratchTable(function() {
            $tables = $this->probe->tables();

            self::assertNotEmpty($tables, 'information_schema returned no tables.');

            $names = array_column($tables, 'name');
            self::assertContains('microscope_probe_test', $names);

            $table = $this->probe->table('microscope_probe_test');
            self::assertIsArray($table);
            self::assertIsInt($table['data']);
            self::assertIsInt($table['index']);
            self::assertSame($table['data'] + $table['index'], $table['total']);
        });
    }

    public function testTablesAreOrderedLargestFirst(): void
    {
        $this->withScratchTable(function() {
            $totals = array_column($this->probe->tables(), 'total');
            $sorted = $totals;
            rsort($sorted);

            self::assertSame($sorted, $totals);
        });
    }

    public function testTotalSizeSumsTheTables(): void
    {
        $this->withScratchTable(function() {
            self::assertSame(
                array_sum(array_column($this->probe->tables(), 'total')),
                $this->probe->totalSize(),
            );
        });
    }

    public function testUnknownTablesAreNull(): void
    {
        self::assertNull($this->probe->table('microscope_no_such_table'));
        self::assertSame(0, $this->probe->tableSize('microscope_no_such_table'));
    }

    public function testDetectsNonInnodbTables(): void
    {
        $pdo = self::connection();
        self::assertNotNull($pdo);

        $pdo->exec('DROP TABLE IF EXISTS microscope_myisam_test');
        $pdo->exec('CREATE TABLE microscope_myisam_test (id INT PRIMARY KEY) ENGINE=MyISAM');

        try {
            $probe = new DatabaseProbe(new PdoSqlRunner($pdo));

            self::assertContains('microscope_myisam_test', $probe->nonInnodbTables());
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS microscope_myisam_test');
        }
    }

    /**
     * A restricted user — which managed database services routinely hand out — must produce empty
     * results rather than an exception. The whole failure mode of this category is "can't see",
     * and it has to be survivable.
     */
    public function testFailedQueriesDegradeToEmptyResults(): void
    {
        $pdo = self::connection();
        self::assertNotNull($pdo);

        $runner = new PdoSqlRunner($pdo);

        self::assertSame([], $runner->queryAll('SELECT * FROM a_table_that_does_not_exist'));
        self::assertNull($runner->queryScalar('SELECT * FROM a_table_that_does_not_exist'));
    }

    public function testSummaryDescribesTheServer(): void
    {
        $summary = $this->probe->summary();

        self::assertArrayHasKey('server', $summary);
        self::assertArrayHasKey('version', $summary);
        self::assertArrayHasKey('size', $summary);
        self::assertArrayHasKey('uptime', $summary);
    }

    /**
     * Creates a table with enough rows for `information_schema` to report a non-zero size, runs
     * the assertions, and cleans up whatever happens.
     */
    private function withScratchTable(callable $assertions): void
    {
        $pdo = self::connection();
        self::assertNotNull($pdo);

        $pdo->exec('DROP TABLE IF EXISTS microscope_probe_test');
        $pdo->exec('CREATE TABLE microscope_probe_test (id INT PRIMARY KEY AUTO_INCREMENT, body TEXT) ENGINE=InnoDB');

        $statement = $pdo->prepare('INSERT INTO microscope_probe_test (body) VALUES (?)');

        for ($i = 0; $i < 200; $i++) {
            $statement->execute([str_repeat('x', 200)]);
        }

        // information_schema serves cached statistics; without this the new table can report a
        // size of zero and the assertion fails for reasons that have nothing to do with the probe.
        //
        // `ANALYZE TABLE` returns a result set, which `exec()` would leave sitting unread on the
        // connection — MySQL then refuses every subsequent query on it. Drain it explicitly.
        $analyze = $pdo->query('ANALYZE TABLE microscope_probe_test');

        if ($analyze !== false) {
            $analyze->fetchAll();
            $analyze->closeCursor();
        }

        try {
            // A fresh probe, so it isn't serving memoized results from before the table existed.
            $this->probe = new DatabaseProbe(new PdoSqlRunner($pdo));
            $assertions();
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS microscope_probe_test');
        }
    }
}
