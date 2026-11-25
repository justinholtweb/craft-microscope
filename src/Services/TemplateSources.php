<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Services;

use CraftCms\Cms\Support\Facades\Path;
use FilesystemIterator;
use JustinHoltWeb\Microscope\Models\Settings;
use JustinHoltWeb\Microscope\TemplateAnalysis\TemplateAnalyzer;
use JustinHoltWeb\Microscope\TemplateAnalysis\TemplateSource;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Finding the site's templates on disk and handing them to the {@see TemplateAnalyzer}, which never
 * touches a disk itself and so stays testable against plain strings.
 *
 * Craft 6 renders site templates by file extension, so a site can have Twig and Blade side by side.
 * Only Twig is analysed; Blade files are counted, so the report can say they weren't read rather
 * than staying silent about them.
 */
class TemplateSources
{
    /**
     * Directories that are never worth reading: other people's code, and compiled output.
     */
    private const array EXCLUDED_DIRECTORIES = ['vendor', 'node_modules', '.git', 'compiled_templates', 'storage'];

    private ?TemplateAnalyzer $analyzer = null;

    /** @var list<TemplateSource>|null */
    private ?array $sources = null;

    /** Twig templates found before the configured cap was applied. */
    private int $foundCount = 0;

    /** Blade templates found (not analysed). */
    private int $bladeCount = 0;

    public function __construct(
        private readonly Settings $settings,
    ) {}

    public function analyzer(): TemplateAnalyzer
    {
        return $this->analyzer ??= new TemplateAnalyzer;
    }

    /**
     * Every Twig template the scan should read, up to `maxTemplates`.
     *
     * @return list<TemplateSource>
     */
    public function sources(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }

        $sources = [];
        $this->foundCount = 0;
        $this->bladeCount = 0;

        foreach ($this->directories() as $directory) {
            foreach ($this->templateFilesIn($directory) as $file) {
                if (str_ends_with(strtolower($file->getFilename()), '.blade.php')) {
                    $this->bladeCount++;

                    continue;
                }

                $this->foundCount++;

                if (count($sources) >= $this->settings->maxTemplates) {
                    continue;
                }

                $source = TemplateSource::fromFile($file->getPathname(), $this->relativePath($file->getPathname(), $directory));

                if ($source !== null) {
                    $sources[] = $source;
                }
            }
        }

        return $this->sources = $sources;
    }

    /** How many Twig templates were found in total, which can exceed how many were read. */
    public function foundCount(): int
    {
        $this->sources();

        return $this->foundCount;
    }

    /** How many Blade templates were found. Microscope's rules are Twig-only. */
    public function bladeCount(): int
    {
        $this->sources();

        return $this->bladeCount;
    }

    public function wasTruncated(): bool
    {
        return $this->foundCount() > count($this->sources());
    }

    /**
     * The directories to walk: Craft's site template root (`resources/views` when it exists, else
     * `templates/`), plus anything configured, relative to the project root.
     *
     * @return list<string>
     */
    public function directories(): array
    {
        $directories = [];

        try {
            $directories[] = Path::siteTemplates();
        } catch (Throwable) {
            // A headless install may not have one.
        }

        $base = base_path();

        foreach ($this->settings->templateDirectoryList() as $directory) {
            // Absolute paths are taken as given; either way the result must be a real directory.
            $path = str_starts_with($directory, '/') ? $directory : $base.DIRECTORY_SEPARATOR.ltrim($directory, '/');
            $real = realpath($path);

            if ($real !== false && is_dir($real)) {
                $directories[] = $real;
            }
        }

        return array_values(array_unique(array_filter($directories, 'is_dir')));
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function templateFilesIn(string $directory): iterable
    {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );
        } catch (Throwable) {
            return;
        }

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $this->isExcluded($file->getPathname())) {
                continue;
            }

            $name = strtolower($file->getFilename());

            if (str_ends_with($name, '.twig') || str_ends_with($name, '.html') || str_ends_with($name, '.blade.php')) {
                yield $file;
            }
        }
    }

    private function isExcluded(string $path): bool
    {
        foreach (self::EXCLUDED_DIRECTORIES as $excluded) {
            if (str_contains($path, DIRECTORY_SEPARATOR.$excluded.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A path relative to its template root — what a developer would type in an `{% include %}`, and
     * it doesn't leak the server's directory layout into a report sent to a client.
     */
    private function relativePath(string $path, string $root): string
    {
        $relative = str_starts_with($path, $root) ? ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR) : $path;

        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }
}
