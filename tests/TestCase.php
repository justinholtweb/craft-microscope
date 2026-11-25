<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Tests;

use CraftCms\Cms\Edition;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use Illuminate\Support\Facades\DB;
use JustinHoltWeb\Microscope\Tests\Support\CreatesScans;
use Override;

abstract class TestCase extends PluginTestCase
{
    use CreatesScans;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Solo allows one user account, and the permission tests need a second.
        Edition::set(Edition::Team);
    }

    /**
     * SQLite only enforces foreign keys when asked, and ignores the pragma inside a transaction,
     * so it's set before each test's transaction. `microscope_findings.scanId` cascades.
     */
    #[Override]
    public function beginDatabaseTransaction(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }

        parent::beginDatabaseTransaction();
    }
}
