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

namespace Milpa\AgentWorkspace\Live;

/**
 * Renders the decisions inbox — the questions agents parked, across all sessions (greenhouse decisions/0195).
 *
 * The live gate lives in the conversation of its own session; this is the cross-session backlog, so a human
 * sees every durable question waiting for them wherever it was raised. Each card links to its session. Pure,
 * so it is tested directly with fixtures.
 */
final class DecisionsInboxView
{
    /**
     * The inbox as HTML: one card per parked question, plus the empty line when none is waiting.
     *
     * The empty line's words are the CALLER's, so the screen that renders this view says them in the
     * declared locale (greenhouse decisions/0138, 0211 phase D4). The default is the English the view
     * used to hardcode, so a caller with no catalog still reads a sentence.
     *
     * An AGENT's card answers here too (greenhouse decisions/0223, F4): its two buttons post the answer to
     * `agent:answer`, the same operation a terminal calls — and when the session is parked on a SEQUENCE
     * the card names it, so an approval can resume the run without leaving the page.
     *
     * @param list<array{session: string, goal: string, question: string, operation: string, reason: string, sequence?: string}> $pending
     * @param array<string, string>                                                                                              $copy    the caller's words for the answers, by key
     * @param list<array{graph: string, instance: string, question: string, options: list<string>, requester: string}>           $graphs
     *                                                                                                                                    Decisions DECLARED GRAPHS are waiting on. They render differently on purpose: an agent's parked
     *                                                                                                                                    question is answered in the conversation of its own session, so its card is a link there; a graph's
     *                                                                                                                                    is answered HERE, so its card carries its options as buttons — and those options are the cases of
     *                                                                                                                                    the enum its routes were declared with, which is why the buttons cannot drift from the machine.
     */
    public function html(
        array $pending,
        string $empty = 'No decisions to make. When an agent parks a gate, it appears here for you to approve or refuse.',
        array $graphs = [],
        string $principal = '',
        array $copy = [],
    ): string {
        $copy += self::COPY;
        $cards = '';

        foreach ($graphs as $g) {
            $options = '';
            foreach ($g['options'] as $option) {
                $options .= '<button type="button" class="mui-btn mui-btn--sm decision-card__option"'
                    . ' data-graph-decide="' . $this->esc($option) . '">' . $this->esc($option) . '</button>';
            }

            $cards .= '<li class="decision-card decision-card--graph" data-graph="' . $this->esc($g['graph']) . '"'
                . ' data-graph-instance="' . $this->esc($g['instance']) . '"'
                . ' data-graph-principal="' . $this->esc($principal) . '">'
                . '<p class="decision-card__goal">' . $this->esc($g['graph']) . '</p>'
                . '<p class="decision-card__q">' . $this->esc($g['question']) . '</p>'
                . ($g['requester'] !== '' ? '<p class="decision-card__facts">started by <strong>' . $this->esc($g['requester']) . '</strong></p>' : '')
                . '<p class="decision-card__options">' . $options . '</p>'
                . '</li>';
        }

        foreach ($pending as $d) {
            $goal = $this->esc($d['goal']);
            $facts = [];
            if ($d['operation'] !== '') {
                $facts[] = 'operation <strong>' . $this->esc($d['operation']) . '</strong>';
            }
            if ($d['reason'] !== '') {
                $facts[] = 'reason ' . $this->esc($d['reason']);
            }
            $factLine = $facts === [] ? '' : '<p class="decision-card__facts">' . implode(' · ', $facts) . '</p>';

            $cards .= '<li class="decision-card" data-decision-session="' . $this->esc($d['session']) . '"'
                . ' data-decision-sequence="' . $this->esc((string) ($d['sequence'] ?? '')) . '">'
                . ($goal !== '' ? '<p class="decision-card__goal">' . $goal . '</p>' : '')
                . '<p class="decision-card__q">' . $this->esc($d['question']) . '</p>'
                . $factLine
                . '<p class="decision-card__facts" data-decision-status></p>'
                . '<p class="decision-card__options">' . $this->answers($copy) . '</p>'
                . '<a class="mui-btn mui-btn--sm decision-card__open" href="?session=' . rawurlencode($d['session']) . '">Open session</a>'
                . '</li>';
        }

        // The list is ALWAYS rendered, even with nothing parked, and the empty line sits NEXT to it
        // (greenhouse decisions/0211, phase D4): a question parked while the page is open has a list to
        // land in, and the empty line steps aside by CSS the moment a card does — so the live inbox never
        // has to build an `<ol>` out of a JavaScript string.
        return '<ol class="mui-replay__stream" id="milpa-decisions-list" aria-live="polite">' . $cards . '</ol>'
            . ($pending === []
                ? '<div class="mui-empty" id="milpa-decisions-empty"><p class="mui-empty__desc">' . $this->esc($empty) . '</p></div>'
                : '');
    }

