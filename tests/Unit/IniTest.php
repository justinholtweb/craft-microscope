<?php

declare(strict_types=1);

use JustinHoltWeb\Microscope\Support\Ini;

/**
 * The boolean cases are the reason this class exists. PHP returns `""` for a directive set to
 * `Off` and `"1"` for one set to `On`, but a directive written as `Off` in some contexts comes
 * back as the literal string `"Off"` — which a naive `(bool)` cast reads as **true**. That would
 * have Microscope reporting a disabled OPcache as enabled, which is the exact opposite of useful.
 */

/**
 * @param  array<string, string|false>  $values
 */
function iniWithValues(array $values): void
{
    Ini::setReader(fn (string $directive) => $values[$directive] ?? false);
}

afterEach(function () {
    Ini::setReader(null);
});

test('reads string values', function () {
    iniWithValues(['some.directive' => 'hello']);

    $this->assertSame('hello', Ini::get('some.directive'));
});

test('unset directives are null', function () {
    iniWithValues([]);

    $this->assertNull(Ini::get('missing.directive'));
});

test('empty strings are null', function () {
    iniWithValues(['blank' => '']);

    $this->assertNull(Ini::get('blank'));
});

test('truthy boolean representations', function () {
    foreach (['1', 'On', 'on', 'true', 'TRUE', 'yes'] as $value) {
        iniWithValues(['flag' => $value]);

        $this->assertTrue(Ini::bool('flag'), sprintf('"%s" should read as true', $value));
    }
});

/**
 * `"Off"` is the case that breaks a plain boolean cast.
 */
test('falsy boolean representations', function () {
    foreach (['0', 'Off', 'off', 'false', '', 'no'] as $value) {
        iniWithValues(['flag' => $value]);

        $this->assertFalse(Ini::bool('flag'), sprintf('"%s" should read as false', $value));
    }
});

test('unset boolean is false', function () {
    iniWithValues([]);

    $this->assertFalse(Ini::bool('never.set'));
});

test('reads integers', function () {
    iniWithValues(['max_execution_time' => '120']);

    $this->assertSame(120, Ini::int('max_execution_time'));
});

test('non numeric integers are null', function () {
    iniWithValues(['max_execution_time' => 'lots']);

    $this->assertNull(Ini::int('max_execution_time'));
});

test('reads byte values', function () {
    iniWithValues(['memory_limit' => '512M']);

    $this->assertSame(536870912, Ini::bytes('memory_limit'));
});

/**
 * -1 has to survive: for `memory_limit` it means unlimited, which a check needs to tell apart
 * from "very small".
 */
test('unlimited byte value is preserved', function () {
    iniWithValues(['memory_limit' => '-1']);

    $this->assertSame(-1, Ini::bytes('memory_limit'));
});

test('detects disabled functions', function () {
    iniWithValues(['disable_functions' => 'exec,shell_exec, proc_open']);

    $this->assertTrue(Ini::functionIsDisabled('shell_exec'));
    $this->assertTrue(Ini::functionIsDisabled('exec'));
    $this->assertTrue(Ini::functionIsDisabled('proc_open'));
});

test('function is disabled is case insensitive', function () {
    iniWithValues(['disable_functions' => 'SHELL_EXEC']);

    $this->assertTrue(Ini::functionIsDisabled('shell_exec'));
});

test('function that exists and is not listed is available', function () {
    iniWithValues(['disable_functions' => 'exec']);

    $this->assertFalse(Ini::functionIsDisabled('strlen'));
});

/**
 * A function that doesn't exist counts as disabled whatever `disable_functions` says — the
 * caller only wants to know whether it can call it.
 */
test('missing function counts as disabled', function () {
    iniWithValues(['disable_functions' => '']);

    $this->assertTrue(Ini::functionIsDisabled('a_function_that_does_not_exist'));
});
