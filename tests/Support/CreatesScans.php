<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Tests\Support;

use CraftCms\Cms\Edition;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\UserGroups;
use CraftCms\Cms\Support\Facades\UserPermissions;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\Event;
use JustinHoltWeb\Microscope\Data\ScanConfig;
use JustinHoltWeb\Microscope\Events\RegisteringChecks;
use JustinHoltWeb\Microscope\Models\Scan;
use JustinHoltWeb\Microscope\Services\Checks;
use JustinHoltWeb\Microscope\Services\Scans;

trait CreatesScans
{
    /**
     * Replaces the check catalogue with these checks, so a scan's outcome is the test's to decide.
     *
     * @param  list<FakeCheck>  $checks
     */
    protected function useChecks(array $checks): void
    {
        Event::listen(RegisteringChecks::class, function (RegisteringChecks $event) use ($checks): void {
            $event->checks = $checks;
        });

        app()->forgetInstance(Checks::class);
        app()->forgetInstance(Scans::class);
    }

    /**
     * Runs a scan with these checks.
     *
     * @param  list<FakeCheck>  $checks
     */
    protected function scanWith(array $checks, string $trigger = ScanConfig::TRIGGER_MANUAL): Scan
    {
        $this->useChecks($checks);

        return app(Scans::class)->run(new ScanConfig(trigger: $trigger));
    }

    protected function admin(): User
    {
        $user = User::find()->admin()->one();
        expect($user)->toBeInstanceOf(User::class);

        return $user;
    }

    /**
     * An active, non-admin user with exactly these permissions (plus `accessCp`).
     *
     * @param  list<string>  $permissions
     */
    protected function userWith(array $permissions, string $username = 'editor'): User
    {
        $user = new User(['username' => $username, 'email' => $username.'@example.com', 'active' => true]);
        expect(Elements::saveElement($user))->toBeTrue();

        $permissions = ['accessCp', ...$permissions];

        // On Team every non-admin shares the Team group's permissions; per-user ones are Pro.
        if (Edition::get() === Edition::Team) {
            UserPermissions::saveGroupPermissions(UserGroups::getTeamGroup()->id, $permissions);
        } else {
            UserPermissions::saveUserPermissions($user->id, $permissions);
        }

        return User::find()->id($user->id)->status(null)->one();
    }
}
