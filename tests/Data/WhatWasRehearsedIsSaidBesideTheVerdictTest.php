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

namespace Milpa\AgentWorkspace\Tests\Data;

use Milpa\AgentWorkspace\Data\DesktopData;
use Milpa\AgentWorkspace\Data\LedgerSession;
use Milpa\AgentWorkspace\Data\Rehearsed;
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\AgentWorkspace\Live\WorkBoard;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * What a session REHEARSED AND DID NOT APPLY is said beside the house's verdict (greenhouse decisions/0605, R2 —
 * decided by Rod on 2026-10-09).
 *
 * Since app-runtime 0.218.0 the session that wrote an operation may be handed, after the refusal, what its call
 * answered in a rehearsal; that refusal no longer holds the closure, and the verdict says so beside itself:
 * `rehearsed: {calls, of_verbs_that_change_state, applied: false}`. The panel dropped it — the fold, the transcript
 * and the stream kept `{verified, reasons, scope}` — so «verified» read the same for a builder that tried its own
 * operations as for one that tried nothing, and a goal that asked to build AND to use closed with the use undone and
 * nothing on the screen saying so (evidence/1179).
 *
 * @guards the datum travels in the fold and in the transcript; the board says it in one line, with its two numbers,
 *         under the verdict — verified or not; in the catalog's locale
 *
 * @refuses a line drawn from anything but the datum: a verdict whose reasons speak of a rehearsal says nothing; a
 *          datum that says something was APPLIED, or that counts no call, is not this and is not shown
 */
final class WhatWasRehearsedIsSaidBesideTheVerdictTest extends TestCase
{
    private const REHEARSED = ['calls' => 2, 'of_verbs_that_change_state' => 1, 'applied' => false];

