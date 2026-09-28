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

use Milpa\AgentWorkspace\Config\WorkspaceKeys;
use Milpa\Runtime\Config;
use Milpa\Runtime\Stack\EnvVar;
use Milpa\Runtime\Stack\PortMapping;
use Milpa\Runtime\Stack\ServiceDeclaration;
use Milpa\Runtime\Stack\ServiceSignature;

/**
 * The Mercure hub the Desktop needs, as a stack declaration (greenhouse decisions/0201).
 *
 * {@see MercureConfig} is how the app TALKS to a hub; this is how the plugin SAYS which hub the host has to
 * run: `dunglas/mercure`, its container port published on the port of the URL the browser reaches, the JWT
 * keys as secrets that point at the app's own config and are never inlined, and the CORS + anonymous
 * directives the browser subscription needs. It reads the wiring's `workspace.mercure.*` keys plus ONE
 * optional key of its own, `desktop.mercure.cors_origin` — declaration-only, the wiring never reads it.
 * Pure data — nothing here starts a container. An admin panel, a CLI or the agent reads it to show the
 * service, probe its port and project a compose fragment.
 */
final class MercureServiceDeclaration
{
    /** The service name — how compose keys the hub and how the admin lists it. */
    public const NAME = 'mercure';

    /** The image the host runs: the official Mercure hub. */
    public const IMAGE = 'dunglas/mercure';

    /** The port the hub listens on inside the container; `SERVER_NAME` binds it. */
    public const CONTAINER_PORT = 80;

    /** The host port published when no loopback URL in config names one. */
    public const DEFAULT_HOST_PORT = 3000;

    /** The default `cors_origins`: BOTH spellings of the quickstart origin, since a credentialed EventSource needs an exact match. */
    public const DEFAULT_CORS_ORIGINS = 'http://127.0.0.1:8080 http://localhost:8080';

    /**
     * What only a hub answers on its port: it refuses a subscription without a topic — 400 when it lets
     * anonymous subscribers in (this declaration's own directives), 401 when it asks for a JWT first (FrankenPHP's
     * embedded hub as the image variant configures it). Measured on the standalone `dunglas/mercure` and on
     * FrankenPHP 1.12.7's hub alike: the wording moves between versions (`missing "match" subscription
     * parameter`, `Missing "topic" parameter.`), the statuses do not. An app that took the port answers
     * otherwise — a `next-server` on :3000 answered 404 (greenhouse evidence/1037).
     */
    public const SIGNATURE_PATH = '/.well-known/mercure';

    /** The statuses a hub answers {@see SIGNATURE_PATH} with, asked without a topic. */
    public const SIGNATURE_STATUSES = [400, 401];

    /** The one-line summary an admin panel shows next to the service. */
    public const SUMMARY = 'The live feed of the Desktop shell and the agent sessions — without it the app falls back to the log feed.';

    /** The config keys whose URL may name the published host port, most preferred first. */
    private const HOST_PORT_KEYS = [WorkspaceKeys::NAMESPACE . '.mercure.public_url', WorkspaceKeys::NAMESPACE . '.mercure.hub_url'];

    /** The hosts that mean «this machine» — the only ones whose port is a port the host publishes. */
    private const LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '::1', '[::1]'];

    /**
     * The declaration, read from the wiring's `workspace.mercure.*` keys so the hub it describes is the hub
     * the app publishes to — plus the optional `cors_origin`: the host port comes from the URL the browser
     * reaches ({@see hostPort()}), the CORS origins verbatim from `cors_origin` when declared, else both
     * quickstart origins. The JWT keys stay `configKey` references flagged secret — the projection reads
     * them from the app; the declaration never carries their values, and the runtime refuses one that does.
     */
    public static function fromConfig(?Config $config): ServiceDeclaration
    {
        return new ServiceDeclaration(
            name: self::NAME,
            image: self::IMAGE,
            ports: [new PortMapping(container: self::CONTAINER_PORT, host: self::hostPort($config))],
            env: [
                new EnvVar('SERVER_NAME', value: ':' . self::CONTAINER_PORT),
                new EnvVar('MERCURE_PUBLISHER_JWT_KEY', configKey: 'workspace.mercure.publisher_key', secret: true),
                new EnvVar('MERCURE_SUBSCRIBER_JWT_KEY', configKey: 'workspace.mercure.subscriber_key', secret: true),
                new EnvVar('MERCURE_EXTRA_DIRECTIVES', value: 'cors_origins ' . self::corsOrigins($config) . "\nanonymous"),
            ],
            summary: self::SUMMARY,
            signature: new ServiceSignature(self::SIGNATURE_PATH, self::SIGNATURE_STATUSES),
        );
    }

    /**
     * Whether the app's own server IS the hub — `workspace.mercure.embedded: true`, as FrankenPHP's built-in
     * Mercure serves it on the app's port (greenhouse decisions/0504). Then there is no container for the host
     * to run, and declaring one would send the operator to start a second hub on a port nothing publishes to.
     * Only a literal `true` counts: a string «false» that read as truthy would hide the hub the app needs.
     */
    public static function embedded(?Config $config): bool
    {
        return WorkspaceKeys::read($config, 'mercure.embedded') === true;
    }

    /**
     * The host port to publish: the port of the URL the BROWSER reaches, because the published port is what
     * makes the hub reachable from the host. `desktop.mercure.public_url` names it first; absent, the browser
     * reaches the hub the app publishes to, so `desktop.mercure.hub_url` is read next; 3000 closes the chain.
     * A URL yields a port only when its host is loopback (`127.0.0.1`, `localhost`, `::1`): an in-network URL
     * such as `http://mercure:80/...` names a port INSIDE the container network, and publishing it as `80:80`
     * would be wrong, so it is skipped. A port outside 1–65535 (`:0` parses as 0, `:70000` makes parse_url
     * fail) or a non-string config value is skipped the same way.
     */
    private static function hostPort(?Config $config): int
    {
        foreach (self::HOST_PORT_KEYS as $key) {
            $port = self::loopbackPort($config?->get($key));
            if ($port !== null) {
                return $port;
            }
        }

        return self::DEFAULT_HOST_PORT;
    }

    /** The port `$url` carries when it is a string naming a loopback host and a publishable port; null otherwise. */
    private static function loopbackPort(mixed $url): ?int
    {
        if (!is_string($url) || $url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || !in_array(strtolower($host), self::LOOPBACK_HOSTS, true)) {
            return null;
        }

        // parse_url already refuses a port above 65535 (the whole parse fails); 0 parses, and no service publishes on it.
        $port = parse_url($url, PHP_URL_PORT);

        return is_int($port) && $port >= 1 ? $port : null;
    }

    /**
     * The origins the hub lets subscribe: `desktop.mercure.cors_origin` verbatim when declared — it may carry
     * several, space-separated, as Mercure's `cors_origins` reads them — else {@see DEFAULT_CORS_ORIGINS}.
     */
    private static function corsOrigins(?Config $config): string
    {
        $origins = WorkspaceKeys::read($config, 'mercure.cors_origin');

        return is_string($origins) && $origins !== '' ? $origins : self::DEFAULT_CORS_ORIGINS;
    }
}
