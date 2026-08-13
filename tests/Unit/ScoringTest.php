<?php

declare(strict_types=1);

namespace justinholtweb\microscope\tests\Unit;

use justinholtweb\microscope\models\Category;
use justinholtweb\microscope\models\Finding;
use justinholtweb\microscope\models\ScanResult;
use PHPUnit\Framework\TestCase;

/**
 * The score is the number everybody looks at first, so its arithmetic needs pinning down.
 */
final class ScoringTest extends TestCase
{
    private function finding(string $category, string $severity, string $check = 'check'): Finding
    {
        $finding = new Finding();
        $finding->category = $category;
        $finding->severity = $severity;
        $finding->checkHandle = $check;
        $finding->title = ucfirst($severity) . ' in ' . $category;

        return $finding;
    }

    public function testAPerfectScanScoresOneHundred(): void
    {
        $result = new ScanResult();
        $result->addFinding($this->finding(Category::PHP, Finding::SEVERITY_PASS));

        self::assertSame(100, $result->score());
        self::assertSame('A', $result->grade());
    }

    public function testAnEmptyScanScoresOneHundred(): void
    {
        self::assertSame(100, (new ScanResult())->score());
    }

    public function testSeveritiesCostTheirWeight(): void
    {
        $result = new ScanResult();
        $result->addFinding($this->finding(Category::PHP, Finding::SEVERITY_CRITICAL));

        self::assertSame(75, $result->categoryScore(Category::PHP));

        $result = new ScanResult();
        $result->addFinding($this->finding(Category::PHP, Finding::SEVERITY_WARNING));

        self::assertSame(90, $result->categoryScore(Category::PHP));

        $result = new ScanResult();
        $result->addFinding($this->finding(Category::PHP, Finding::SEVERITY_NOTICE));

        self::assertSame(97, $result->categoryScore(Category::PHP));
    }

    public function testCategoryScoresNeverGoBelowZero(): void
    {
        $result = new ScanResult();

        for ($i = 0; $i < 10; $i++) {
            $result->addFinding($this->finding(Category::PHP, Finding::SEVERITY_CRITICAL, "check{$i}"));
        }

        self::assertSame(0, $result->categoryScore(Category::PHP));
    }

    /**
     * Costs are subtracted rather than averaged across a category's checks. Averaging would make
     * the score go *up* every time a passing check was added, which would make the number mean
     * "what fraction of my checks passed" rather than "how much is wrong here".
     */
    public function testAddingPassingChecksDoesNotImproveTheScore(): void
    {
        $withoutPasses = new ScanResult();
        $withoutPasses->addFinding($this->finding(Category::PHP, Finding::SEVERITY_CRITICAL));

        $withPasses = new ScanResult();
        $withPasses->addFinding($this->finding(Category::PHP, Finding::SEVERITY_CRITICAL));

        for ($i = 0; $i < 5; $i++) {
            $withPasses->addFinding($this->finding(Category::PHP, Finding::SEVERITY_PASS, "pass{$i}"));
        }

        self::assertSame(
            $withoutPasses->categoryScore(Category::PHP),
            $withPasses->categoryScore(Category::PHP),
        );
    }

    public function testOverallScoreIsTheMeanOfTheCategoriesThatRan(): void
    {
        $result = new ScanResult();
        $result->addFinding($this->finding(Category::PHP, Finding::SEVERITY_CRITICAL));   // 75
        $result->addFinding($this->finding(Category::DATABASE, Finding::SEVERITY_PASS));  // 100

        self::assertSame(88, $result->score());
    }

    /**
     * A category that couldn't run — a Postgres site, or a host that won't allow shell access —
     * must not drag the score down for something the site isn't doing wrong.
     */
    public function testSkippedCategoriesAreExcludedFromTheScore(): void
    {
        $result = new ScanResult();
        $result->addFinding($this->finding(Category::PHP, Finding::SEVERITY_CRITICAL));
        $result->skipped = ['systemResources' => 'shell_exec is disabled'];

        // Only PHP ran, so the overall score is PHP's.
        self::assertSame(75, $result->score());
        self::assertFalse($result->hasCategory(Category::SERVER));
    }

    public function testGradeBoundaries(): void
    {
        self::assertSame('A', ScanResult::gradeFor(100));
        self::assertSame('A', ScanResult::gradeFor(90));
        self::assertSame('B', ScanResult::gradeFor(89));
        self::assertSame('B', ScanResult::gradeFor(80));
        self::assertSame('C', ScanResult::gradeFor(70));
        self::assertSame('D', ScanResult::gradeFor(60));
        self::assertSame('F', ScanResult::gradeFor(59));
        self::assertSame('F', ScanResult::gradeFor(0));
    }

