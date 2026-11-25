<?php

declare(strict_types=1);

use JustinHoltWeb\Microscope\Checks\CheckContext;
use JustinHoltWeb\Microscope\Checks\Craft\CacheConfigCheck;
use JustinHoltWeb\Microscope\Checks\Craft\DevModeCheck;
use JustinHoltWeb\Microscope\Checks\Craft\LaravelOptimizationCheck;
use JustinHoltWeb\Microscope\Checks\Craft\LogLevelCheck;
use JustinHoltWeb\Microscope\Checks\Craft\QueueRunnerCheck;
use JustinHoltWeb\Microscope\Checks\Craft\SessionDriverCheck;
use JustinHoltWeb\Microscope\Data\Finding;
use JustinHoltWeb\Microscope\Data\ScanConfig;
use JustinHoltWeb\Microscope\Models\Settings;
use JustinHoltWeb\Microscope\Probes\CraftProbe;
use JustinHoltWeb\Microscope\Probes\DatabaseProbe;
use JustinHoltWeb\Microscope\Probes\Db\SqlRunnerInterface;
use JustinHoltWeb\Microscope\Probes\PhpProbe;
use JustinHoltWeb\Microscope\Probes\ServerProbe;
use JustinHoltWeb\Microscope\Probes\ShellRunner;

/*
 | The Craft-category checks that judge Laravel's services, against a CraftProbe whose answers are
 | set per test — so production-only findings can be exercised without a production server.
 */

/**
 * @param  array<string, mixed>  $answers  CraftProbe method => return value.
 */
function laravelChecksContext(array $answers, array $extensions = []): CheckContext
{
    $craft = new class($answers) extends CraftProbe
    {
        /** @param array<string, mixed> $answers */
        public function __construct(private array $answers) {}

        public function __call(string $name, array $arguments): mixed
        {
            return $this->answers[$name] ?? null;
        }

        public function isProduction(): bool
        {
            return $this->answers['isProduction'] ?? true;
        }

        public function environment(): string
        {
            return $this->answers['environment'] ?? 'production';
        }

        public function devMode(): bool
        {
            return $this->answers['devMode'] ?? false;
        }

        public function appDebug(): bool
        {
            return $this->answers['appDebug'] ?? false;
        }

        public function allowAdminChanges(): bool
        {
            return $this->answers['allowAdminChanges'] ?? false;
        }

        public function templateCachingEnabled(): bool
        {
            return $this->answers['templateCachingEnabled'] ?? true;
        }

        public function cacheDuration(): ?int
        {
            return $this->answers['cacheDuration'] ?? 86400;
        }

        public function cacheStore(): string
        {
            return $this->answers['cacheStore'] ?? 'redis';
        }

        public function cacheDriver(): string
        {
            return $this->answers['cacheDriver'] ?? 'redis';
        }

        public function sessionDriver(): string
        {
            return $this->answers['sessionDriver'] ?? 'redis';
        }

        public function queueConnection(): string
        {
            return $this->answers['queueConnection'] ?? 'redis';
        }

        public function queueDriver(): string
        {
            return $this->answers['queueDriver'] ?? 'redis';
        }

        public function runQueueAutomatically(): bool
        {
            return $this->answers['runQueueAutomatically'] ?? false;
        }

        public function queueDepth(): array
        {
            return $this->answers['queueDepth'] ?? ['waiting' => 0, 'failed' => 0];
        }

        public function logChannel(): string
        {
            return 'stack';
        }

        public function logChannels(): array
        {
            return $this->answers['logChannels'] ?? ['daily' => ['driver' => 'daily', 'level' => 'warning']];
        }

        public function configurationIsCached(): bool
        {
            return $this->answers['configurationIsCached'] ?? true;
        }

        public function routesAreCached(): bool
        {
            return $this->answers['routesAreCached'] ?? true;
        }

        public function eventsAreCached(): bool
        {
            return $this->answers['eventsAreCached'] ?? true;
        }
    };

    $php = new class($extensions) extends PhpProbe
    {
        /** @param list<string> $extensions */
        public function __construct(private array $extensions) {}

        public function hasExtension(string $name): bool
        {
            return in_array($name, $this->extensions, true);
        }
    };

    $sql = new class implements SqlRunnerInterface
    {
        public function queryAll(string $sql, array $params = []): array
        {
            return [];
        }

        public function queryScalar(string $sql, array $params = []): mixed
        {
            return null;
        }

        public function driver(): string
        {
            return 'sqlite';
        }

        public function databaseName(): ?string
        {
            return null;
        }

        public function tablePrefix(): string
        {
            return '';
        }
    };

    return new CheckContext(new Settings, new ScanConfig, $php, new DatabaseProbe($sql), new ServerProbe(new ShellRunner(false)), $craft);
}

/**
 * @param  list<Finding>  $findings
 * @return list<string>
 */
function laravelChecksSeverities(array $findings): array
{
    return array_map(fn (Finding $f) => $f->severity, $findings);
}

