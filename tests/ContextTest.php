<?php

/**
 * This file is part of milpa/agent-workspace — the agent's workspace inside a Milpa app.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent-workspace
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Tests;

use Milpa\AgentWorkspace\Live\Context;
use Milpa\AgentWorkspace\Live\ContextComponent;
use Milpa\Eventing\EventDispatcher;
use Milpa\Live\ValueObjects\ComponentContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The Context tab is the shell's sixth pure-Milpa-Components surface (greenhouse decisions/0189): the container
 * of plugin-contributed panels, as a projection component with a signed envelope and lifecycle events.
 */
final class ContextTest extends TestCase
{
    public function testItRendersContributedPanelsAsAMilpaLiveComponent(): void
    {
        $panels = [
            ['id' => 'plan', 'title' => 'The plan', 'html' => '<p>step one</p>'],
            ['id' => 'files', 'title' => null, 'html' => '<p>no title panel</p>'],
        ];

        $html = (new Context('secret'))->render($panels);

        self::assertStringContainsString('data-milpa-component="desktop-context"', $html);
        self::assertStringContainsString('data-milpa-state="context"', $html);
        self::assertStringContainsString('security="signed"', $html);
        self::assertStringContainsString('class="panel-grid"', $html);
        // Each panel keeps data-panel + data-panel-body so live updates (MilpaShell.panel) still target them.
        self::assertStringContainsString('data-panel="plan"', $html);
        self::assertStringContainsString('data-panel-body', $html);
        self::assertStringContainsString('The plan', $html);
        self::assertStringContainsString('step one', $html);
        self::assertStringContainsString('no title panel', $html);
    }

    /**
     * WITHOUT DATA THERE IS NOTHING TO SAY, and it says nothing — no note, no placeholder.
     *
     * This tab used to render ONLY what plugins contributed, so an app with no contributing plugin
     * opened «Context» and read «No plugin has contributed a panel yet» — an answer about the
     * extension mechanism to somebody asking what the agent can see. Rod's words: «ahí deberían
     * aparecer los datos del contexto». The developer note is gone; the data took its place
     * (greenhouse decisions/0288).
     */
    public function testWithoutDataItStillMountsAndSaysNothingItCannotKnow(): void
    {
        $html = (new Context('secret'))->render([]);

        self::assertStringContainsString('data-milpa-component="desktop-context"', $html, 'it is still a component');
        self::assertStringNotContainsString('addPanel', $html, 'the extension mechanism is not an answer to «what is my context»');
        self::assertStringNotContainsString('mui-empty', $html, 'and a tab with real content does not need an empty state for the region that has none');
        self::assertStringNotContainsString('ctx-grid', $html, 'with no data, no card: zeros stated as measurements would be worse than silence');
    }

    public function testItEmitsRenderEventsSoPluginsCanExtendIt(): void
    {
        $events = new EventDispatcher(new NullLogger());
        $events->subscribe(Context::BEFORE_RENDER, static function (string $n, array $p): void {
            $p['context']->props['panels'] = [['id' => 'injected', 'title' => 'Injected panel', 'html' => '<p>x</p>']];
        });
        $events->subscribe(Context::AFTER_RENDER, static function (string $n, array $p): void {
            $p['context']->html .= '<!-- context extended -->';
        });

        $html = (new Context('secret', $events))->render([]);

        self::assertStringContainsString('context extended', $html, 'after_render changed the html');
        self::assertStringContainsString('Injected panel', $html, 'before_render changed the props');
    }

    public function testTheContractIsAProjectionSurfaceWithNoActions(): void
    {
        $contract = ContextComponent::contract();
        self::assertSame('desktop-context', $contract->name);
        self::assertSame([], $contract->actions);

        $component = new ContextComponent();
        $state = $component->mount(['panels' => [['id' => 'a', 'title' => null, 'html' => '']]], new ComponentContext('context'));
        self::assertSame(1, $state->data['count']);
    }
}