    public function testProblemsAreSortedBySeverity(): void
    {
        $result = new ScanResult();
        $result->addFinding($this->finding(Category::PHP, Finding::SEVERITY_NOTICE, 'a'));
        $result->addFinding($this->finding(Category::PHP, Finding::SEVERITY_CRITICAL, 'b'));
        $result->addFinding($this->finding(Category::PHP, Finding::SEVERITY_WARNING, 'c'));
        $result->addFinding($this->finding(Category::PHP, Finding::SEVERITY_PASS, 'd'));

        $severities = array_map(fn(Finding $f) => $f->severity, $result->problems());

        self::assertSame(
            [Finding::SEVERITY_CRITICAL, Finding::SEVERITY_WARNING, Finding::SEVERITY_NOTICE],
            $severities,
        );
    }

    public function testPassesAreExcludedFromProblems(): void
    {
        $result = new ScanResult();
        $result->addFinding($this->finding(Category::PHP, Finding::SEVERITY_PASS));

        self::assertSame([], $result->problems());
        self::assertCount(1, $result->passes());
    }

    public function testProblemsCanBeFilteredByCategory(): void
    {
        $result = new ScanResult();
        $result->addFinding($this->finding(Category::PHP, Finding::SEVERITY_WARNING, 'a'));
        $result->addFinding($this->finding(Category::DATABASE, Finding::SEVERITY_WARNING, 'b'));

        self::assertCount(1, $result->problems(Category::PHP));
        self::assertCount(2, $result->problems());
    }

    public function testGroupedByCategoryFollowsCategoryOrderAndDropsEmpties(): void
    {
        $result = new ScanResult();
        $result->addFinding($this->finding(Category::TEMPLATES, Finding::SEVERITY_WARNING, 'a'));
        $result->addFinding($this->finding(Category::PHP, Finding::SEVERITY_WARNING, 'b'));

        self::assertSame([Category::PHP, Category::TEMPLATES], array_keys($result->groupedByCategory()));
    }

    // -----------------------------------------------------------------
    // Comparison between scans
    // -----------------------------------------------------------------

    public function testFingerprintsCombineCheckAndTarget(): void
    {
        $finding = $this->finding(Category::PHP, Finding::SEVERITY_WARNING, 'opcache');
        $finding->target = 'opcache.memory_consumption';

        self::assertSame('opcache:opcache.memory_consumption', $finding->fingerprint());
    }

    public function testNewSinceIdentifiesFindingsAbsentFromTheEarlierScan(): void
    {
        $before = new ScanResult();
        $before->addFinding($this->finding(Category::PHP, Finding::SEVERITY_WARNING, 'opcache'));

        $after = new ScanResult();
        $after->addFinding($this->finding(Category::PHP, Finding::SEVERITY_WARNING, 'opcache'));
        $after->addFinding($this->finding(Category::PHP, Finding::SEVERITY_CRITICAL, 'memoryLimit'));

        $new = $after->newSince($before);

        self::assertCount(1, $new);
        self::assertSame('memoryLimit', $new[0]->checkHandle);
    }

    public function testResolvedSinceIdentifiesFindingsThatHaveGone(): void
    {
        $before = new ScanResult();
        $before->addFinding($this->finding(Category::PHP, Finding::SEVERITY_WARNING, 'opcache'));
        $before->addFinding($this->finding(Category::PHP, Finding::SEVERITY_WARNING, 'memoryLimit'));

        $after = new ScanResult();
        $after->addFinding($this->finding(Category::PHP, Finding::SEVERITY_WARNING, 'opcache'));

        $resolved = $after->resolvedSince($before);

        self::assertCount(1, $resolved);
        self::assertSame('memoryLimit', $resolved[0]->checkHandle);
    }

    /**
     * With nothing to compare against, everything is new — which is what makes the first
     * scheduled scan send a useful email rather than an empty one.
     */
    public function testEverythingIsNewWhenThereIsNoPreviousScan(): void
    {
        $result = new ScanResult();
        $result->addFinding($this->finding(Category::PHP, Finding::SEVERITY_WARNING));

        self::assertCount(1, $result->newSince(null));
        self::assertSame([], $result->resolvedSince(null));
    }

    public function testResultSurvivesARoundTripThroughAnArray(): void
    {
        $original = new ScanResult();
        $finding = $this->finding(Category::PHP, Finding::SEVERITY_CRITICAL, 'opcache');
        $finding->target = 'opcache.enable';
        $finding->remediation = 'Turn it on.';
        $finding->evidence = [['setting' => 'opcache.enable', 'value' => '0']];
        $original->addFinding($finding);
        $original->runtime = 1.25;

        $restored = ScanResult::fromArray($original->toArray());

        self::assertSame($original->score(), $restored->score());
        self::assertSame(1.25, $restored->runtime);
        self::assertSame('opcache:opcache.enable', $restored->problems()[0]->fingerprint());
        self::assertSame('Turn it on.', $restored->problems()[0]->remediation);
        self::assertSame([['setting' => 'opcache.enable', 'value' => '0']], $restored->problems()[0]->evidence);
    }
}
