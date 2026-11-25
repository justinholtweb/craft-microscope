<?php

declare(strict_types=1);

use CraftCms\Cms\Cp\Data\NavItem;
use JustinHoltWeb\Microscope\Models\Settings;
use JustinHoltWeb\Microscope\Plugin;

it('stores list settings as lists, from text or arrays', function () {
    $settings = new Settings;
    $settings->notifyRecipients = "a@example.com\n\n b@example.com, a@example.com";
    $settings->templateDirectories = ['modules/x/templates', ' '];

    expect($settings->validate())->toBeTrue()
        ->and($settings->notifyRecipients)->toBe(['a@example.com', 'b@example.com'])
        ->and($settings->templateDirectories)->toBe(['modules/x/templates']);
});

it('checks recipients only while notifications are on, and never $ENV_VAR ones', function () {
    $settings = new Settings;
    $settings->notifyRecipients = ['not-an-email', '$OPS_EMAIL'];

    expect($settings->validate())->toBeTrue();

    $settings->notifyEnabled = true;
    expect($settings->validate())->toBeFalse()
        ->and($settings->errors()->get('notifyRecipients'))->toHaveCount(1);
});

it('accepts one <svg> and real icons, and refuses the rest', function () {
    $settings = new Settings;

    $settings->navIconSvg = '<svg viewBox="0 0 16 16"><path d="M0 0h16v16H0z"/></svg>';
    $settings->navIcon = 'microscope';
    expect($settings->validate())->toBeTrue();

    $settings->navIconSvg = '/etc/passwd';
    $settings->navIcon = 'not-a-real-icon-name';
    expect($settings->validate())->toBeFalse()
        ->and($settings->errors()->keys())->toContain('navIconSvg', 'navIcon');

    $settings->navIconSvg = null;
    $settings->navIcon = null;
    $settings->webhookUrl = 'ftp://example.com';
    expect($settings->validate())->toBeFalse();
    $settings->webhookUrl = '$MICROSCOPE_WEBHOOK';
    expect($settings->validate())->toBeTrue();
});

it('draws the custom nav label and a sanitised SVG, and never breaks the nav', function () {
    $plugin = Plugin::getInstance();
    $settings = new Settings;
    $settings->navLabel = 'Perf';
    $settings->navIconSvg = '<svg viewBox="0 0 16 16" onload="alert(1)"><script>alert(1)</script><path d="M0 0h16v16H0z"/></svg>';

    $item = $plugin->customiseNavItem(new NavItem()->label('Microscope')->icon('x'), $settings);

    expect($item->label)->toBe('Perf')
        ->and($item->iconSvg)->toContain('<svg')
        ->and($item->iconSvg)->not->toContain('onload')
        ->and($item->iconSvg)->not->toContain('<script');

    $settings->navIconSvg = '<svg><unclosed';
    $settings->navIcon = 'not-a-real-icon-name';
    expect($plugin->customiseNavItem(new NavItem()->label('Microscope')->icon('x'), $settings)->icon)->toBe('x');
});
