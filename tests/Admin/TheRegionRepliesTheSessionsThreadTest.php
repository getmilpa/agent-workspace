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

namespace Milpa\AgentWorkspace\Tests\Admin;

use Milpa\AgentWorkspace\Admin\AgentViewComponent;
use Milpa\AgentWorkspace\Admin\AgentViewRenderer;
use Milpa\AgentWorkspace\Controllers\ShellController;
use Milpa\AgentWorkspace\Data\DesktopData;
use Milpa\Container\DIContainer;
use Milpa\Eventing\EventDispatcher;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Una conversación es de la SESIÓN, no del dispositivo que la empezó (greenhouse decisions/0258).
 *
 * Rod: «si el día de mañana empiezo una sesión en Desktop y me paso a mobile, quiero continuar todo
 * desde mobile pero también tener contexto — traerme todos los mensajes anteriores, y a su vez los
 * demás dispositivos».
 *
 * Medido antes de esta rebanada: el ledger tenía los 50 eventos de una sesión real y **el panel
 * entregaba un transcript vacío**. El seam existía desde siempre —`ConversationComponent` toma un prop
 * `agent` y revive ese hilo— y esta región nunca se lo pasaba, aunque deriva la sesión para escribirla
 * en su propio tag y en el sello del hub.
 */
final class TheRegionRepliesTheSessionsThreadTest extends TestCase
{
    /** F1 · la región entrega la historia de la sesión que habita. */
    public function testTheRegionHandsDownTheThreadOfTheSessionItInhabits(): void
    {
        [$html, $sessionId] = $this->region(withLedger: true);

        self::assertNotSame('', $sessionId, 'la región deriva su sesión');
        $transcript = $this->transcriptOf($html);
        self::assertNotSame([], $transcript, 'el panel ya no entrega un hilo vacío teniéndolo en el ledger');

        $kinds = array_map(static fn (array $row): string => (string) ($row['kind'] ?? ''), $transcript);
        self::assertContains('user', $kinds, 'lo que el humano dijo');
        self::assertContains('agent', $kinds, 'y lo que el agente contestó — la mitad que sólo viajaba en la respuesta del POST');
    }

    /**
     * F2 · EL CONTROL: sin historia, sigue el vacío honesto.
     *
     * Un hilo vacío no es un error: es una sesión que no ha empezado. Confundirlos haría que la primera
     * pantalla de alguien pareciera rota.
     */
    public function testWithNoThreadItStillHandsTheHonestEmpty(): void
    {
        [$html] = $this->region(withLedger: false);

        self::assertSame([], $this->transcriptOf($html));
        self::assertStringContainsString('milpa-desktop-transcript', $html, 'el tag está: lo que no hay es historia');
    }

    /**
     * F5, la mitad que este test puede probar: la sesión que la región entrega al hilo es LA MISMA que
     * declara en su tag. Dos superficies del mismo dato no pueden discrepar sobre cuál sesión es.
     */
    public function testTheThreadAndTheSessionTagNameTheSameSession(): void
    {
        [$html, $sessionId] = $this->region(withLedger: true);

        self::assertSame(1, preg_match('#<script id="milpa-desktop-session" type="application/json">(.*?)</script>#s', $html, $m));
        /** @var array{agent?: string} $declared */
        $declared = json_decode($m[1], true);

        self::assertSame($sessionId, $declared['agent'] ?? '', 'una sola sesión, dicha una vez');
    }

    /**
     * The region, rendered the way the panel renders it.
     *
     * @return array{0: string, 1: string} the html, and the session id it derived
     */
    private function region(bool $withLedger): array
    {
        $dir = sys_get_temp_dir() . '/milpa-region-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o777, true);

        $events = new EventDispatcher(new NullLogger());
        // ONE data seam for both, as the plugin wires it: the region composes the SHELL's registry, so
        // the conversation's renderer reads the shell's data — handing it only to the region would test
        // a wiring nobody has.
        $data = $this->dataFor($dir, $withLedger);
        $shell = new ShellController($events, null, $data);
        $renderer = new AgentViewRenderer($shell->components(), $data);

        $html = $renderer->render(
            new AgentViewComponent(),
            new RenderRequest(new ComponentContext('milpa-admin-section-agent', route: '/milpa/admin'), ['gate' => 'loopback']),
        )->output;

        // The id the region derives is the one it prints; reading it back is how this test stays honest
        // about which session it is asserting on, instead of hardcoding a hash.
        preg_match('#<script id="milpa-desktop-session" type="application/json">(.*?)</script>#s', $html, $m);
        /** @var array{agent?: string} $declared */
        $declared = json_decode($m[1] ?? '{}', true) ?: [];

        if ($withLedger) {
            $this->cleanup($dir);
        }

        return [$html, (string) ($declared['agent'] ?? '')];
    }

    /** A ledger holding one session's thread — a human turn and the agent's answer — or nothing at all. */
    private function dataFor(string $dir, bool $withLedger): DesktopData
    {
        if (!$withLedger) {
            return new DesktopData(new DIContainer(), null, $dir);
        }

        // The session the region derives with NO principal (a loopback panel) — computed the same way it
        // computes it, so this fixture cannot drift from the code it feeds.
        $sid = 'desk-admin-' . substr(hash('sha256', 'milpa/admin|agent|'), 0, 16);
        $lines = [
            ['stream_id' => 'agent-session:' . $sid, 'type' => 'session.turn', 'payload' => ['role' => 'user', 'content' => '¿qué capacidades tengo?'], 'seq' => 1],
            ['stream_id' => 'agent-session:' . $sid, 'type' => 'session.turn', 'payload' => ['role' => 'assistant', 'content' => 'Tienes nueve.'], 'seq' => 2],
        ];
        file_put_contents(
            $dir . '/agent-sessions.jsonl',
            implode("\n", array_map(static fn (array $l): string => (string) json_encode($l), $lines)) . "\n",
        );

        return new DesktopData(new DIContainer(), null, $dir, ledgerPath: $dir . '/agent-sessions.jsonl');
    }

    /** @return list<array<string, mixed>> the transcript the page hands the client, as data */
    private function transcriptOf(string $html): array
    {
        if (preg_match('#<script id="milpa-desktop-transcript" type="application/json">(.*?)</script>#s', $html, $m) !== 1) {
            return [];
        }
        /** @var list<array<string, mixed>>|null $rows */
        $rows = json_decode(html_entity_decode($m[1], \ENT_QUOTES), true);

        return \is_array($rows) ? $rows : [];
    }

    private function cleanup(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}