test('debug mode in production is critical, and names APP_DEBUG', function () {
    $findings = (new DevModeCheck)->run(laravelChecksContext(['devMode' => true, 'appDebug' => true]));

    $this->assertSame(['critical'], laravelChecksSeverities($findings));
    $this->assertSame('APP_DEBUG = true', $findings[0]->observed);
    $this->assertSame('devMode', $findings[0]->target);
});

test('debug mode is not judged outside production', function () {
    $findings = (new DevModeCheck)->run(laravelChecksContext(['isProduction' => false, 'devMode' => true, 'environment' => 'local']));

    $this->assertSame(['pass'], laravelChecksSeverities($findings));
});

test('missing bootstrap caches are a warning when config is uncached, a notice otherwise', function () {
    $check = new LaravelOptimizationCheck;

    $this->assertSame(['warning'], laravelChecksSeverities($check->run(laravelChecksContext(['configurationIsCached' => false]))));
    $this->assertSame(['notice'], laravelChecksSeverities($check->run(laravelChecksContext(['routesAreCached' => false]))));
    $this->assertSame(['pass'], laravelChecksSeverities($check->run(laravelChecksContext([]))));
    $this->assertSame(['pass'], laravelChecksSeverities($check->run(laravelChecksContext(['isProduction' => false, 'configurationIsCached' => false]))));
});

test('the array cache store is critical in production', function () {
    $findings = (new CacheConfigCheck)->run(laravelChecksContext(['cacheDriver' => 'array', 'cacheStore' => 'array']));

    $this->assertSame(['critical'], laravelChecksSeverities($findings));
    $this->assertSame('cache.driver', $findings[0]->target);
});

test('a file cache is a warning only when a faster store is installed', function () {
    $check = new CacheConfigCheck;

    $this->assertSame(['warning'], laravelChecksSeverities($check->run(laravelChecksContext(['cacheDriver' => 'file', 'cacheStore' => 'file'], ['redis']))));
    $this->assertSame(['notice'], laravelChecksSeverities($check->run(laravelChecksContext(['cacheDriver' => 'file', 'cacheStore' => 'file']))));
    $this->assertSame(['pass'], laravelChecksSeverities($check->run(laravelChecksContext([]))));
});

test('session drivers', function () {
    $check = new SessionDriverCheck;

    $this->assertSame(['critical'], laravelChecksSeverities($check->run(laravelChecksContext(['sessionDriver' => 'array']))));
    $this->assertSame(['notice'], laravelChecksSeverities($check->run(laravelChecksContext(['sessionDriver' => 'file'], ['redis']))));
    $this->assertSame(['pass'], laravelChecksSeverities($check->run(laravelChecksContext(['sessionDriver' => 'file']))));
    $this->assertSame(['notice'], laravelChecksSeverities($check->run(laravelChecksContext(['sessionDriver' => 'cookie']))));
    $this->assertSame(['pass'], laravelChecksSeverities($check->run(laravelChecksContext(['sessionDriver' => 'database']))));
});

test('a sync queue in production is a warning, and outranks runQueueAutomatically', function () {
    $findings = (new QueueRunnerCheck)->run(laravelChecksContext([
        'queueDriver' => 'sync',
        'queueConnection' => 'sync',
        'runQueueAutomatically' => true,
        'queueDepth' => ['waiting' => null, 'failed' => null],
    ]));

    $this->assertSame(['warning'], laravelChecksSeverities($findings));
    $this->assertSame('queue.driver', $findings[0]->target);
});

test('the queue reports in-request running, backlogs and failures', function () {
    $findings = (new QueueRunnerCheck)->run(laravelChecksContext([
        'queueDriver' => 'database',
        'runQueueAutomatically' => true,
        'queueDepth' => ['waiting' => 500, 'failed' => 3],
    ]));

    $this->assertSame(['runQueueAutomatically', 'queue.backlog', 'queue.failed'], array_map(fn (Finding $f) => $f->target, $findings));
});

test('an unmeasurable queue still passes cleanly', function () {
    $findings = (new QueueRunnerCheck)->run(laravelChecksContext(['queueDepth' => ['waiting' => null, 'failed' => null]]));

    $this->assertSame(['pass'], laravelChecksSeverities($findings));
});

test('debug-level logging in production is a warning on file channels and a notice elsewhere', function () {
    $check = new LogLevelCheck;

    $this->assertSame(['warning'], laravelChecksSeverities($check->run(laravelChecksContext(['logChannels' => ['daily' => ['driver' => 'daily', 'level' => 'debug']]]))));
    $this->assertSame(['notice'], laravelChecksSeverities($check->run(laravelChecksContext(['logChannels' => ['papertrail' => ['driver' => 'monolog', 'level' => 'info']]]))));
    $this->assertSame(['pass'], laravelChecksSeverities($check->run(laravelChecksContext([]))));
    $this->assertSame(['pass'], laravelChecksSeverities($check->run(laravelChecksContext(['isProduction' => false, 'logChannels' => ['daily' => ['driver' => 'daily', 'level' => 'debug']]]))));
});
