<?php

namespace justinholtweb\microscope\helpers;

/**
 * Version comparison against the strings servers actually report.
 *
 * `SELECT VERSION()` on MariaDB returns things like `10.11.6-MariaDB-1:10.11.6+maria~ubu2204`,
 * and on a MySQL-compatible proxy it can return almost anything. `version_compare()` handles the
 * numeric part fine but is thrown by the vendor suffix, so everything is normalised down to
 * `major.minor.patch` before it gets compared.
 */
final class Version
{
    /**
     * Pulls the first `x`, `x.y` or `x.y.z` out of a version banner.
     */
    public static function normalize(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }

        if (!preg_match('/(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $version, $matches)) {
            return null;
        }

        return sprintf(
            '%d.%d.%d',
            (int)$matches[1],
            (int)($matches[2] ?? 0),
            (int)($matches[3] ?? 0),
        );
    }

    /**
     * Whether `$version` is at least `$minimum`, ignoring any vendor suffix on either side.
     */
    public static function atLeast(?string $version, string $minimum): bool
    {
        $normalized = self::normalize($version);
        $target = self::normalize($minimum);

        if ($normalized === null || $target === null) {
            return false;
        }

        return version_compare($normalized, $target, '>=');
    }

    public static function lessThan(?string $version, string $maximum): bool
    {
        $normalized = self::normalize($version);
        $target = self::normalize($maximum);

        if ($normalized === null || $target === null) {
            return false;
        }

        return version_compare($normalized, $target, '<');
    }

    /**
     * Whether a version banner is MariaDB rather than MySQL.
     *
     * The two have diverged enough that several checks give different advice for each — MariaDB
     * still has a query cache worth turning off, MySQL 8 removed it entirely — so the distinction
     * has to be reliable.
     */
    public static function isMariaDb(?string $banner): bool
    {
        return $banner !== null && stripos($banner, 'mariadb') !== false;
    }
}
