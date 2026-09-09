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
use Milpa\AgentWorkspace\Live\MercureConfig;
use Milpa\AgentWorkspace\Live\SessionTicket;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * La pertenencia se resuelve una vez y viaja hacia abajo (greenhouse decisions/0256).
 *
 * Escrito ANTES del código, a petición de Rod. Lo que se prueba no es «A recibe lo suyo» —eso pasa
 * igual con una fuga— sino que **no exista la operación «elige qué sesión escuchar»**.
 *
 * El defecto que lo motivó: la página del panel manejaba `desk-admin-96359…` y el hub la suscribía a
 * `desk-ae0ef…`, porque el transporte volvía a acuñar identidad desde una cookie. Medido en vivo: los
 * catorce hechos publicados para la sesión de la página no llegaron a ninguna parte.
 */
final class SessionOwnershipCrossesTheBoundaryOnceTest extends TestCase
{
    private const string SECRET = 'a-signing-secret-for-the-house-32';

    /** F1 · dos sesiones reales a la vez: cada una recibe la SUYA y ninguna la de la otra. */
    public function testTwoLiveSurfacesGetTheirOwnStreamAndNotEachOthers(): void
    {
        $a = $this->connect(SessionTicket::issue(self::SECRET, 'desk-a1b2c3d4e5f60718'));
        $b = $this->connect(SessionTicket::issue(self::SECRET, 'desk-99887766554433aa'));

        $topicsA = $this->topicsOf($a);
        $topicsB = $this->topicsOf($b);

        self::assertContains('milpa/sessions/desk-a1b2c3d4e5f60718', $topicsA, 'A escucha lo suyo');
        self::assertNotContains('milpa/sessions/desk-99887766554433aa', $topicsA, 'y NO lo de B');
        self::assertContains('milpa/sessions/desk-99887766554433aa', $topicsB, 'B escucha lo suyo');
        self::assertNotContains('milpa/sessions/desk-a1b2c3d4e5f60718', $topicsB, 'y NO lo de A');
    }

    /**
     * F2 · EL CONTROL ADVERSARIAL, y es el que importa.
     *
     * A intenta nombrar la sesión de B por todos los caminos que un cliente tiene: query, cuerpo,
     * cabecera, cookie. El resultado que se exige **no** es un 403 —eso significaría que la capacidad
     * sigue ahí, defendida— sino que nombrarla **no cambie nada**: no hay parámetro que lo intente.
     */
    public function testASurfaceCannotNameAnotherSessionBecauseThereIsNowhereToNameIt(): void
    {
        $mine = 'desk-a1b2c3d4e5f60718';
        $theirs = 'desk-99887766554433aa';
        $ticket = SessionTicket::issue(self::SECRET, $mine);

        $attempts = [
            'query' => new ServerRequest('GET', '/desktop/hub?session=' . $theirs, [SessionTicket::HEADER => $ticket]),
            'header' => new ServerRequest('GET', '/desktop/hub', [SessionTicket::HEADER => $ticket, 'X-Milpa-Session' => $theirs]),
            'cookie' => (new ServerRequest('GET', '/desktop/hub', [SessionTicket::HEADER => $ticket]))->withCookieParams(['milpa_agent_sid' => $theirs]),
            'body' => (new ServerRequest('GET', '/desktop/hub', [SessionTicket::HEADER => $ticket]))->withParsedBody(['session' => $theirs]),
        ];

        foreach ($attempts as $how => $request) {
            $topics = $this->topicsOf($this->controller()->connect($request));
            self::assertContains('milpa/sessions/' . $mine, $topics, 'por «' . $how . '» sigue siendo la mía');
            self::assertNotContains('milpa/sessions/' . $theirs, $topics, 'por «' . $how . '» NO alcanza la ajena');
        }
    }

    /** F3 · un sobre forjado no vale: ni firma inventada, ni sesión cambiada dentro de un sobre bueno. */
    public function testAForgedTicketOpensNothing(): void
    {
        $good = SessionTicket::issue(self::SECRET, 'desk-a1b2c3d4e5f60718');
        // 🚨 UNA MUTACIÓN QUE NO MUTABA: el sobre viaja en base64url, así que un `str_replace` del id en
        // claro no encontraba nada y este caso probaba el sobre BUENO — verde por no medir. Se altera
        // donde de verdad vive: se abre el cuerpo, se cambia la sesión, y se conserva la firma vieja.
        [$body, $signature] = explode('.', $good, 2);
        $decoded = (string) base64_decode(strtr($body, '-_', '+/'), true);
        $swapped = str_replace('desk-a1b2c3d4e5f60718', 'desk-99887766554433aa', $decoded);
        self::assertNotSame($decoded, $swapped, 'el control del control: la alteración de verdad altera');
        $tampered = rtrim(strtr(base64_encode($swapped), '+/', '-_'), '=') . '.' . $signature;

        foreach ([
            'firma inventada' => 'desk-a1b2c3d4e5f60718.deadbeef',
            'sesión cambiada dentro del sobre' => $tampered,
            'sobre de otra app' => SessionTicket::issue('another-apps-signing-secret-32ch', 'desk-a1b2c3d4e5f60718'),
            'vacío' => '',
            'basura' => 'not-a-ticket',
        ] as $what => $forged) {
            $body = (string) $this->connect($forged)->getBody();
            self::assertSame('{}', $body, 'un sobre forjado (' . $what . ') no conecta nada');
        }
    }

    /**
     * F4 · NINGÚN transporte acuña.
     *
     * Sin sobre no hay conexión de sesión. Acuñar aquí es exactamente cómo nació el fantasma: la sala
     * quedaba «live» sobre una sesión que nadie manejaba.
     */
    public function testWithNoTicketTheTransportMintsNothing(): void
    {
        $body = (string) $this->controller()->connect(new ServerRequest('GET', '/desktop/hub'))->getBody();

        self::assertSame('{}', $body, 'sin sobre, no se inventa una sesión');
        self::assertStringNotContainsString('milpa/sessions/', $body);
    }

    private function controller(): HubController
    {
        return new HubController(
            new MercureConfig(
                hubUrl: 'http://hub.example/.well-known/mercure',
                publicUrl: 'http://hub.example/.well-known/mercure',
                publisherKey: str_repeat('p', 32),
                subscriberKey: str_repeat('s', 32),
                topic: 'desktop/shell',
            ),
            self::SECRET,
        );
    }

    private function connect(string $ticket): ResponseInterface
    {
        return $this->controller()->connect(new ServerRequest('GET', '/desktop/hub', [SessionTicket::HEADER => $ticket]));
    }

    /** @return list<string> the exact topics the subscribe URL carries */
    private function topicsOf(ResponseInterface $response): array
    {
        $read = json_decode((string) $response->getBody(), true);
        $url = \is_array($read) && \is_string($read['url'] ?? null) ? $read['url'] : '';
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $topics = $query['topic'] ?? [];

        return array_values(array_map('strval', \is_array($topics) ? $topics : [$topics]));
    }
}
