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

namespace Milpa\AgentWorkspace;

/**
 * The mutable collector other plugins contribute the desktop shell's UI through (greenhouse decisions/0188).
 *
 * When a host composes the workspace, it dispatches {@see self::EVENT}
 * carrying one of these in the payload; any plugin that subscribed to that event (in its own `boot()`)
 * appends sections here, and the controller renders them into the page. This is the seam Rod named:
 * "a plugin renders the UI, and other plugins have events they use to modify that same UI." It is
 * decoupled by construction — the shell never knows which plugins contribute; they meet only at the
 * event name.
 *
 * Sections keep insertion order, which is subscription-priority order (the dispatcher sorts handlers
 * by priority before calling them), so a contributor that must come first subscribes at a higher
 * priority. Contributed HTML is trusted plugin output — plugins are code the app deliberately
 * installed, not request input — so it is emitted verbatim; only the plugin id attribute is escaped.
 *
 * The developer experience (greenhouse decisions/0478): a plugin adds a dashboard panel with one call —
 * {@see addPanel()} for a titled panel the shell wraps in consistent card chrome (the ergonomic default),
 * or {@see addSection()} for a raw fragment that owns its own markup. Either way it lands as a component
 * on the client runtime; the plugin's own script then updates it live via `MilpaShell.panel('<id>')`.
 */
final class ShellComposition
{
    /**
     * The event a host dispatches while composing, with this object as its mutable subject.
     *
     * 🚨 THE NAME USED TO LIVE ON THE PAGE'S CONTROLLER, AND THAT MADE A PUBLIC EXTENSION POINT DIE
     * WITH A SURFACE. `ShellController::COMPOSE_EVENT` was the only dispatcher AND the only renderer of
     * these sections, so retiring the `/desktop` page would have taken a seam third-party plugins
     * subscribe to — silently, since a contribution nobody renders looks exactly like a plugin that
     * contributed nothing. Caught by adversarially mapping the retirement (greenhouse decisions/0283).
     *
     * The string is UNCHANGED on purpose: subscribers name events by string, and renaming a published
     * event to tidy its owner would break every one of them for nothing.
     */
    public const EVENT = 'desktop.shell.compose';

    /** The payload key this object rides under in that event. */
    public const SUBJECT_KEY = 'composition';

    /** @var list<array{id: string, title: string|null, html: string}> */
    private array $sections = [];

    /** Append a raw section of shell HTML contributed by the plugin identified by `$pluginId`. */
    public function addSection(string $pluginId, string $html): void
    {
        $this->sections[] = ['id' => $pluginId, 'title' => null, 'html' => $html];
    }

    /** Append a titled dashboard panel; the shell wraps `$html` in its standard panel card. */
    public function addPanel(string $pluginId, string $title, string $html): void
    {
        $this->sections[] = ['id' => $pluginId, 'title' => $title, 'html' => $html];
    }

    /**
     * The contributed sections, in the order they were added (subscription-priority order).
     *
     * @return list<array{id: string, title: string|null, html: string}>
     */
    public function sections(): array
    {
        return $this->sections;
    }

    /**
     * The seam's declaration, from the same constants a host dispatches with (greenhouse decisions/0228).
     *
     * `dispatchedBy` names no class: the HOSTS dispatch it — the admin panel's Agent region today, and
     * whoever else composes the workspace tomorrow. Naming one of them would be the coupling this move
     * removed.
     *
     * @return list<\Milpa\Interfaces\Event\EventDeclaration>
     */
    public static function events(): array
    {
        return [
            new \Milpa\Interfaces\Event\EventDeclaration(
                name: self::EVENT,
                dispatchedBy: self::class,
                when: 'When a host composes the workspace, before any surface is painted: a plugin adds its panels and sections.',
                subjectKey: self::SUBJECT_KEY,
                subjectType: self::class,
                mutable: true,
            ),
        ];
    }
}
