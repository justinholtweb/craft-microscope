<?php

declare(strict_types=1);

use JustinHoltWeb\Microscope\Probes\DatabaseProbe;
use JustinHoltWeb\Microscope\Probes\Db\PdoSqlRunner;
use PHPUnit\Framework\Assert;

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
function dbProbeConnection(): ?PDO
{
    static $attempted = false;
    static $pdo = null;

    if ($attempted) {
        return $pdo;
    }

    $attempted = true;

    $host = getenv('MICROSCOPE_DB_HOST') ?: '127.0.0.1';
    $port = getenv('MICROSCOPE_DB_PORT') ?: '3306';
    $name = getenv('MICROSCOPE_DB_NAME') ?: 'db';
    $user = getenv('MICROSCOPE_DB_USER') ?: 'db';
    $password = getenv('MICROSCOPE_DB_PASSWORD');
    $password = $password === false ? 'db' : $password;

    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s', $host, $port, $name),
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3,
            ],
        );
    } catch (Throwable) {
        $pdo = null;
    }

    return $pdo;
}

/**
 * Creates a table with enough rows for `information_schema` to report a non-zero size, runs
 * the assertions, and cleans up whatever happens.
 */
function dbProbeWithScratchTable(callable $assertions): void
{
    $pdo = dbProbeConnection();
    Assert::assertNotNull($pdo);

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
        $assertions(new DatabaseProbe(new PdoSqlRunner($pdo)));
    } finally {
        $pdo->exec('DROP TABLE IF EXISTS microscope_probe_test');
    }
}

beforeEach(function () {
    $pdo = dbProbeConnection();

    if ($pdo === null) {
        $this->markTestSkipped(
            'No MySQL/MariaDB reachable. Run this inside DDEV (`ddev test`), or set '
            .'MICROSCOPE_DB_HOST / _PORT / _NAME / _USER / _PASSWORD.',
        );
    }

    $this->probe = new DatabaseProbe(new PdoSqlRunner($pdo));
});

test('identifies the driver', function () {
    $this->assertTrue($this->probe->isMysql());
});

test('reads a version banner', function () {
    $banner = $this->probe->versionBanner();

    $this->assertIsString($banner);
    $this->assertNotSame('', $banner);
});

/**
 * The banner is what `VERSION()` actually returns — for MariaDB something like
 * `10.11.6-MariaDB-1:10.11.6+maria~ubu2204`. Normalising that is the whole point of the
 * Version helper, and this confirms it survives a real one.
 */
test('normalizes the real version', function () {
    $version = $this->probe->version();

    $this->assertIsString($version);
    $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
});

test('names the server product', function () {
    $this->assertContains($this->probe->serverName(), ['MySQL', 'MariaDB']);
    $this->assertSame(
        $this->probe->isMariaDb(),
        $this->probe->serverName() === 'MariaDB',
    );
});

test('reads server variables', function () {
    $variables = $this->probe->variables();

    $this->assertNotEmpty($variables);
    // Keys are lower-cased on the way in, so callers never have to guess the server's casing.
    $this->assertArrayHasKey('innodb_buffer_pool_size', $variables);
    $this->assertArrayHasKey('max_connections', $variables);
});

test('variable lookup is case insensitive', function () {
    $this->assertSame(
        $this->probe->variable('innodb_buffer_pool_size'),
        $this->probe->variable('INNODB_BUFFER_POOL_SIZE'),
    );
});

test('reads integer variables', function () {
    $poolSize = $this->probe->intVariable('innodb_buffer_pool_size');

    $this->assertIsInt($poolSize);
    $this->assertGreaterThan(0, $poolSize);
});

test('reads boolean variables', function () {
    // `autocommit` is ON by default everywhere, which makes it a safe assertion about the
    // ON/OFF parsing rather than about anyone's configuration.
    $this->assertTrue($this->probe->boolVariable('autocommit'));
});

