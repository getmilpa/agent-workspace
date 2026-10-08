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

use Milpa\AgentWorkspace\Live\DecisionsInboxView;
use PHPUnit\Framework\TestCase;

/**
 * A built capability is in works or admitted, never both — and the cards say which (greenhouse decisions/0590, rule 10).
 *
 * Admitting closes the capability's building permit for every seat; granting that permit again over a capability
 * persons admitted suspends what they admitted. Both are things an act DOES beyond what its button names, so the
 * card a person approves from says them before the touch. The view judges nothing: who holds the permit, who was
 * admitted what and what was closed all arrive from the house.
 */
final class ThePanelSaysInWorksOrAdmittedTest extends TestCase
{
    private const SEAT = 'EEEE5555FFFF6666AAAA7777BBBB8888CCCC9999';
    private const OTHER = '1111AAAA2222BBBB3333CCCC4444DDDD5555EEEE';

    public function testAnAdmissionCardSaysWhosePermitAdmittingCloses(): void
    {
        $html = $this->admission(['works' => ['holders' => [self::SEAT, self::OTHER]]]);

        self::assertStringContainsString(
            '<p class="decision-card__facts" data-admit-works>Prestamos is in works: its building permit is held by EEEE5555FFFF… and 1111AAAA2222…, and while it is no seat uses its verbs. Admitting closes that permit; what you already admitted and did not change stands again.</p>',
            $html,
        );
        // Where nobody holds it, or the house does not say, the card says nothing of it.
        self::assertStringNotContainsString('data-admit-works', $this->admission(['works' => null]));
        self::assertStringNotContainsString('data-admit-works', $this->admission([]));
        self::assertStringNotContainsString('data-admit-works', $this->admission(['works' => ['holders' => []]]));
    }

    public function testTheGrantThatReopensTheWorksSaysWhatItSuspends(): void
    {
        $html = $this->authoring(['suspends' => [['seat' => self::SEAT, 'scopes' => ['herramientas:read', 'herramientas:write']], ['seat' => self::OTHER, 'scopes' => ['herramientas:read']]]]);

        self::assertStringContainsString(
            '<p class="decision-card__facts" data-seat-suspends>Prestamos is admitted — herramientas:read, herramientas:write to EEEE5555FFFF…; herramientas:read to 1111AAAA2222…. Granting puts it back in works: what was admitted is suspended while this permit stands, and the next admission closes it.</p>',
            $html,
        );
        self::assertStringNotContainsString('data-seat-suspends', $this->authoring(['suspends' => []]));
        self::assertStringNotContainsString('data-seat-suspends', $this->authoring([]), 'a runtime that does not know admissions says nothing');
    }

    public function testYourSeatsSaysWhatIsSuspendedWhichPermitsASeatHoldsAndWhatWasClosed(): void
    {
        $html = (new DecisionsInboxView())->seatsHtml([[
            'fingerprint' => self::SEAT, 'label' => 'resident', 'scopes' => ['agent:run', 'plugins.Prestamos:write'], 'authorized_by' => 'passkey:QM1L',
            'admitted' => [
                ['capability' => 'Prestamos', 'scope' => 'herramientas:write', 'key' => 'herramientas:write', 'admitted_by' => 'passkey:QM1L', 'at' => '2026-10-08T01:00:00Z', 'verbs' => ['herramientas.prestar' => 'admitted'], 'suspended' => [self::SEAT]],
                ['capability' => 'Otra', 'scope' => 'otra:read', 'key' => 'otra:read', 'admitted_by' => 'passkey:QM1L', 'at' => '2026-10-08T01:00:00Z', 'verbs' => ['otra.ver' => 'admitted'], 'suspended' => []],
            ],
            'unadmitted' => [], 'withdrawn' => [],
            'permits' => ['Prestamos'],
            'closures' => [['permit' => 'plugins.Prestamos:write', 'capability' => 'Prestamos', 'closed_by' => 'passkey:QM1L', 'at' => '2026-10-08T00:30:00Z', 'admitted' => ['seat' => self::OTHER, 'scope' => 'herramientas:read']]],
        ]]);

        self::assertMatchesRegularExpression('~Admitted: herramientas:write of Prestamos.*?</span> <em data-seat-suspended>suspended: Prestamos is in works</em>~s', $html);
        self::assertSame(1, substr_count($html, 'data-seat-suspended'), 'only what is in works');
        self::assertStringContainsString('<p class="decision-card__facts" data-seat-permits>It holds the building permit of Prestamos: while it does, no seat uses those verbs.</p>', $html);
        self::assertStringContainsString('<p class="decision-card__facts" data-seat-closed>Building permit of Prestamos closed · by passkey:QM1L, 2026-10-08T00:30:00Z</p>', $html);
    }

