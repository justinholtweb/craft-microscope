<?php

namespace justinholtweb\microscope\services;

use Craft;
use FilesystemIterator;
use justinholtweb\microscope\Plugin;
use justinholtweb\microscope\templates\TemplateAnalyzer;
use justinholtweb\microscope\templates\TemplateSource;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use yii\base\Component;

/**
 * Finds the site's Twig templates and hands them to the analyzer.
 *
 * The filesystem half of the template category, deliberately separate from
 * {@see TemplateAnalyzer} so that the analysis itself never touches a disk and stays testable
 * against plain strings.
 */
class Templates extends Component
{
    /**
     * Directories that are never worth reading.
     *
     * `vendor` and `node_modules` are other people's code; a finding about a query inside a loop
     * in a dependency isn't actionable. Craft's own compiled output isn't source at all.
     */
    private const EXCLUDED_DIRECTORIES = [
        'vendor',
        'node_modules',
        '.git',
        'compiled_templates',
        'storage',
    ];

    private ?TemplateAnalyzer $analyzer = null;

    /**
     * @var TemplateSource[]|null
     */
    private ?array $sources = null;

    /**
     * @var int Templates found before the configured cap was applied.
     */
    private int $foundCount = 0;

    public function analyzer(): TemplateAnalyzer
    {
        return $this->analyzer ??= new TemplateAnalyzer();
    }

    /**
     * Every template the scan should read.
     *
     * @return TemplateSource[]
     */
    public function sources(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }

        $settings = Plugin::getInstance()->getSettings();
        $sources = [];
        $this->foundCount = 0;

        foreach ($this->directories() as $directory) {
            foreach ($this->twigFilesIn($directory) as $file) {
                $this->foundCount++;

                if (count($sources) >= $settings->maxTemplates) {
                    continue;
                }

                $source = TemplateSource::fromFile(
                    $file->getPathname(),
                    $this->relativePath($file->getPathname(), $directory),
                );

                if ($source !== null) {
                    $sources[] = $source;
                }
            }
        }

        return $this->sources = $sources;
    }

    /**
     * How many templates were found in total, which can exceed how many were read.
     */
    public function foundCount(): int
    {
        $this->sources();

        return $this->foundCount;
    }

    public function wasTruncated(): bool
    {
        return $this->foundCount() > count($this->sources());
    }

    /**
     * The directories to walk: Craft's own template root, plus anything configured.
     *
     * @return string[]
     */
    public function directories(): array
    {
        $directories = [];

        try {
            $directories[] = Craft::$app->getPath()->getSiteTemplatesPath();
        } catch (Throwable) {
            // A headless install may not have one.
        }

        $base = defined('CRAFT_BASE_PATH') ? CRAFT_BASE_PATH : dirname(Craft::$app->getPath()->getConfigPath());

        foreach (Plugin::getInstance()->getSettings()->templateDirectories as $directory) {
            $directory = trim((string)$directory);

            if ($directory === '') {
                continue;
            }

            // Relative paths are resolved against the Craft base path; absolute ones are taken as
            // given. Either way the result has to be a real directory before it's walked.
            $path = str_starts_with($directory, '/')
                ? $directory
                : $base . DIRECTORY_SEPARATOR . ltrim($directory, '/');

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
    private function twigFilesIn(string $directory): iterable
    {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );
        } catch (Throwable) {
            return [];
        }

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }

            if (!in_array(strtolower($file->getExtension()), ['twig', 'html'], true)) {
                continue;
            }

            if ($this->isExcluded($file->getPathname())) {
                continue;
            }

            yield $file;
        }
    }

    private function isExcluded(string $path): bool
    {
        foreach (self::EXCLUDED_DIRECTORIES as $excluded) {
            if (str_contains($path, DIRECTORY_SEPARATOR . $excluded . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A path relative to its template root, which is what a developer would type in an
     * `{% include %}` — far more useful in a report than an absolute filesystem path, and it
     * doesn't leak the server's directory layout into a PDF that might be sent to a client.
     */
    private function relativePath(string $path, string $root): string
    {
        $relative = str_starts_with($path, $root)
            ? ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR)
            : $path;

        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }
}
