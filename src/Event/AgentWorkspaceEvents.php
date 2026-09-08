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

use Milpa\AgentWorkspace\Controllers\ShellController;
use Milpa\AgentWorkspace\Live\Activity;
use Milpa\AgentWorkspace\Live\AgentMessage;
use Milpa\AgentWorkspace\Live\AuthOverlay;
use Milpa\AgentWorkspace\Live\CapabilitiesScreen;
use Milpa\AgentWorkspace\Live\ComposerBar;
use Milpa\AgentWorkspace\Live\ComposerField;
use Milpa\AgentWorkspace\Live\Context;
use Milpa\AgentWorkspace\Live\Conversation;
use Milpa\AgentWorkspace\Live\DecisionsInbox;
use Milpa\AgentWorkspace\Live\Gate;
use Milpa\AgentWorkspace\Live\MessagePrototypes;
use Milpa\AgentWorkspace\Live\ScreenPreview;
use Milpa\AgentWorkspace\Live\SessionStrip;
use Milpa\AgentWorkspace\Live\SettingsScreen;
use Milpa\AgentWorkspace\Live\Sidebar;
use Milpa\AgentWorkspace\Live\SkillsScreen;
use Milpa\AgentWorkspace\Live\StatusBar;
use Milpa\AgentWorkspace\Live\Tabs;
use Milpa\AgentWorkspace\Live\Thinking;
use Milpa\AgentWorkspace\Live\Topbar;
use Milpa\AgentWorkspace\Live\WorkBoard;
use Milpa\Interfaces\Event\EventDeclaration;

/**
 * Every event this package dispatches, declared (greenhouse decisions/0228).
 *
 * The authority on «what events exist» is the emitter, and the one place every dispatch passes through
 * is the dispatcher — so this package declares its events TO the dispatcher, where it receives it
 * ({@see \Milpa\AgentWorkspace\AgentWorkspacePlugin::boot()}), and the house counts them. Each emitter
 * answers `events()` from its own constants — the same `BEFORE_RENDER` / `AFTER_RENDER` / `SUBJECT_KEY`
 * its `dispatch()` reads — and this class only gathers the answers: it retypes no name. The surfaces'
 * names are irregular (`ScreenPreview` dispatches `desktop.screens.*`, `StatusBar` `desktop.statusbar.*`,
 * `WorkBoard` `desktop.work_board.*`), which is exactly why the declaration is built, never copied.
 *
 * `desktop.shell.changed` ({@see \Milpa\AgentWorkspace\AgentWorkspacePlugin::CHANGED_EVENT}) is NOT here:
 * this package names it and subscribes to it, but the plugin that pushes a live update is the one that
 * dispatches it, and a declaration belongs to the emitter.
 */
final class AgentWorkspaceEvents
{
    /**
     * One declaration per event name this package dispatches, in the order the shell paints its surfaces.
     *
     * @return list<EventDeclaration>
     */
    public static function declarations(): array
    {
        return [
            ...ShellController::events(),
            ...Sidebar::events(),
            ...Topbar::events(),
            ...SessionStrip::events(),
            ...Tabs::events(),
            ...Conversation::events(),
            ...Gate::events(),
            ...WorkBoard::events(),
            ...Activity::events(),
            ...Context::events(),
            ...ComposerBar::events(),
            ...ComposerField::events(),
            ...Thinking::events(),
            ...AgentMessage::events(),
            ...MessagePrototypes::events(),
            ...SettingsScreen::events(),
            ...CapabilitiesScreen::events(),
            ...SkillsScreen::events(),
            ...ScreenPreview::events(),
            ...DecisionsInbox::events(),
            ...StatusBar::events(),
            ...AuthOverlay::events(),
        ];
    }
}
