<?php

/**
 * This file is part of milpa/agent-workspace.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent-workspace
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Tests\Live;

use Milpa\AgentWorkspace\AgentWorkspacePlugin;
use Milpa\AgentWorkspace\Live\Screens;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 THE PANEL MUST NOT NEED THE PAGE'S SIDEBAR IN ORDER TO DRAW ITS SECTIONS.
 *
 * It did. `AgentWorkspacePlugin::screenIcon()` read the glyphs out of `Sidebar::NAV`, written that way
 * on purpose with the comment «read from its list, never a second copy» — sound about duplication, and
 * it made a surface the panel REPLACED into a thing the panel could not boot without. Deleting the
 * page's sidebar, which is what retiring `/desktop` means, would have been a fatal «class not found»
 * on every panel request.
 *
 * An adversarial mapping of that retirement found it and said the map had to move first, because it
 * outlives the page (greenhouse decisions/0272, moved in decisions/0273).
 *
 * This test is the guard that keeps it moved: it asserts, over the SOURCE, that the plugin's section
 * declaration names no page surface at all. Over the source because a runtime assertion would pass
 * while the coupling sat one call away, and the whole point is that the class can be deleted.
 */
final class TheScreensOwnTheirIdentityTest extends TestCase
{
    /** The identity of a screen: its catalog key and its glyph, from the class neither host owns. */
    public function testAScreenIsNamedAndDrawnByTheClassNeitherHostOwns(): void
    {
        self::assertSame('nav.settings', Screens::title('settings'));
        self::assertSame('⚙', Screens::icon('settings'));
        self::assertSame('◉', Screens::icon('subagents'));

        // A key nobody declared answers with ITSELF, never with an empty string: a nav item reading
        // «agent-widgets» tells whoever added a screen without declaring it what is missing.
        self::assertSame('agent-widgets', Screens::title('agent-widgets'));
        self::assertSame('', Screens::icon('agent-widgets'), 'no glyph is a glyph nobody drew');
    }

    /**
     * 🚨 THE GUARD: a glyph exists in EXACTLY ONE PLACE, and that place is not a host.
     *
     * This is the second form of this test. The first read the plugin's source from `screenSections()`
     * onward and asserted it named no page surface — and it passed with the coupling put back, because
     * a helper defined ABOVE that method was outside what it read. A guard with a hole is worse than
     * none: it reports safety it never checked.
     *
     * So the property is asserted where it cannot hide: the glyphs are in `Screens` and in no host's
     * file. Whatever calls what, a host that carries no glyph cannot be the source of one, and the
     * page's surfaces become deletable without the panel noticing (greenhouse decisions/0273).
     */
    public function testAGlyphLivesInExactlyOnePlaceAndItIsNotAHost(): void
    {
        $glyphs = [];
        foreach (Screens::ALL as $screen) {
            $glyphs[] = $screen['icon'];
        }
        self::assertNotSame([], $glyphs, 'this would prove nothing with no glyphs declared');

        // 🚨 ONE HOST LEFT, AND THE OTHER THREE ARE GONE RATHER THAN EXEMPTED. This read the sidebar,
        // the topbar and the status bar — the page's chrome — and they were retired with the page. A
        // list of files that no longer exist does not fail: `file_get_contents` warns and returns
        // false, and the assertion then passes on an empty string. `failOnWarning` is what caught it
        // (greenhouse decisions/0281, decisions/0283).
        foreach (['AgentWorkspacePlugin.php', 'Live/Surfaces.php'] as $host) {
            $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/' . $host);
            foreach ($glyphs as $glyph) {
                self::assertStringNotContainsString(
                    $glyph,
                    $source,
                    $host . ' carries the glyph «' . $glyph . '» — a host holding a screen\'s identity is a host nothing can retire',
                );
            }
        }

        $owner = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Live/Screens.php');
        foreach ($glyphs as $glyph) {
            self::assertStringContainsString($glyph, $owner, 'and the one place that does carry them is Screens');
        }
    }

    /** And every screen the panel declares as a section is one this class knows. */
    public function testEverySectionKeyIsADeclaredScreen(): void
    {
        $reflected = new \ReflectionClass(AgentWorkspacePlugin::class);
        /** @var array<string, class-string> $sections */
        $sections = $reflected->getConstant('SCREEN_SECTIONS');

        self::assertNotSame([], $sections, 'this would prove nothing on an empty list');
        foreach (array_keys($sections) as $key) {
            self::assertArrayHasKey($key, Screens::ALL, 'the panel declares a section «' . $key . '» no screen declares');
        }
    }
}
