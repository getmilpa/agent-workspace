<?php

/**
 * This file is part of Milpa Agent Workspace.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/agent-workspace
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Tests\Live;

use Milpa\AgentWorkspace\Controllers\PanelDoorController;
use Milpa\AgentWorkspace\Live\DecisionsInboxView;
use PHPUnit\Framework\TestCase;

/**
 * «Your seats» takes one admission back (greenhouse decisions/0590, rule 12).
 *
 * The list already said what each seat was admitted; beside each line there was nothing to undo it with. Now there
 * is one button. It only removes authority, so it asks for no box to be ticked — the passkey's touch is the act —
 * and what it is bound to is exactly what the line says: this seat, this capability, this scope.
 */
final class APersonWithdrawsAnAdmissionFromThePanelTest extends TestCase
{
    private const SEAT = 'BD93ED040122235995977BA235799583EC8050B5';

    public function testEachAdmissionCarriesTheButtonThatTakesItBackBoundToWhatTheLineSays(): void
    {
        $html = (new DecisionsInboxView())->seatsHtml([$this->seat([
            ['capability' => 'Prestamos', 'scope' => 'herramientas:write', 'key' => 'herramientas:write', 'admitted_by' => 'passkey:QM1L', 'at' => '2026-10-07T18:00:00Z', 'verbs' => ['herramientas.prestar' => 'admitted']],
            ['capability' => 'Prestamos', 'scope' => '(no scope) herramientas.contar', 'key' => '=herramientas.contar', 'admitted_by' => 'passkey:QM1L', 'at' => '2026-10-07T18:05:00Z', 'verbs' => ['herramientas.contar' => 'admitted']],
        ])]);

        self::assertSame(2, substr_count($html, 'data-seat-withdraw>'), 'one button per admission');
        self::assertStringContainsString('data-withdraw-seat="' . self::SEAT . '" data-withdraw-capability="Prestamos" data-withdraw-scope="herramientas:write"', $html);
        self::assertStringContainsString('data-withdraw-scope="=herramientas.contar"', $html, 'the scope as the house keeps it, not as it is read');
        self::assertMatchesRegularExpression('~Admitted: herramientas:write of Prestamos.*?<button[^>]*data-seat-withdraw>Withdraw</button>~s', $html);
        self::assertStringNotContainsString('data-seat-ack', $html, 'taking authority away asks for no box: the touch is the act');
    }

    /**
     * Measured through the real panel (greenhouse evidence/1142): once withdrawn, the line read «Admitted: … withdrawn
     * · …» in one breath. What was admitted is a part of the line the page can strike through, and what the
     * withdrawal says stands apart from it.
     */
    public function testWhatWasAdmittedStandsApartFromWhatTheWithdrawalSays(): void
    {
        $html = (new DecisionsInboxView())->seatsHtml([$this->seat([
            ['capability' => 'Prestamos', 'scope' => 'herramientas:write', 'key' => 'herramientas:write', 'admitted_by' => 'passkey:QM1L', 'at' => '2026-10-07T18:00:00Z', 'verbs' => ['herramientas.prestar' => 'admitted']],
        ])]);

        self::assertStringContainsString('<span class="decision-card__held">Admitted: herramientas:write of Prestamos · herramientas.prestar · by passkey:QM1L, 2026-10-07T18:00:00Z</span>', $html);
        self::assertMatchesRegularExpression('~</button><span class="decision-card__said" data-seat-status></span></p>~', $html);
        $css = (string) file_get_contents(\dirname(__DIR__, 2) . '/resources/components/desktop-decisions/desktop-decisions.css');
        self::assertStringContainsString('[data-withdrawn] > .decision-card__held { text-decoration: line-through;', $css, 'the page strikes what was taken back');
        self::assertStringContainsString('.decision-card__said { display: block;', $css, 'and what the withdrawal says is a line of its own');
    }

    /** A runtime that cannot withdraw does not say what a withdrawal would name, and no button is offered. */
    public function testARuntimeThatCannotWithdrawOffersNoButton(): void
    {
        $html = (new DecisionsInboxView())->seatsHtml([$this->seat([
            ['capability' => 'Prestamos', 'scope' => 'herramientas:write', 'admitted_by' => 'passkey:QM1L', 'at' => '2026-10-07T18:00:00Z', 'verbs' => ['herramientas.prestar' => 'admitted']],
        ])]);

        self::assertStringContainsString('Admitted: herramientas:write of Prestamos', $html);
        self::assertStringNotContainsString('data-seat-withdraw', $html);
    }

