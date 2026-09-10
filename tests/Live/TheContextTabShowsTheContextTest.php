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

use Milpa\AgentWorkspace\Data\DesktopData;
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\AgentWorkspace\Live\Context;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * THE TAB NAMED CONTEXT SHOWS THE CONTEXT — it used to show a note about a plugin API.
 *
 * `Context` was the one surface in the Agent region built WITHOUT `DesktopData`: the line above it in
 * `AgentWorkspacePlugin` gives `Activity` its data, and every sibling gets it too. So the tab rendered
 * nothing but the panels plugins contribute, and an app with no contributing plugin opened «Context»
 * and read «No plugin has contributed a panel yet» — an answer about the extension mechanism to
 * somebody asking what the agent can see. Rod, on seeing it: «ahí deberían aparecer los datos del
 * contexto» (greenhouse decisions/0288).
 *
 * Every number the tab paints is READ, never derived for display, and this file asserts that by giving
 * the data source a known state and looking for that state in the HTML.
 */
#[CoversClass(Context::class)]
final class TheContextTabShowsTheContextTest extends TestCase
{
    /** A data source whose context window is a known 8192 of 32768. */
    private function data(): DesktopData
    {
        $dir = sys_get_temp_dir() . '/milpa-ctx-tab-' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/s.json', json_encode(['tokens' => 8192], \JSON_THROW_ON_ERROR));
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [],
            'config' => ['agent' => ['context_window' => 32768]],
        ]);
        $kernel->container()->registerService(Kernel::class, $kernel);

        return new DesktopData($kernel->container(), null, $dir);
    }

    /** The window card states the measurement, and the meter's fill IS the percentage it claims. */
    public function testTheWindowCardShowsWhatWasMeasuredNotAGuess(): void
    {
        $html = (new Context('secret', null, $this->data(), new Catalog()))->render([]);

        self::assertStringContainsString('ctx-grid', $html, 'the context is the tab\'s own content now');
        self::assertStringContainsString('8.2K / 32.8K', $html, 'the used tokens over the window, read from the ledger and the config');
        self::assertStringContainsString('25% used', $html, 'and the percentage the data source computed');
        self::assertStringContainsString('width:25%', $html, 'the meter FILL is that same percentage — a bar that disagrees with its label is worse than no bar');
    }

    /** A stat's value slot carries the quantity; the sentence explaining it does not belong there. */
    public function testTheSentenceIsNeverSetInTheValueSlot(): void
    {
        $html = (new Context('secret', null, $this->data(), new Catalog()))->render([]);

        self::assertMatchesRegularExpression('#<p class="mui-stat__value">[\d.KM /]+</p>#', $html, 'the value is digits and a suffix, never prose — the first build set «nothing has been sent yet» in display type');
    }

    /** What the model is, and WHERE that was declared — a name with no provenance is a guess. */
    public function testTheModelCardNamesItsSourceOrSaysThereIsNone(): void
    {
        $html = (new Context('secret', null, new DesktopData(new DIContainer()), new Catalog()))->render([]);

        self::assertStringContainsString('No model declared', $html);
        self::assertStringContainsString('no endpoint declared, so nothing was asked', $html, 'it says nothing was asked rather than implying a failed request');
    }

    /** A plugin's panels are an ADDITION under their own heading, not the tab's content. */
    public function testPluginPanelsAppearUnderTheirOwnHeadingAfterTheContext(): void
    {
        $html = (new Context('secret', null, $this->data(), new Catalog()))->render([
            ['id' => 'mine', 'title' => 'A panel', 'html' => '<b>from a plugin</b>'],
        ]);

        self::assertStringContainsString('Plugin panels', $html, 'contributed panels are labelled as what they are');
        self::assertStringContainsString('from a plugin', $html, 'and still rendered');
        self::assertLessThan(
            strpos($html, 'from a plugin'),
            strpos($html, 'ctx-grid'),
            'the context comes FIRST: it is the answer to the tab\'s name, the panels are an addition',
        );
    }

    /** And with no panels there is no heading and no placeholder for them. */
    public function testWithNoPanelsThereIsNoHeadingAndNoPlaceholder(): void
    {
        $html = (new Context('secret', null, $this->data(), new Catalog()))->render([]);

        self::assertStringNotContainsString('Plugin panels', $html);
        self::assertStringNotContainsString('addPanel', $html, 'the extension mechanism is documentation, not a screen a person reads');
    }

    /** The copy is catalogued, so the tab speaks Spanish when the panel does. */
    public function testItSpeaksTheLocaleThePanelIsIn(): void
    {
        $html = (new Context('secret', null, $this->data(), new Catalog('es')))->render([]);

        self::assertStringContainsString('Ventana de contexto', $html);
        self::assertStringContainsString('25% usada', $html);
        self::assertStringNotContainsString('Context window', $html, 'no English left behind in a Spanish panel');
    }
}
