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

namespace Milpa\AgentWorkspace\Live;

use Milpa\Runtime\Config;

/**
 * Where the panel's sections live — when there IS a panel (greenhouse decisions/0255).
 *
 * ── WHY THIS IS NOT A CONSTANT ──────────────────────────────────────────────────────────────────
 *
 * The Desktop deliberately does not depend on milpa/admin: {@see \Milpa\AgentWorkspace\Admin\AdminGuest}
 * exists so a fresh app WITHOUT the panel boots and serves `/desktop`. So a surface here that wants to
 * send someone to a panel section has two ways to get the address wrong, and both are the same mistake:
 * assuming there is a panel, and assuming where it is mounted.
 *
 * The panel's mount point is CONFIGURABLE (`admin.route`). Copying its default here would put the same
 * value in two packages, which is precisely where two truths drift apart — so the default is read from
 * the class that owns it, `AdminSettings::DEFAULT_ROUTE`, and never retyped.
 *
 * The `class_exists` is a declared behaviour, not a hidden coupling (the failure mode named in greenhouse
 * decisions/0225): **no panel, no link**. A dead link to a page that does not exist in this app is worse
 * than a sentence that simply does not offer one, and `stack()` returning `''` is what the renderer reads
 * to say the state without offering a way out that is not there.
 */
final readonly class PanelLink
{
    /** The panel's own class, named as a string so this file loads in an app that does not have it. */
    private const string ADMIN_SETTINGS = 'Milpa\\Admin\\AdminSettings';

    /** The id of the panel section that reports the backing services — the Stack. */
    public const string STACK = 'stack';

    private function __construct(private string $route) {}

    /**
     * Read the panel's mount point from the app's config, or answer that there is no panel.
     *
     * A declared `admin.route` wins; with none declared the panel's OWN default is asked of the panel.
     * With no panel installed at all the route is empty, and every link this resolves is empty with it.
     */
    public static function fromConfig(?Config $config): self
    {
        $raw = $config?->get('admin');
        $declared = \is_array($raw) ? ($raw['route'] ?? null) : null;
        if (\is_string($declared) && $declared !== '' && str_starts_with($declared, '/')) {
            return new self(rtrim($declared, '/'));
        }
        if (!class_exists(self::ADMIN_SETTINGS)) {
            return new self('');
        }

        /** @var string $default */
        $default = \constant(self::ADMIN_SETTINGS . '::DEFAULT_ROUTE');

        return new self(rtrim($default, '/'));
    }

    /**
     * The URL of one panel section, or `''` when this app has no panel.
     *
     * The shape (`<route>/s/<id>`) is the panel's `sectionUrl()`; it is the one thing this file mirrors
     * rather than reads, because it is not exposed as a constant. A falsifier pins it against the panel's
     * own method so the mirror cannot drift in silence.
     *
     * Private: the Stack is the only section this package has anything to say about, so a public opener
     * onto every section would be surface nobody asked for — and surface nobody uses is the debt this
     * house counts (decisions/0213). It widens the day a second destination exists.
     */
    private function section(string $id): string
    {
        return $this->route === '' ? '' : $this->route . '/s/' . $id;
    }

    /** The Stack section — where a service that is down says what starts it (greenhouse decisions/0252). */
    public function stack(): string
    {
        return $this->section(self::STACK);
    }
}