    /**
     * The sequences this app declared, each a card that RUNS it from here and answers its pause in place
     * (greenhouse decisions/0223, F4). The list is always rendered and the empty line sits next to it, the
     * way the inbox does. The answer buttons are printed on every card and hidden until a run parks — the
     * module toggles them, it never invents them.
     *
     * @param list<array{name: string, steps: list<string>, session: string, paused: bool, pending_operation: string}> $sequences
     * @param array<string, string>                                                                                    $copy      the caller's words, by key
     */
    public function sequencesHtml(array $sequences, string $empty = 'This app declares no sequences.', array $copy = []): string
    {
        $copy += self::COPY;
        $cards = '';

        foreach ($sequences as $seq) {
            $open = $seq['pending_operation'] !== '';
            $status = $seq['paused']
                ? sprintf($copy['paused_on'], $seq['pending_operation'] !== '' ? $seq['pending_operation'] : '?')
                : '';

            $cards .= '<li class="decision-card sequence-card" data-sequence="' . $this->esc($seq['name']) . '"'
                . ' data-sequence-session="' . $this->esc($seq['session']) . '"'
                . ($seq['paused'] ? ' data-sequence-paused' : '') . '>'
                . '<p class="decision-card__goal">' . $this->esc($seq['name']) . '</p>'
                . '<p class="decision-card__q">' . $this->esc(implode(' → ', $seq['steps'])) . '</p>'
                . '<p class="decision-card__facts" data-sequence-status>' . $this->esc($status) . '</p>'
                . '<p class="decision-card__options">'
                . '<button type="button" class="mui-btn mui-btn--sm mui-btn--primary" data-sequence-run>' . $this->esc($copy['run']) . '</button>'
                . $this->answers($copy, !$open)
                . '</p>'
                . '</li>';
        }

        return '<ol class="mui-replay__stream" id="milpa-sequences-list">' . $cards . '</ol>'
            . ($sequences === [] ? '<div class="mui-empty" id="milpa-sequences-empty"><p class="mui-empty__desc">' . $this->esc($empty) . '</p></div>' : '');
    }

