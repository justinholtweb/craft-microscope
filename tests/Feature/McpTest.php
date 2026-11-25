<?php

declare(strict_types=1);

use CraftCms\Cms\Cms;
use CraftCms\Cms\Mcp\McpActor;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use JustinHoltWeb\Microscope\Data\Finding;
use JustinHoltWeb\Microscope\Jobs\RunScan;
use JustinHoltWeb\Microscope\Mcp\MicroscopeTools;
use JustinHoltWeb\Microscope\Tests\Support\FakeCheck;
use Mcp\Exception\ToolCallException;

/** MCP reads the user from Craft's auth guard; outside HTTP the request has no resolver. */
function mcpAs(object $test, User $user): MicroscopeTools
{
    $test->actingAs($user, Cms::config()->authGuard);
    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);
    app()->instance(McpActor::class, new McpActor($request));

    return app(MicroscopeTools::class);
}

beforeEach(function () {
    $this->scanWith([new FakeCheck('first', 'php', [[Finding::SEVERITY_WARNING, 'Gone later', 'a']])]);
    $this->scan = $this->scanWith([
        new FakeCheck('opcache', 'php', [[Finding::SEVERITY_CRITICAL, 'OPcache is off']]),
        new FakeCheck('db', 'database'),
    ]);
});

it('reports status in the shared shape', function () {
    $status = mcpAs($this, $this->admin())->status();

    expect($status)->toHaveKeys(['scanned', 'latestScan', 'categories', 'newProblems', 'resolvedProblems', 'schedule'])
        ->and($status['scanned'])->toBeTrue()
        ->and($status['latestScan'])->toMatchArray(['id' => $this->scan->id, 'grade' => 'B', 'criticalCount' => 1])
        ->and($status['latestScan']['url'])->toContain('microscope/scans/'.$this->scan->id)
        ->and($status['newProblems'])->toBe(1)
        ->and($status['resolvedProblems'])->toBe(1)
        ->and(array_column($status['categories'], 'category'))->toBe(['php', 'database', 'server', 'craft', 'schema', 'templates']);
});

it('gets a scan’s findings, filtered, with capped evidence', function () {
    $tools = mcpAs($this, $this->admin());
    $result = $tools->getScan();

    expect($result['scan']['id'])->toBe($this->scan->id)
        ->and($result['findings'])->toHaveCount(1)
        ->and($result['findings'][0])->toMatchArray(['severity' => 'critical', 'isNew' => true, 'evidenceTotal' => 30])
        ->and($result['findings'][0]['evidence'])->toHaveCount(25)
        ->and($result['resolved'][0]['title'])->toBe('Gone later');

    expect($tools->getScan(includePasses: true)['findings'])->toHaveCount(2)
        ->and($tools->getScan(category: 'database')['findings'])->toBe([])
        ->and(fn () => $tools->getScan(999999))->toThrow(ToolCallException::class, 'Scan not found.');
});

it('lists scans and checks', function () {
    $tools = mcpAs($this, $this->admin());

    expect($tools->listScans()['total'])->toBe(2)
        ->and($tools->listScans(limit: 1)['scans'])->toHaveCount(1)
        ->and(array_column($tools->listChecks()['checks'], 'handle'))->toContain('opcache', 'db');
});

it('queues a scan as the user, never runs it inline', function () {
    Bus::fake();
    $admin = $this->admin();

    $result = mcpAs($this, $admin)->runScan(['php']);

    expect($result)->toMatchArray(['queued' => true, 'categories' => ['php']]);
    Bus::assertDispatched(RunScan::class, fn (RunScan $job) => $job->config['categories'] === ['php'] && $job->creatorId === $admin->id);
});

it('refuses users without Microscope’s permissions', function () {
    $tools = mcpAs($this, $this->userWith(['microscope:view']));

    expect($tools->status()['scanned'])->toBeTrue()
        ->and(fn () => $tools->runScan())->toThrow(ToolCallException::class, 'You don’t have permission to do that.');
});
