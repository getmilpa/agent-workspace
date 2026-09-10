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

namespace Milpa\AgentWorkspace\Live;

/**
 * WHAT EACH OF THE AGENT'S SCREENS IS CALLED AND DRAWN WITH — owned by neither host.
 *
 * 🚨 THIS CLASS EXISTS BECAUSE I MADE A SURFACE I WAS ABOUT TO RETIRE LOAD-BEARING FOR THE PANEL.
 *
 * `AgentWorkspacePlugin::screenIcon()` read the glyphs out of `Sidebar::NAV`, and I wrote it that way
 * on purpose, with the comment «read from its list, never a second copy». The reasoning was sound about
 * duplication. The consequence was that `Sidebar::NAV` became the only place in the package where those
 * glyphs exist AND a thing the admin panel reads on every request — so deleting the `/desktop` page's
 * sidebar, which the panel replaced, would have been a fatal «class not found» on every panel request.
 *
 * An adversarial mapping of the retirement found it and said the map has to move FIRST because it
 * outlives the page (greenhouse decisions/0272, moved in decisions/0273).
 *
 * So a screen's IDENTITY lives here — its catalog key and its glyph — and each host keeps what is its
 * own: the ORDER it lists them in, and which of them it shows at all. That is the line between the two:
 * what a screen IS belongs to the screen; how a surface arranges them belongs to the surface.
 */
final class Screens
{
    /**
     * Every screen the agent has, by the key both hosts name it with.
     *
     * `title` is a catalog key, never copy: English is the default and Spanish is selectable, and the
     * same key titles the panel's section and the page's nav item, so the two doors can never disagree
     * about what a screen is called (greenhouse decisions/0139).
     *
     * @var array<string, array{title: string, icon: string}>
     */
    public const array ALL = [
        // The conversation itself, which IS the panel's Agent section — its glyph was a literal in the
        // plugin until the guard below caught it. A screen's identity belongs with the screens even
        // when the screen is the room the others hang off (greenhouse decisions/0273).
        //
        // It shares `◈` with `decisions` on purpose: they never appear on one surface. The panel lists
        // `agent` and puts decisions in a TAB; the page's sidebar lists `decisions` and has no `agent`
        // item, because there the conversation is the default view rather than a destination.
        'agent' => ['title' => 'agent.title', 'icon' => '◈'],
        'sessions' => ['title' => 'nav.sessions', 'icon' => '▤'],
        'decisions' => ['title' => 'nav.decisions', 'icon' => '◈'],
        'capabilities' => ['title' => 'nav.capabilities', 'icon' => '▩'],
        'skills' => ['title' => 'nav.skills', 'icon' => '✦'],
        'subagents' => ['title' => 'nav.subagents', 'icon' => '◉'],
        'preview' => ['title' => 'nav.preview', 'icon' => '◱'],
        'settings' => ['title' => 'nav.settings', 'icon' => '⚙'],
    ];

    /**
     * The catalog key a screen is titled by, or the key itself when nobody declared it.
     *
     * The fallback is the key rather than an empty string: a nav item reading `agent-widgets` tells
     * whoever added a screen without declaring it exactly what is missing, and an empty one tells them
     * nothing (greenhouse evidence/0165 — a lie on the first screen is worse than a wrong value).
     */
    public static function title(string $key): string
    {
        return self::ALL[$key]['title'] ?? $key;
    }

    /** The glyph a screen is drawn with, or nothing — an item with no glyph paints its label alone. */
    public static function icon(string $key): string
    {
        return self::ALL[$key]['icon'] ?? '';
    }

    /*
     * NO HAY `inOrder()`, Y SU ÚNICO LLAMADOR ERA EL SIDEBAR DE LA PÁGINA. Existía porque el ORDEN es
     * del anfitrión, no de la pantalla — la página listaba las suyas de una forma y el engrane del panel
     * de otra. Con la página retirada queda un solo anfitrión, y ordena su propia lista
     * (greenhouse decisions/0283).
     *
     * Lo cazó el censo de piezas sin cablear el mismo día, no yo. La observación sobre el orden sigue
     * siendo cierta y vuelve como método cuando haya un segundo anfitrión que ordene distinto.
     */
}
