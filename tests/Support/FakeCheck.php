<?php

declare(strict_types=1);

namespace JustinHoltWeb\Microscope\Tests\Support;

use JustinHoltWeb\Microscope\Checks\BaseCheck;
use JustinHoltWeb\Microscope\Checks\CheckContext;
use RuntimeException;

/**
 * A check whose findings the test decides.
 */
class FakeCheck extends BaseCheck
{
    /**
     * @param  list<array{0: string, 1: string, 2?: string|null}>  $findings  [severity, title, target]
     */
    public function __construct(
        private readonly string $handle = 'fake',
        private readonly string $category = 'php',
        private readonly array $findings = [],
        private readonly ?string $unavailable = null,
        private readonly bool $throws = false,
    ) {}

    public function handle(): string
    {
        return $this->handle;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function label(): string
    {
        return ucfirst($this->handle);
    }

    public function description(): string
    {
        return 'A test check.';
    }

    public function isAvailable(CheckContext $context): bool
    {
        return $this->unavailable === null;
    }

    public function unavailableReason(CheckContext $context): string
    {
        return (string) $this->unavailable;
    }

    public function run(CheckContext $context): array
    {
        if ($this->throws) {
            throw new RuntimeException('Boom');
        }

        if ($this->findings === []) {
            return [$this->pass(ucfirst($this->handle).' is fine', 'ok')];
        }

        return array_map(fn (array $f) => $this->finding($f[0], [
            'title' => $f[1],
            'target' => $f[2] ?? null,
            'summary' => 'Summary of '.$f[1],
            'observed' => '1',
            'expected' => '2',
            'impact' => 'Slow.',
            'remediation' => "Fix it:\n\n```php\nreturn [];\n```",
            'evidence' => array_map(fn (int $i) => ['row' => $i], range(1, 30)),
        ]), $this->findings);
    }
}
