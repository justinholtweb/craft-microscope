<?php

declare(strict_types=1);

namespace justinholtweb\microscope\tests\Unit;

use justinholtweb\microscope\helpers\Bytes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every PHP check depends on this being right, and getting it wrong is silently wrong: a parser
 * that reads `256M` as the integer 256 makes Microscope report a 256-byte memory limit.
 */
final class BytesTest extends TestCase
{
    /**
     * @return array<string, array{0: string|null, 1: int|null}>
     */
    public static function iniValues(): array
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

    #[DataProvider('iniValues')]
    public function testParsesIniShorthand(?string $value, ?int $expected): void
    {
        self::assertSame($expected, Bytes::fromIni($value));
    }

    public function testFormatsWholeNumbersWithoutDecimals(): void
    {
        self::assertSame('512 MB', Bytes::format(536870912));
        self::assertSame('2 GB', Bytes::format(2147483648));
    }

    public function testFormatsPartialValuesWithOneDecimal(): void
    {
        self::assertSame('1.5 GB', Bytes::format(1610612736));
    }

    public function testFormatsSmallValuesAsBytes(): void
    {
        self::assertSame('512 B', Bytes::format(512));
        self::assertSame('0 B', Bytes::format(0));
    }

    public function testFormatsUnknownAndUnlimited(): void
    {
        self::assertSame('—', Bytes::format(null));
        self::assertSame('unlimited', Bytes::format(-1));
    }

    /**
     * The binary units matter here — a report is read next to a `php.ini`, so "256 MB" has to line
     * up with `256M` rather than with a decimal megabyte.
     */
    public function testFormatUsesBinaryUnits(): void
    {
        self::assertSame('1 KB', Bytes::format(1024));
        self::assertSame('1 MB', Bytes::format(1048576));
    }

    public function testRoundTripsThroughIniShorthand(): void
    {
        foreach (['8K', '256M', '2G'] as $original) {
            $bytes = Bytes::fromIni($original);
            self::assertNotNull($bytes);
            self::assertSame($original, Bytes::toIni($bytes));
        }
    }

    public function testToIniFallsBackToBytesWhenNotAWholeUnit(): void
    {
        self::assertSame('1500', Bytes::toIni(1500));
    }
}
