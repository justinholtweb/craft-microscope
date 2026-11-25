<?php

declare(strict_types=1);

namespace justinholtweb\microscope\tests\Unit;

use justinholtweb\microscope\models\Settings;
use justinholtweb\microscope\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * The sidebar settings' validation, and the Craft version gate on the MCP tools.
 */
final class NavSettingsTest extends TestCase
{
    public function testSvgMarkupIsOneSvgElement(): void
    {
        self::assertTrue(Settings::isSvgMarkup('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle r="4"/></svg>'));
        self::assertTrue(Settings::isSvgMarkup("<?xml version=\"1.0\"?>\n<!-- icon -->\n<svg viewBox=\"0 0 1 1\"></svg>\n"));

        self::assertFalse(Settings::isSvgMarkup(''));
        self::assertFalse(Settings::isSvgMarkup('/var/www/icon.svg'));
        self::assertFalse(Settings::isSvgMarkup('<div><svg></svg></div>'));
        self::assertFalse(Settings::isSvgMarkup('<svg><g></svg>'));
        self::assertFalse(Settings::isSvgMarkup('<svg></svg><script>alert(1)</script>'));
        self::assertFalse(Settings::isSvgMarkup('<svg>'));
    }

    /**
     * Rejected before any file lookup. That a real name is found is checked against a live Craft.
     */
    public function testIconNamesMustLookLikeIconNames(): void
    {
        self::assertFalse(Settings::iconExists(''));
        self::assertFalse(Settings::iconExists('../../etc/passwd'));
        self::assertFalse(Settings::iconExists('Gauge'));
        self::assertFalse(Settings::iconExists(str_repeat('a', 65)));
        self::assertFalse(Settings::iconExists('gauge.svg'));
    }

    public function testMcpNeedsCraft5121(): void
    {
        self::assertFalse(Plugin::mcpAvailable('5.3.0'));
        self::assertFalse(Plugin::mcpAvailable('5.12.0'));

        $hasServer = class_exists(\craft\services\Mcp::class) && class_exists(\Mcp\Capability\Attribute\McpTool::class);
        self::assertSame($hasServer, Plugin::mcpAvailable('5.12.1'));
        self::assertSame($hasServer, Plugin::mcpAvailable('5.13.0'));
    }
}