test('unknown variables are null', function () {
    $this->assertNull($this->probe->variable('microscope_not_a_real_variable'));
    $this->assertNull($this->probe->intVariable('microscope_not_a_real_variable'));
    $this->assertNull($this->probe->boolVariable('microscope_not_a_real_variable'));
});

test('reads global status', function () {
    $status = $this->probe->status();

    $this->assertNotEmpty($status);
    $this->assertArrayHasKey('uptime', $status);
});

test('reads uptime', function () {
    $uptime = $this->probe->uptime();

    $this->assertIsInt($uptime);
    $this->assertGreaterThanOrEqual(0, $uptime);
});

test('status values are integers', function () {
    $requests = $this->probe->statusValue('innodb_buffer_pool_read_requests');

    $this->assertIsInt($requests);
});

/**
 * `TABLE_ROWS` and friends need aliasing — `ROWS` is a reserved word in MySQL 8 — and the
 * columns come back as strings. Both are easy to get wrong and silent when you do.
 */
test('reads table sizes', function () {
    dbProbeWithScratchTable(function (DatabaseProbe $probe) {
        $this->probe = $probe;

        $tables = $this->probe->tables();

        $this->assertNotEmpty($tables, 'information_schema returned no tables.');

        $names = array_column($tables, 'name');
        $this->assertContains('microscope_probe_test', $names);

        $table = $this->probe->table('microscope_probe_test');
        $this->assertIsArray($table);
        $this->assertIsInt($table['data']);
        $this->assertIsInt($table['index']);
        $this->assertSame($table['data'] + $table['index'], $table['total']);
    });
});

test('tables are ordered largest first', function () {
    dbProbeWithScratchTable(function (DatabaseProbe $probe) {
        $this->probe = $probe;

        $totals = array_column($this->probe->tables(), 'total');
        $sorted = $totals;
        rsort($sorted);

        $this->assertSame($sorted, $totals);
    });
});

test('total size sums the tables', function () {
    dbProbeWithScratchTable(function (DatabaseProbe $probe) {
        $this->probe = $probe;

        $this->assertSame(
            array_sum(array_column($this->probe->tables(), 'total')),
            $this->probe->totalSize(),
        );
    });
});

test('unknown tables are null', function () {
    $this->assertNull($this->probe->table('microscope_no_such_table'));
    $this->assertSame(0, $this->probe->tableSize('microscope_no_such_table'));
});

test('detects non innodb tables', function () {
    $pdo = dbProbeConnection();
    $this->assertNotNull($pdo);

    $pdo->exec('DROP TABLE IF EXISTS microscope_myisam_test');
    $pdo->exec('CREATE TABLE microscope_myisam_test (id INT PRIMARY KEY) ENGINE=MyISAM');

    try {
        $probe = new DatabaseProbe(new PdoSqlRunner($pdo));

        $this->assertContains('microscope_myisam_test', $probe->nonInnodbTables());
    } finally {
        $pdo->exec('DROP TABLE IF EXISTS microscope_myisam_test');
    }
});

/**
 * A restricted user — which managed database services routinely hand out — must produce empty
 * results rather than an exception. The whole failure mode of this category is "can't see",
 * and it has to be survivable.
 */
test('failed queries degrade to empty results', function () {
    $pdo = dbProbeConnection();
    $this->assertNotNull($pdo);

    $runner = new PdoSqlRunner($pdo);

    $this->assertSame([], $runner->queryAll('SELECT * FROM a_table_that_does_not_exist'));
    $this->assertNull($runner->queryScalar('SELECT * FROM a_table_that_does_not_exist'));
});

test('summary describes the server', function () {
    $summary = $this->probe->summary();

    $this->assertArrayHasKey('server', $summary);
    $this->assertArrayHasKey('version', $summary);
    $this->assertArrayHasKey('size', $summary);
    $this->assertArrayHasKey('uptime', $summary);
});
