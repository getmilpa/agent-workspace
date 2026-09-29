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
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\AgentWorkspace\Live\WorkBoard;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * The house's closure verdict is shown where Rod looks (greenhouse decisions/0509 §7).
 *
 * Measured (evidence/1036): the final answer said «The blog is built, tested, and live», the Work board put
 * the eight todos in DONE, and the house had written `session.closure_derived {verified: false}` — on no
 * screen. The fold now carries the verdict, the transcript stamps it on the answer, and the board says above
 * its cards whether the house verified them.
 *
 * @guards the last verdict is folded, travels in the transcript, and the board shows verified/unverified with
 *         what it rests on or why not — in the catalog's locale
 *
 * @refuses showing a verdict a later request reopened; showing reasons for a verified close; unescaped reasons
 */
final class TheHouseVerdictIsShownWhereRodLooksTest extends TestCase
{
    private const UNVERIFIED = ['verified' => false, 'reasons' => ['artifact Blog has no current verification', 'artifact <script>x</script> has no current verification'], 'scope' => 'recorded_work'];

    private const VERIFIED = ['verified' => true, 'reasons' => [], 'scope' => 'recorded_work_and_house_observation',
        'derivedFrom' => ['observation' => ['subject' => 'blog', 'seq' => 9], 'lastChangeSeq' => 9]];

    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }
    }

    public function testTheFoldCarriesTheHouseLastVerdictUntilARequestReopensTheWork(): void
    {
        $record = LedgerSession::fold('s', $this->rows($this->leg(self::UNVERIFIED)));

        self::assertSame(['verified' => false, 'reasons' => self::UNVERIFIED['reasons'], 'scope' => 'recorded_work', 'seq' => 5], $record['closure']);

        $reopened = LedgerSession::fold('s', $this->rows([...$this->leg(self::UNVERIFIED), ['session.turn', ['role' => 'user', 'content' => 'and add an RSS feed']]]));
        self::assertNull($reopened['closure'], 'a later request reopened the work: the verdict answered the one before it');

        $later = LedgerSession::fold('s', $this->rows([...$this->leg(self::UNVERIFIED), ...$this->leg(self::VERIFIED)]));
        self::assertTrue($later['closure']['verified'], 'the last verdict is the one shown');
        self::assertNull(LedgerSession::fold('s', $this->rows([['session.started', ['goal' => 'g']]]))['closure'], 'no verdict recorded, none shown');
        self::assertFalse(LedgerSession::fold('s', $this->rows($this->leg(['reasons' => [], 'scope' => 'recorded_work'])))['closure']['verified'], 'a verdict that does not SAY verified is not read as verified');
    }

    public function testTheTranscriptCarriesTheVerdictBesideTheAnswerItJudged(): void
    {
        $data = $this->data($this->leg(self::UNVERIFIED));

        $rows = $data->transcript('s');

        self::assertSame(['user', 'agent', 'closure'], array_column($rows, 'kind'));
        self::assertSame(['kind' => 'closure', 'verified' => false, 'reasons' => self::UNVERIFIED['reasons'], 'scope' => 'recorded_work'], $rows[2]);
    }

    public function testTheBoardSaysTheHouseDidNotVerifyAndWhy(): void
    {
        $html = (new WorkBoard('secret', $this->data($this->leg(self::UNVERIFIED))))->render();

        self::assertStringContainsString('data-work-closure data-verified="0"', $html);
        self::assertStringContainsString('Not verified by the house', $html);
        self::assertStringContainsString('The cards below are the session&#039;s own claim. The house has not verified the work:', $html);
        self::assertStringContainsString('<li>artifact Blog has no current verification</li>', $html);
        self::assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html, 'a reason is data, escaped');
        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertLessThan(strpos($html, 'class="work-board"'), strpos($html, 'data-work-closure'), 'above the cards');
    }

    public function testTheBoardSaysTheHouseVerifiedAndOnWhat(): void
    {
        $html = (new WorkBoard('secret', $this->data($this->leg(self::VERIFIED))))->render();

        self::assertStringContainsString('data-work-closure data-verified="1" data-scope="recorded_work_and_house_observation"', $html);
        self::assertStringContainsString('Verified by the house', $html);
        self::assertStringContainsString('Every todo is closed with evidence the house accepted, and the house observed what landed served after the last change.', $html);
        self::assertStringNotContainsString('work-closure__reasons', $html);
    }

    public function testTheBoardSpeaksTheCatalogLocale(): void
    {
        $html = (new WorkBoard('secret', $this->data($this->leg(self::UNVERIFIED)), null, new Catalog('es')))->render();

        self::assertStringContainsString('No verificado por la casa', $html);
    }

    public function testWithoutAVerdictTheBoardSaysNothingAboutIt(): void
    {
        $html = (new WorkBoard('secret', $this->data([['session.started', ['goal' => 'g']], ['session.todo_changed', ['id' => 't1', 'text' => 'x', 'status' => 'done']]])))->render();

        self::assertStringNotContainsString('data-work-closure', $html);
    }

    /**
     * One leg as 1036 recorded its end: the answer, the termination, the verdict.
     *
     * @param array<string, mixed> $closure
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function leg(array $closure): array
    {
        return [
            ['session.turn', ['role' => 'user', 'content' => 'Build the blog']],
            ['session.todo_changed', ['id' => 't1', 'text' => 'Serve GET /blog', 'status' => 'done']],
            ['session.turn', ['role' => 'assistant', 'content' => 'The blog is built, tested, and live.']],
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
        $this->dir = sys_get_temp_dir() . '/milpa-closure-' . uniqid('', true);
        mkdir($this->dir);
        $ledger = $this->dir . '/agent-sessions.jsonl';
        file_put_contents($ledger, implode("\n", array_map(static fn (array $r): string => json_encode($r, \JSON_THROW_ON_ERROR), $this->rows($events))) . "\n");

        return new DesktopData(new DIContainer(), null, '', null, $ledger);
    }
}
