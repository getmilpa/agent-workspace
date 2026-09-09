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

use Milpa\AgentWorkspace\Data\LedgerSession;
use PHPUnit\Framework\TestCase;

/**
 * ONE SESSION, FOLDED FROM THE LEDGER (greenhouse evidence/0561, Rod: «todo sincronizado»).
 *
 * Every figure a surface shows must come from the agent's own facts, by the rules the docblock of the
 * fold states — and the state word must follow the session through its life: idle, working, waiting,
 * paused, ended.
 */
final class LedgerSessionTest extends TestCase
{
    /** @param list<array{0: string, 1: array<string, mixed>}> $events */
    private static function rows(string $sid, array $events, int $from = 1): array
    {
        $out = [];
        foreach ($events as $i => [$type, $payload]) {
            $out[] = ['stream_id' => 'agent-session:' . $sid, 'type' => $type, 'payload' => $payload, 'seq' => $from + $i];
        }

        return $out;
    }

    public function testItFoldsGoalModeCountersTokensWorkAndActivityFromTheFacts(): void
    {
        $record = LedgerSession::fold('desk-1', self::rows('desk-1', [
            ['session.started', ['goal' => 'run the rollout sequence', 'mode' => 'ask', 'by' => ['id' => 'actor:passkey:YkS3', 'verified' => true]]],
            ['session.turn', ['role' => 'user', 'content' => 'run the rollout sequence']],
            ['session.model_called', []],
            ['session.model_returned', ['usage' => ['prompt_tokens' => 900, 'completion_tokens' => 100, 'total_tokens' => 1000]]],
            ['session.tool_called', ['tool' => 'house_context', 'ok' => true, 'result' => '{}']],
            ['session.todo_changed', ['id' => 't1', 'text' => 'List the plugins', 'status' => 'pending', 'origin' => 'planned']],
            ['session.model_called', []],
            ['session.model_returned', ['usage' => ['prompt_tokens' => 1500, 'total_tokens' => 1700]]],
            ['session.todo_changed', ['id' => 't1', 'text' => 'List the plugins', 'status' => 'done']],
            ['session.mode_changed', ['mode' => 'auto']],
            ['session.goal_changed', ['goal' => 'roll it out']],
            ['session.turn', ['role' => 'assistant', 'content' => 'Done.']],
        ]));

        self::assertSame('desk-1', $record['id']);
        self::assertSame('roll it out', $record['goal'], 'the last goal_changed wins');
        self::assertSame('auto', $record['mode'], 'the last mode_changed wins');
        self::assertSame('actor:passkey:YkS3', $record['started_by']);
        self::assertSame(1, $record['turns'], 'user turns only');
        self::assertSame(2, $record['steps'], 'one per model call');
        self::assertSame(2700, $record['tokens'], 'Σ total_tokens');
        self::assertSame(1500, $record['context_tokens'], 'the LAST prompt');
        self::assertSame(1, $record['tool_calls']);
        self::assertSame([['title' => 'List the plugins', 'status' => 'done', 'origin' => 'planned']], $record['work'], 'the last change per todo, origin kept from birth');
        self::assertSame('idle', $record['state'], 'answered: idle');
        self::assertCount(12, $record['activity']);
        self::assertSame('session.tool_called', $record['activity'][4]['type']);
        self::assertSame(5, $record['activity'][4]['seq']);
        self::assertStringContainsString('house_context', $record['activity'][4]['data']);
    }

    public function testTheStateFollowsTheSessionThroughItsLife(): void
    {
        $started = [['session.started', ['goal' => 'g', 'mode' => 'ask']]];
        $asked = [...$started, ['session.turn', ['role' => 'user', 'content' => 'go']]];
        self::assertSame('idle', LedgerSession::fold('s', self::rows('s', $started))['state']);
        self::assertSame('working', LedgerSession::fold('s', self::rows('s', $asked))['state'], 'a user turn without an answer yet');
        self::assertSame('idle', LedgerSession::fold('s', self::rows('s', [...$asked, ['session.turn', ['role' => 'assistant', 'content' => 'ok']]]))['state']);
        self::assertSame('waiting', LedgerSession::fold('s', self::rows('s', [...$asked, ['session.question_asked', ['id' => 'perm:x', 'question' => 'May I?']]]))['state']);
        self::assertSame('idle', LedgerSession::fold('s', self::rows('s', [...$asked, ['session.question_asked', ['id' => 'perm:x', 'question' => 'May I?']], ['session.question_answered', ['id' => 'perm:x', 'answer' => 'sí']], ['session.turn', ['role' => 'assistant', 'content' => 'ok']]]))['state']);
        self::assertSame('paused', LedgerSession::fold('s', self::rows('s', [...$started, ['session.sequence_paused', ['sequenceId' => 'deploy', 'nextIndex' => 1]]]))['state']);
        self::assertSame('idle', LedgerSession::fold('s', self::rows('s', [...$started, ['session.sequence_paused', ['sequenceId' => 'deploy']], ['session.sequence_resumed', ['sequenceId' => 'deploy']]]))['state']);
        self::assertSame('ended', LedgerSession::fold('s', self::rows('s', [...$asked, ['session.ended', ['because' => 'done']]]))['state'], 'ended outranks everything');
    }

    /** THE CONTROL for the counters: a provider that spoke no usage leaves zero, not an invention. */
    public function testSilentUsageIsZeroAndAnEmptyStreamIsAnEmptyRecord(): void
    {
        $quiet = LedgerSession::fold('q', self::rows('q', [['session.started', ['goal' => 'g']], ['session.model_called', []], ['session.model_returned', ['model' => 'x']]]));
        self::assertSame(0, $quiet['tokens']);
        self::assertSame(0, $quiet['context_tokens']);
        self::assertSame(1, $quiet['steps']);

        $empty = LedgerSession::fold('e', []);
        self::assertSame('', $empty['goal']);
        self::assertSame('idle', $empty['state']);
        self::assertSame([], $empty['work']);
    }

    public function testAllReadsEveryStreamOfTheFileOnceAndIgnoresWhatIsNotASession(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ledger');
        $rows = [
            ['stream_id' => 'agent-session:desk-b', 'type' => 'session.started', 'payload' => ['goal' => 'second'], 'seq' => 3],
            ['stream_id' => 'agent-session:desk-a', 'type' => 'session.started', 'payload' => ['goal' => 'first'], 'seq' => 1],
            ['stream_id' => 'something-else:x', 'type' => 'other', 'payload' => [], 'seq' => 2],
            ['stream_id' => 'agent-session:desk-a', 'type' => 'session.turn', 'payload' => ['role' => 'user', 'content' => 'hi'], 'seq' => 4],
        ];
        file_put_contents($file, implode("\n", array_map(static fn (array $r): string => json_encode($r, \JSON_THROW_ON_ERROR), $rows)) . "\nnot json\n");

        $all = LedgerSession::all($file);
        self::assertSame(['desk-b', 'desk-a'], array_keys($all), 'in order of first appearance, sessions only');
        self::assertSame('first', $all['desk-a']['goal']);
        self::assertSame('working', $all['desk-a']['state']);
        self::assertSame([], LedgerSession::all($file . '.missing'));

        unlink($file);
    }
}
