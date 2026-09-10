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
     * The two answers a parked question takes — `sí` and `no` are what `agent:answer` reads, whatever the label says.
     *
     * @param array<string, string> $copy
     */
    private function answers(array $copy, bool $hidden = false): string
    {
        $h = $hidden ? ' hidden' : '';

        return '<button type="button" class="mui-btn mui-btn--sm decision-card__option" data-agent-answer="sí"' . $h . '>' . $this->esc($copy['approve']) . '</button>'
            . '<button type="button" class="mui-btn mui-btn--sm decision-card__option" data-agent-answer="no"' . $h . '>' . $this->esc($copy['deny']) . '</button>';
    }

    /** The English a caller with no catalog reads. */
    private const array COPY = ['run' => 'Run', 'approve' => 'Approve', 'deny' => 'Deny', 'paused_on' => 'paused on %s — answer below'];

    private function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES);
    }
}