    /**
     * Measured in the lab house (greenhouse evidence/1147): a scope a person HAD admitted, suspended by the works,
     * waited under the heading «No admission covers…». The house says when an admission is whole and only
     * suspended, and the card is headed by that.
     */
    public function testAScopeThatIsOnlySuspendedIsNotHeadedAsIfNobodyAdmittedIt(): void
    {
        $card = [
            'capability' => 'Prestamos', 'scope' => 'herramientas:write', 'verbs' => ['herramientas.prestar'], 'ran_before' => false,
            'contract' => 'sha256:' . str_repeat('a', 64), 'not_admissible' => null,
            'opens' => [['verb' => 'herramientas.prestar', 'mutating' => true, 'standing' => 'admitted']],
            'works' => ['holders' => [self::OTHER]],
        ];
        $seat = ['fingerprint' => self::SEAT, 'label' => 'resident', 'scopes' => ['agent:run'], 'authorized_by' => 'passkey:QM1L', 'admitted' => []];

        $suspended = (new DecisionsInboxView())->seatsHtml([$seat + ['unadmitted' => [$card + ['suspended' => true]]]]);
        $lacking = (new DecisionsInboxView())->seatsHtml([$seat + ['unadmitted' => [$card + ['suspended' => false]]]]);

        self::assertStringContainsString('<p class="decision-card__q">The admission of herramientas:write of the capability Prestamos is suspended</p>', $suspended);
        self::assertStringNotContainsString('No admission covers', $suspended);
        self::assertStringContainsString('<p class="decision-card__q">No admission covers herramientas:write of the capability Prestamos</p>', $lacking);
        self::assertStringContainsString('No admission covers', (new DecisionsInboxView())->seatsHtml([$seat + ['unadmitted' => [$card]]]), 'a runtime that does not say reads as it did');
    }

    public function testASeatTheHouseSaysNothingOfReadsAsItDid(): void
    {
        $html = (new DecisionsInboxView())->seatsHtml([[
            'fingerprint' => self::SEAT, 'label' => 'resident', 'scopes' => ['agent:run'], 'authorized_by' => 'passkey:QM1L',
            'admitted' => [['capability' => 'Prestamos', 'scope' => 'herramientas:write', 'key' => 'herramientas:write', 'admitted_by' => 'passkey:QM1L', 'at' => '2026-10-08T01:00:00Z', 'verbs' => ['herramientas.prestar' => 'admitted']]],
            'unadmitted' => [],
        ]]);

        foreach (['data-seat-suspended', 'data-seat-permits', 'data-seat-closed'] as $hook) {
            self::assertStringNotContainsString($hook, $html);
        }
    }

    public function testBothLanguagesHaveTheWords(): void
    {
        foreach (['frontier.admit_works', 'frontier.suspends', 'frontier.and', 'seats.suspended', 'seats.suspended_scope', 'seats.permits', 'seats.closed', 'frontier.admitted_closed', 'frontier.granted_works'] as $key) {
            $en = (new \Milpa\AgentWorkspace\I18n\Catalog('en'))->all()[$key] ?? null;
            $es = (new \Milpa\AgentWorkspace\I18n\Catalog('es'))->all()[$key] ?? null;
            self::assertIsString($en, $key);
            self::assertIsString($es, $key);
            self::assertNotSame($en, $es, $key);
        }
        foreach (['admit_works', 'suspends', 'and'] as $word) {
            self::assertContains($word, DecisionsInboxView::ADMISSION_WORDS);
        }
        foreach (['suspended', 'suspended_scope', 'permits', 'closed'] as $word) {
            self::assertContains($word, DecisionsInboxView::HOLDING_WORDS);
        }
    }

    /** @param array<string, mixed> $more */
    private function admission(array $more): string
    {
        return (new DecisionsInboxView())->frontierHtml([[
            'session' => 'taller', 'goal' => '', 'seat' => 'key:' . self::SEAT, 'refusals' => [],
            'admissions' => [$more + [
                'seq' => 9, 'tool' => 'herramientas_prestar', 'capability' => 'Prestamos', 'permission' => 'herramientas:write', 'scope' => 'herramientas:write',
                'contract' => 'sha256:' . str_repeat('a', 64), 'not_admissible' => null,
                'opens' => [['verb' => 'herramientas.prestar', 'mutating' => true, 'standing' => 'admitted']],
            ]],
        ]]);
    }

    /** @param array<string, mixed> $more */
    private function authoring(array $more): string
    {
        return (new DecisionsInboxView())->frontierHtml([[
            'session' => 'taller', 'goal' => '', 'seat' => 'key:' . self::SEAT,
            'refusals' => [$more + ['seq' => 12, 'tool' => 'edit', 'plugin' => 'Prestamos', 'permission' => 'plugins.Prestamos:write', 'call' => ['plugin' => 'Prestamos'], 'target' => 'existing', 'named' => true, 'consent' => 'informed']],
        ]]);
    }
}
