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
use Milpa\AgentWorkspace\Live\ComposerBar;
use Milpa\AgentWorkspace\Live\Conversation;
use Milpa\AgentWorkspace\Live\ShellSignals;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * Greenhouse decisions/0513 (R4 of evidence/1036): what the panel paints during a resident's two-hour run is asked of
 * whoever knows it — the house's own voice, the process, the session's mode, the window the run obeyed.
 */
final class ThePanelThroughALongRunTest extends TestCase
{
    /** 1036's grant notice, verbatim (seq 66). */
    private const NOTICE = '[house] passkey:Spt4GZi-TDJUQnGvUgUzm-RJ_3yhI5ZHuep_N0LSXKw granted this seat the scope «plugins.Blog:write». Your call #62 (make plugin=Blog) was refused for lacking it; that same call can run now. Nothing else changed.';

    private string $root = '';

    protected function tearDown(): void
    {
        DesktopData::useRunLease(null);
        HeldLease::$held = [];
        if ($this->root !== '') {
            @unlink($this->root . '/var/agent-sessions.jsonl');
            @rmdir($this->root . '/var');
            @rmdir($this->root);
        }
    }

    /** (b) The grant's notice, after the leg that was refused, leaves the session idle — the composer free. */
    public function testTheHousesNoticeIsNotAHumanTurn(): void
    {
        $record = LedgerSession::fold('s', self::rows(self::afterTheGrant()));

        self::assertSame('idle', $record['state'], 'nobody asked the seat to work: /goal can be sent');
        self::assertSame(1, $record['turns'], 'the human asked once');
        self::assertContains('session.turn', array_column($record['activity'], 'type'));
        self::assertStringContainsString('granted this seat', implode(' ', array_column($record['activity'], 'data')), 'the notice stays, auditable (0495)');
    }

    /** (b) negative: a human who writes the next turn still opens work, and reopens the verdict. */
    public function testAHumanTurnStillOpensWork(): void
    {
        $rows = [...self::afterTheGrant(), ['session.closure_derived', ['verified' => false, 'reasons' => ['r']]], ['session.turn', ['role' => 'user', 'content' => 'continue']]];
        $record = LedgerSession::fold('s', self::rows($rows));

        self::assertSame('working', $record['state']);
        self::assertSame(2, $record['turns']);
        self::assertNull($record['closure'], 'the human\'s turn reopens the work');

        $notice = LedgerSession::fold('s', self::rows([...self::afterTheGrant(), ['session.closure_derived', ['verified' => false, 'reasons' => ['r']]], ['session.turn', ['role' => 'user', 'content' => self::NOTICE]]]));
        self::assertNotNull($notice['closure'], 'the house\'s notice does not');
    }

    /** (b) the prefix is the house's voice only at the start: a human quoting it is still a human. */
    public function testOnlyATurnThatBeginsInTheHousesVoiceIsTheHouses(): void
    {
        $record = LedgerSession::fold('s', self::rows([...self::afterTheGrant(), ['session.turn', ['role' => 'user', 'content' => 'why did you say ' . self::NOTICE]]]));

        self::assertSame('working', $record['state']);
    }

    /** (c) A turn whose run holds the lease is working — no «interrupted», the composer in stop. */
    public function testALiveRunIsWorkingNotInterrupted(): void
    {
        $data = $this->house([['session.started', ['goal' => 'g', 'mode' => 'auto']], ['session.turn', ['role' => 'user', 'content' => 'continue']]]);
        DesktopData::useRunLease(HeldLease::class);
        HeldLease::$held = [$this->root . '|s' => true];

        self::assertSame('working', $data->counters()['state']);
        self::assertTrue($data->running('s'));
        self::assertTrue(ShellSignals::of(new Catalog(), $data)['session.working'], 'the composer shows stop while it runs');
        self::assertStringNotContainsString('milpa-interrupted', (new Conversation('secret', null, $data))->render('s'));
    }

    /** (c) The same stream with nobody holding the lease is interrupted — the notice, and a composer that can send. */
    public function testATurnNoRunHoldsIsInterruptedAndTheComposerIsFree(): void
    {
        $data = $this->house([['session.started', ['goal' => 'g', 'mode' => 'auto']], ['session.turn', ['role' => 'user', 'content' => 'continue']]]);
        DesktopData::useRunLease(HeldLease::class);

        self::assertSame('interrupted', $data->counters()['state']);
        $signals = ShellSignals::of(new Catalog(), $data);
        self::assertFalse($signals['session.working'], '«send again» can be sent');
        self::assertSame('Interrupted', $signals['session.state.label']);
        self::assertStringContainsString('milpa-interrupted', (new Conversation('secret', null, $data))->render('s'));
    }

    /** (c) A house whose runtime keeps no lease reads the stream alone, as before: working, and the old notice. */
    public function testAHouseWithoutALeaseKeepsTheOldReading(): void
    {
        $data = $this->house([['session.started', ['goal' => 'g']], ['session.turn', ['role' => 'user', 'content' => 'go']]]);
        DesktopData::useRunLease('Milpa\\NoSuch\\Lease');

        self::assertNull($data->running('s'));
        self::assertSame('working', $data->counters()['state']);
        self::assertStringContainsString('milpa-interrupted', (new Conversation('secret', null, $data))->render('s'));
    }

