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
 * WHETHER A SCREEN PAINTS HIDDEN IS THE HOST'S CALL, NOT THE SCREEN'S.
 *
 * Every deep screen used to carry a static `hidden`, which assumed exactly one host: the `/desktop`
 * shell, where a screen is one of several stacked views and `desktop-sidebar.js` toggles the attribute
 * on navigation. Rendered anywhere else — as a panel section, where the screen IS the page — it
 * painted a heading over an empty body (greenhouse decisions/0268). A surface receives its context; it
 * does not declare it (greenhouse decisions/0256).
 *
 * 🚨 AND THE ATTRIBUTE IS NOT LOAD-BEARING ON ITS OWN, measured in a real browser: `.milpa-settings`
 * declares `display: flex`, which BEATS the user agent's `[hidden] { display: none }`. So the Settings
 * screen was visible in the panel by accident while its sibling was invisible by accident, both
 * carrying the same attribute. The `/desktop` shell survives that only because one rule,
 * `.desktop-agent [hidden] { display: none !important }`, happens to scope over its views. That is a
 * defect of the shell's own stylesheet, recorded rather than patched here: a screen rendered outside
 * `.desktop-agent` still shows or hides by luck.
 */
final class ScreenVisibility
{
    /**
     * The `hidden` attribute, or nothing.
     *
     * Defaults to hidden so the shell's first paint is byte-for-byte what it was, and the host that
     * renders one screen per page passes `hidden: false`.
     *
     * @param array<string, mixed> $props the render subject's props, as the events may have changed them
     */
    public static function attr(array $props): string
    {
        return ($props['hidden'] ?? true) === false ? '' : ' hidden';
    }
}
