<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Checks\Craft;

use JustinHoltWeb\Microscope\Checks\BaseCheck;
use JustinHoltWeb\Microscope\Checks\CheckContext;
use JustinHoltWeb\Microscope\Data\Category;

use function CraftCms\Cms\t;

/**
 * Where Laravel keeps sessions, which every control panel request (and every logged-in front-end
 * request) reads and usually writes.
 */
class SessionDriverCheck extends BaseCheck
{
    public function handle(): string
    {
        return 'sessionDriver';
    }

    public function category(): string
    {
        return Category::CRAFT;
    }

    public function label(): string
    {
        return t('Sessions', category: 'microscope');
    }

    public function description(): string
    {
        return t('Checks Laravel’s session driver: whether sessions persist, and whether they’re stored somewhere fast and shared.', category: 'microscope');
    }

    public function run(CheckContext $context): array
    {
        $craft = $context->craft;
        $php = $context->php;
        $driver = $craft->sessionDriver();
        $observed = 'SESSION_DRIVER='.$driver;

        if ($driver === 'array' && $context->isProduction()) {
            return [$this->critical([
                'title' => t('Sessions don’t survive the request', category: 'microscope'),
                'summary' => t('Laravel’s `array` session driver keeps sessions in memory for one request only.', category: 'microscope'),
                'observed' => $observed,
                'expected' => t('redis, database or file', category: 'microscope'),
                'target' => 'session.driver',
                'impact' => t('Nobody can stay logged in: every request starts a new, empty session, so the control panel bounces editors back to the login screen and CSRF tokens never match.', category: 'microscope'),
                'remediation' => $this->instructions($php->hasExtension('redis')),
                'docsUrl' => 'https://laravel.com/docs/session#configuration',
            ])];
        }

        if ($driver === 'file' && ($php->hasExtension('redis') || $php->hasExtension('memcached'))) {
            return [$this->notice([
                'title' => t('Sessions are stored as files', category: 'microscope'),
                'summary' => t('Laravel’s `file` session driver is in use, though Redis or Memcached support is installed.', category: 'microscope'),
                'observed' => $observed,
                'expected' => t('redis or memcached', category: 'microscope'),
                'target' => 'session.driver',
                'impact' => t('File sessions are a filesystem read and write on every request, and nothing but a lottery sweeps expired ones, so `storage/framework/sessions` keeps growing. With more than one web server they don’t work at all unless that directory is shared — users are logged out whenever the load balancer sends them to another machine.', category: 'microscope'),
                'remediation' => $this->instructions($php->hasExtension('redis')),
                'docsUrl' => 'https://laravel.com/docs/session#configuration',
            ])];
        }

        if ($driver === 'cookie') {
            return [$this->notice([
                'title' => t('Sessions are stored in cookies', category: 'microscope'),
                'summary' => t('The whole session is encrypted into a cookie and sent with every request.', category: 'microscope'),
                'observed' => $observed,
                'expected' => t('redis, database or file', category: 'microscope'),
                'target' => 'session.driver',
                'impact' => t('Every request — including every image and asset request to the same domain — carries the session in its headers, and a session that grows past about 4 KB is silently truncated by the browser, which looks like random logouts.', category: 'microscope'),
                'remediation' => $this->instructions($php->hasExtension('redis')),
                'docsUrl' => 'https://laravel.com/docs/session#configuration',
            ])];
        }

        return [$this->pass(
            t('Sessions are stored sensibly', category: 'microscope'),
            $observed,
        )];
    }

    private function instructions(bool $hasRedis): string
    {
        $redis = $hasRedis
            ? 'The `redis` extension is installed here, so Redis is a configuration change only.'
            : 'Redis needs the `redis` PHP extension (or `predis/predis`); without it, the database driver is a solid choice.';

        return <<<MD
{$redis}

```bash
# .env
SESSION_DRIVER=redis        # or: database
SESSION_CONNECTION=default  # a Redis connection from config/database.php
```

The `database` driver uses the `sessions` table a Craft 6 install already has, and works across
several web servers with nothing else to run.

Rebuild the cached configuration afterwards (`php artisan config:cache`). Changing the driver logs
everybody out once, so do it outside editors' working hours.
MD;
    }
}
