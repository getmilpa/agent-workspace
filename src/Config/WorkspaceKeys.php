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

namespace Milpa\AgentWorkspace\Config;

use Milpa\Runtime\Config;

/**
 * THE CONFIG NAMESPACE THIS PACKAGE READS, AND THE ONE IT USED TO.
 *
 * 🚨 THE PACKAGE WAS RENAMED AND ITS VOCABULARY WAS NOT. `milpa/desktop-app` became
 * `milpa/agent-workspace`, the `/desktop` page it was named after is retired (greenhouse
 * decisions/0283), and an app still declared `desktop.middleware` — so a person reading their own
 * `config/app.php` had to translate a name for a surface that no longer exists.
 *
 * 🚨 AND THE FALLBACK IS A REFUSAL, NOT A QUIET READ. A reader that accepts `desktop.middleware` and
 * carries on is the shape this house spent a day deleting: the app keeps working, the stale key stays
 * in the file, and nobody learns anything until the compatibility window closes and a gate silently
 * falls back to loopback. A missing gate is not a detail — `desktop.middleware` is what stands between
 * the workspace and the network.
 *
 * So {@see refuseLegacy()} runs at BOOT and names both keys. The error reaches whoever wrote the
 * config, which is the same reasoning as the framework's own boot guard for an unjudgeable operation
 * (greenhouse decisions/0279): fail closed AND fail loud, where the person who can fix it is looking.
 *
 * WHAT THIS DOES NOT RENAME, and it is measured rather than deferred by taste: the 50 `desktop-*`
 * component names and the 70 `desktop.*` EVENT names. Those are what CODE names — a plugin subscribes
 * to an event by string and may declare a renderer for a component — and the string is the contract.
 * Renaming them is its own slice with its own compatibility question. What this slice renames is what
 * a PERSON types or reads: the config keys and the route paths (greenhouse decisions/0284).
 */
final class WorkspaceKeys
{
    /** The namespace an app declares this workspace under. */
    public const string NAMESPACE = 'workspace';

    /** What it was called while this package was `milpa/desktop-app`. */
    public const string LEGACY = 'desktop';

    /**
     * One config value, read under this package's namespace.
     *
     * The path is relative: `read($config, 'mercure.topic')` asks for `workspace.mercure.topic`, and
     * `read($config)` returns the whole bag. Absent is `null`, which every caller already handles —
     * this class adds no opinion about defaults, only about the name.
     */
    public static function read(?Config $config, string $path = ''): mixed
    {
        if (!$config instanceof Config) {
            return null;
        }

        return $config->get($path === '' ? self::NAMESPACE : self::NAMESPACE . '.' . $path);
    }

    /**
     * REFUSE TO BOOT while the app still declares the old namespace, naming both keys.
     *
     * It looks only at CONFIG, and that precision matters: `desktop.tab`, `desktop.nav` and
     * `desktop.i18n` are SIGNALS, seeded per page and never read from `Config`, so a detector that
     * grepped for the string would refuse an app that is perfectly configured.
     *
     * @throws \RuntimeException when `desktop` carries anything at all
     */
    public static function refuseLegacy(?Config $config): void
    {
        if (!$config instanceof Config) {
            return;
        }
        $legacy = $config->get(self::LEGACY);
        if (!\is_array($legacy) || $legacy === []) {
            return;
        }

        $keys = array_keys($legacy);
        sort($keys);

        throw new \RuntimeException(
            'config/app.php declares `' . self::LEGACY . '` (' . implode(', ', array_map(
                static fn (mixed $k): string => self::LEGACY . '.' . (string) $k,
                $keys,
            )) . '), and this package reads `' . self::NAMESPACE . '` now: it is milpa/agent-workspace, '
            . 'and the /desktop page it was named after is retired. Rename the key to `'
            . self::NAMESPACE . '` — the shape inside it is unchanged. This refuses instead of falling '
            . 'back because `' . self::LEGACY . '.middleware` is what stands between the workspace and '
            . 'the network, and a gate that quietly reverts to loopback-only is worse than an app that '
            . 'will not start.',
        );
    }
}
