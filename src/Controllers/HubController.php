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

namespace Milpa\AgentWorkspace\Controllers;

use Milpa\AgentWorkspace\Live\HubConnection;
use Milpa\AgentWorkspace\Live\MercureConfig;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Where a surface that cannot set cookies asks for its live connection.
 *
 * The workspace inside the panel is a `DeclaredView`: it contributes markup to the admin's response
 * and never owns the headers, so it cannot mint the cookie the hub reads. It loaded the connector,
 * found no payload, and reported itself offline forever — with the hub running and the Stack green
 * (greenhouse decisions/0253).
 *
 * The Desktop page keeps writing the payload inline, because it owns its response and an extra
 * request would buy nothing. This route exists for every surface that does not.
 *
 * ── WHY IT ANSWERS `{}` INSTEAD OF 404 WITH NO HUB ──────────────────────────────────────────────
 *
 * Because «this app wired no hub» is an ANSWER, and the connector already knows what to do with it:
 * it says offline once and opens nothing, which is exactly right — the workspace runs on the polled
 * log. A 404 would make the client guess between «no hub here» and «this route is broken».
 */
final class HubController
{
    public function __construct(private readonly ?MercureConfig $mercure = null)
    {
    }

    /**
     * `GET {route}/hub` — the subscribe URL as JSON, and the cookies that let the browser in.
     */
    public function connect(ServerRequestInterface $request): ResponseInterface
    {
        $cookie = $request->getCookieParams()[HubConnection::SESSION_COOKIE] ?? null;
        // THE SESSION THE BROWSER ALREADY HAS, or a fresh one. Minting a new id on every call would
        // give the same person a different stream on every reload, and the room would never see the
        // events its own turn published.
        $sessionId = \is_string($cookie) && preg_match('/^desk-[0-9a-f]{16}$/', $cookie) === 1
            ? $cookie
            : 'desk-' . bin2hex(random_bytes(8));

        $connection = HubConnection::of($this->mercure, $sessionId);

        if ($connection === null) {
            return new Response(200, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'], '{}');
        }

        return new Response(
            200,
            [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store',
                'Set-Cookie' => $connection->cookies(),
            ],
            $connection->payload(),
        );
    }
}
