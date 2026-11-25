<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Database;

/**
 * Microscope's table names, unprefixed: Laravel adds the connection's prefix itself.
 *
 * Here rather than on the install migration, which Craft `require`s outside the autoloader.
 */
final class Tables
{
    public const string SCANS = 'microscope_scans';

    public const string FINDINGS = 'microscope_findings';
}
