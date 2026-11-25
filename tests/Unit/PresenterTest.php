<?php

declare(strict_types=1);

namespace justinholtweb\microscope\tests\Unit;

use DateTime;
use justinholtweb\microscope\helpers\Presenter;
use justinholtweb\microscope\models\Category;
use justinholtweb\microscope\models\Finding;
use justinholtweb\microscope\models\Scan;
use justinholtweb\microscope\models\ScanResult;
use PHPUnit\Framework\TestCase;

/**
 * The MCP tools and GraphQL share these shapes, and so do Microscope 5 and 6. Renaming a key is a
 * breaking change for every agent and query using it, so the keys are pinned here.
 */
final class PresenterTest extends TestCase
{
    private function finding(string $severity, string $check, ?string $target = null, string $category = Category::PHP): Finding
    {
        $finding = new Finding();
        $finding->checkHandle = $check;
        $finding->category = $category;
        $finding->severity = $severity;
        $finding->title = $check;
        $finding->target = $target;

        return $finding;
    }

    public function testScanSummaryShape(): void
    {
        $scan = new Scan();
        $scan->id = 7;
        $scan->status = 'completed';
        $scan->trigger = 'manual';
        $scan->score = 84;
        $scan->criticalCount = 1;
        $scan->warningCount = 2;
        $scan->runtime = 1.5;
        $scan->categoryScores = ['php' => 75, 'craft' => '90'];
        $scan->dateCreated = new DateTime('2026-11-24T10:00:00+00:00');

        $data = Presenter::scan($scan, 'https://example.test/admin/microscope/scans/7');

        self::assertSame([
            'id', 'status', 'trigger', 'score', 'grade', 'criticalCount', 'warningCount', 'noticeCount',
            'passCount', 'runtime', 'error', 'dateStarted', 'dateFinished', 'dateCreated', 'categoryScores', 'url',
        ], array_keys($data));
        self::assertSame('B', $data['grade']);
        self::assertSame(['php' => 75, 'craft' => 90], $data['categoryScores']);
        self::assertSame('2026-11-24T10:00:00+00:00', $data['dateCreated']);
        self::assertNull($data['dateStarted']);
        self::assertSame('https://example.test/admin/microscope/scans/7', $data['url']);
    }

    public function testAnUnscoredScanHasNoGrade(): void
    {
        $data = Presenter::scan(new Scan());

        self::assertNull($data['score']);
        self::assertNull($data['grade']);
    }

    public function testFindingShape(): void
    {
        $finding = $this->finding(Finding::SEVERITY_WARNING, 'opcache', 'opcache.memory_consumption');
        $finding->evidence = array_fill(0, 30, ['row' => 1]);

        $data = Presenter::finding($finding, true, true);

        self::assertSame([
            'checkHandle', 'category', 'severity', 'title', 'summary', 'observed', 'expected', 'impact',
            'remediation', 'docsUrl', 'target', 'occurrences', 'fingerprint', 'isNew', 'evidence', 'evidenceTotal',
        ], array_keys($data));
        self::assertSame('opcache:opcache.memory_consumption', $data['fingerprint']);
        self::assertTrue($data['isNew']);
        self::assertCount(Presenter::EVIDENCE_LIMIT, $data['evidence']);
        self::assertSame(30, $data['evidenceTotal']);

        self::assertArrayNotHasKey('evidence', Presenter::finding($finding));
    }

    public function testFindingsAreSortedAndFiltered(): void
    {
        $result = new ScanResult();
        $result->addFinding($this->finding(Finding::SEVERITY_NOTICE, 'a'));
        $result->addFinding($this->finding(Finding::SEVERITY_PASS, 'b'));
        $result->addFinding($this->finding(Finding::SEVERITY_CRITICAL, 'c'));
        $result->addFinding($this->finding(Finding::SEVERITY_WARNING, 'd', null, Category::DATABASE));

        $handles = fn(array $findings) => array_column($findings, 'checkHandle');

        self::assertSame(['c', 'd', 'a'], $handles(Presenter::findings($result)));
        self::assertSame(['c', 'd', 'a', 'b'], $handles(Presenter::findings($result, includePasses: true)));
        self::assertSame(['b'], $handles(Presenter::findings($result, severity: Finding::SEVERITY_PASS)));
        self::assertSame(['d'], $handles(Presenter::findings($result, category: Category::DATABASE)));
        self::assertSame(['a'], $handles(Presenter::findings($result, ['a:'], onlyNew: true)));

        $flags = array_column(Presenter::findings($result, ['a:']), 'isNew', 'checkHandle');
        self::assertSame(['c' => false, 'd' => false, 'a' => true], $flags);
    }

    public function testSkippedAndResolved(): void
    {
        self::assertSame(
            [['checkHandle' => 'memoryLimit', 'reason' => 'CLI']],
            Presenter::skipped(['memoryLimit' => 'CLI']),
        );

        self::assertSame(
            ['checkHandle' => 'x', 'title' => 'x', 'severity' => 'critical', 'target' => 't'],
            Presenter::resolved($this->finding(Finding::SEVERITY_CRITICAL, 'x', 't')),
        );
    }
}
