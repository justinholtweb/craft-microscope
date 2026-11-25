<?php

declare(strict_types=1);

use JustinHoltWeb\Microscope\Support\Bytes;

/**
 * Every PHP check depends on this being right, and getting it wrong is silently wrong: a parser
 * that reads `256M` as the integer 256 makes Microscope report a 256-byte memory limit.
 */

/**
 * @return array<string, array{0: string|null, 1: int|null}>
 */
function bytesIniValues(): array
{
    return [
        'plain integer' => ['134217728', 134217728],
        'kilobytes' => ['8K', 8192],
        'lowercase kilobytes' => ['8k', 8192],
        'megabytes' => ['256M', 268435456],
        'lowercase megabytes' => ['256m', 268435456],
        'gigabytes' => ['2G', 2147483648],
        'unlimited' => ['-1', -1],
        'zero' => ['0', 0],
        'whitespace is ignored' => ['  512M  ', 536870912],
        'not a number' => ['abc', null],
        'empty' => ['', null],
        'null' => [null, null],
    ];
}

test('parses ini shorthand', function (?string $value, ?int $expected) {
    $this->assertSame($expected, Bytes::fromIni($value));
})->with(bytesIniValues());

test('formats whole numbers without decimals', function () {
    $this->assertSame('512 MB', Bytes::format(536870912));
    $this->assertSame('2 GB', Bytes::format(2147483648));
});

test('formats partial values with one decimal', function () {
    $this->assertSame('1.5 GB', Bytes::format(1610612736));
});

test('formats small values as bytes', function () {
    $this->assertSame('512 B', Bytes::format(512));
    $this->assertSame('0 B', Bytes::format(0));
});

test('formats unknown and unlimited', function () {
    $this->assertSame('—', Bytes::format(null));
    $this->assertSame('unlimited', Bytes::format(-1));
});

/**
 * The binary units matter here — a report is read next to a `php.ini`, so "256 MB" has to line
 * up with `256M` rather than with a decimal megabyte.
 */
test('format uses binary units', function () {
    $this->assertSame('1 KB', Bytes::format(1024));
    $this->assertSame('1 MB', Bytes::format(1048576));
});

test('round trips through ini shorthand', function () {
    foreach (['8K', '256M', '2G'] as $original) {
        $bytes = Bytes::fromIni($original);
        $this->assertNotNull($bytes);
        $this->assertSame($original, Bytes::toIni($bytes));
    }
});

test('to ini falls back to bytes when not a whole unit', function () {
    $this->assertSame('1500', Bytes::toIni(1500));
});