    /**
     * The frontier of the seats the reader enrolled (greenhouse decisions/0493): one card per open refusal —
     * which seat, which call, which plugin, which scope it lacks — with the action to GRANT that scope, and a
     * link to the seat's session. A refusal asks nothing (decisions/0317); this is where the human who
     * answers for the seat decides it. The button carries the refused call's session and sequence number and
     * never a scope: the house re-derives what is missing from the recorded call when it grants.
     *
     * Each card also says what granting OPENS (decisions/0510): the refused call as it was recorded, and
     * whether the plugin is new to the house or existing work. A grant over an existing plugin is an informed
     * act, never one touch: its card shows a box the reader ticks knowingly and a button that names the
     * plugin, and the grant carries that name for the passkey to approve. A runtime older than 0510 sends
     * none of these facts, and the card reads as it did.
     *
     * @param list<array{session: string, goal: string, seat: string, refusals: list<array{seq: int, tool: string, plugin: ?string, permission: string, call?: array<string, mixed>, target?: ?string, named?: bool, consent?: string}>}> $frontier
     * @param array<string, string>                                                                                                                                                                                                       $copy     the caller's words, by key
     */
    public function frontierHtml(array $frontier, string $empty = self::FRONTIER_COPY['empty'], array $copy = []): string
    {
        $copy += self::FRONTIER_COPY;
        $cards = '';
        foreach ($frontier as $seat) {
            foreach ($seat['refusals'] as $refusal) {
                $plugin = $refusal['plugin'];
                $facts = sprintf($copy['refused'], $seat['seat'], $refusal['tool'])
                    . ($plugin !== null ? ' · ' . sprintf($copy['plugin'], $plugin) : '');
                $informed = ($refusal['consent'] ?? 'touch') === 'informed' && $plugin !== null;
                $opens = match ($refusal['target'] ?? null) {
                    'new' => sprintf($copy['opens_new'], (string) $plugin),
                    'existing' => sprintf($copy['opens_existing'], (string) $plugin)
                        . (($refusal['named'] ?? true) ? '' : ' ' . sprintf($copy['unnamed'], (string) $plugin)),
                    default => '',
                };
                $call = isset($refusal['call']) ? $this->shownCall($refusal['tool'], $refusal['call']) : '';
                $cards .= '<li class="decision-card decision-card--frontier' . ($informed ? ' decision-card--informed' : '') . '"'
                    . ' data-seat-session="' . $this->esc($seat['session']) . '"'
                    . ' data-seat-seq="' . $refusal['seq'] . '" data-seat-permission="' . $this->esc($refusal['permission']) . '"'
                    . ($informed ? ' data-seat-existing="' . $this->esc((string) $plugin) . '"' : '') . '>'
                    . ($seat['goal'] !== '' ? '<p class="decision-card__goal">' . $this->esc($seat['goal']) . '</p>' : '')
                    . '<p class="decision-card__q">' . $this->esc(sprintf($copy['lacks'], $refusal['permission'])) . '</p>'
                    . '<p class="decision-card__facts">' . $this->esc($facts) . '</p>'
                    . ($call !== '' ? '<p class="decision-card__facts" data-seat-call>' . $this->esc($copy['call']) . ' <code>' . $this->esc($call) . '</code></p>' : '')
                    . ($opens !== '' ? '<p class="decision-card__facts" data-seat-opens>' . $this->esc($opens) . '</p>' : '')
                    . ($informed
                        ? '<p class="decision-card__facts"><label><input type="checkbox" data-seat-ack> ' . $this->esc(sprintf($copy['ack'], (string) $plugin)) . '</label></p>'
                        : '')
                    . '<p class="decision-card__facts" data-seat-status></p>'
                    . '<p class="decision-card__options">'
                    . ($informed
                        ? '<button type="button" class="mui-btn mui-btn--sm mui-btn--danger" data-seat-grant>' . $this->esc(sprintf($copy['grant_existing'], (string) $plugin)) . '</button>'
                        : '<button type="button" class="mui-btn mui-btn--sm mui-btn--primary" data-seat-grant>' . $this->esc(sprintf($copy['grant'], $refusal['permission'])) . '</button>')
                    . '<a class="mui-btn mui-btn--sm decision-card__open" href="?session=' . rawurlencode($seat['session']) . '">' . $this->esc($copy['open']) . '</a>'
                    . '</p>'
                    . '</li>';
            }
        }

        return '<ol class="mui-replay__stream" id="milpa-frontier-list">' . $cards . '</ol>'
            . ($cards === '' ? '<div class="mui-empty" id="milpa-frontier-empty"><p class="mui-empty__desc">' . $this->esc($empty) . '</p></div>' : '');
    }

    /**
     * A recorded call as a person reads it — `tool name=value …`, an empty or spaced text quoted — shown, never
     * interpreted: what the call would do is its handler's to say, not its spelling's (decisions/0510).
     *
     * @param array<string, mixed> $arguments
     */
    private function shownCall(string $tool, array $arguments): string
    {
        $parts = [$tool];
        foreach ($arguments as $name => $value) {
            $text = match (true) {
                \is_bool($value) => $value ? 'true' : 'false',
                $value === null => 'null',
                \is_scalar($value) => (string) $value,
                default => (string) json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            };
            $parts[] = $name . '=' . (\is_string($value) && ($value === '' || preg_match('/\s/u', $value) === 1) ? '"' . $text . '"' : $text);
        }

        return implode(' ', $parts);
    }

