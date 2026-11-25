<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Microscope\Data\Category;
use JustinHoltWeb\Microscope\Services\Checks;

/**
 * Lists the checks Microscope runs, and whether each is on.
 */
class ChecksListCommand extends Command
{
    use CraftCommand;

    #[\Override]
    protected $signature = 'microscope:checks';

    #[\Override]
    protected $description = 'Lists the checks Microscope runs.';

    #[\Override]
    protected $aliases = ['microscope/scan/checks'];

    public function handle(Checks $checks): int
    {
        foreach ($checks->grouped() as $category => $categoryChecks) {
            $this->newLine();
            $this->line('<options=bold>'.strtoupper(Category::label($category)).'</>');

            foreach ($categoryChecks as $check) {
                $enabled = $checks->isEnabled($check->handle());
                $this->line(sprintf('  <fg=%s>%s %-24s %s</>', $enabled ? 'green' : 'gray', $enabled ? '✓' : '·', $check->handle(), $check->label()));
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }
}