    private const VERIFIED = ['verified' => true, 'reasons' => [], 'scope' => 'house_observation'];

    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }
    }

    public function testOnlyTheDatumAsTheHouseWritesItIsRead(): void
    {
        self::assertSame(self::REHEARSED, Rehearsed::of(self::REHEARSED + ['a key the house may add later' => 1]));
        self::assertSame(['calls' => 1, 'of_verbs_that_change_state' => 0, 'applied' => false], Rehearsed::of(['calls' => 1, 'of_verbs_that_change_state' => 0, 'applied' => false]));

        foreach ([
            'nothing' => null,
            'a sentence' => 'two calls were answered in a rehearsal',
            'no call' => ['calls' => 0, 'of_verbs_that_change_state' => 0, 'applied' => false],
            'something applied' => ['calls' => 2, 'of_verbs_that_change_state' => 1, 'applied' => true],
            'applied not said' => ['calls' => 2, 'of_verbs_that_change_state' => 1],
            'calls as text' => ['calls' => '2', 'of_verbs_that_change_state' => 1, 'applied' => false],
            'more writes than calls' => ['calls' => 1, 'of_verbs_that_change_state' => 2, 'applied' => false],
            'a negative count' => ['calls' => 2, 'of_verbs_that_change_state' => -1, 'applied' => false],
            'no count of writes' => ['calls' => 2, 'applied' => false],
        ] as $what => $notIt) {
            self::assertNull(Rehearsed::of($notIt), $what);
        }
    }

    public function testTheFoldAndTheTranscriptCarryItBesideTheVerdict(): void
    {
        $leg = $this->leg(self::VERIFIED + ['rehearsed' => self::REHEARSED]);

        $closure = LedgerSession::fold('s', $this->rows($leg))['closure'];
        self::assertSame(['verified' => true, 'reasons' => [], 'scope' => 'house_observation', 'seq' => 4, 'rehearsed' => self::REHEARSED], $closure);

        $rows = $this->data($leg)->transcript('s');
        self::assertSame(['kind' => 'closure', 'verified' => true, 'reasons' => [], 'scope' => 'house_observation', 'rehearsed' => self::REHEARSED], $rows[2]);
    }

    public function testAVerdictThatSaysNothingOfItCarriesNothingOfIt(): void
    {
        $leg = $this->leg(self::VERIFIED);

        self::assertArrayNotHasKey('rehearsed', LedgerSession::fold('s', $this->rows($leg))['closure']);
        self::assertArrayNotHasKey('rehearsed', $this->data($leg)->transcript('s')[2]);
        self::assertStringNotContainsString('data-work-rehearsed', (new WorkBoard('secret', $this->data($leg)))->render());
    }

    public function testTheBoardSaysItInOneLineUnderAVerifiedVerdict(): void
    {
        $html = (new WorkBoard('secret', $this->data($this->leg(self::VERIFIED + ['rehearsed' => self::REHEARSED]))))->render();

        self::assertStringContainsString('data-work-closure data-verified="1"', $html);
        self::assertStringContainsString(
            '<p class="mui-alert__desc work-closure__rehearsed" data-work-rehearsed="2"><strong>Rehearsed, not applied.</strong> '
            . 'Calls of operations this session built that the house answered in a rehearsal: 2 (1 of an operation that changes state). '
            . 'Nothing of them was applied to the house.</p>',
            $html,
        );
        self::assertSame(1, substr_count($html, 'data-work-rehearsed'), 'one line');
    }

    public function testTheBoardSaysItUnderAVerdictThatDidNotVerifyToo(): void
    {
        $unverified = ['verified' => false, 'reasons' => ['artifact Prestamos has no current verification'], 'scope' => 'recorded_work', 'rehearsed' => self::REHEARSED];

        $html = (new WorkBoard('secret', $this->data($this->leg($unverified))))->render();

        self::assertStringContainsString('data-work-closure data-verified="0"', $html);
        self::assertStringContainsString('data-work-rehearsed="2"', $html, 'the datum is beside the verdict whichever it is');
        self::assertStringContainsString('<li>artifact Prestamos has no current verification</li>', $html);
    }

    /** BY THE DATUM, NEVER BY A SENTENCE: a reason that speaks of a rehearsal is a reason, and draws no line. */
    public function testAReasonThatSpeaksOfARehearsalDrawsNoLine(): void
    {
        $spoken = ['verified' => false, 'reasons' => ['«herramientas.prestar» ran in a trial, not in the house (seq 9): a rehearsal is not work'], 'scope' => 'recorded_work'];
        $malformed = self::VERIFIED + ['rehearsed' => ['calls' => 2, 'of_verbs_that_change_state' => 1, 'applied' => true]];

        self::assertStringNotContainsString('data-work-rehearsed', (new WorkBoard('secret', $this->data($this->leg($spoken))))->render());
        self::assertStringNotContainsString('data-work-rehearsed', (new WorkBoard('secret', $this->data($this->leg($malformed))))->render(), 'a datum that says something was applied is not this one');
    }

    public function testTheBoardSpeaksTheCatalogLocale(): void
    {
        $html = (new WorkBoard('secret', $this->data($this->leg(self::VERIFIED + ['rehearsed' => self::REHEARSED])), null, new Catalog('es')))->render();

        self::assertStringContainsString('<strong>Ensayado, no aplicado.</strong> Llamadas a operaciones que esta sesión construyó y que la casa contestó en un ensayo: 2 (1 de una operación que cambia estado). Nada de ellas se aplicó a la casa.', $html);
    }

    /**
     * @param array<string, mixed> $closure
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function leg(array $closure): array
    {
        return [
            ['session.turn', ['role' => 'user', 'content' => 'Build a plugin named Prestamos and lend the drill']],
            ['session.turn', ['role' => 'assistant', 'content' => 'Prestamos is built.']],
            ['session.run_terminated', ['reason' => 'final_answer', 'receipt' => null]],
            ['session.closure_derived', $closure],
        ];
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>}> $events
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $events): array
    {
        $out = [];
        foreach ($events as $i => [$type, $payload]) {
            $out[] = ['stream_id' => 'agent-session:s', 'type' => $type, 'payload' => $payload, 'seq' => $i + 1];
        }

        return $out;
    }

    /** @param list<array{0: string, 1: array<string, mixed>}> $events */
    private function data(array $events): DesktopData
    {
        $this->dir = sys_get_temp_dir() . '/milpa-rehearsed-' . uniqid('', true);
        mkdir($this->dir);
        $ledger = $this->dir . '/agent-sessions.jsonl';
        file_put_contents($ledger, implode("\n", array_map(static fn (array $r): string => json_encode($r, \JSON_THROW_ON_ERROR), $this->rows($events))) . "\n");

        return new DesktopData(new DIContainer(), null, '', null, $ledger);
    }
}
