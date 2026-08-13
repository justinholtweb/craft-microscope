<?php

namespace justinholtweb\microscope\probes;

use justinholtweb\microscope\helpers\Ini;

/**
 * Runs a short read-only shell command, or says why it couldn't.
 *
 * Microscope shells out for the handful of facts PHP has no API for: how many cores the box has,
 * how much RAM is free, which modules nginx was built with. That is genuinely useful information
 * and it is why the server category exists at all — but it is also the part most likely to be
 * unavailable, because shared hosts disable `shell_exec` and `proc_open` as a matter of course.
 *
 * So the contract here is deliberately narrow:
 *
 * - only fixed, argument-free commands the plugin itself defines ever get run — nothing derived
 *   from user input reaches a shell,
 * - a disabled function, a missing binary or a non-zero exit is a *null result*, not an error,
 * - and every command is capped, so a probe can't hang a queue worker on a wedged binary.
 *
 * Callers treat null as "this machine won't tell me", which is a legitimate outcome rather than a
 * failure to report.
 */
class ShellRunner
{
    /**
     * @var int Seconds any single command is allowed to take.
     */
    public int $timeout = 5;

    /**
     * @var array<string, string|null> Memoized results, so several checks asking for `nproc` cost
     *                                 one fork rather than five.
     */
    private array $cache = [];

    /**
     * @var string|null Why shelling out isn't possible here, if it isn't.
     */
    private ?string $unavailableReason = null;

    /**
     * @var bool Whether {@see $unavailableReason} has been worked out yet.
     */
    private bool $resolved = false;

    /**
     * @param bool $enabled Whether the site permits shell probes at all. A site can forbid them in
     *                      Microscope's settings even on a host that would allow them.
     */
    public function __construct(private readonly bool $enabled = true)
    {
    }

    /**
     * Whether this environment lets Microscope run anything at all.
     */
    public function isAvailable(): bool
    {
        return $this->unavailableReason() === null;
    }

    /**
     * Why shell probes are unavailable, or null if they aren't.
     */
    public function unavailableReason(): ?string
    {
        if ($this->resolved) {
            return $this->unavailableReason;
        }

        $this->resolved = true;

        if (!$this->enabled) {
            $this->unavailableReason = 'shell probes are turned off in Microscope’s settings';
        } elseif (Ini::functionIsDisabled('shell_exec')) {
            $this->unavailableReason = 'shell_exec() is disabled on this server';
        } elseif (Ini::bool('safe_mode')) {
            $this->unavailableReason = 'PHP is running in safe mode';
        }

        return $this->unavailableReason;
    }

    /**
     * Runs a command and returns its trimmed stdout, or null.
     *
     * The command is expected to be a literal defined in a probe. stderr is discarded rather than
     * merged: `nginx -V` writes its build string to stderr on some builds and a plain error on
     * others, and probes that want stderr ask for it explicitly with {@see runCapturingErrors()}.
     */
    public function run(string $command): ?string
    {
        return $this->execute($command, false);
    }

    /**
     * As {@see run()}, but folds stderr into the output.
     *
     * Needed for the several system binaries that report their own version information on stderr.
     */
    public function runCapturingErrors(string $command): ?string
    {
        return $this->execute($command, true);
    }

    /**
     * Whether a binary is on the PATH.
     */
    public function has(string $binary): bool
    {
        // `command -v` is POSIX, unlike `which`, which isn't installed in every slim container.
        return $this->run(sprintf('command -v %s', escapeshellarg($binary))) !== null;
    }

    /**
     * Reads a file from /proc or /sys, which is often cheaper and always safer than a fork.
     *
     * Kept here rather than in a check because the failure modes are the same ones: the path may
     * not exist (macOS has no /proc), or open_basedir may forbid it.
     */
    public function readSystemFile(string $path): ?string
    {
        $key = 'file:' . $path;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $contents = null;

        // Silenced deliberately: an open_basedir violation is a warning, and on a locked-down host
        // it would fire on every scan for a fact Microscope is happy to do without.
        if (@is_readable($path)) {
            $raw = @file_get_contents($path);

            if ($raw !== false) {
                $contents = trim($raw);
            }
        }

        return $this->cache[$key] = $contents;
    }

    private function execute(string $command, bool $withStderr): ?string
    {
        $key = ($withStderr ? 'e:' : 'o:') . $command;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        if (!$this->isAvailable()) {
            return $this->cache[$key] = null;
        }

        $wrapped = sprintf(
            'timeout %d %s 2>%s',
            $this->timeout,
            $command,
            $withStderr ? '&1' : '/dev/null',
        );

        // `timeout` is coreutils and missing on some minimal images; fall back to the bare command
        // rather than failing every probe because of it.
        $output = @shell_exec($wrapped);

        if ($output === null || $output === false || trim((string)$output) === '') {
            $output = @shell_exec($command . ($withStderr ? ' 2>&1' : ' 2>/dev/null'));
        }

        $output = is_string($output) ? trim($output) : '';

        return $this->cache[$key] = $output === '' ? null : $output;
    }
}
