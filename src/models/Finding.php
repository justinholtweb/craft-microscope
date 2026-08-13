<?php

namespace justinholtweb\microscope\models;

use craft\base\Model;

/**
 * One thing Microscope noticed.
 *
 * A finding is deliberately more than a pass/fail flag. The whole point of the plugin is that
 * somebody reads it later — possibly a client, possibly a developer who has never seen this
 * server — and can act on it without going and researching first. So every finding carries:
 *
 * - what was measured (`observed`) and what it should be (`expected`),
 * - why it costs something (`impact`),
 * - and the actual steps to put it right (`remediation`), including a config snippet where one
 *   exists.
 *
 * Findings are value objects. Checks build them; nothing mutates them afterwards.
 */
class Finding extends Model
{
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_NOTICE = 'notice';
    public const SEVERITY_PASS = 'pass';

    /**
     * Severities in descending order of urgency. Used for sorting and for iterating in the UI, so
     * that adding a severity in one place is enough.
     */
    public const SEVERITIES = [
        self::SEVERITY_CRITICAL,
        self::SEVERITY_WARNING,
        self::SEVERITY_NOTICE,
        self::SEVERITY_PASS,
    ];

    /**
     * How much each severity costs against a category's score, out of 100.
     *
     * A single critical finding takes a category from an A to a C on its own, which is intended:
     * `devMode` on in production or a 2 MB opcache are not "one of several considerations", they
     * are the answer to why the site is slow.
     */
    private const SEVERITY_COST = [
        self::SEVERITY_CRITICAL => 25,
        self::SEVERITY_WARNING => 10,
        self::SEVERITY_NOTICE => 3,
        self::SEVERITY_PASS => 0,
    ];

    /**
     * @var string Handle of the check that produced this.
     */
    public string $checkHandle = '';

    /**
     * @var string One of the {@see Category} constants.
     */
    public string $category = '';

    /**
     * @var string One of the SEVERITY_* constants.
     */
    public string $severity = self::SEVERITY_NOTICE;

    /**
     * @var string Short headline, e.g. "OPcache is disabled".
     */
    public string $title = '';

    /**
     * @var string A sentence or two of context, shown under the title without expanding anything.
     */
    public string $summary = '';

    /**
     * @var string|null What Microscope actually measured, e.g. "16M".
     */
    public ?string $observed = null;

    /**
     * @var string|null What it should be, e.g. "256M or more".
     */
    public ?string $expected = null;

    /**
     * @var string What this costs in practice, in plain language.
     */
    public string $impact = '';

    /**
     * @var string How to fix it. GitHub-flavoured Markdown: paragraphs, `-` bullets and fenced
     *             blocks for config snippets. Rendered with Craft's `|md('gfm')` filter.
     */
    public string $remediation = '';

    /**
     * @var string|null Where to read more — Craft docs, PHP manual, MySQL manual.
     */
    public ?string $docsUrl = null;

    /**
     * @var string|null The specific thing this is about: a template path, a field handle, a
     *                  php.ini directive. Gives the finding an anchor when several share a check.
     */
    public ?string $target = null;

    /**
     * @var int How many times this was found, when a check collapses many occurrences into one
     *          finding (e.g. 12 templates querying inside a loop).
     */
    public int $occurrences = 1;

    /**
     * @var array<int, array<string, mixed>> Supporting rows — the individual templates, fields or
     *                                       tables behind the finding.
     */
    public array $evidence = [];

    /**
     * The score cost of this finding.
     */
    public function cost(): int
    {
        return self::SEVERITY_COST[$this->severity] ?? 0;
    }

    /**
     * Whether this is something to act on, as opposed to a check that passed.
     */
    public function isProblem(): bool
    {
        return $this->severity !== self::SEVERITY_PASS;
    }

    /**
     * Sort weight, lowest first — critical findings sort to the top.
     */
    public function rank(): int
    {
        $index = array_search($this->severity, self::SEVERITIES, true);
        return $index === false ? count(self::SEVERITIES) : $index;
    }

    /**
     * A stable identity for the finding across scans.
     *
     * Two scans a week apart should recognise "OPcache is disabled" as the *same* problem so the
     * report can say it is still outstanding, and so the scheduler can tell a new problem from a
     * familiar one. The check handle alone isn't enough when a check emits one finding per
     * template, hence the target.
     */
    public function fingerprint(): string
    {
        return $this->checkHandle . ':' . ($this->target ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'checkHandle' => $this->checkHandle,
            'category' => $this->category,
            'severity' => $this->severity,
            'title' => $this->title,
            'summary' => $this->summary,
            'observed' => $this->observed,
            'expected' => $this->expected,
            'impact' => $this->impact,
            'remediation' => $this->remediation,
            'docsUrl' => $this->docsUrl,
            'target' => $this->target,
            'occurrences' => $this->occurrences,
            'evidence' => $this->evidence,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $finding = new self();
        $finding->setAttributes($data, false);
        return $finding;
    }
}
