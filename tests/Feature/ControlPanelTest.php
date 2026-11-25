<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use JustinHoltWeb\Microscope\Data\Finding;
use JustinHoltWeb\Microscope\Jobs\RunScan;
use JustinHoltWeb\Microscope\Models\Settings;
use JustinHoltWeb\Microscope\Tests\Support\FakeCheck;

use function CraftCms\Cms\cp_url;

beforeEach(function () {
    $this->scan = $this->scanWith([new FakeCheck('opcache', 'php', [[Finding::SEVERITY_CRITICAL, 'OPcache is off']])]);
});

it('shows the dashboard, scans, a scan and the checks', function () {
    $this->actingAs($this->admin());

    $this->get(cp_url('microscope'))->assertOk()->assertSee('OPcache is off');
    $this->get(cp_url('microscope/scans'))->assertOk();
    $this->get(cp_url('microscope/scans/'.$this->scan->id))->assertOk()->assertSee('OPcache is off');
    $this->get(cp_url('microscope/scans/'.$this->scan->id.'/report'))->assertOk()->assertSee('OPcache is off');
    $this->get(cp_url('microscope/checks'))->assertOk()->assertSee('opcache');
    $this->get(cp_url('settings/plugins/microscope'))->assertOk();
});

it('queues a scan from the control panel', function () {
    Bus::fake([RunScan::class]);
    $this->actingAs($this->admin());

    $this->post(cp_url('microscope/scans/run'), ['return' => 'microscope/scans'])->assertRedirect(cp_url('microscope/scans'));
    $this->post(cp_url('microscope/scans/run'), ['return' => 'https://evil.example.com'])->assertRedirect(cp_url('microscope'));

    Bus::assertDispatchedTimes(RunScan::class, 2);
});

it('turns a check off and on', function () {
    $this->actingAs($this->admin());

    $this->postJson(cp_url('microscope/checks/toggle'), ['handle' => 'opcache', 'enabled' => false])->assertOk()->assertJson(['enabled' => false]);
    expect(app(Settings::class)->checkIsEnabled('opcache'))->toBeFalse();

    $this->postJson(cp_url('microscope/checks/toggle'), ['handle' => 'nope', 'enabled' => true])->assertStatus(400);
});

it('keeps Microscope from people without its permissions', function () {
    $this->actingAs($this->userWith([]));
    $this->get(cp_url('microscope'))->assertForbidden();

    $this->actingAs($this->userWith(['microscope:view'], 'viewer'));
    $this->get(cp_url('microscope'))->assertOk();
    $this->post(cp_url('microscope/scans/run'))->assertForbidden();
    $this->postJson(cp_url('microscope/checks/toggle'), ['handle' => 'opcache', 'enabled' => false])->assertForbidden();
});
