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
 * The card a person admits a built verb from (greenhouse decisions/0590, 0597).
 *
 * A seat called a verb of a capability built in the house, and no person had admitted it. The house judged that and
 * hands the panel what a person must see: every verb that scope opens, what each declares, where its work keeps its
 * state, how a call of it would run — and the digest of exactly that. The card paints it and judges nothing; what
 * the passkey approves is the digest of what the card showed.
 */
final class APersonAdmitsABuiltVerbFromThePanelTest extends TestCase
{
    private const DIGEST = 'sha256:12b7c568802279593b0f78fc2462543fab5b37a0f108c494305e70bea67bbee7';
    private const SEAT = 'BD93ED040122235995977BA235799583EC8050B5';

    public function testTheCardShowsEveryVerbTheScopeOpensWhatItDeclaresAndWhereItsStateLives(): void
    {
        $html = (new DecisionsInboxView())->frontierHtml([$this->session([$this->admission()])]);

        // What is approved travels on the card: the refused call, and the digest of what is shown.
        self::assertStringContainsString('data-seat-session="taller"', $html);
        self::assertStringContainsString('data-seat-seq="57"', $html);
        self::assertStringContainsString('data-seat-admits="' . self::DIGEST . '"', $html);
        self::assertStringContainsString('It asks for herramientas:write of the capability Prestamos', $html);
        self::assertStringContainsString('<code>herramientas_agregar nombre=Taladro</code>', $html, 'the refused call, as recorded');

        // Every verb, with what it declares.
        foreach (['herramientas.agregar', 'herramientas.devolver', 'herramientas.prestar'] as $verb) {
            self::assertStringContainsString('<code>' . $verb . '</code>', $html);
        }
        self::assertStringContainsString('changes state', $html);
        self::assertStringContainsString('persistent · none · manual_recovery · write_as_user', $html);
        self::assertStringContainsString('names its target: id', $html);
        // Where its state lives, who said so, and how a call of it would run in this house.
        self::assertStringContainsString('<code>var/herramientas.json</code>', $html);
        self::assertStringContainsString('the store of its entities', $html);
        self::assertStringContainsString('in the house, confined to that state', $html);
        self::assertStringContainsString('what was there is kept', $html);

        // What it does not open, and what the house does not claim.
        self::assertStringContainsString('It does not open the other scopes of Prestamos', $html);
        self::assertStringContainsString('It did not read the code behind it', $html);
        self::assertStringContainsString('sha256:12b7c5688022', $html, 'the digest, short enough to compare');

        // Never one touch: a box the reader ticks knowingly, and a button that says what it admits.
        self::assertStringContainsString('data-seat-ack', $html);
        self::assertStringContainsString('I read the contract: admit herramientas:write of Prestamos for this seat', $html);
        self::assertMatchesRegularExpression('~<button[^>]*data-seat-grant[^>]*>Admit herramientas:write of Prestamos</button>~', $html);
        self::assertStringNotContainsString('Granting opens write over the existing plugin', $html, 'this is not write over a plugin');
    }

    /**
     * Measured with the real panel (greenhouse evidence/1139): a seat that was refused the same scope in ten sessions
     * put ten cards in front of its person, and admitting from any one cleared them all. One decision is one card:
     * the latest refusal of that seat for that contract, saying how many sessions asked.
     */
    public function testASeatThatAskedForTheSameContractInSeveralSessionsGetsOneCard(): void
    {
        $older = ['session' => 'taller-lunes', 'admissions' => [['seq' => 7] + $this->admission()]] + $this->session([]);
        $latest = ['session' => 'taller-martes', 'admissions' => [['seq' => 21] + $this->admission()]] + $this->session([]);
        $other = ['seat' => 'key:D2A77A0E6562218C52C02D67022F264E481377BD', 'session' => 'taller-b', 'admissions' => [['seq' => 3] + $this->admission()]] + $this->session([]);
        $read = ['contract' => 'sha256:' . str_repeat('d', 64), 'permission' => 'herramientas:read', 'scope' => 'herramientas:read', 'seq' => 30] + $this->admission();

        $html = (new DecisionsInboxView())->frontierHtml([$older, ['admissions' => [$latest['admissions'][0], $read]] + $latest, $other]);

        self::assertSame(3, substr_count($html, 'decision-card--admission'), 'one for A\'s write, one for A\'s read, one for B\'s write');
        self::assertStringNotContainsString('data-seat-session="taller-lunes"', $html, 'the older refusal of the same contract is not a second decision');
        self::assertStringContainsString('data-seat-session="taller-martes" data-seat-seq="21"', $html, 'the latest one is what the approval is bound to');
        self::assertStringContainsString('data-seat-session="taller-martes" data-seat-seq="30"', $html, 'another scope is another decision');
        self::assertStringContainsString('data-seat-session="taller-b" data-seat-seq="3"', $html, 'another seat is another decision');
        self::assertSame(1, substr_count($html, 'It asked for this in 2 sessions; this is the latest.'));
    }

