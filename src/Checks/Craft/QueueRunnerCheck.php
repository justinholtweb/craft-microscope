<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Checks\Craft;

use JustinHoltWeb\Microscope\Checks\BaseCheck;
use JustinHoltWeb\Microscope\Checks\CheckContext;
use JustinHoltWeb\Microscope\Data\Category;

use function CraftCms\Cms\t;

/**
 * How Craft's queue gets run: Laravel's queue connection, and whether Craft also runs jobs inside
 * control panel requests (`runQueueAutomatically`, on by default). In-request running needs no
 * infrastructure, and it means an editor's request can end up resizing two hundred images.
 */
class QueueRunnerCheck extends BaseCheck
{
    /**
     * A backlog above this suggests jobs aren't being picked up rather than merely being busy.
     */
    private const BACKLOG_WARNING = 100;

    public function handle(): string
    {
        return 'queueRunner';
    }

    public function category(): string
    {
        return Category::CRAFT;
    }

    public function label(): string
    {
        return t('Queue', category: 'microscope');
    }

    public function description(): string
    {
        return t('Checks the Laravel queue connection, how jobs are run, and whether a backlog or failures have built up.', category: 'microscope');
    }

    public function run(CheckContext $context): array
    {
        $craft = $context->craft;
        $findings = [];
        $driver = $craft->queueDriver();
        $depth = $craft->queueDepth();

        if ($driver === 'sync' && $context->isProduction()) {
            $findings[] = $this->warning([
                'title' => t('Queued jobs run inside the request that queues them', category: 'microscope'),
                'summary' => t('The default queue connection uses Laravel’s `sync` driver, so “queued” jobs run immediately, in the request that dispatched them.', category: 'microscope'),
                'observed' => sprintf('QUEUE_CONNECTION=%s (sync)', $craft->queueConnection()),
                'expected' => t('A database or Redis queue with a worker', category: 'microscope'),
                'target' => 'queue.driver',
                'impact' => t('Saving an entry waits for its search indexing and propagation; resaving a section or regenerating transforms holds a PHP worker for minutes and can time out halfway. Nothing is retried on failure, because nothing was ever queued.', category: 'microscope'),
                'remediation' => $this->workerInstructions(),
                'docsUrl' => 'https://laravel.com/docs/queues#driver-prerequisites',
            ]);
        } elseif ($craft->runQueueAutomatically() && $context->isProduction()) {
            $findings[] = $this->warning([
                'title' => t('The queue runs inside web requests', category: 'microscope'),
                'summary' => t('`runQueueAutomatically` is on, so background jobs are executed by control panel requests as they arrive.', category: 'microscope'),
                'observed' => 'runQueueAutomatically = true',
                'expected' => t('A dedicated queue worker', category: 'microscope'),
                'target' => 'runQueueAutomatically',
                'impact' => t('Jobs run in a browser request, competing with the person who triggered them for PHP workers and CPU. Resaving a section, regenerating transforms or reindexing search then makes the control panel feel broken — and when nobody has the control panel open, nothing runs at all.', category: 'microscope'),
                'remediation' => $this->workerInstructions(),
                'docsUrl' => 'https://laravel.com/docs/queues#running-the-queue-worker',
            ]);
        }

        if ($driver === 'database' && $context->isProduction() && ($depth['waiting'] ?? 0) > self::BACKLOG_WARNING && $context->php->hasExtension('redis')) {
            // Only worth raising alongside a backlog: a database queue is fine for most sites.
            $findings[] = $this->notice([
                'title' => t('The queue is stored in the database', category: 'microscope'),
                'summary' => t('Jobs are kept in the `jobs` table, and there is a backlog. Redis is installed and would take that load off the database.', category: 'microscope'),
                'observed' => sprintf('QUEUE_CONNECTION=%s (database)', $craft->queueConnection()),
                'expected' => 'QUEUE_CONNECTION=redis',
                'target' => 'queue.driver',
                'impact' => t('Each worker polls the `jobs` table and locks rows to reserve them. With a long backlog that is steady write traffic on the same database serving the site.', category: 'microscope'),
                'remediation' => "```bash\n# .env\nQUEUE_CONNECTION=redis\nREDIS_QUEUE_CONNECTION=default\n```\n\nThen rebuild the configuration and restart the workers so they pick up the new connection:\n\n```bash\nphp artisan config:cache\nphp artisan queue:restart\n```\n\nJobs already in the `jobs` table stay there; let a worker on the old connection drain them first (`php artisan queue:work database --stop-when-empty`).",
                'docsUrl' => 'https://laravel.com/docs/queues#redis',
            ]);
        }

        if (($depth['waiting'] ?? 0) > self::BACKLOG_WARNING) {
            $findings[] = $this->warning([
                'title' => t('The queue has a backlog', category: 'microscope'),
                'summary' => t('{count} jobs are waiting to run.', ['count' => number_format((int) $depth['waiting'])], category: 'microscope'),
                'observed' => number_format((int) $depth['waiting']).t(' waiting', category: 'microscope'),
                'expected' => t('A queue that drains', category: 'microscope'),
                'target' => 'queue.backlog',
                'impact' => t('A backlog means search indexes, image transforms and element propagation are behind. Search returns stale results and images 404 until the queue catches up — and if nothing is running the queue at all, it never will.', category: 'microscope'),
                'remediation' => $this->backlogInstructions(),
            ]);
        }

        if (($depth['failed'] ?? 0) > 0) {
            $findings[] = $this->notice([
                'title' => t('There are failed queue jobs', category: 'microscope'),
                'summary' => t('{count} jobs have failed and are being retained.', ['count' => number_format((int) $depth['failed'])], category: 'microscope'),
                'observed' => number_format((int) $depth['failed']).t(' failed', category: 'microscope'),
                'expected' => '0',
                'target' => 'queue.failed',
                'impact' => t('Failed jobs are kept so they can be inspected, so this is mostly a signal rather than a cost. But whatever they were meant to do hasn’t happened — usually search indexing or transform generation, both of which then quietly stay out of date.', category: 'microscope'),
                'remediation' => "Look at them before clearing them — **Utilities → Queue Manager** shows Craft's jobs, and Laravel lists every failure with its exception:\n\n```bash\nphp artisan queue:failed\n```\n\nThe usual causes:\n\n- **Memory exhaustion** during transforms of large images. Give the worker more memory (`php -d memory_limit=1G artisan queue:work`).\n- **Timeouts**: the worker's `--timeout` (60s by default) is shorter than the job. Raise it, and keep it below the connection's `retry_after`.\n- **A plugin job referencing something deleted** since it was queued.\n\nOnce the cause is fixed, retry them, or clear them:\n\n```bash\nphp artisan queue:retry all\nphp artisan queue:flush\n```",
                'docsUrl' => 'https://laravel.com/docs/queues#dealing-with-failed-jobs',
            ]);
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                t('The queue is healthy', category: 'microscope'),
                t('{driver} queue: {waiting} waiting, {failed} failed', [
                    'driver' => $driver,
                    'waiting' => $depth['waiting'] ?? '—',
                    'failed' => $depth['failed'] ?? '—',
                ], category: 'microscope'),
            );
        }

