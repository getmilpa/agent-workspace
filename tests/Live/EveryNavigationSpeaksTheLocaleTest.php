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

use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\AgentWorkspace\Live\Sidebar;
use Milpa\AgentWorkspace\Live\Tabs;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 EVERY NAVIGATION LABEL COMES FROM THE CATALOG, AND THE CATALOG ACTUALLY REACHES IT.
 *
 * Both surfaces carried English literals: a person who chose Spanish got a Spanish panel with an
 * English navigation, which is the one thing the house's language rule forbids outright
 * (greenhouse decisions/0139).
 *
 * And the first fix was WORSE THAN THE BUG in one way: the labels became catalog keys and nobody
 * passed the catalog, so the surface fell back to English and looked exactly as broken while the
 * source looked correct — a fix declared and not wired, which greenhouse decisions/0213 names as
 * the defect that reads like a capability. So this asserts the SPANISH STRING on a rendered
 * surface, not the presence of a key (greenhouse decisions/0270).
 */
final class EveryNavigationSpeaksTheLocaleTest extends TestCase
{
    /** The shell's own nav, in Spanish, painted. */
    public function testTheSidebarPaintsSpanishWhenTheLocaleIsSpanish(): void
    {
        $html = (new Sidebar('test-sidebar-secret-0123456789', null, null, new Catalog('es')))->render();

        self::assertStringContainsString('Sesiones', $html);
        self::assertStringContainsString('Ajustes', $html);
        self::assertStringContainsString('Vista previa', $html);
        self::assertStringNotContainsString('>Sessions<', $html, 'not the English default');
    }

    /** The tablist, in Spanish, painted. */
    public function testTheTablistPaintsSpanishWhenTheLocaleIsSpanish(): void
    {
        $html = (new Tabs('test-tabs-secret-0123456789', null, new Catalog('es')))->render();

        self::assertStringContainsString('Conversación', $html);
        self::assertStringContainsString('Decisiones', $html);
        self::assertStringContainsString('Contexto', $html);
        self::assertStringNotContainsString('>Conversation<', $html, 'not the English default');
    }

    /** English is the default, and a host that named no locale gets it rather than a fatal. */
    public function testWithNoCatalogAtAllBothAnswerInEnglish(): void
    {
        self::assertStringContainsString('Sessions', (new Sidebar('test-sidebar-secret-0123456789'))->render());
        self::assertStringContainsString('Conversation', (new Tabs('test-tabs-secret-0123456789'))->render());
    }

    /**
     * THE CONTROL: no navigation label is a literal in either surface's source.
     *
     * Asserted over the source, because a label can be reintroduced as a literal in a fallback or a
     * second list, and a test that only read one render would pass while the literal lived elsewhere.
     */
    public function testNeitherSurfaceCarriesAnEnglishLabelAsALiteral(): void
    {
        foreach (['Sidebar', 'Tabs'] as $surface) {
            $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Live/' . $surface . '.php');
            foreach (["'Sessions'", "'Conversation'", "'Settings'", "'Activity'", "'Context'"] as $literal) {
                self::assertStringNotContainsString($literal, $source, $surface . ' names a label instead of a catalog key');
            }
        }
    }
}
