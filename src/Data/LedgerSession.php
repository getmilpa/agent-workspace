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
 *   closure · the last `session.closure_derived`, until a later user turn reopens the work
 *   window · the last `session.window_composed` — the context window the run obeyed, or null
 *   state · ended › waiting (a question is open) › paused (a sequence is parked) › working (a user turn
 *           without a later answer or run termination) › idle
 *
 * A turn in the HOUSE's voice (`[house] …`, the grant notice of greenhouse decisions/0495) is kept in the activity and
 * the thread, but it is not the human's: it opens no work, counts as no turn and reopens no verdict (decisions/0513 §2).
 */
final class LedgerSession
{
    public const string PREFIX = 'agent-session:';

    /**
     * How the house's own turns begin — the same test `Milpa\AppRuntime\Agent\SeatFrontier::NOTICE_PREFIX` makes, named
     * here as a string because this package reads the ledger by its format and requires no runtime.
     */
    public const string HOUSE_VOICE = '[house] ';

    /** The event a leg records with the context window it obeys (greenhouse decisions/0513 §5). */
    public const string WINDOW_COMPOSED = 'session.window_composed';

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
        $lastRunEnd = 0;
        $question = null;
        $sequence = null;
        $ended = false;
        /** @var array<string, array{title: string, status: string, origin: string}> $todos */
        $todos = [];
        $activity = [];
        /** @var array{verified: bool, reasons: list<string>, scope: string, seq: int}|null $closure */
        $closure = null;
        $window = null;

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
                    } elseif (\is_string($p['content'] ?? null) && str_starts_with($p['content'], self::HOUSE_VOICE)) {
                        // The house told the session a fact; nobody asked it to work (greenhouse decisions/0513 §2).
                    } else {
                        ++$turns;
                        $lastUser = $seq;
                        // A new request reopens the work: the verdict answered the one before it.
                        $closure = null;
                    }
                    break;
                case 'session.closure_derived':
                    // THE HOUSE'S VERDICT on the leg that just ended (greenhouse decisions/0509 §7): whether it
                    // verified the work, on what, and why not. The todos are the session's claim; this is the
                    // house's — a board of DONE cards next to a verdict nobody shows says «done» twice.
                    $closure = [
                        'verified' => ($p['verified'] ?? null) === true,
                        'reasons' => array_values(array_filter(
                            \is_array($p['reasons'] ?? null) ? $p['reasons'] : [],
                            static fn (mixed $reason): bool => \is_string($reason) && $reason !== '',
                        )),
                        'scope' => self::str($p['scope'] ?? null),
                        'seq' => $seq,
                    ];
                    break;
                case 'session.model_called':
                    ++$steps;
                    break;
                case self::WINDOW_COMPOSED:
                    $window = \is_int($p['tokens'] ?? null) && $p['tokens'] > 0 ? $p['tokens'] : $window;
                    break;
                case 'session.run_terminated':
                    // The invocation returned even when no answer was produced. Its reason does not
                    // decide success; a later user turn remains active (greenhouse 0396/0714).
                    $lastRunEnd = $seq;
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
            : ($lastUser > max($lastAnswer, $lastRunEnd) ? 'working' : 'idle')));

        return [
            'id' => $id,
            'goal' => $goal,
            'mode' => $mode,
            'state' => $state,
            'turns' => $turns,
            'steps' => $steps,
            'tokens' => $tokens,
            'context_tokens' => $contextTokens,
            'window' => $window,
            'tool_calls' => $toolCalls,
            'work' => array_values($todos),
            'closure' => $closure,
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
