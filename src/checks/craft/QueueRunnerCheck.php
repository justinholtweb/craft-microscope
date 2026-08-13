<?php

namespace justinholtweb\microscope\checks\craft;

use Craft;
use justinholtweb\microscope\checks\BaseCheck;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\models\Category;

/**
 * How Craft's queue gets run.
 *
 * By default Craft runs queue jobs inside control panel web requests. It works, it needs no
 * infrastructure, and it means a visitor's request can end up resizing two hundred images.
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
        return Craft::t('microscope', 'Queue');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Checks how background jobs are run and whether a backlog has built up.');
    }

    public function run(CheckContext $context): array
    {
        $craft = $context->craft;
        $findings = [];
        $depth = $craft->queueDepth();

        if ($craft->runQueueAutomatically() && $context->isProduction()) {
            $findings[] = $this->warning([
                'title' => Craft::t('microscope', 'The queue runs inside web requests'),
                'summary' => Craft::t('microscope', '`runQueueAutomatically` is on, so background jobs are executed by control panel requests as they arrive.'),
                'observed' => 'runQueueAutomatically = true',
                'expected' => Craft::t('microscope', 'A dedicated queue worker'),
                'target' => 'runQueueAutomatically',
                'impact' => Craft::t('microscope', 'Jobs run in a browser request via Ajax, competing with the person who triggered them for PHP workers and CPU. Resaving a section, regenerating transforms or reindexing search then makes the control panel feel broken for whoever set it off — and the work stops the moment they close the tab.'),
                'remediation' => $this->workerInstructions(),
                'docsUrl' => 'https://craftcms.com/docs/5.x/system/queue.html',
            ]);
        }

        if ($depth['waiting'] > self::BACKLOG_WARNING) {
            $findings[] = $this->warning([
                'title' => Craft::t('microscope', 'The queue has a backlog'),
                'summary' => Craft::t('microscope', '{count} jobs are waiting to run.', ['count' => number_format($depth['waiting'])]),
                'observed' => number_format($depth['waiting']) . Craft::t('microscope', ' waiting'),
                'expected' => Craft::t('microscope', 'A queue that drains'),
                'target' => 'queue.backlog',
                'impact' => Craft::t('microscope', 'A backlog means search indexes, image transforms and element propagation are behind. Search returns stale results and images 404 until the queue catches up — and if nothing is running the queue at all, it never does.'),
                'remediation' => $this->backlogInstructions(),
            ]);
        }

        if ($depth['failed'] > 0) {
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'There are failed queue jobs'),
                'summary' => Craft::t('microscope', '{count} jobs have failed and are being retained.', ['count' => number_format($depth['failed'])]),
                'observed' => number_format($depth['failed']) . Craft::t('microscope', ' failed'),
                'expected' => '0',
                'target' => 'queue.failed',
                'impact' => Craft::t('microscope', 'Failed jobs stay in the table so they can be inspected, so this is mostly a signal rather than a cost. But whatever they were meant to do hasn’t happened — usually search indexing or transform generation, both of which fail quietly.'),
                'remediation' => "Look at them before clearing them — **Utilities → Queue Manager** shows the error for each.\n\nThe usual causes in Craft:\n\n- **Memory exhaustion** during image transforms of large files. Give the queue worker more: `php -d memory_limit=1G craft queue/listen`.\n- **A timeout** on a job that legitimately takes minutes.\n- **A deleted element** the job still refers to, which is harmless and safe to clear.\n\nRetry them:\n\n```bash\nphp craft queue/retry all\n```\n\nOr clear them once you know what they were:\n\n```bash\nphp craft queue/release all\n```",
            ]);
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                Craft::t('microscope', 'The queue is healthy'),
                Craft::t('microscope', '{waiting} waiting, {failed} failed', [
                    'waiting' => $depth['waiting'],
                    'failed' => $depth['failed'],
                ]),
            );
        }

        return $findings;
    }

    private function workerInstructions(): string
    {
        return <<<'MD'
Move the queue out of web requests and run it as its own process.

**1. Turn off the in-request runner:**

```php
// config/general.php
'runQueueAutomatically' => false,
```

**2. Run a worker.** Pick whichever matches your hosting.

**systemd** (a VPS you control) — `/etc/systemd/system/craft-queue.service`:

```ini
[Unit]
Description=Craft queue worker
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/example.com
ExecStart=/usr/bin/php craft queue/listen --verbose
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now craft-queue
```

**Supervisor** (common on managed platforms):

```ini
[program:craft-queue]
command=php /var/www/example.com/craft queue/listen --verbose
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/craft-queue.log
```

**Cron**, if you can't run a daemon — less responsive, but far better than in-request:

```bash
* * * * * cd /var/www/example.com && php craft queue/run --verbose >> /dev/null 2>&1
```

`queue/listen` blocks and picks jobs up immediately; `queue/run` processes what's waiting and
exits, which is what you want from cron.

**3. A caveat on `numprocs`.** More than one worker means jobs run concurrently, and not every job
is safe to run alongside itself. Craft's own jobs are, but check any plugin jobs before scaling
past one.

**4. Restart the worker on deploy.** It holds the old code in memory otherwise:

```bash
sudo systemctl restart craft-queue
```
MD;
    }

    private function backlogInstructions(): string
    {
        return <<<'MD'
**First, establish whether anything is running the queue at all:**

```bash
ps aux | grep 'queue/listen'
php craft queue/info
```

If nothing is listening, see the worker setup above — that's the whole problem.

**If a worker is running but not keeping up**, find out what the jobs are. **Utilities → Queue
Manager** groups them by description. A large backlog is nearly always one of:

- **Search index updates** after a bulk resave. These are quick individually and there are simply
  a lot of them; it will drain.
- **Image transform generation**, which is CPU-bound. Set
  `'generateTransformsBeforePageLoad' => false` so transforms are generated on first request to the
  image rather than queued in bulk.
- **Element propagation** on a multi-site install, which fires one job per element per site.

**To drain it faster, temporarily:**

```bash
php craft queue/run --verbose
```

You can run several of these in parallel terminals to clear a backlog, then go back to a single
worker.

**If the backlog is stuck rather than slow**, the head of the queue may be a job that keeps
timing out and retrying. `php craft queue/info` shows reserved jobs; release the offender
specifically rather than clearing everything:

```bash
php craft queue/release <id>
```
MD;
    }
}