    public function testWhatWasTakenBackIsSaidWithWhoDidItAndWhen(): void
    {
        $html = (new DecisionsInboxView())->seatsHtml([$this->seat([], [
            ['capability' => 'Prestamos', 'scope' => 'herramientas:write', 'verbs' => ['herramientas.agregar', 'herramientas.prestar'], 'withdrawn_by' => 'passkey:QM1L', 'at' => '2026-10-07T19:00:00Z', 'admitted_by' => 'key:C6D8'],
            ['capability' => 'Prestamos', 'scope' => '(no scope) herramientas.contar', 'verbs' => ['herramientas.contar'], 'withdrawn_by' => 'key:C6D8', 'at' => '2026-10-07T19:30:00Z', 'admitted_by' => 'key:C6D8'],
        ])]);

        self::assertStringContainsString('Withdrawn: herramientas:write of Prestamos · herramientas.agregar; herramientas.prestar · by passkey:QM1L, 2026-10-07T19:00:00Z', $html);
        self::assertStringContainsString('Withdrawn: (no scope) herramientas.contar of Prestamos', $html);
        self::assertSame(2, substr_count($html, 'data-seat-withdrawn'));
        self::assertStringNotContainsString('data-seat-withdraw>', $html, 'there is nothing left to take back');
    }

    /** The scope waits again, and its card says this is something a person took back — not something nobody decided. */
    public function testACardForAScopeThatWasWithdrawnSaysWhoTookItBack(): void
    {
        $card = [
            'capability' => 'Prestamos', 'permission' => 'herramientas:write', 'scope' => 'herramientas:write', 'contract' => 'sha256:' . str_repeat('a', 64), 'not_admissible' => null,
            'opens' => [['verb' => 'herramientas.prestar', 'mutating' => true, 'standing' => 'withdrawn']],
            'withdrawn' => ['by' => 'passkey:QM1L', 'at' => '2026-10-07T19:00:00Z'],
        ];
        $view = new DecisionsInboxView();

        $frontier = $view->frontierHtml([['session' => 'taller', 'goal' => '', 'seat' => 'key:' . self::SEAT, 'refusals' => [], 'admissions' => [['seq' => 9, 'tool' => 'herramientas_prestar'] + $card]]]);
        $seats = $view->seatsHtml([['unadmitted' => [['verbs' => ['herramientas.prestar'], 'ran_before' => false] + $card]] + $this->seat([])]);

        foreach ([$frontier, $seats] as $html) {
            self::assertStringContainsString('Its admission was withdrawn by passkey:QM1L, 2026-10-07T19:00:00Z.', $html);
            self::assertStringContainsString('its admission was withdrawn', $html, 'and each verb says so');
        }
        self::assertStringNotContainsString('was withdrawn by', $view->frontierHtml([['session' => 'taller', 'goal' => '', 'seat' => 'key:' . self::SEAT, 'refusals' => [], 'admissions' => [['seq' => 9, 'tool' => 'herramientas_prestar', 'withdrawn' => null] + $card]]]));
    }

    public function testTheWithdrawDoorIsMountedAsAPost(): void
    {
        self::assertSame('identity:withdraw', PanelDoorController::WITHDRAW);
        self::assertSame('/workspace/withdraw', PanelDoorController::DOORS[PanelDoorController::WITHDRAW]['path']);
        self::assertSame('POST', PanelDoorController::DOORS[PanelDoorController::WITHDRAW]['verb']);
    }

    /**
     * @param list<array<string, mixed>> $admitted
     * @param list<array<string, mixed>> $withdrawn
     *
     * @return array<string, mixed>
     */
    private function seat(array $admitted, array $withdrawn = []): array
    {
        return ['fingerprint' => self::SEAT, 'label' => 'resident', 'scopes' => ['agent:run'], 'authorized_by' => 'passkey:QM1L', 'admitted' => $admitted, 'unadmitted' => [], 'withdrawn' => $withdrawn];
    }
}
