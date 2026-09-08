<?php

/**
 * This file is part of milpa/desktop-app — a Milpa app hosts itself as a desktop app.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/desktop-app
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Data;

/**
 * ONE agent session, folded from the ledger the agent writes — the truth every surface reads.
 *
 * The Desktop used to paint a session from a file of its own (`.milpa/sessions/<id>.json`) that nothing
 * ever updated past creation: zeros beside a thread that had run for an hour, «No session open» over a
 * sequence the human had just answered (greenhouse evidence/0561, Rod: «todo sincronizado»). The ledger
 * `var/agent-sessions.jsonl` already holds everything a header, a counter, a work board and an activity
 * stream need; this folds one stream of it into one record, by the format the event store pins (one JSON
 * line per event: `stream_id`, `type`, `payload`, `seq`). It depends on no package: the file is the contract.
 *
 * What the fold answers, and from which facts:
 *   goal · `session.started`, then `session.goal_changed`      mode · `session.started`, then `session.mode_changed`
 *   turns · user turns                                          steps · model calls
 *   tokens · Σ `model_returned.usage.total_tokens`              context_tokens · the LAST call's `prompt_tokens`
 *   tool_calls · `session.tool_called`                          work · the last `todo_changed` per todo
 *   activity · every event, in order                            started_by · the opening event's principal
 *   state · ended › waiting (a question is open) › paused (a sequence is parked) › working (a user turn
 *           without an answer yet) › idle
 */
final class LedgerSession
{
    public const string PREFIX = 'agent-session:';

    /**
     * Every session the ledger holds, folded, keyed by id — in the order they first appeared.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(string $file): array
    {
        if ($file === '' || !is_file($file)) {
            return [];
        }
        /** @var array<string, list<array<string, mixed>>> $streams */
        $streams = [];
        foreach (file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $event = json_decode($line, true);
            if (!\is_array($event) || !\is_string($event['stream_id'] ?? null) || !str_starts_with($event['stream_id'], self::PREFIX)) {
                continue;
            }
            $streams[substr($event['stream_id'], \strlen(self::PREFIX))][] = $event;
        }

        $out = [];
        foreach ($streams as $id => $rows) {
            usort($rows, static fn (array $a, array $b): int => (int) ($a['seq'] ?? 0) <=> (int) ($b['seq'] ?? 0));
            $out[(string) $id] = self::fold((string) $id, $rows);
        }

        return $out;
    }

    /**
     * One session's record from its events, in order.
     *
     * @param list<array<string, mixed>> $rows the stream's events, sorted by `seq`
     *
     * @return array<string, mixed>
     */
    public static function fold(string $id, array $rows): array
    {
        $goal = '';
        $mode = 'ask';
        $startedBy = null;
        $turns = 0;
        $steps = 0;
        $tokens = 0;
        $contextTokens = 0;
        $toolCalls = 0;
        $lastUser = 0;
        $lastAnswer = 0;
        $question = null;
        $sequence = null;
        $ended = false;
        /** @var array<string, array{title: string, status: string, origin: string}> $todos */
        $todos = [];
        $activity = [];

        foreach ($rows as $event) {
            $type = (string) ($event['type'] ?? '');
            $seq = (int) ($event['seq'] ?? 0);
            /** @var array<string, mixed> $p */
            $p = \is_array($event['payload'] ?? null) ? $event['payload'] : [];
            switch ($type) {
                case 'session.started':
                    $goal = self::str($p['goal'] ?? null);
                    $mode = self::str($p['mode'] ?? null) ?: 'ask';
                    $startedBy = \is_array($p['by'] ?? null) ? (self::str($p['by']['id'] ?? null) ?: null) : null;
                    break;
                case 'session.goal_changed':
                    $goal = self::str($p['goal'] ?? null) ?: $goal;
                    break;
                case 'session.mode_changed':
                    $mode = self::str($p['mode'] ?? null) ?: $mode;
                    break;
                case 'session.turn':
                    if (($p['role'] ?? '') === 'assistant') {
                        $lastAnswer = $seq;
                    } else {
                        ++$turns;
                        $lastUser = $seq;
                    }
                    break;
                case 'session.model_called':
                    ++$steps;
                    break;
                case 'session.model_returned':
                    $usage = \is_array($p['usage'] ?? null) ? $p['usage'] : [];
                    $tokens += (int) ($usage['total_tokens'] ?? 0);
                    if (isset($usage['prompt_tokens'])) {
                        $contextTokens = (int) $usage['prompt_tokens'];
                    }
                    break;
                case 'session.tool_called':
                    ++$toolCalls;
                    break;
                case 'session.todo_changed':
                    $todoId = self::str($p['id'] ?? null);
                    if ($todoId !== '') {
                        $todos[$todoId] = [
                            'title' => self::str($p['text'] ?? null) ?: '(untitled)',
                            'status' => self::str($p['status'] ?? null) ?: 'pending',
                            'origin' => self::str($p['origin'] ?? null) ?: ($todos[$todoId]['origin'] ?? 'planned'),
                        ];
                    }
                    break;
                case 'session.question_asked':
                    $question = self::str($p['question'] ?? null) ?: '?';
                    break;
                case 'session.question_answered':
                case 'session.answer_window_closed':
                    $question = null;
                    break;
                case 'session.sequence_paused':
                    $sequence = self::str($p['sequenceId'] ?? null) ?: '?';
                    break;
                case 'session.sequence_resumed':
                    $sequence = null;
                    break;
                case 'session.ended':
                    $ended = true;
                    break;
                default:
                    break;
            }
            $activity[] = ['seq' => $seq, 'type' => $type, 'data' => self::brief($p)];
        }

        $state = $ended ? 'ended'
            : ($question !== null ? 'waiting'
            : ($sequence !== null ? 'paused'
            : ($lastUser > $lastAnswer ? 'working' : 'idle')));

        return [
            'id' => $id,
            'goal' => $goal,
            'mode' => $mode,
            'state' => $state,
            'turns' => $turns,
            'steps' => $steps,
            'tokens' => $tokens,
            'context_tokens' => $contextTokens,
            'tool_calls' => $toolCalls,
            'work' => array_values($todos),
            'activity' => $activity,
            'started_by' => $startedBy,
            'question' => $question,
            'sequence' => $sequence,
            'ended' => $ended,
        ];
    }

    /**
     * A payload as one short line of data — enough to read the fact, never the whole result.
     *
     * @param array<string, mixed> $payload
     */
    private static function brief(array $payload): string
    {
        $json = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '{}';

        return mb_strlen($json) > 160 ? mb_substr($json, 0, 157) . '…' : $json;
    }

    private static function str(mixed $value): string
    {
        return \is_string($value) ? trim($value) : (\is_int($value) || \is_float($value) ? (string) $value : '');
    }
}
