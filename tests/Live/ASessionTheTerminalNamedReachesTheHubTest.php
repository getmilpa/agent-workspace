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

namespace Milpa\AgentWorkspace\Tests\Live;

use Milpa\AgentWorkspace\Admin\PanelSession;
use Milpa\AgentWorkspace\Controllers\HubController;
use Milpa\AgentWorkspace\Live\MercureConfig;
use Milpa\AgentWorkspace\Live\SessionTicket;
use Milpa\Live\ValueObjects\ComponentContext;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * A session the terminal named reaches the hub, judged the way the panel already judged it.
 *
 * The rehearsal from the real Desktop (greenhouse evidence/1091, E4) named its resident's session in the terminal —
 * `--session=camino-rod-blog` — and the enroller opened it in the panel: the page was admitted (the seat's session is
 * one the enroller answers for, decisions/0493), the ticket was sealed for it, and `GET /workspace/hub` answered `{}`.
 * The ticket only OPENED for ids shaped `desk-…`, the shape the Desktop's own store mints. So «live hub not connected»
 * stayed on screen while the turn ran, and the page never changed.
 *
 * Who may read a session is decided once, where the ticket is sealed ({@see PanelSession::fromContext()}); opening the
 * ticket only checks it is this house's and well formed — the same grammar a selection takes ({@see
 * \Milpa\AgentWorkspace\Data\DesktopData::select()}), not the Desktop store's file names.
 */
final class ASessionTheTerminalNamedReachesTheHubTest extends TestCase
{
    private const string SECRET = 'the-apps-signing-secret-32-chars';

    private const string SEAT_SESSION = 'camino-rod-blog';

    private function hub(): HubController
    {
        return new HubController(new MercureConfig(
            'http://127.0.0.1:8899/.well-known/mercure',
            'http://localhost:8899/.well-known/mercure',
            str_repeat('p', 32),
            str_repeat('s', 32),
            'desktop/shell',
        ), self::SECRET);
    }

    /** A request from `$principal` (null: nobody signed in) carrying `$ticket` the way the connector sends it. */
    private function asking(?string $principal, ?string $ticket): ServerRequest
    {
        $request = new ServerRequest('GET', '/workspace/hub', $ticket === null ? [] : [SessionTicket::HEADER => $ticket]);
        if ($principal === null) {
            return $request;
        }

        return $request->withAttribute('milpa.auth', new class ($principal) {
            public object $actor;

            public function __construct(string $id)
            {
                $this->actor = (object) ['id' => $id];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        });
    }

    /** What the panel seals for `$principal` asking for `$session`, or null when it admits no such task. */
    private function ticketFor(string $principal, string $session, array $seatSessions): ?string
    {
        $selection = PanelSession::fromContext(new ComponentContext('agent', principal: $principal, meta: ['query' => ['session' => $session]]), null, $seatSessions);

        return $selection->id === null ? null : SessionTicket::issue(self::SECRET, $selection->id, $principal);
    }

    /** @return array<string, mixed> */
    private function connect(?string $principal, ?string $ticket): array
    {
        return json_decode((string) $this->hub()->connect($this->asking($principal, $ticket))->getBody(), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testTheEnrollerOfTheSeatIsConnectedToTheSessionTheTerminalNamed(): void
    {
        $ticket = $this->ticketFor('passkey:rod', self::SEAT_SESSION, [self::SEAT_SESSION]);
        self::assertNotNull($ticket, 'the panel admits the seat session for the one who enrolled it (0493)');

        $payload = $this->connect('passkey:rod', $ticket);

        self::assertStringContainsString('topic=' . rawurlencode(MercureConfig::sessionTopic(self::SEAT_SESSION)), $payload['url'] ?? '', 'the hub carries the session the terminal named');
    }

    public function testAStrangerIsSealedNoTicketForIt(): void
    {
        self::assertNull($this->ticketFor('passkey:stranger', self::SEAT_SESSION, []), 'a principal who did not enroll the seat is not admitted');
    }

    public function testTheEnrollersTicketIsInertInAnybodyElsesHands(): void
    {
        $ticket = $this->ticketFor('passkey:rod', self::SEAT_SESSION, [self::SEAT_SESSION]);

        self::assertSame([], $this->connect('passkey:stranger', $ticket));
        self::assertSame([], $this->connect(null, $ticket));
    }

    /**
     * The shapes the Desktop and the runtime give a session all open: the Desktop store's, the panel's default, a
     * sequence's, and one typed in a terminal.
     */
    public function testEveryShapeAHouseGivesASessionOpens(): void
    {
        foreach (['desk-0123456789abcdef', PanelSession::forPrincipal('passkey:rod'), 'sequence:publish', self::SEAT_SESSION, 'Camino.Rod_2'] as $id) {
            $opened = SessionTicket::open(self::SECRET, SessionTicket::issue(self::SECRET, $id, 'passkey:rod'));
            self::assertNotNull($opened, $id);
            self::assertSame($id, $opened->sessionId);
        }
    }

    /**
     * A sealed body that is not a session id still fails closed — the signature proves who sealed it, the grammar
     * keeps a topic from ever being anything but one session's.
     */
    public function testWhatIsNotASessionIdNeverOpensEvenSealed(): void
    {
        foreach (['', '../escape', '-leading-dash', 'has space', 'milpa/sessions/x', 'a#b', 'x?topic=desktop', str_repeat('a', 65)] as $id) {
            self::assertNull(SessionTicket::open(self::SECRET, SessionTicket::issue(self::SECRET, $id, 'passkey:rod')), var_export($id, true));
        }
        self::assertNotNull(SessionTicket::open(self::SECRET, SessionTicket::issue(self::SECRET, str_repeat('a', 64), 'passkey:rod')), 'the longest id a selection takes');
    }
}