    /** (d) The chip says the mode the open session runs in — 1036's resident was AUTO, the chip said Ask. */
    public function testTheChipSaysTheOpenSessionsMode(): void
    {
        $data = $this->house([['session.started', ['goal' => 'g', 'mode' => 'auto']], ['session.turn', ['role' => 'user', 'content' => 'go']]]);

        self::assertSame('auto', $data->mode());
        $signals = ShellSignals::of(new Catalog(), $data);
        self::assertSame('auto', $signals['composer.mode']);
        self::assertSame('Continue automatically', $signals['composer.mode.label']);
        self::assertStringContainsString('Continue automatically', (new ComposerBar('secret', $data))->render());

        $changed = $this->house([['session.started', ['goal' => 'g', 'mode' => 'auto']], ['session.mode_changed', ['mode' => 'acknowledge']]]);
        self::assertSame('acknowledge', $changed->mode(), 'the last mode_changed wins');
    }

    /** (d) negative: with no session open, the chip follows Settings as before. */
    public function testWithNoSessionTheChipFollowsSettings(): void
    {
        $kernel = Kernel::boot(['root' => sys_get_temp_dir(), 'plugins' => []]);
        $kernel->container()->registerService(Kernel::class, $kernel);

        self::assertSame('ask', (new DesktopData($kernel->container(), null, '', null, '/nonexistent/ledger.jsonl'))->mode());
    }

    /** (e) The window is the one the run recorded — 49,152 in 1036 — not 32,768. */
    public function testTheWindowIsTheOneTheRunObeyed(): void
    {
        $data = $this->house([
            ['session.started', ['goal' => 'g']],
            ['session.window_composed', ['tokens' => 49152, 'source' => 'measured', 'declared' => null, 'measured' => 49152]],
            ['session.model_returned', ['usage' => ['prompt_tokens' => 19580, 'total_tokens' => 20000]]],
        ], ['agent' => ['contextTokens' => 100000]]);

        $ctx = $data->context();
        self::assertSame(49152, $ctx['window'], 'what the run obeyed beats a declaration it was clipped from');
        self::assertSame(40, $ctx['used_pct']);
        self::assertSame('49.15K', ShellSignals::of(new Catalog(), $data)['context.window']);
    }

    /** (e) A stream from before the event falls back to what was declared, by the key the runtime reads. */
    public function testAnOldStreamReadsTheDeclaredWindow(): void
    {
        $data = $this->house([['session.started', ['goal' => 'g']], ['session.model_returned', ['usage' => ['prompt_tokens' => 8192, 'total_tokens' => 9000]]]], ['agent' => ['contextTokens' => '16384']]);

        self::assertSame(16384, $data->context()['window']);
        self::assertSame(50, $data->context()['used_pct']);
    }

    /** (e) And with nothing recorded or declared, the window is unknown — never the 32.77K of 1036. */
    public function testNoWindowIsSaidAsUnknown(): void
    {
        $previous = getenv('MILPA_AGENT_CONTEXT_TOKENS');
        putenv('MILPA_AGENT_CONTEXT_TOKENS');
        try {
            $data = $this->house([['session.started', ['goal' => 'g']], ['session.model_returned', ['usage' => ['prompt_tokens' => 8192, 'total_tokens' => 9000]]]]);
            $ctx = $data->context();

            self::assertSame(0, $ctx['window']);
            self::assertSame(0, $ctx['used_pct'], 'no percentage over a number nobody said');
            self::assertSame('?', ShellSignals::of(new Catalog(), $data)['context.window']);
            self::assertStringNotContainsString('32.77K', (new ComposerBar('secret', $data))->render());

            putenv('MILPA_AGENT_CONTEXT_TOKENS=24576');
            self::assertSame(24576, $data->context()['window'], 'the environment the runtime reads is read too');
        } finally {
            $previous === false ? putenv('MILPA_AGENT_CONTEXT_TOKENS') : putenv('MILPA_AGENT_CONTEXT_TOKENS=' . $previous);
        }
    }

    /**
     * The refused leg, the grant, and its notice — 1036's seq 2 → 66, in shape.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private static function afterTheGrant(): array
    {
        return [
            ['session.started', ['goal' => 'Build the blog', 'mode' => 'auto']],
            ['session.turn', ['role' => 'user', 'content' => 'Build the blog']],
            ['session.model_called', []],
            ['session.run_terminated', ['reason' => 'progress_stalled', 'receipt' => null]],
            ['session.sequence_authorized', ['operation' => 'agent']],
            ['session.turn', ['role' => 'user', 'content' => self::NOTICE]],
        ];
    }

    /**
     * A booted house whose ledger holds session `s` with `$events`.
     *
     * @param list<array{0: string, 1: array<string, mixed>}> $events
     * @param array<string, mixed>                            $config
     */
    private function house(array $events, array $config = []): DesktopData
    {
        $this->root = sys_get_temp_dir() . '/milpa-long-run-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var', 0o777, true);
        file_put_contents($this->root . '/var/agent-sessions.jsonl', implode("\n", array_map(static fn (array $row): string => (string) json_encode($row), self::rows($events))) . "\n");
        $kernel = Kernel::boot(['root' => $this->root, 'plugins' => [], 'config' => $config]);
        $kernel->container()->registerService(Kernel::class, $kernel);
        $data = new DesktopData($kernel->container());
        $data->select('s');

        return $data;
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>}> $events
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(array $events): array
    {
        $out = [];
        foreach ($events as $i => [$type, $payload]) {
            $out[] = ['stream_id' => 'agent-session:s', 'type' => $type, 'payload' => $payload, 'seq' => $i + 1];
        }

        return $out;
    }
}

/** The runtime's lease, stood in: which (root, session) pairs a process holds. */
final class HeldLease
{
    /** @var array<string, bool> */
    public static array $held = [];

    public static function held(string $root, string $sessionId): bool
    {
        return self::$held[$root . '|' . $sessionId] ?? false;
    }
}
