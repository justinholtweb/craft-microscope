<?php

namespace justinholtweb\microscope\helpers;

/**
 * Reading `php.ini` values without being lied to.
 *
 * `ini_get()` returns strings, and for boolean directives it returns `"1"`, `""`, `"On"`, `"Off"`,
 * `"true"` or `"0"` depending on how the directive was written and where. A naive `(bool)` cast
 * makes `"Off"` true, which would have Microscope reporting a disabled OPcache as enabled.
 */
final class Ini
{
    /**
     * The strings PHP itself treats as true for a boolean directive.
     */
    private const TRUTHY = ['1', 'on', 'true', 'yes'];

    /**
     * @var callable(string): (string|false|null) Indirection so tests can supply ini values
     *      without changing the process's real configuration. `ini_get()` itself never returns
     *      null, but a substituted reader might, and the accessors below handle it either way.
     */
    private static $reader = 'ini_get';

    /**
     * Swaps the ini reader out. Pass null to restore `ini_get()`.
     *
     * @param callable(string): (string|false|null)|null $reader
     */
    public static function setReader(?callable $reader): void
    {
        self::$reader = $reader ?? 'ini_get';
    }

    /**
     * The raw string value of a directive, or null if it isn't set.
     */
    public static function get(string $directive): ?string
    {
        $value = (self::$reader)($directive);

        if ($value === false || $value === null) {
            return null;
        }

        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }

    /**
     * A boolean directive, read the way PHP reads it.
     *
     * Note that an *unset* directive is false, but so is an empty string — which is what PHP
     * returns for a directive explicitly set to `Off`. Both mean "not on", so both are false.
     */
    public static function bool(string $directive): bool
    {
        $value = (self::$reader)($directive);

        if ($value === false || $value === null) {
            return false;
        }

        return in_array(strtolower(trim((string)$value)), self::TRUTHY, true);
    }

    /**
     * A numeric directive.
     */
    public static function int(string $directive): ?int
    {
        $value = self::get($directive);

        return $value === null || !is_numeric($value) ? null : (int)$value;
    }

    /**
     * A byte-valued directive such as `memory_limit`, resolved from ini shorthand.
     *
     * `-1` is preserved rather than normalised away: for `memory_limit` it means unlimited, which
     * a check needs to distinguish from "very small".
     */
    public static function bytes(string $directive): ?int
    {
        return Bytes::fromIni(self::get($directive));
    }

    /**
     * Whether a function has been switched off by `disable_functions`.
     *
     * Shared hosts routinely disable `shell_exec`, `exec` and `proc_open`. Microscope has to
     * notice that and degrade rather than emit warnings — a plugin that fills the log with
     * "shell_exec() has been disabled" on every scan is worse than one that quietly reports less.
     */
    public static function functionIsDisabled(string $function): bool
    {
        $disabled = self::get('disable_functions');

        if ($disabled === null) {
            return !function_exists($function);
        }

        $list = array_map('trim', explode(',', strtolower($disabled)));

        return in_array(strtolower($function), $list, true) || !function_exists($function);
    }
}
