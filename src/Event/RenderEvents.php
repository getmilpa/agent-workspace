<?php

/**
 * This file is part of milpa/agent-workspace — the agent's workspace, a section of the Milpa panel.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent-workspace
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Event;

use Milpa\AgentWorkspace\Live\ComposerRender;
use Milpa\Interfaces\Event\EventDeclaration;

/**
 * The declaration of one surface's render pair — `before_render` / `after_render` — built from the SAME
 * constants the surface dispatches with (greenhouse decisions/0228): the two names, the payload key, and
 * the class whose `render()` calls `dispatch()`. Every Desktop surface carries a mutable
 * {@see ComposerRender} under its own camelCase key — the props before the mount, the HTML after it — so
 * the two declarations differ only in their name and their moment. A helper so that no surface retypes
 * a name: what it declares is what it dispatches, or the falsifier goes red.
 */
final class RenderEvents
{
    /**
     * The two declarations of a surface's render events.
     *
     * @param class-string $emitter    the class whose `render()` dispatches both names
     * @param string       $before     the surface's `BEFORE_RENDER` constant
     * @param string       $after      the surface's `AFTER_RENDER` constant
     * @param string       $subjectKey the surface's `SUBJECT_KEY` constant — the payload key the subject travels under
     * @param string       $surface    the surface, as a noun phrase for the prose («the sidebar»)
     *
     * @return list<EventDeclaration>
     */
    public static function of(string $emitter, string $before, string $after, string $subjectKey, string $surface): array
    {
        return [
            new EventDeclaration(
                name: $before,
                dispatchedBy: $emitter,
                when: sprintf('Before %s mounts, with the props it will mount with.', $surface),
                subjectKey: $subjectKey,
                subjectType: ComposerRender::class,
                mutable: true,
            ),
            new EventDeclaration(
                name: $after,
                dispatchedBy: $emitter,
                when: sprintf('After %s rendered, with the HTML it produced.', $surface),
                subjectKey: $subjectKey,
                subjectType: ComposerRender::class,
                mutable: true,
            ),
        ];
    }
}
