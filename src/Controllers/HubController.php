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

use Milpa\AgentWorkspace\Http\RequestPrincipal;
use Milpa\AgentWorkspace\Live\HubConnection;
use Milpa\AgentWorkspace\Live\MercureConfig;
use Milpa\AgentWorkspace\Live\SessionTicket;
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
    public function __construct(
        private readonly ?MercureConfig $mercure = null,
        /** The app's signing secret — what tells this house's own sealed decision from a forgery. */
        private readonly string $signingSecret = '',
    ) {
    }

    /**
     * `GET {route}/hub` — the subscribe URL as JSON, and the cookies that let the browser in.
     *
     * ── ESTE TRANSPORTE NO DECIDE IDENTIDAD (greenhouse decisions/0256) ──────────────────────────
     *
     * It used to: it read a cookie, matched it against `/^desk-[0-9a-f]{16}$/`, and MINTED a fresh id
     * when that failed. The panel's region is `desk-admin-<hash>`, which cannot match that pattern and
     * whose page cannot set that cookie — so the room connected, reported itself live, and subscribed to
     * a session nobody was driving. Measured: fourteen facts published for the page's own session
     * reached nothing at all.
     *
     * Now it opens the sealed decision the house already made and projects it. It mints nothing, and
     * there is no session parameter for a client to name — not a validated one, none. The capability was
     * removed rather than defended.
     */
    public function connect(ServerRequestInterface $request): ResponseInterface
    {
        $ticket = SessionTicket::open($this->signingSecret, $request->getHeaderLine(SessionTicket::HEADER));
        // A ticket lifted from another browser is inert: it was sealed for a principal, and this request
        // has to be that principal. `RequestPrincipal` is the house's ONE reader of who a request is.
        $sessionId = $ticket !== null && $ticket->belongsTo(RequestPrincipal::of($request)) ? $ticket->sessionId : '';
        $connection = $sessionId === '' ? null : HubConnection::of($this->mercure, $sessionId);

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
