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
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * A surface that cannot write headers can still get its live connection.
 *
 * The workspace inside the panel is a `DeclaredView`: it contributes markup to somebody else's
 * response and never owns the headers, so it cannot mint the cookie the hub reads. It loaded the
 * connector, found no payload, and reported itself offline FOREVER — with the hub running and the
 * Stack green (greenhouse decisions/0253).
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

    /**
     * It answers with the URL AND the cookie, which is the pair that was impossible from a view.
     */
    public function testItAnswersWithTheUrlAndTheCookieTheHubReads(): void
    {
        $response = (new HubController($this->mercure()))->connect(new ServerRequest('GET', '/desktop/hub'));
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
     * It keeps the session the browser already has.
     *
     * Minting a new id on every call would give one person a different stream on every reload, and the
     * room would never see the events its own turn published.
     */
    public function testItKeepsTheSessionTheBrowserAlreadyHas(): void
    {
        $request = (new ServerRequest('GET', '/desktop/hub'))
            ->withCookieParams([HubConnection::SESSION_COOKIE => 'desk-0123456789abcdef']);

        $payload = json_decode((string) (new HubController($this->mercure()))->connect($request)->getBody(), true);

        self::assertStringContainsString(rawurlencode(MercureConfig::sessionTopic('desk-0123456789abcdef')), $payload['url'] ?? '');
    }

    /**
     * A cookie that is not a session id is not one, and a fresh id is minted instead of trusted.
     */
    public function testAForgedSessionCookieIsNotHonoured(): void
    {
        $request = (new ServerRequest('GET', '/desktop/hub'))
            ->withCookieParams([HubConnection::SESSION_COOKIE => '../../etc/passwd']);

        $payload = json_decode((string) (new HubController($this->mercure()))->connect($request)->getBody(), true);

        self::assertStringNotContainsString('passwd', $payload['url'] ?? '');
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
        $response = (new HubController(null))->connect(new ServerRequest('GET', '/desktop/hub'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{}', (string) $response->getBody());
        self::assertSame([], $response->getHeader('Set-Cookie'));
    }
}
