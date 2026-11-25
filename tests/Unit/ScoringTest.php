<?php

declare(strict_types=1);

use JustinHoltWeb\Microscope\Data\Category;
use JustinHoltWeb\Microscope\Data\Finding;
use JustinHoltWeb\Microscope\Data\ScanResult;

/**
 * The score is the number everybody looks at first, so its arithmetic needs pinning down.
 */
function scoringFinding(string $category, string $severity, string $check = 'check'): Finding
{
    $finding = new Finding;
    $finding->category = $category;
    $finding->severity = $severity;
    $finding->checkHandle = $check;
    $finding->title = ucfirst($severity).' in '.$category;

    return $finding;
}

test('a perfect scan scores one hundred', function () {
    $result = new ScanResult;
    $result->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_PASS));

    $this->assertSame(100, $result->score());
    $this->assertSame('A', $result->grade());
});

test('an empty scan scores one hundred', function () {
    $this->assertSame(100, (new ScanResult)->score());
});

test('severities cost their weight', function () {
    $result = new ScanResult;
    $result->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_CRITICAL));

    $this->assertSame(75, $result->categoryScore(Category::PHP));

    $result = new ScanResult;
    $result->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_WARNING));

    $this->assertSame(90, $result->categoryScore(Category::PHP));

    $result = new ScanResult;
    $result->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_NOTICE));

    $this->assertSame(97, $result->categoryScore(Category::PHP));
});

test('category scores never go below zero', function () {
    $result = new ScanResult;

    for ($i = 0; $i < 10; $i++) {
        $result->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_CRITICAL, "check{$i}"));
    }

    $this->assertSame(0, $result->categoryScore(Category::PHP));
});

/**
 * Costs are subtracted rather than averaged across a category's checks. Averaging would make
 * the score go *up* every time a passing check was added, which would make the number mean
 * "what fraction of my checks passed" rather than "how much is wrong here".
 */
test('adding passing checks does not improve the score', function () {
    $withoutPasses = new ScanResult;
    $withoutPasses->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_CRITICAL));

    $withPasses = new ScanResult;
    $withPasses->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_CRITICAL));

    for ($i = 0; $i < 5; $i++) {
        $withPasses->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_PASS, "pass{$i}"));
    }

    $this->assertSame(
        $withoutPasses->categoryScore(Category::PHP),
        $withPasses->categoryScore(Category::PHP),
    );
});

test('overall score is the mean of the categories that ran', function () {
    $result = new ScanResult;
    $result->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_CRITICAL));   // 75
    $result->addFinding(scoringFinding(Category::DATABASE, Finding::SEVERITY_PASS));  // 100

    $this->assertSame(88, $result->score());
});

/**
 * A category that couldn't run — a Postgres site, or a host that won't allow shell access —
 * must not drag the score down for something the site isn't doing wrong.
 */
test('skipped categories are excluded from the score', function () {
    $result = new ScanResult;
    $result->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_CRITICAL));
    $result->skipped = ['systemResources' => 'shell_exec is disabled'];

    // Only PHP ran, so the overall score is PHP's.
    $this->assertSame(75, $result->score());
    $this->assertFalse($result->hasCategory(Category::SERVER));
});

test('grade boundaries', function () {
    $this->assertSame('A', ScanResult::gradeFor(100));
    $this->assertSame('A', ScanResult::gradeFor(90));
    $this->assertSame('B', ScanResult::gradeFor(89));
    $this->assertSame('B', ScanResult::gradeFor(80));
    $this->assertSame('C', ScanResult::gradeFor(70));
    $this->assertSame('D', ScanResult::gradeFor(60));
    $this->assertSame('F', ScanResult::gradeFor(59));
    $this->assertSame('F', ScanResult::gradeFor(0));
});

test('problems are sorted by severity', function () {
    $result = new ScanResult;
    $result->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_NOTICE, 'a'));
    $result->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_CRITICAL, 'b'));
    $result->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_WARNING, 'c'));
    $result->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_PASS, 'd'));

    $severities = array_map(fn (Finding $f) => $f->severity, $result->problems());

    $this->assertSame(
        [Finding::SEVERITY_CRITICAL, Finding::SEVERITY_WARNING, Finding::SEVERITY_NOTICE],
        $severities,
    );
});

test('passes are excluded from problems', function () {
    $result = new ScanResult;
    $result->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_PASS));

    $this->assertSame([], $result->problems());
    $this->assertCount(1, $result->passes());
});

test('problems can be filtered by category', function () {
    $result = new ScanResult;
    $result->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_WARNING, 'a'));
    $result->addFinding(scoringFinding(Category::DATABASE, Finding::SEVERITY_WARNING, 'b'));

    $this->assertCount(1, $result->problems(Category::PHP));
    $this->assertCount(2, $result->problems());
});

test('grouped by category follows category order and drops empties', function () {
    $result = new ScanResult;
    $result->addFinding(scoringFinding(Category::TEMPLATES, Finding::SEVERITY_WARNING, 'a'));
    $result->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_WARNING, 'b'));

    $this->assertSame([Category::PHP, Category::TEMPLATES], array_keys($result->groupedByCategory()));
});

test('fingerprints combine check and target', function () {
    $finding = scoringFinding(Category::PHP, Finding::SEVERITY_WARNING, 'opcache');
    $finding->target = 'opcache.memory_consumption';

    $this->assertSame('opcache:opcache.memory_consumption', $finding->fingerprint());
});

test('new since identifies findings absent from the earlier scan', function () {
    $before = new ScanResult;
    $before->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_WARNING, 'opcache'));

    $after = new ScanResult;
    $after->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_WARNING, 'opcache'));
    $after->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_CRITICAL, 'memoryLimit'));

    $new = $after->newSince($before);

    $this->assertCount(1, $new);
    $this->assertSame('memoryLimit', $new[0]->checkHandle);
});

test('resolved since identifies findings that have gone', function () {
    $before = new ScanResult;
    $before->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_WARNING, 'opcache'));
    $before->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_WARNING, 'memoryLimit'));

    $after = new ScanResult;
    $after->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_WARNING, 'opcache'));

    $resolved = $after->resolvedSince($before);

    $this->assertCount(1, $resolved);
    $this->assertSame('memoryLimit', $resolved[0]->checkHandle);
});

/**
 * With nothing to compare against, everything is new — which is what makes the first
 * scheduled scan send a useful email rather than an empty one.
 */
test('everything is new when there is no previous scan', function () {
    $result = new ScanResult;
    $result->addFinding(scoringFinding(Category::PHP, Finding::SEVERITY_WARNING));

    $this->assertCount(1, $result->newSince(null));
    $this->assertSame([], $result->resolvedSince(null));
});

test('result survives a round trip through an array', function () {
    $original = new ScanResult;
    $finding = scoringFinding(Category::PHP, Finding::SEVERITY_CRITICAL, 'opcache');
    $finding->target = 'opcache.enable';
    $finding->remediation = 'Turn it on.';
    $finding->evidence = [['setting' => 'opcache.enable', 'value' => '0']];
    $original->addFinding($finding);
    $original->runtime = 1.25;

    $restored = ScanResult::fromArray($original->toArray());

    $this->assertSame($original->score(), $restored->score());
    $this->assertSame(1.25, $restored->runtime);
    $this->assertSame('opcache:opcache.enable', $restored->problems()[0]->fingerprint());
    $this->assertSame('Turn it on.', $restored->problems()[0]->remediation);
    $this->assertSame([['setting' => 'opcache.enable', 'value' => '0']], $restored->problems()[0]->evidence);
});
