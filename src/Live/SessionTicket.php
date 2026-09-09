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
 * Which session a surface inhabits, said ONCE by the house and carried down (greenhouse decisions/0256).
 *
 * ── LA REGLA ────────────────────────────────────────────────────────────────────────────────────
 *
 * **Una superficie no declara qué sesión representa; recibe la que su autoridad le permite representar.**
 *
 * The defect this exists to remove: the panel's region belonged to `desk-admin-96359…`, the server that
 * rendered it knew so, and then `GET /desktop/hub` **reconstructed** identity from a cookie and a
 * pattern — subscribing the live feed to a session nobody was driving, while the room reported itself
 * live. A truth known upstream, lost crossing a boundary, rebuilt downstream by somebody else. The
 * rebuild looked perfectly reasonable, which is what makes the shape dangerous.
 *
 * ── POR QUÉ UN SOBRE FIRMADO Y NO UN PARÁMETRO VALIDADO ─────────────────────────────────────────
 *
 * A validated `?session=` would be safe enough and still wrong: it hands the client the vocabulary to
 * SELECT sessions, and then defends against the wrong picks. That is negative authority — ask for
 * anything, be told no afterwards. Here the operation «choose which session to listen to» simply does
 * not exist: there is no session parameter anywhere, only a ticket the client cannot forge.
 *
 * The renderer inside the panel cannot set a cookie — that is the whole reason the hub route exists — so
 * the ticket rides in the page as data and comes back in a header. It carries the PRINCIPAL it was
 * issued to as well, so a ticket lifted from another browser is inert unless the thief is also that
 * principal.
 *
 * Nothing here mints. A surface with no ticket gets no session stream, and that is said.
 */
final readonly class SessionTicket
{
    /** The header a surface returns its ticket in — never a session id, only the sealed envelope. */
    public const string HEADER = 'X-Milpa-Session-Ticket';

    /** Where the page carries the ticket the server issued it. */
    public const string TAG = 'milpa-desktop-ticket';

    private function __construct(
        public string $sessionId,
        public string $principal,
    ) {
    }

    /**
     * Seal the house's decision: this principal, on this surface, inhabits this session.
     *
     * @param string $secret    the app's signing secret — the same one every state envelope here is sealed with
     * @param string $sessionId the session the AUTHORITY resolved, never one a client asked for
     * @param string $principal who the gate authenticated, `''` for a surface behind a gate with no principal
     */
    public static function issue(string $secret, string $sessionId, string $principal = ''): string
    {
        $claim = $sessionId . "\n" . $principal;

        return self::b64($claim) . '.' . self::b64(hash_hmac('sha256', $claim, $secret, true));
    }

    /**
     * Open a ticket, or `null` when it was not this house that sealed it.
     *
     * Fails closed on every shape a forgery takes: a missing half, a body that is not the two lines it
     * seals, a signature that does not verify under this app's secret. `hash_equals` because comparing
     * signatures with `===` leaks their prefix through timing.
     */
    public static function open(string $secret, ?string $ticket): ?self
    {
        if (!\is_string($ticket) || $ticket === '' || substr_count($ticket, '.') !== 1) {
            return null;
        }
        [$body, $signature] = explode('.', $ticket, 2);
        $claim = self::unb64($body);
        if ($claim === null || self::unb64($signature) === null) {
            return null;
        }
        if (!hash_equals(hash_hmac('sha256', $claim, $secret, true), (string) self::unb64($signature))) {
            return null;
        }
        $lines = explode("\n", $claim);
        if (\count($lines) !== 2 || preg_match('/^desk-[0-9a-z-]{1,64}$/', $lines[0]) !== 1) {
            return null;
        }

        return new self($lines[0], $lines[1]);
    }

    /** Was this ticket sealed for the principal now asking? A lifted ticket is inert for anybody else. */
    public function belongsTo(?string $principal): bool
    {
        return hash_equals($this->principal, $principal ?? '');
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $encoded): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
            return null;
        }
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }
}