    /**
     * The seats the reader answers for (greenhouse decisions/0499) — key, name, scopes and who enrolled it, so the
     * person who gave a seat can compare the key with the resident's own — and the form that gives the resident
     * one: a name, and a button that asks the passkey for THIS act. What the seat may do is the house's to
     * declare; the form carries no scope. The command the resident's key runs appears under it, once.
     *
     * @param list<array{fingerprint: string, label: string|null, scopes: list<string>, authorized_by: string}> $seats
     * @param array<string, string>                                                                             $copy  the caller's words, by key
     */
    public function seatsHtml(array $seats, array $copy = []): string
    {
        $copy += self::SEATS_COPY;
        $rows = '';
        foreach ($seats as $seat) {
            $rows .= '<li class="decision-card decision-card--seat" data-seat-key="' . $this->esc($seat['fingerprint']) . '">'
                . '<p class="decision-card__goal">' . $this->esc($seat['label'] ?? $seat['fingerprint']) . '</p>'
                . '<p class="decision-card__q"><code>' . $this->esc($seat['fingerprint']) . '</code></p>'
                . '<p class="decision-card__facts">' . $this->esc(implode(' ', $seat['scopes'])) . '</p>'
                . '<p class="decision-card__facts">' . $this->esc(sprintf($copy['enrolled_by'], $seat['authorized_by'])) . '</p>'
                . '</li>';
        }

        return '<ol class="mui-replay__stream" id="milpa-seats-list">' . $rows . '</ol>'
            . ($rows === '' ? '<div class="mui-empty" id="milpa-seats-empty"><p class="mui-empty__desc">' . $this->esc($copy['empty']) . '</p></div>' : '')
            . '<div class="decision-card decision-card--give-seat" data-seat-give-form>'
            . '<p class="decision-card__options">'
            . '<label>' . $this->esc($copy['label']) . ' <input type="text" class="mui-input" value="resident" data-seat-label></label> '
            . '<button type="button" class="mui-btn mui-btn--sm mui-btn--primary" data-seat-give>' . $this->esc($copy['give']) . '</button>'
            . '</p>'
            . '<p class="decision-card__facts" data-seat-give-status></p>'
            . '<pre class="decision-card__command" data-seat-command hidden></pre>'
            . '</div>';
    }

    /**
     * The two answers a parked question takes — `yes` and `no` are what `agent:answer` reads, whatever the label says.
     * The wire speaks the question's language (greenhouse decisions/0518); the label is the catalog's.
     *
     * @param array<string, string> $copy
     */
    private function answers(array $copy, bool $hidden = false): string
    {
        $h = $hidden ? ' hidden' : '';

        return '<button type="button" class="mui-btn mui-btn--sm decision-card__option" data-agent-answer="yes"' . $h . '>' . $this->esc($copy['approve']) . '</button>'
            . '<button type="button" class="mui-btn mui-btn--sm decision-card__option" data-agent-answer="no"' . $h . '>' . $this->esc($copy['deny']) . '</button>';
    }

    /** The English «Your seats» reads when the caller hands no catalog. */
    private const array SEATS_COPY = [
        'empty' => 'You answer for no seat yet.',
        'enrolled_by' => 'enrolled by %s',
        'label' => 'Seat name',
        'give' => 'Give the resident a seat',
    ];

    /** The English the frontier cards read when the caller hands no catalog. */
    private const array FRONTIER_COPY = [
        'empty' => 'None of the seats you enrolled is waiting on a scope.',
        'refused' => '%s was refused %s',
        'plugin' => 'plugin %s',
        'lacks' => 'It lacks %s',
        'grant' => 'Grant %s',
        'open' => 'Open session',
        'call' => 'The refused call:',
        'opens_new' => 'Granting lets it create plugin %s — the house does not have it yet.',
        'opens_existing' => 'Granting opens write over the existing plugin %s — all of its work, not only this call.',
        'unnamed' => 'The task does not name %s.',
        'ack' => 'I read the call: grant write over all of %s',
        'grant_existing' => 'Grant write over existing %s',
    ];

    /** The English a caller with no catalog reads. */
    private const array COPY = ['run' => 'Run', 'approve' => 'Approve', 'deny' => 'Deny', 'paused_on' => 'paused on %s — answer below'];

    private function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES);
    }
}
