<?php

declare(strict_types=1);

namespace justinholtweb\microscope\tests\Unit;

use justinholtweb\microscope\helpers\Ini;
use PHPUnit\Framework\TestCase;

/**
 * The boolean cases are the reason this class exists. PHP returns `""` for a directive set to
 * `Off` and `"1"` for one set to `On`, but a directive written as `Off` in some contexts comes
 * back as the literal string `"Off"` — which a naive `(bool)` cast reads as **true**. That would
 * have Microscope reporting a disabled OPcache as enabled, which is the exact opposite of useful.
 */
final class IniTest extends TestCase
{
    protected function tearDown(): void
    {
        Ini::setReader(null);

        parent::tearDown();
    }

    /**
     * @param array<string, string|false> $values
     */
    private function withValues(array $values): void
    {
        Ini::setReader(fn(string $directive) => $values[$directive] ?? false);
    }

    public function testReadsStringValues(): void
    {
        $this->withValues(['some.directive' => 'hello']);

        self::assertSame('hello', Ini::get('some.directive'));
    }

    public function testUnsetDirectivesAreNull(): void
    {
        $this->withValues([]);

        self::assertNull(Ini::get('missing.directive'));
    }

    public function testEmptyStringsAreNull(): void
    {
        $this->withValues(['blank' => '']);

        self::assertNull(Ini::get('blank'));
    }

    public function testTruthyBooleanRepresentations(): void
    {
        foreach (['1', 'On', 'on', 'true', 'TRUE', 'yes'] as $value) {
            $this->withValues(['flag' => $value]);

            self::assertTrue(Ini::bool('flag'), sprintf('"%s" should read as true', $value));
        }
    }

    /**
     * `"Off"` is the case that breaks a plain boolean cast.
     */
    public function testFalsyBooleanRepresentations(): void
    {
        foreach (['0', 'Off', 'off', 'false', '', 'no'] as $value) {
            $this->withValues(['flag' => $value]);

            self::assertFalse(Ini::bool('flag'), sprintf('"%s" should read as false', $value));
        }
    }

    public function testUnsetBooleanIsFalse(): void
    {
        $this->withValues([]);

        self::assertFalse(Ini::bool('never.set'));
    }

    public function testReadsIntegers(): void
    {
        $this->withValues(['max_execution_time' => '120']);

        self::assertSame(120, Ini::int('max_execution_time'));
    }

    public function testNonNumericIntegersAreNull(): void
    {
        $this->withValues(['max_execution_time' => 'lots']);

        self::assertNull(Ini::int('max_execution_time'));
    }

    public function testReadsByteValues(): void
    {
        $this->withValues(['memory_limit' => '512M']);

        self::assertSame(536870912, Ini::bytes('memory_limit'));
    }

    /**
     * -1 has to survive: for `memory_limit` it means unlimited, which a check needs to tell apart
     * from "very small".
     */
    public function testUnlimitedByteValueIsPreserved(): void
    {
        $this->withValues(['memory_limit' => '-1']);

        self::assertSame(-1, Ini::bytes('memory_limit'));
    }

    public function testDetectsDisabledFunctions(): void
    {
        $this->withValues(['disable_functions' => 'exec,shell_exec, proc_open']);

        self::assertTrue(Ini::functionIsDisabled('shell_exec'));
        self::assertTrue(Ini::functionIsDisabled('exec'));
        self::assertTrue(Ini::functionIsDisabled('proc_open'));
    }

    public function testFunctionIsDisabledIsCaseInsensitive(): void
    {
        $this->withValues(['disable_functions' => 'SHELL_EXEC']);

        self::assertTrue(Ini::functionIsDisabled('shell_exec'));
    }

    public function testFunctionThatExistsAndIsNotListedIsAvailable(): void
    {
        $this->withValues(['disable_functions' => 'exec']);

        self::assertFalse(Ini::functionIsDisabled('strlen'));
    }

    /**
     * A function that doesn't exist counts as disabled whatever `disable_functions` says — the
     * caller only wants to know whether it can call it.
     */
    public function testMissingFunctionCountsAsDisabled(): void
    {
        $this->withValues(['disable_functions' => '']);

        self::assertTrue(Ini::functionIsDisabled('a_function_that_does_not_exist'));
    }
}
