<?php

namespace justinholtweb\microscope\checks\php;

use Craft;
use justinholtweb\microscope\checks\BaseCheck;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\helpers\Bytes;
use justinholtweb\microscope\models\Category;

/**
 * Whether PHP has enough memory for the work Craft asks of it — and not so much that a runaway
 * request can take the server down with it.
 */
class MemoryLimitCheck extends BaseCheck
{
    private const MINIMUM = 256 * 1024 * 1024;
    private const COMFORTABLE = 512 * 1024 * 1024;

    /**
     * Above this, the limit has stopped being a safety net.
     */
    private const EXCESSIVE = 2048 * 1024 * 1024;

    public function handle(): string
    {
        return 'memoryLimit';
    }

    public function category(): string
    {
        return Category::PHP;
    }

    public function label(): string
    {
        return Craft::t('microscope', 'Memory limit');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Checks PHP’s memory limit against what Craft needs for image transforms, project config and large queries.');
    }

    public function run(CheckContext $context): array
    {
        $limit = $context->php->memoryLimit();

        if ($limit === null) {
            return [];
        }

        if ($limit === -1) {
            return [$this->notice([
                'title' => Craft::t('microscope', 'PHP memory limit is unlimited'),
                'summary' => Craft::t('microscope', '`memory_limit` is set to -1, so a single request can consume as much memory as the machine has.'),
                'observed' => 'unlimited',
                'expected' => Bytes::format(self::COMFORTABLE),
                'target' => 'memory_limit',
                'impact' => Craft::t('microscope', 'This isn’t a performance problem so much as a stability one: one runaway request — an image transform on a huge upload, a query that returns more than expected — can exhaust the machine and take every other PHP worker with it. A limit turns that into one failed request.'),
                'remediation' => "Set a real ceiling in `php.ini`:\n\n```ini\nmemory_limit = 512M\n```\n\nThen raise it selectively where it's genuinely needed — Craft's console commands can be given their own value:\n\n```bash\nphp -d memory_limit=1G craft resave/entries\n```",
            ])];
        }

        if ($limit < self::MINIMUM) {
            return [$this->finding($this->severityForRatio($limit / self::MINIMUM), [
                'title' => Craft::t('microscope', 'PHP memory limit is below Craft’s minimum'),
                'summary' => Craft::t('microscope', 'Craft requires at least {minimum}; this server allows {observed}.', [
                    'minimum' => Bytes::format(self::MINIMUM),
                    'observed' => Bytes::format($limit),
                ]),
                'observed' => Bytes::format($limit),
                'expected' => Bytes::format(self::COMFORTABLE),
                'target' => 'memory_limit',
                'impact' => Craft::t('microscope', 'Requests that exceed the limit are killed outright with a fatal error — no template, no error page, just a blank 500. In Craft this shows up first on image transforms of large uploads, on project config applies, and on any query that returns more elements than expected.'),
                'remediation' => $this->instructions(),
                'docsUrl' => 'https://craftcms.com/docs/5.x/system/requirements.html',
            ])];
        }

        if ($limit >= self::EXCESSIVE) {
            return [$this->notice([
                'title' => Craft::t('microscope', 'PHP memory limit is very high'),
                'summary' => Craft::t('microscope', 'At {observed}, the limit is high enough that a few simultaneous heavy requests could exhaust the machine.', [
                    'observed' => Bytes::format($limit),
                ]),
                'observed' => Bytes::format($limit),
                'expected' => Bytes::format(self::COMFORTABLE),
                'target' => 'memory_limit',
                'impact' => Craft::t('microscope', 'A limit this high is usually there to make a specific error go away. It works, but it moves the failure from one predictable request to the whole server under load — and it hides the underlying problem, which is normally an unbounded query or an oversized image.'),
                'remediation' => "Bring the web limit back to something like `512M` and find what needed more.\n\nThe usual culprits in Craft:\n\n- **An element query with no `.limit()`** loading a whole section into memory. Microscope's template category will point these out.\n- **Image transforms on very large source files.** Set `maxUploadFileSize` and consider `'imageDriver' => 'imagick'`, which streams rather than loading whole bitmaps.\n- **`project-config/apply` on a large config.** Give the console its own higher limit rather than raising it for web requests: `php -d memory_limit=1G craft project-config/apply`.",
            ])];
        }

        return [$this->pass(
            Craft::t('microscope', 'PHP memory limit is adequate'),
            Bytes::format($limit),
        )];
    }

    private function instructions(): string
    {
        return <<<'MD'
Raise it in `php.ini`:

```ini
memory_limit = 512M
```

and restart PHP-FPM. Craft's documented minimum is 256 MB; 512 MB is the practical figure for a
site that transforms images or has a substantial project config.

If you can't edit `php.ini`:

- **`.user.ini`** in the web root works on most shared hosts running PHP-FPM:
  `memory_limit = 512M`
- **`.htaccess`** works under mod_php only: `php_value memory_limit 512M`
- **cPanel / Plesk** expose it under "Select PHP Version → Options" or "PHP Settings".

Note that Craft's console commands read a different `php.ini` from the web SAPI, so raising one
does not raise the other. Check both.
MD;
    }
}
