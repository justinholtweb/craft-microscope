<?php

declare(strict_types=1);

namespace justinholtweb\microscope\tests\Unit;

use justinholtweb\microscope\helpers\Version;
use PHPUnit\Framework\TestCase;

/**
 * Real database servers report banners like
 * `10.11.6-MariaDB-1:10.11.6+maria~ubu2204`, which `version_compare()` handles badly on its own.
 */
final class VersionTest extends TestCase
{
    public function testNormalizesPlainVersions(): void
    {
        self::assertSame('8.0.35', Version::normalize('8.0.35'));
        self::assertSame('8.0.0', Version::normalize('8.0'));
        self::assertSame('8.0.0', Version::normalize('8'));
    }

    public function testNormalizesMariaDbBanners(): void
    {
        self::assertSame(
            '10.11.6',
            Version::normalize('10.11.6-MariaDB-1:10.11.6+maria~ubu2204'),
        );
    }

    public function testNormalizesMysqlBannersWithSuffixes(): void
    {
        self::assertSame('8.0.35', Version::normalize('8.0.35-0ubuntu0.22.04.1'));
        self::assertSame('5.7.44', Version::normalize('5.7.44-log'));
    }

    public function testNormalizeReturnsNullWithoutDigits(): void
    {
        self::assertNull(Version::normalize('unknown'));
        self::assertNull(Version::normalize(null));
    }

    public function testAtLeastComparesIgnoringSuffixes(): void
    {
        self::assertTrue(Version::atLeast('10.11.6-MariaDB-1:10.11.6+maria~ubu2204', '10.5'));
        self::assertTrue(Version::atLeast('8.0.35-0ubuntu0.22.04.1', '8.0'));
        self::assertFalse(Version::atLeast('5.7.44-log', '8.0'));
    }

    /**
     * The one that catches people out: 8.10 is newer than 8.9, but a string comparison says
     * otherwise.
     */
    public function testComparesMinorVersionsNumerically(): void
    {
        self::assertTrue(Version::atLeast('8.10.0', '8.9'));
        self::assertFalse(Version::lessThan('8.10.0', '8.9'));
    }

    public function testLessThan(): void
    {
        self::assertTrue(Version::lessThan('8.1.0', '8.3'));
        self::assertFalse(Version::lessThan('8.3.0', '8.3'));
        self::assertFalse(Version::lessThan('8.4.1', '8.3'));
    }

    public function testUnparseableVersionsAreNeitherAtLeastNorLessThan(): void
    {
        // Neither answer would be honest, and a check reading either as fact would report
        // something it doesn't know.
        self::assertFalse(Version::atLeast(null, '8.0'));
        self::assertFalse(Version::lessThan(null, '8.0'));
    }

    public function testIdentifiesMariaDb(): void
    {
        self::assertTrue(Version::isMariaDb('10.11.6-MariaDB-1:10.11.6+maria~ubu2204'));
        self::assertTrue(Version::isMariaDb('10.6.16-mariadb'));
        self::assertFalse(Version::isMariaDb('8.0.35-0ubuntu0.22.04.1'));
        self::assertFalse(Version::isMariaDb(null));
    }
}
