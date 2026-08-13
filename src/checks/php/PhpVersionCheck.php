<?php

namespace justinholtweb\microscope\checks\php;

use Craft;
use justinholtweb\microscope\checks\BaseCheck;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\helpers\Version;
use justinholtweb\microscope\models\Category;

/**
 * How old the PHP runtime is.
 *
 * PHP's own performance improves measurably between minor versions, so this is one of the few
 * findings where the fix is a single number in a config file and the payoff is site-wide.
 */
class PhpVersionCheck extends BaseCheck
{
    /**
     * Below this, the runtime is missing years of optimizer work — and, more pressingly, security
     * support.
     */
    private const RECOMMENDED = '8.3';

    public function handle(): string
    {
        return 'phpVersion';
    }

    public function category(): string
    {
        return Category::PHP;
    }

    public function label(): string
    {
        return Craft::t('microscope', 'PHP version');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Checks the PHP version against the one Craft performs best on, and against PHP’s own support schedule.');
    }

    public function run(CheckContext $context): array
    {
        $version = $context->php->version();
        $findings = [];

        if (Version::lessThan($version, self::RECOMMENDED)) {
            $findings[] = $this->warning([
                'title' => Craft::t('microscope', 'PHP is older than recommended'),
                'summary' => Craft::t('microscope', 'This site is running PHP {version}. Craft 5 runs measurably faster on {recommended} and later.', [
                    'version' => $version,
                    'recommended' => self::RECOMMENDED,
                ]),
                'observed' => $version,
                'expected' => self::RECOMMENDED . '+',
                'target' => 'php.version',
                'impact' => Craft::t('microscope', 'Each PHP minor release since 8.0 has brought real interpreter improvements — faster property access, a better JIT, cheaper string handling. Staying a version or two behind typically costs 5–15% of raw request time across the whole site, on every single request, for no benefit.'),
                'remediation' => $this->upgradeInstructions($version),
                'docsUrl' => 'https://www.php.net/supported-versions.php',
            ]);
        } else {
            $findings[] = $this->pass(
                Craft::t('microscope', 'PHP is a current version'),
                $version,
                Craft::t('microscope', 'PHP {version} is recent enough that the runtime is not the bottleneck.', ['version' => $version]),
            );
        }

        if (!$context->php->is64Bit()) {
            $findings[] = $this->warning([
                'title' => Craft::t('microscope', 'PHP is running in 32-bit mode'),
                'summary' => Craft::t('microscope', 'A 32-bit PHP build caps process memory at around 2 GB and cannot represent large integers or file sizes.'),
                'observed' => '32-bit',
                'expected' => '64-bit',
                'target' => 'php.architecture',
                'impact' => Craft::t('microscope', 'Memory limits above 2 GB have no effect, file operations break above 2 GB, and any timestamp arithmetic past 2038 is wrong. On a content site this shows up first as image transforms of large uploads failing outright.'),
                'remediation' => "Install a 64-bit PHP build. On any mainstream host this is the default and a 32-bit build means either a very old server or a deliberately unusual one — worth asking your host about directly, because nothing you can change in configuration will work around it.",
            ]);
        }

        return $findings;
    }

    private function upgradeInstructions(string $current): string
    {
        return <<<MD
This site is on PHP {$current}. Move it to PHP 8.3 or 8.4.

**Check Craft and your plugins first.** Craft 5 supports PHP 8.2–8.4. Run this to see whether
anything you depend on objects:

```bash
composer why-not php 8.3
```

**Then switch the runtime.** How depends on where the site runs:

- **DDEV** — set `php_version: "8.3"` in `.ddev/config.yaml`, then `ddev restart`.
- **Forge / Ploi** — change the PHP version for the site in the panel, then restart PHP-FPM.
- **cPanel / Plesk** — "Select PHP Version" or "PHP Settings" for the domain.
- **Docker** — bump the base image tag, and check any `docker-php-ext-install` lines still apply.

**Afterwards**, confirm the *web* SAPI moved too — `php -v` on the CLI and the version PHP-FPM
runs are configured separately, and they disagree more often than you'd expect. Craft's
**Utilities → System Report** shows the version actually serving the control panel.
MD;
    }
}
