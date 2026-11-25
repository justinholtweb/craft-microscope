<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Data;

/**
 * One thing Microscope noticed.
 *
 * A finding is deliberately more than a pass/fail flag. Somebody reads it later — possibly a
 * client, possibly a developer who has never seen this server — and must be able to act on it
 * without researching first. So every finding carries what was measured (`observed`), what it
 * should be (`expected`), why it costs something (`impact`) and the steps to fix it
 * (`remediation`, Markdown, with a config snippet where one exists).
 *
 * Checks build findings; nothing mutates them afterwards.
 */
final class Finding
{
    public const string SEVERITY_CRITICAL = 'critical';

    public const string SEVERITY_WARNING = 'warning';

    public const string SEVERITY_NOTICE = 'notice';

    public const string SEVERITY_PASS = 'pass';

    /** Severities in descending order of urgency. */
    public const array SEVERITIES = [
        self::SEVERITY_CRITICAL,
        self::SEVERITY_WARNING,
        self::SEVERITY_NOTICE,
        self::SEVERITY_PASS,
    ];

    /**
     * How much each severity costs against a category's score, out of 100. A single critical
     * finding takes a category from an A to a C on its own, which is intended.
     */
    private const array SEVERITY_COST = [
        self::SEVERITY_CRITICAL => 25,
        self::SEVERITY_WARNING => 10,
        self::SEVERITY_NOTICE => 3,
        self::SEVERITY_PASS => 0,
    ];

    /** The properties a finding is built from and stored as, in storage order. */
    private const array ATTRIBUTES = [
        'checkHandle', 'category', 'severity', 'title', 'summary', 'observed', 'expected', 'impact',
        'remediation', 'docsUrl', 'target', 'occurrences', 'evidence',
    ];

    /** Handle of the check that produced this. */
    public string $checkHandle = '';

    /** One of the {@see Category} constants. */
    public string $category = '';

    /** One of the SEVERITY_* constants. */
    public string $severity = self::SEVERITY_NOTICE;

    /** Short headline, e.g. "OPcache is disabled". */
    public string $title = '';

    /** A sentence or two of context, shown under the title. */
    public string $summary = '';

    /** What Microscope measured, e.g. "16M". */
    public ?string $observed = null;

    /** What it should be, e.g. "256M or more". */
    public ?string $expected = null;

    /** What this costs in practice, in plain language. */
    public string $impact = '';

    /** How to fix it. GitHub-flavoured Markdown. */
    public string $remediation = '';

    /** Where to read more. */
    public ?string $docsUrl = null;

    /** The specific thing this is about: a template path, a field handle, a php.ini directive. */
    public ?string $target = null;

    /** How many times this was found, when a check collapses many occurrences into one finding. */
    public int $occurrences = 1;

    /** @var list<array<string, mixed>> Supporting rows: the templates, fields or tables behind it. */
    public array $evidence = [];

    /**
     * @param  array<string, mixed>  $attributes  Any of the public properties. Unknown keys are ignored.
     */
    public static function make(string $checkHandle, string $category, string $severity, array $attributes = []): self
    {
        return self::fromArray(['checkHandle' => $checkHandle, 'category' => $category, 'severity' => $severity] + $attributes);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $finding = new self;

        foreach (self::ATTRIBUTES as $attribute) {
            if (! array_key_exists($attribute, $data)) {
                continue;
            }

            $value = $data[$attribute];

            $finding->$attribute = match ($attribute) {
                'observed', 'expected', 'docsUrl', 'target' => $value === null ? null : (string) $value,
                'occurrences' => max(1, (int) $value),
                'evidence' => is_array($value) ? array_values($value) : [],
                default => (string) $value,
            };
        }

        return $finding;
    }

    /** The score cost of this finding. */
    public function cost(): int
    {
        return self::SEVERITY_COST[$this->severity] ?? 0;
    }

    /** Whether this is something to act on, as opposed to a check that passed. */
    public function isProblem(): bool
    {
        return $this->severity !== self::SEVERITY_PASS;
    }

    /** Sort weight, lowest first: critical findings sort to the top. */
    public function rank(): int
    {
        $index = array_search($this->severity, self::SEVERITIES, true);

        return $index === false ? count(self::SEVERITIES) : $index;
    }

    /**
     * A stable identity across scans, so "OPcache is disabled" a week later is recognised as the
     * same problem. The check handle alone isn't enough when a check emits one finding per
     * template, hence the target.
     */
    public function fingerprint(): string
    {
        return $this->checkHandle.':'.($this->target ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        foreach (self::ATTRIBUTES as $attribute) {
            $data[$attribute] = $this->$attribute;
        }

        return $data;
    }
}