        return $findings;
    }

    private function workerInstructions(): string
    {
        return <<<'MD'
Move the queue out of web requests and run it as its own process.

**1. Use a real queue connection** and turn off Craft's in-request runner:

```bash
# .env
QUEUE_CONNECTION=database   # or redis
CRAFT_RUN_QUEUE_AUTOMATICALLY=false
```

The `database` driver needs the `jobs` table, which a Craft 6 install already has.

**2. Run a worker.** Pick whichever matches your hosting.

**Supervisor** (the usual choice; also what Forge and most managed hosts configure):

```ini
[program:craft-queue]
command=php /var/www/example.com/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/craft-queue.log
stopwaitsecs=3600
```

**systemd** — `ExecStart=/usr/bin/php /var/www/example.com/artisan queue:work --max-time=3600`
with `Restart=always`.

**Cron**, if you can't run a daemon — less responsive, but far better than in-request:

```bash
* * * * * cd /var/www/example.com && php artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1
```

**3. Restart workers on every deploy.** They hold the old code in memory otherwise:

```bash
php artisan queue:restart
```

**4. A caveat on `numprocs`.** More than one worker runs jobs concurrently. Craft's own jobs are
safe to run alongside themselves; check any plugin jobs before scaling past one.
MD;
    }

    private function backlogInstructions(): string
    {
        return <<<'MD'
**First, establish whether anything is working the queue at all:**

```bash
ps aux | grep 'queue:work'
php artisan queue:monitor default
```

If nothing is running, see the worker setup in Laravel's queue docs — that's the whole problem.

**If a worker is running but not keeping up**, find out what the jobs are. **Utilities → Queue
Manager** groups Craft's jobs by description. A large backlog is nearly always one of:

- **Search index updates** after a bulk resave. Quick individually; there are simply a lot of them.
- **Image transform generation**, which is CPU-bound.
- **Element propagation** on a multi-site install, which fires one job per element per site.

**To drain it faster, temporarily** run extra workers until it's clear:

```bash
php artisan queue:work --stop-when-empty
```

**If the backlog is stuck rather than slow**, the job at the head may be timing out and retrying.
Check `php artisan queue:failed` and the worker's log, and raise `--timeout` (keeping it below the
connection's `retry_after`) if a job legitimately takes longer than a minute.
MD;
    }
}
