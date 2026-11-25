<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Checks;

use DateTimeImmutable;
use DateTimeInterface;
use JustinHoltWeb\Microscope\Data\ScanConfig;
use JustinHoltWeb\Microscope\Models\Settings;
use JustinHoltWeb\Microscope\Probes\CraftProbe;
use JustinHoltWeb\Microscope\Probes\DatabaseProbe;
use JustinHoltWeb\Microscope\Probes\Db\LaravelSqlRunner;
use JustinHoltWeb\Microscope\Probes\PhpProbe;
use JustinHoltWeb\Microscope\Probes\ServerProbe;
use JustinHoltWeb\Microscope\Probes\ShellRunner;
use Throwable;

/**
 * Everything a check is given to work with.
 *
 * Checks never reach for Craft's services or `ini_get()` directly — they go through the probes on
 * here. That is partly tidiness and mostly testability: a probe can be substituted, so a check's
 * thresholds can be tested against a fabricated server without one existing.
 *
 * The probes memoize, so ten checks reading `SHOW VARIABLES` cost one query between them.
 */
class CheckContext
{
    public function __construct(
        public readonly Settings $settings,
        public readonly ScanConfig $config,
        public readonly PhpProbe $php,
        public readonly DatabaseProbe $database,
        public readonly ServerProbe $server,
        public readonly CraftProbe $craft,
    ) {}

    /**
     * The real probes for this site: the default database connection, and a shell runner that
     * honours `allowShellProbes` — the one place that decides whether Microscope may run a command.
     */
    public static function make(Settings $settings, ScanConfig $config): self
    {
        return new self(
            settings: $settings,
            config: $config,
            php: new PhpProbe,
            database: new DatabaseProbe(new LaravelSqlRunner),
            server: new ServerProbe(new ShellRunner($settings->allowShellProbes)),
            craft: new CraftProbe,
        );
    }

    /**
     * Whether this environment should be held to production standards.
     *
     * Several checks are only meaningful in production — debug mode is *supposed* to be on locally,
     * and telling a developer off for it on every scan would train them to ignore the report.
     */
    public function isProduction(): bool
    {
        return $this->craft->isProduction();
    }

    /**
     * What the probes measured, stored with the scan so a report can show the environment it ran
     * in. A probe that can't answer contributes an empty section rather than failing the scan.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $section = function (callable $summary): array {
            try {
                return $summary();
            } catch (Throwable) {
                return [];
            }
        };

        return [
            'php' => $section($this->php->summary(...)),
            'database' => $section($this->database->summary(...)),
            'server' => $section($this->server->summary(...)),
            'craft' => $section($this->craft->summary(...)),
            'scannedAt' => new DateTimeImmutable()->format(DateTimeInterface::ATOM),
        ];
    }
}
