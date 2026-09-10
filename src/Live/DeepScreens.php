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

use Milpa\AgentWorkspace\Data\DesktopData;
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;

/**
 * THE AGENT'S DEEP SCREENS, DECLARED IN ONE PLACE FOR BOTH DOORS.
 *
 * Capabilities, skills, preview and decisions used to be declared inside the shell controller alone,
 * which runs on a `/desktop` request and nowhere else. The moment the panel wanted the same screens as
 * sections of its own, a second declaration site appeared — two lists of the same screens, built from
 * the same collaborators, free to drift the first time one gained a screen the other did not
 * (greenhouse decisions/0268).
 *
 * So the list lives here and both callers ask for it: the shell controller for its page, the plugin for
 * the sections it declares under Agent. Declaring a name twice replaces it, which is what makes this
 * safe to call from both — {@see DesktopComponents::declare()}.
 *
 * The Settings screen takes an INSTANCE rather than being built here: it needs a signing secret where
 * these take the registry's codec, and both hosts already have one built — the shell an injected
 * instance it prefers, the plugin the same one from the container. Building a second here would give
 * the panel a screen signed with a different key than the one its interactions post back to.
 */
final class DeepScreens
{
    /**
     * The screens each door declares on the registry it renders from.
     *
     * @param string $principal who the Decisions inbox is answering as — empty when nobody is named,
     *                          which is the panel's case until the section learns its request's principal
     * @param bool   $hidden    whether these screens paint hidden. TRUE for the `/desktop` shell, where
     *                          each is one of several stacked views and `desktop-sidebar.js` toggles the
     *                          attribute on navigation; FALSE for the panel, where one screen IS the page
     *                          ({@see ScreenVisibility})
     */
    public static function declareOn(
        DesktopComponents $live,
        ?DesktopData $data,
        ?MilpaEventDispatcherInterface $events,
        Catalog $catalog,
        string $principal = '',
        bool $hidden = true,
        ?SettingsScreen $settings = null,
    ): void {
        // Declared with the rest when the caller has one, because it is one of the screens both doors
        // show and leaving it out cost exactly what this list exists to prevent: the shell declared it
        // at boot with the shell's own visibility, so the panel's Settings section painted hidden while
        // its siblings painted visible (greenhouse decisions/0268).
        if ($settings !== null) {
            $live->declare(
                new SettingsScreenComponent(),
                static fn (array $props): string => $settings->render($hidden),
            );
        }
        $live->declare(
            new CapabilitiesScreenComponent(),
            static fn (array $props): string => (new CapabilitiesScreen($live->codec(), $data, $events, $catalog))->render($hidden),
        );
        $live->declare(
            new SkillsScreenComponent(),
            static fn (array $props): string => (new SkillsScreen($live->codec(), $data, $events, $catalog))->render($hidden),
        );
        $live->declare(
            new ScreenPreviewComponent(),
            static fn (array $props): string => (new ScreenPreview($live->codec(), $data, $events, $catalog))->render($hidden),
        );
        $live->declare(
            new DecisionsInboxComponent(),
            static fn (array $props): string => (new DecisionsInbox(
                $live->codec(),
                $data,
                $events,
                $catalog,
                // The render request's prop wins over the caller's default: the shell knows who is
                // signed in per request, and a screen answering as the wrong principal is worse than
                // one answering as nobody.
                \is_string($props['principal'] ?? null) && $props['principal'] !== '' ? $props['principal'] : $principal,
            ))->render($hidden),
        );
    }
}
