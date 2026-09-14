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
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;

/**
 * THE AGENT'S DEEP SCREENS, DECLARED IN ONE PLACE FOR BOTH DOORS.
 *
 * Skills, preview and decisions used to be declared inside the shell controller alone,
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
        foreach (self::paints($data, $events, $catalog, $hidden, $settings) as $class => $paint) {
            if ($paint !== null) {
                $live->declare(new $class(), static fn (array $props): string => $paint($live, $props));
            }
        }
    }

    /**
     * The screens this class declares — the same map {@see declareOn()} walks, asked for its keys.
     *
     * Settings is in the list whether or not a host hands an instance: the list says what this class
     * DECLARES, and a host that has the screen declares it (greenhouse decisions/0388).
     *
     * @return list<class-string<ComponentDefinitionInterface>>
     */
    public static function components(): array
    {
        return array_keys(self::paints(null, null, new Catalog(), true, null));
    }

    /**
     * Every deep screen's definition, mapped to what paints it — or to `null` when the host handed nothing
     * to paint it with, which is the Settings screen without its instance.
     *
     * @return array<class-string<ComponentDefinitionInterface>, ?\Closure(DesktopComponents, array<string, mixed>): string>
     */
    private static function paints(
        ?DesktopData $data,
        ?MilpaEventDispatcherInterface $events,
        Catalog $catalog,
        bool $hidden,
        ?SettingsScreen $settings,
    ): array {
        return [
            // Declared with the rest when the caller has one: it is one of the screens both doors show, and
            // leaving it out is what this list exists to prevent — the panel's Settings section once painted
            // hidden while its siblings painted visible (greenhouse decisions/0268).
            SettingsScreenComponent::class => $settings === null ? null : static fn (DesktopComponents $live, array $props): string => $settings->render($hidden),
            SkillsScreenComponent::class => static fn (DesktopComponents $live, array $props): string => (new SkillsScreen($live->codec(), $data, $events, $catalog))->render($hidden),
            SubagentsScreenComponent::class => static fn (DesktopComponents $live, array $props): string => (new SubagentsScreen($live->codec(), $data, $events, $catalog))->render($hidden),
            ScreenPreviewComponent::class => static fn (DesktopComponents $live, array $props): string => (new ScreenPreview($live->codec(), $data, $events, $catalog))->render($hidden),
            // NOT `desktop-decisions`: the inbox is a REGION surface, not a deep screen — no section paints it,
            // the Agent region does, so it lives in {@see Surfaces}. Declaring it here made the region depend
            // on the sections path having run first (greenhouse decisions/0283).
        ];
    }
}