    /** The description is text the capability's author wrote — and the author may be the seat that asks. */
    public function testTheCapabilitysOwnWordsAreShownAsItsWordsAndNeverAsMarkup(): void
    {
        $admission = $this->admission();
        $admission['opens'][0]['description'] = 'A harmless read-only helper <script>alert(1)</script>';

        $html = (new DecisionsInboxView())->frontierHtml([$this->session([$admission])]);

        self::assertStringContainsString('The capability&#039;s own words', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        // The declared facts stand before its words: a mutation is said to be one whatever it calls itself.
        self::assertLessThan(strpos($html, 'A harmless read-only helper'), strpos($html, 'changes state'));
    }

    public function testAScopeTheHouseCannotAdmitOffersNothingToApproveAndSaysWhy(): void
    {
        $admission = ['not_admissible' => '«herramientas.purgar» does not declare its effects'] + $this->admission();

        $html = (new DecisionsInboxView())->frontierHtml([$this->session([$admission])]);

        self::assertStringContainsString('This cannot be admitted: «herramientas.purgar» does not declare its effects', $html);
        self::assertStringNotContainsString('data-seat-grant', $html);
        self::assertStringNotContainsString('data-seat-ack', $html);
        self::assertStringContainsString('<code>herramientas.agregar</code>', $html, 'the contract is still shown');
    }

    public function testAVerbWhoseContractMovedOrThatWasAddedSaysSo(): void
    {
        $admission = ['why' => 'changed'] + $this->admission();
        $admission['opens'][0]['standing'] = 'admitted';
        $admission['opens'][1]['standing'] = 'added';
        $admission['opens'][2]['standing'] = 'changed';
        $admission['opens'][2]['runs'] = ['how' => 'asks', 'why' => 'this house cannot confine a process here'];
        $admission['opens'][1]['state'] = ['paths' => ['var/taller/bajas.json'], 'source' => 'declared'];
        $admission['opens'][1]['runs'] = ['how' => 'refused', 'why' => 'it is reached through a link'];

        $html = (new DecisionsInboxView())->frontierHtml([$this->session([$admission])]);

        self::assertStringContainsString('you already admitted it', $html);
        self::assertStringContainsString('added after you admitted this scope', $html);
        self::assertStringContainsString('its contract changed since you admitted it', $html);
        self::assertStringContainsString('a person is asked first: this house cannot confine a process here', $html);
        self::assertStringContainsString('declared by the capability', $html);
        self::assertStringContainsString('never: it is reached through a link', $html);
    }

    public function testAReadAndAVerbThatIsNotWorkInTheDomainSayHowTheyRun(): void
    {
        $admission = $this->admission();
        $admission['opens'] = [
            ['verb' => 'herramientas.listar', 'mutating' => false, 'state' => null, 'runs' => ['how' => 'reads'], 'namedTarget' => null, 'effects' => ['mutation' => 'none', 'externality' => 'none', 'reversibility' => 'not_applicable', 'authority' => 'read']] + $admission['opens'][0],
            ['verb' => 'herramientas.regenerar', 'state' => null, 'runs' => ['how' => 'trial'], 'requiresConfirmation' => true] + $admission['opens'][1],
        ];

        $html = (new DecisionsInboxView())->frontierHtml([$this->session([$admission])]);

        self::assertStringContainsString('changes nothing', $html);
        self::assertStringContainsString('in a trial; lands by a promotion', $html);
        self::assertStringContainsString('asks each time', $html);
    }

    /** A runtime that does not know admissions hands none, and the frontier reads as it did. */
    public function testAHouseWhoseRuntimeHandsNoAdmissionsReadsAsBefore(): void
    {
        $session = $this->session([]);
        unset($session['admissions']);
        $session['refusals'] = [['seq' => 40, 'tool' => 'make', 'plugin' => 'Blog', 'permission' => 'plugins.Blog:write']];

        $html = (new DecisionsInboxView())->frontierHtml([$session]);

        self::assertStringContainsString('It lacks plugins.Blog:write', $html);
        self::assertStringNotContainsString('data-seat-admits', $html);
        self::assertStringNotContainsString('It asks for', $html);
    }

    /** «Your seats»: what each was admitted, whether it still stands, and what no admission covers. */
    public function testYourSeatsSaysWhatEachSeatHoldsAndOffersWhatWaitsWithItsContract(): void
    {
        $card = $this->admission();
        $html = (new DecisionsInboxView())->seatsHtml([[
            'fingerprint' => self::SEAT,
            'label' => 'resident',
            'scopes' => ['agent:run', 'agent:read'],
            'authorized_by' => 'passkey:QM1L',
            'admitted' => [[
                'capability' => 'Prestamos', 'scope' => 'herramientas:read', 'admitted_by' => 'passkey:QM1L', 'at' => '2026-10-07T18:00:00Z',
                'verbs' => ['herramientas.listar' => 'admitted', 'herramientas.buscar' => 'changed', 'herramientas.contar' => 'gone'],
            ]],
            'unadmitted' => [[
                'capability' => 'Prestamos', 'scope' => 'herramientas:write', 'verbs' => array_column($card['opens'], 'verb'), 'ran_before' => true,
                'contract' => self::DIGEST, 'opens' => $card['opens'], 'not_admissible' => null,
            ]],
        ]]);

        self::assertStringContainsString('Admitted: herramientas:read of Prestamos', $html);
        self::assertStringContainsString('herramientas.listar', $html);
        self::assertStringContainsString('herramientas.buscar — its contract changed: no longer covered', $html);
        self::assertStringContainsString('herramientas.contar — gone from the capability', $html);

        // What waits is offered with the same contract a refusal's card shows, bound to THIS seat and THIS digest.
        self::assertStringContainsString('data-admit-seat="' . self::SEAT . '"', $html);
        self::assertStringContainsString('data-seat-admits="' . self::DIGEST . '"', $html);
        self::assertStringContainsString('No admission covers herramientas:write of the capability Prestamos', $html);
        self::assertStringContainsString('It ran these before, by a word it holds', $html);
        self::assertStringContainsString('<code>var/herramientas.json</code>', $html);
        self::assertStringContainsString('data-seat-ack', $html);
        self::assertMatchesRegularExpression('~<button[^>]*data-seat-admit[^>]*>Admit herramientas:write of Prestamos</button>~', $html);
        self::assertStringNotContainsString('data-seat-grant', $html, 'there is no refused call to grant from');
    }

    public function testYourSeatsWithoutHoldingsReadsAsBefore(): void
    {
        $html = (new DecisionsInboxView())->seatsHtml([['fingerprint' => self::SEAT, 'label' => 'resident', 'scopes' => ['agent:run'], 'authorized_by' => 'passkey:QM1L']]);

        self::assertStringContainsString('enrolled by passkey:QM1L', $html);
        self::assertStringNotContainsString('data-seat-admits', $html);
        self::assertStringNotContainsString('Admitted:', $html);
    }

    /** The door «Your seats» admits through: the operation that takes a seat and a digest, and nothing else. */
    public function testTheAdmitDoorIsMountedAsAPost(): void
    {
        self::assertSame('identity:admit', PanelDoorController::ADMIT);
        self::assertSame('/workspace/admit', PanelDoorController::DOORS[PanelDoorController::ADMIT]['path']);
        self::assertSame('POST', PanelDoorController::DOORS[PanelDoorController::ADMIT]['verb']);
        self::assertSame('milpa/app-runtime', PanelDoorController::DOORS[PanelDoorController::ADMIT]['from']);
    }

    /**
     * One seat's session with these admissions waiting, as app-runtime's frontier hands it.
     *
     * @param list<array<string, mixed>> $admissions
     *
     * @return array<string, mixed>
     */
    private function session(array $admissions): array
    {
        return ['session' => 'taller', 'goal' => 'Registra un taladro en el taller y préstalo.', 'seat' => 'key:' . self::SEAT, 'refusals' => [], 'admissions' => $admissions];
    }

    /** @return array<string, mixed> a built verb's refusal, `kind: capability`, with the contract its scope opens */
    private function admission(): array
    {
        $verb = static fn (string $name): array => [
            'verb' => $name,
            'tool' => str_replace('.', '_', $name),
            'description' => 'The workshop: ' . $name,
            'mutating' => true,
            'requiresConfirmation' => false,
            'namedTarget' => $name === 'herramientas.agregar' ? null : 'id',
            'surfaces' => ['cli', 'tui', 'mcp'],
            'scopes' => ['herramientas:write'],
            'effects' => ['mutation' => 'persistent', 'externality' => 'none', 'reversibility' => 'manual_recovery', 'authority' => 'write_as_user', 'subject' => 'data', 'fully_classified' => true],
            'state' => ['paths' => ['var/herramientas.json'], 'source' => 'entities'],
            'runs' => ['how' => 'house', 'pre_image' => true],
            'digest' => 'sha256:' . hash('sha256', $name),
            'standing' => 'never',
            'not_admissible' => null,
        ];

        return [
            'seq' => 57,
            'tool' => 'herramientas_agregar',
            'plugin' => 'Prestamos',
            'permission' => 'herramientas:write',
            'call' => ['nombre' => 'Taladro'],
            'target' => 'existing',
            'named' => false,
            'consent' => 'informed',
            'kind' => 'capability',
            'capability' => 'Prestamos',
            'scope' => 'herramientas:write',
            'why' => 'never',
            'opens' => [$verb('herramientas.agregar'), $verb('herramientas.devolver'), $verb('herramientas.prestar')],
            'contract' => self::DIGEST,
            'not_admissible' => null,
        ];
    }
}
