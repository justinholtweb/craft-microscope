<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Checks\Craft;

use JustinHoltWeb\Microscope\Checks\BaseCheck;
use JustinHoltWeb\Microscope\Checks\CheckContext;
use JustinHoltWeb\Microscope\Data\Category;

use function CraftCms\Cms\t;

/**
 * How much Laravel logs in production, and where to.
 *
 * Craft 6 logs through Laravel's channels. At `debug`, Craft writes a line for nearly everything it
 * does — template renders, queries, cache misses — and a file channel turns each one into a disk
 * write inside the request.
 */
class LogLevelCheck extends BaseCheck
{
    /** Monolog levels from most to least verbose. */
    private const array VERBOSE_LEVELS = ['debug', 'info'];

    public function handle(): string
    {
        return 'logLevel';
    }

    public function category(): string
    {
        return Category::CRAFT;
    }

    public function label(): string
    {
        return t('Logging', category: 'microscope');
    }

    public function description(): string
    {
        return t('Checks the log level and channels in production: verbose logging to files costs a disk write on most requests.', category: 'microscope');
    }

    public function run(CheckContext $context): array
    {
        $craft = $context->craft;
        $channels = $craft->logChannels();

        if ($channels === []) {
            return [$this->pass(
                t('Logging configuration couldn’t be read', category: 'microscope'),
                'LOG_CHANNEL='.$craft->logChannel(),
            )];
        }

        $verbose = [];

        foreach ($channels as $name => $config) {
            $level = strtolower((string) ($config['level'] ?? 'debug'));

            if (in_array($level, self::VERBOSE_LEVELS, true)) {
                $verbose[$name] = sprintf('%s (%s, %s)', $name, (string) ($config['driver'] ?? '?'), $level);
            }
        }

        $summary = implode(', ', array_map(
            fn (string $name, array $config) => sprintf('%s: %s', $name, strtolower((string) ($config['level'] ?? 'debug'))),
            array_keys($channels),
            $channels,
        ));

        if (! $context->isProduction() || $verbose === []) {
            return [$this->pass(
                t('Log level is appropriate for this environment', category: 'microscope'),
                $summary,
            )];
        }

        $fileBased = array_filter(
            array_intersect_key($channels, $verbose),
            fn (array $config) => in_array($config['driver'] ?? null, ['single', 'daily'], true),
        );

        return [$this->finding($fileBased !== [] ? 'warning' : 'notice', [
            'title' => t('Production is logging at debug level', category: 'microscope'),
            'summary' => t('These log channels record debug or info messages: {channels}.', ['channels' => implode(', ', $verbose)], category: 'microscope'),
            'observed' => $summary,
            'expected' => 'LOG_LEVEL=warning',
            'target' => 'logging.level',
            'impact' => $fileBased !== []
                ? t('Craft and its plugins log a great deal at debug level. Written to a file channel, that’s a disk write — often several — in the middle of most requests, and log files that grow by gigabytes until a disk fills. The useful warnings and errors are buried in it, too.', category: 'microscope')
                : t('Craft and its plugins log a great deal at debug level. Sent to an external service, each line is extra work in the request (or the shutdown phase) and a bigger bill, and the useful warnings and errors are buried in it.', category: 'microscope'),
            'remediation' => <<<'MD'
Raise the level in production's `.env`:

```bash
LOG_LEVEL=warning
LOG_STACK=daily
LOG_DAILY_DAYS=14
```

`LOG_LEVEL` applies to the channels in Laravel's default `config/logging.php`; a channel with its
own `level` in a customised `config/logging.php` needs changing there.

`daily` keeps one file per day and deletes old ones after `LOG_DAILY_DAYS`, so a noisy day can't
fill the disk. When something needs investigating, lower the level temporarily, then put it back.

Rebuild the cached configuration afterwards: `php artisan config:cache`.
MD,
            'docsUrl' => 'https://laravel.com/docs/logging#log-levels',
        ])];
    }
}
