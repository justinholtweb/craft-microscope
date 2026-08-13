<?php

namespace justinholtweb\microscope\helpers;

/**
 * Byte arithmetic for values that arrive as strings.
 *
 * Almost nothing Microscope reads gives it a plain integer. `php.ini` uses shorthand (`256M`),
 * MySQL reports `SHOW VARIABLES` values as strings, and `/proc` and `free` use kilobytes. Getting
 * this wrong is silently wrong — a check that reads `memory_limit` as the integer `256` will
 * happily report a 256-byte memory limit — so it lives in one place with tests around it.
 */
final class Bytes
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    /**
     * Parses a PHP ini shorthand byte value.
     *
     * PHP's own parser is quirky in ways worth reproducing: the suffix is a single letter, only
     * the *last* character is considered, and anything unrecognised is ignored rather than being
     * an error. `-1` (unlimited) and `0` come back as-is for the caller to interpret.
     *
     * @return int|null Bytes, or null if the value isn't numeric at all.
     */
    public static function fromIni(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $suffix = strtolower(substr($value, -1));
        $number = rtrim($value, "bkmgtBKMGT \t");

        if (!is_numeric($number)) {
            return null;
        }

        $bytes = (float)$number;

        $bytes *= match ($suffix) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            't' => 1024 ** 4,
            default => 1,
        };

        return (int)$bytes;
    }

    /**
     * Formats bytes for a human, using the same binary units the config files do.
     *
     * Reports are read next to a `php.ini`, so "256 MB" needs to line up with `256M` rather than
     * with a decimal megabyte.
     */
    public static function format(?int $bytes, int $precision = 1): string
    {
        if ($bytes === null) {
            return '—';
        }

        if ($bytes < 0) {
            return 'unlimited';
        }

        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $power = min((int)floor(log($bytes, 1024)), count(self::UNITS) - 1);
        $scaled = $bytes / (1024 ** $power);

        // Whole numbers read better without a trailing .0 — "512 MB", not "512.0 MB".
        $decimals = fmod($scaled, 1) === 0.0 ? 0 : $precision;

        return number_format($scaled, $decimals) . ' ' . self::UNITS[$power];
    }

    /**
     * Formats bytes back into ini shorthand, for config snippets in remediation text.
     */
    public static function toIni(int $bytes): string
    {
        foreach ([['G', 1024 ** 3], ['M', 1024 ** 2], ['K', 1024]] as [$suffix, $size]) {
            if ($bytes >= $size && $bytes % $size === 0) {
                return ((int)($bytes / $size)) . $suffix;
            }
        }

        return (string)$bytes;
    }
}
