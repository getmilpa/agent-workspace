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

namespace Milpa\AgentWorkspace\Admin;

use Milpa\AgentWorkspace\Live\DesktopAssets;
use Milpa\AgentWorkspace\Live\DesktopComponents;
use Milpa\AgentWorkspace\Live\ShellSignals;
use Milpa\AgentWorkspace\I18n\Catalog;

/**
 * ONE OF THE AGENT'S DEEP SCREENS, DECLARED AS A PANEL SECTION OF ITS OWN.
 *
 * Settings, skills and capabilities were reachable through exactly one door: the `/desktop` page,
 * whose own navigation switched between them. So the panel could show the conversation and nothing
 * else about the agent, and the page Rod asked to retire could not go — retiring it would have
 * orphaned every screen behind it (greenhouse decisions/0268).
 *
 * 🚨 THE CHEAP ANSWER WOULD HAVE BEEN A `settings` SLOT ON THE ADMIN'S SECTION CONTRACT. The true one
 * is that these screens ARE sections and only ever needed a way to say whose they are: `{route}/s/{id}`
 * already routes any declared id, one middleware stack already covers every panel route, and the
 * catalogue already orders them. What was missing was one optional field, `parent` — so each screen
 * becomes a section under Agent, out of the main navigation, behind its gear.
 *
 * The markup is the screen's own component as the single root. The registry's other surfaces still
 * travel with the view, for the reason {@see AgentView} gives: the panel's composite registry and its
 * live wire must be able to resolve anything the screen painted inside itself.
 */
final class ScreenView
{
    /**
     * One screen, as the view a panel section renders.
     *
     * The markup is that screen's own component as the single root; the registry's other surfaces still
     * travel with it, so the panel's composite registry and its live wire can resolve anything the
     * screen painted inside itself.
     *
     * @param DesktopComponents    $live    the Desktop's ONE registry — the shell's instances, not copies
     * @param string               $name    the screen component's contract name, already declared on `$live`
     * @param string               $region  the dom id the section's root gets
     * @param Catalog              $catalog the Desktop's copy in its declared locale
     * @param array<string, mixed> $props   the screen component's props, if it takes any
     */
    public static function of(
        DesktopComponents $live,
        string $name,
        string $region,
        Catalog $catalog,
        array $props = [],
    ): \Milpa\Admin\Section\DeclaredView {
        $definitions = [];
        $renderers = [];
        foreach (AgentView::surfacesOf($live) as $surface => [$definition, $renderer]) {
            $definitions[$surface] = $definition;
            $renderers[$surface] = $renderer;
        }

        return new \Milpa\Admin\Section\DeclaredView(
            markup: '<milpa:' . $name . ' id="' . $region . '"/>',
            definitions: $definitions,
            renderers: $renderers,
            props: $props === [] ? [] : [$name => $props],
            // 🚨 THE VIEW DECLARES THE DESKTOP RUNTIME, because no component of it owns those modules —
            // they have no surface to paint. The standalone page emitted them by hand in its template,
            // so the same screens worked there and shipped DEAD here: the panel's Settings section
            // rendered perfectly and its Save button fired nothing, the console saying the guard module
            // was not loaded (greenhouse decisions/0272).
            assets: DesktopAssets::runtimeAssets(),
            signals: ShellSignals::of($catalog, null),
            computed: ShellSignals::computed(),
        );
    }
}
