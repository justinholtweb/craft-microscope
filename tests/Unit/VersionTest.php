<?php

declare(strict_types=1);

use JustinHoltWeb\Microscope\Support\Version;

/**
 * Real database servers report banners like
 * `10.11.6-MariaDB-1:10.11.6+maria~ubu2204`, which `version_compare()` handles badly on its own.
 */
test('normalizes plain versions', function () {
    $this->assertSame('8.0.35', Version::normalize('8.0.35'));
    $this->assertSame('8.0.0', Version::normalize('8.0'));
    $this->assertSame('8.0.0', Version::normalize('8'));
});

test('normalizes maria db banners', function () {
    $this->assertSame(
        '10.11.6',
        Version::normalize('10.11.6-MariaDB-1:10.11.6+maria~ubu2204'),
    );
});

test('normalizes mysql banners with suffixes', function () {
    $this->assertSame('8.0.35', Version::normalize('8.0.35-0ubuntu0.22.04.1'));
    $this->assertSame('5.7.44', Version::normalize('5.7.44-log'));
});

test('normalize returns null without digits', function () {
    $this->assertNull(Version::normalize('unknown'));
    $this->assertNull(Version::normalize(null));
});

test('at least compares ignoring suffixes', function () {
    $this->assertTrue(Version::atLeast('10.11.6-MariaDB-1:10.11.6+maria~ubu2204', '10.5'));
    $this->assertTrue(Version::atLeast('8.0.35-0ubuntu0.22.04.1', '8.0'));
    $this->assertFalse(Version::atLeast('5.7.44-log', '8.0'));
});

/**
 * The one that catches people out: 8.10 is newer than 8.9, but a string comparison says
 * otherwise.
 */
test('compares minor versions numerically', function () {
    $this->assertTrue(Version::atLeast('8.10.0', '8.9'));
    $this->assertFalse(Version::lessThan('8.10.0', '8.9'));
});

test('less than', function () {
    $this->assertTrue(Version::lessThan('8.1.0', '8.3'));
    $this->assertFalse(Version::lessThan('8.3.0', '8.3'));
    $this->assertFalse(Version::lessThan('8.4.1', '8.3'));
});

test('unparseable versions are neither at least nor less than', function () {
    // Neither answer would be honest, and a check reading either as fact would report
    // something it doesn't know.
    $this->assertFalse(Version::atLeast(null, '8.0'));
    $this->assertFalse(Version::lessThan(null, '8.0'));
});

test('identifies maria db', function () {
    $this->assertTrue(Version::isMariaDb('10.11.6-MariaDB-1:10.11.6+maria~ubu2204'));
    $this->assertTrue(Version::isMariaDb('10.6.16-mariadb'));
    $this->assertFalse(Version::isMariaDb('8.0.35-0ubuntu0.22.04.1'));
    $this->assertFalse(Version::isMariaDb(null));
});
