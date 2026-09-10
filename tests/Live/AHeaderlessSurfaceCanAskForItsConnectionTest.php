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

use Milpa\AgentWorkspace\Controllers\HubController;
use Milpa\AgentWorkspace\Live\HubConnection;
use Milpa\AgentWorkspace\Live\MercureConfig;
use Milpa\AgentWorkspace\Live\SessionTicket;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * A surface that cannot write headers can still get its live connection.
 *
 * The workspace inside the panel is a `DeclaredView`: it contributes markup to somebody else's
 * response and never owns the headers, so it cannot mint the cookie the hub reads. It loaded the
 * connector, found no payload, and reported itself offline FOREVER — with the hub running and the
 * Stack green (greenhouse decisions/0253).
 *
 * 🚨 AND THEN IT CONNECTED TO THE WRONG SESSION (greenhouse decisions/0256). Closing the first half
 * opened a worse one: this route rebuilt identity from a cookie, so the panel — whose page cannot set
 * that cookie — got a session nobody was driving, while the room reported itself live. The test below
 * named `testItKeepsTheSessionTheBrowserAlreadyHas` PINNED that as the contract, and was green through
 * the whole defect. A transport does not decide identity; it carries the decision the house sealed.
 */
final class AHeaderlessSurfaceCanAskForItsConnectionTest extends TestCase
{
    private function mercure(): MercureConfig
    {
        return new MercureConfig(
            'http://127.0.0.1:3000/.well-known/mercure',
            'http://127.0.0.1:3000/.well-known/mercure',
            str_repeat('p', 32),
            str_repeat('s', 32),
            'desktop/shell',
        );
    }

    private const string SECRET = 'the-apps-signing-secret-32-chars';

    /** A request carrying the house's sealed decision, which is the only way to name a session at all. */
    private function withTicket(string $sessionId, string $principal = ''): ServerRequest
    {
        return new ServerRequest('GET', '/workspace/hub', [SessionTicket::HEADER => SessionTicket::issue(self::SECRET, $sessionId, $principal)]);
    }

    private function hub(): HubController
    {
        return new HubController($this->mercure(), self::SECRET);
    }

    /**
     * It answers with the URL AND the cookie, which is the pair that was impossible from a view.
     */
    public function testItAnswersWithTheUrlAndTheCookieTheHubReads(): void
    {
        $response = $this->hub()->connect($this->withTicket('desk-0123456789abcdef'));
        /** @var array{url?: string} $payload */
        $payload = json_decode((string) $response->getBody(), true);
        $cookies = $response->getHeader('Set-Cookie');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('http://127.0.0.1:3000/.well-known/mercure?topic=', $payload['url'] ?? '');
        self::assertStringContainsString('topic=desktop%2Fshell', $payload['url'] ?? '');
        self::assertNotSame([], array_filter($cookies, static fn (string $c): bool => str_starts_with($c, HubConnection::COOKIE . '=')));
        self::assertNotSame([], array_filter($cookies, static fn (string $c): bool => str_starts_with($c, HubConnection::SESSION_COOKIE . '=')));
    }

    /**
     * It carries the session the HOUSE sealed — the id the page was rendered for, not one it read back.
     *
     * This replaces `testItKeepsTheSessionTheBrowserAlreadyHas`, which asserted the opposite and was
     * green while the panel subscribed to a session nobody was driving.
     */
    public function testItCarriesTheSessionTheHouseSealed(): void
    {
        $payload = json_decode((string) $this->hub()->connect($this->withTicket('desk-0123456789abcdef'))->getBody(), true);

        self::assertStringContainsString(rawurlencode(MercureConfig::sessionTopic('desk-0123456789abcdef')), $payload['url'] ?? '');
    }

    /**
     * A COOKIE IS NOT AN IDENTITY HERE ANY MORE — not even a well-formed one.
     *
     * The cookie the browser happens to carry from a `/desktop` visit is exactly what used to win, and
     * that is how the panel ended up on somebody else's stream. With no sealed ticket there is no
     * session connection at all.
     */
    public function testACookieNoLongerNamesTheSession(): void
    {
        foreach (['desk-0123456789abcdef', '../../etc/passwd'] as $cookie) {
            $request = (new ServerRequest('GET', '/workspace/hub'))->withCookieParams([HubConnection::SESSION_COOKIE => $cookie]);

            self::assertSame('{}', (string) $this->hub()->connect($request)->getBody(), 'a cookie decides nothing');
        }
    }

    /**
     * With no hub wired it answers `{}` — an ANSWER, not a failure.
     *
     * The connector already knows what to do with it: say offline once and open nothing, which is
     * right, because the workspace runs on the polled log. A 404 would make the client guess between
     * «no hub here» and «this route is broken».
     */
    public function testWithNoHubItAnswersAnEmptyObjectAndSetsNoCookie(): void
    {
        $response = (new HubController(null, self::SECRET))->connect($this->withTicket('desk-0123456789abcdef'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{}', (string) $response->getBody());
        self::assertSame([], $response->getHeader('Set-Cookie'));
    }
}
