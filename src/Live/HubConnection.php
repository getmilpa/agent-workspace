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

/**
 * What a browser needs to open the live feed: the URL to subscribe on, and the cookie the hub reads.
 *
 * Both facts were built inline in {@see \Milpa\AgentWorkspace\Controllers\ShellController}, which is
 * the page at `/desktop`. That was fine while the Desktop was the only surface — and it stopped being
 * true when the workspace moved INSIDE the panel: the room there is a `DeclaredView`, it contributes
 * markup to somebody else's response, and **a view cannot set a cookie**. So the panel's room loaded
 * the connector, found no payload, and reported itself offline forever — with the hub up, the Stack
 * green, and nothing wrong (greenhouse decisions/0253).
 *
 * Extracted here so the two surfaces answer from one place. The shell keeps writing it inline — it
 * owns its response and costs no extra request — and a surface that cannot set headers asks the route
 * that can.
 */
final readonly class HubConnection
{
    public const COOKIE = 'mercureAuthorization';

    public const SESSION_COOKIE = 'milpa_agent_sid';

    /**
     * @param string $url the subscribe URL, with the exact topics this browser may read
     * @param string $jwt the subscriber token the hub reads from {@see COOKIE}
     */
    private function __construct(
        public string $url,
        public string $jwt,
        public string $sessionId,
    ) {
    }

    /**
     * The connection for one agent session, or `null` when this app wired no hub.
     *
     * Null is not a failure: a house with no Mercure runs on the polled log, which is the fallback the
     * workspace was built with. It is the caller's job to say so, not to pretend otherwise.
     */
    public static function of(?MercureConfig $mercure, string $sessionId): ?self
    {
        if ($mercure === null) {
            return null;
        }

        // TWO EXACT TOPICS ON ONE CONNECTION: the shell's own events and this session's stream. Exact
        // topics rather than a URI template, so the hub authorizes and delivers them without matching
        // ambiguity (greenhouse decisions/0190).
        $topics = [$mercure->topic, MercureConfig::sessionTopic($sessionId)];
        $url = $mercure->publicUrl . '?topic=' . rawurlencode($topics[0]) . '&topic=' . rawurlencode($topics[1]);

        return new self($url, $mercure->subscriberJwt([$topics[1]]), $sessionId);
    }

    /**
     * The payload the connector reads — the same shape whether it is inlined or fetched.
     */
    public function payload(): string
    {
        return (string) json_encode(
            ['url' => $this->url],
            \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * The cookies a response must carry for the browser's `EventSource` to be let in.
     *
     * @return list<string>
     */
    public function cookies(): array
    {
        return [
            self::SESSION_COOKIE . '=' . $this->sessionId . '; Path=/; SameSite=Lax',
            self::COOKIE . '=' . $this->jwt . '; Path=/; SameSite=Lax',
        ];
    }
}
