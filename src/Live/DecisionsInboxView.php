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
     * @param list<array<string, mixed>>                                                                                         $graphs
     *                                                                                                                                    Decisions DECLARED GRAPHS are waiting on. They render differently on purpose: an agent's parked
     *                                                                                                                                    question is answered in the conversation of its own session, so its card is a link there; a graph's
     *                                                                                                                                    is answered HERE, so its card carries its options as buttons — and those options are the cases of
     *                                                                                                                                    the enum its routes were declared with, which is why the buttons cannot drift from the machine.
     *                                                                                                                                    It carries no approver: `graph:decide` reads who answers from the passkey session (decisions/0528).
     */
    public function html(
        array $pending,
        string $empty = 'No decisions to make. When an agent parks a gate, it appears here for you to approve or refuse.',
        array $graphs = [],
        array $copy = [],
    ): string {
        $copy += self::COPY;
        $cards = '';

        foreach ($graphs as $g) {
            [$options, $reasons] = $this->graphOptions($g, $copy);

            $cards .= '<li class="decision-card decision-card--graph" data-graph="' . $this->esc($g['graph']) . '"'
                . ' data-graph-instance="' . $this->esc($g['instance']) . '">'
                . '<p class="decision-card__goal">' . $this->esc($g['graph']) . '</p>'
                . '<p class="decision-card__q">' . $this->esc($g['question']) . '</p>'
                . ($g['requester'] !== '' ? '<p class="decision-card__facts">started by <strong>' . $this->esc($g['requester']) . '</strong></p>' : '')
                . '<p class="decision-card__options">' . $options . '</p>'
                . $reasons
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
        // ONE REGION (greenhouse decisions/0563): a card here carries the session it answers and its two
        // buttons, which a pushed fact does not — so the page re-reads this from the house, list and empty line.
        return LiveRegion::of(LiveRegion::DECISIONS_PENDING, '<ol class="mui-replay__stream" id="milpa-decisions-list" aria-live="polite">' . $cards . '</ol>'
            . ($pending === []
                ? '<div class="mui-empty" id="milpa-decisions-empty"><p class="mui-empty__desc">' . $this->esc($empty) . '</p></div>'
                : ''));
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
     * A seat's call to a verb of a capability BUILT in the house is another kind of card (decisions/0590): the
     * house lists those apart, under `admissions`, each with the contract its scope opens ({@see admissionCard()}).
     *
     * @param list<array{session: string, goal: string, seat: string, refusals: list<array{seq: int, tool: string, plugin: ?string, permission: string, call?: array<string, mixed>, target?: ?string, named?: bool, consent?: string}>, admissions?: list<array<string, mixed>>}> $frontier
     * @param array<string, string>                                                                                                                                                                                                                                                $copy     the caller's words, by key
     */
    public function frontierHtml(array $frontier, string $empty = self::FRONTIER_COPY['empty'], array $copy = []): string
    {
        $copy += self::FRONTIER_COPY;
        $cards = '';
        // ONE DECISION, ONE CARD (greenhouse evidence/1139). A seat refused the same scope in ten sessions is one
        // thing for its person to decide, and admitting from any of them clears them all: the card is the LATEST
        // refusal of that seat for that contract, and it says how many sessions asked.
        $latest = [];
        $asked = [];
        foreach ($frontier as $index => $seat) {
            foreach ($seat['admissions'] ?? [] as $admission) {
                $key = $seat['seat'] . "\0" . (string) ($admission['contract'] ?? '') . "\0" . (string) ($admission['permission'] ?? '');
                $latest[$key] = $index . ':' . (int) $admission['seq'];
                $asked[$key][$seat['session']] = true;
            }
        }
        foreach ($frontier as $index => $seat) {
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
            // A BUILT VERB WAITING FOR A PERSON'S ADMISSION (greenhouse decisions/0590). The house lists these apart
            // from the refusals above — they are not a scope of authoring — and a runtime that does not know them
            // hands none.
            foreach ($seat['admissions'] ?? [] as $admission) {
                $key = $seat['seat'] . "\0" . (string) ($admission['contract'] ?? '') . "\0" . (string) ($admission['permission'] ?? '');
                if ($latest[$key] !== $index . ':' . (int) $admission['seq']) {
                    continue;
                }
                $sessions = \count($asked[$key]);
                $call = isset($admission['call']) ? $this->shownCall((string) $admission['tool'], $admission['call']) : '';
                $cards .= $this->admissionCard(
                    $admission,
                    $copy,
                    ' data-seat-session="' . $this->esc($seat['session']) . '" data-seat-seq="' . (int) $admission['seq'] . '"',
                    ($seat['goal'] !== '' ? '<p class="decision-card__goal">' . $this->esc($seat['goal']) . '</p>' : '')
                        . '<p class="decision-card__q">' . $this->esc(sprintf($copy['admit_q'], (string) $admission['permission'], (string) $admission['capability'])) . '</p>'
                        . '<p class="decision-card__facts">' . $this->esc(sprintf($copy['refused'], $seat['seat'], (string) $admission['tool'])) . '</p>'
                        . ($call !== '' ? '<p class="decision-card__facts" data-seat-call>' . $this->esc($copy['call']) . ' <code>' . $this->esc($call) . '</code></p>' : '')
                        . ($sessions > 1 ? '<p class="decision-card__facts" data-admit-asked>' . $this->esc(sprintf($copy['admit_asked'], $sessions)) . '</p>' : ''),
                    'data-seat-grant',
                    '<a class="mui-btn mui-btn--sm decision-card__open" href="?session=' . rawurlencode($seat['session']) . '">' . $this->esc($copy['open']) . '</a>',
                );
            }
        }

        // ONE REGION (greenhouse decisions/0563): the frontier is derived from the refused calls, the ledger
        // and the policy — the page re-reads it from the house instead of deriving it again from a push.
        return LiveRegion::of(LiveRegion::DECISIONS_FRONTIER, '<ol class="mui-replay__stream" id="milpa-frontier-list">' . $cards . '</ol>'
            . ($cards === '' ? '<div class="mui-empty" id="milpa-frontier-empty"><p class="mui-empty__desc">' . $this->esc($empty) . '</p></div>' : ''));
    }

    /**
     * The card a person admits one scope of a built capability from (greenhouse decisions/0590, 0597).
     *
     * THE CARD JUDGES NOTHING. Which verbs the scope opens, what each declares, where its work keeps its state, how
     * a call of it would run in this house, whether it may be admitted at all — every one arrives judged by the
     * house, with `contract`: the digest of exactly what is shown here. That digest travels on the card and inside
     * what the passkey approves, so the reader approves what was read; it is never one touch — a box the reader
     * ticks knowingly comes first. A scope the house says is not admissible shows its contract and nothing to
     * approve.
     *
     * @param array<string, mixed>  $card  the house's row: `capability`, `permission`, `opens`, `contract`, `not_admissible`
     * @param array<string, string> $copy
     * @param string                $bound the attributes that say what the approval is bound to — a refused call, or a seat
     * @param string                $head  what stands above the contract: who asks, or for whom
     * @param string                $hook  the button's hook: `data-seat-grant` from a refusal, `data-seat-admit` without one
     * @param string                $more  what follows the button
     */
    private function admissionCard(array $card, array $copy, string $bound, string $head, string $hook, string $more = ''): string
    {
        $copy += self::FRONTIER_COPY;
        $permission = (string) ($card['permission'] ?? $card['scope'] ?? '');
        $capability = (string) ($card['capability'] ?? '');
        $contract = \is_string($card['contract'] ?? null) ? $card['contract'] : '';
        $blocked = \is_string($card['not_admissible'] ?? null) ? $card['not_admissible'] : null;
        $offered = $blocked === null && $contract !== '';

        return '<li class="decision-card decision-card--frontier decision-card--informed decision-card--admission"' . $bound
            . ($offered ? ' data-seat-admits="' . $this->esc($contract) . '"' : '') . ' data-seat-permission="' . $this->esc($permission) . '">'
            . $head
            . '<p class="decision-card__facts" data-admit-opens>' . $this->esc($copy['admit_opens']) . '</p>'
            . $this->contractHtml(\is_array($card['opens'] ?? null) ? $card['opens'] : [], $copy)
            . '<p class="decision-card__facts">' . $this->esc(sprintf($copy['not_opened'], $capability)) . ' ' . $this->esc($copy['house_limit']) . '</p>'
            . ($contract !== '' ? '<p class="decision-card__facts" data-admit-digest>' . $this->esc($copy['digest']) . ' <code title="' . $this->esc($contract) . '">' . $this->esc(substr($contract, 0, 19)) . '…</code></p>' : '')
            . ($blocked !== null ? '<p class="decision-card__facts" data-admit-blocked>' . $this->esc(sprintf($copy['blocked'], $blocked)) . '</p>' : '')
            . ($offered
                ? '<p class="decision-card__facts"><label><input type="checkbox" data-seat-ack> ' . $this->esc(sprintf($copy['admit_ack'], $permission, $capability)) . '</label></p>'
                : '')
            . '<p class="decision-card__facts" data-seat-status></p>'
            . '<p class="decision-card__options">'
            . ($offered ? '<button type="button" class="mui-btn mui-btn--sm mui-btn--danger" ' . $hook . '>' . $this->esc(sprintf($copy['admit'], $permission, $capability)) . '</button>' : '')
            . $more
            . '</p>'
            . '</li>';
    }

    /**
     * A scope's contract as a table: one verb per row — what it declares it does, its effects as declared, where
     * its work keeps its state and who said so, and how a call of it would run here. Under it, each verb's
     * description, labelled as the capability's own words: whoever built the capability wrote them, and that may
     * be the seat that asks.
     *
     * @param array<mixed>          $opens
     * @param array<string, string> $copy
     */
    private function contractHtml(array $opens, array $copy): string
    {
        $rows = '';
        $words = '';
        foreach ($opens as $verb) {
            if (!\is_array($verb) || !\is_string($verb['verb'] ?? null)) {
                continue;
            }
            $does = [($verb['mutating'] ?? false) === true ? $copy['does_writes'] : $copy['does_reads']];
            if (($verb['requiresConfirmation'] ?? false) === true) {
                $does[] = $copy['does_confirms'];
            }
            if (\is_string($verb['namedTarget'] ?? null) && $verb['namedTarget'] !== '') {
                $does[] = sprintf($copy['does_names'], $verb['namedTarget']);
            }
            $standing = match ($verb['standing'] ?? null) {
                'admitted' => $copy['standing_admitted'],
                'changed' => $copy['standing_changed'],
                'added' => $copy['standing_added'],
                default => '',
            };
            $effects = \is_array($verb['effects'] ?? null) ? $verb['effects'] : [];
            $declared = implode(' · ', array_filter(array_map(
                static fn (string $axis): string => \is_string($effects[$axis] ?? null) ? $effects[$axis] : '',
                ['mutation', 'externality', 'reversibility', 'authority'],
            )));
            $state = \is_array($verb['state'] ?? null) ? $verb['state'] : null;
            $paths = $state === null || !\is_array($state['paths'] ?? null) ? [] : array_filter($state['paths'], '\is_string');
            $where = $state === null
                ? $this->esc($copy['state_none'])
                : implode(' ', array_map(fn (string $path): string => '<code>' . $this->esc($path) . '</code>', $paths))
                    . ' ' . $this->esc(($state['source'] ?? null) === 'declared' ? $copy['state_declared'] : $copy['state_entities']);
            $runs = \is_array($verb['runs'] ?? null) ? $verb['runs'] : [];
            $why = \is_string($runs['why'] ?? null) ? $runs['why'] : '';
            $how = match ($runs['how'] ?? null) {
                'reads' => $copy['runs_reads'],
                'trial' => $copy['runs_trial'],
                'house' => $copy['runs_house'] . ' — ' . (($runs['pre_image'] ?? false) === true ? $copy['runs_pre'] : $copy['runs_nopre']),
                'asks' => sprintf($copy['runs_asks'], $why),
                'refused' => sprintf($copy['runs_refused'], $why),
                default => '',
            };
            $rows .= '<tr data-admit-verb="' . $this->esc($verb['verb']) . '">'
                . '<td><code>' . $this->esc($verb['verb']) . '</code>' . ($standing !== '' ? ' <em>' . $this->esc($standing) . '</em>' : '') . '</td>'
                . '<td>' . $this->esc(implode(' · ', $does)) . '</td>'
                . '<td>' . $this->esc($declared) . '</td>'
                . '<td>' . $where . '</td>'
                . '<td>' . $this->esc($how) . '</td>'
                . '</tr>';
            if (\is_string($verb['description'] ?? null) && $verb['description'] !== '') {
                $words .= '<li><code>' . $this->esc($verb['verb']) . '</code> — ' . $this->esc($verb['description']) . '</li>';
            }
        }
        if ($rows === '') {
            return '';
        }
        $head = '';
        foreach (['col_verb', 'col_does', 'col_effects', 'col_state', 'col_runs'] as $column) {
            $head .= '<th scope="col">' . $this->esc($copy[$column]) . '</th>';
        }

        return '<table class="decision-card__contract" data-admit-contract><thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . ($words === '' ? '' : '<p class="decision-card__facts">' . $this->esc($copy['words']) . '</p><ul class="decision-card__words">' . $words . '</ul>');
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
        $taken = [];
        foreach ($seats as $seat) {
            $rows .= '<li class="decision-card decision-card--seat" data-seat-key="' . $this->esc($seat['fingerprint']) . '">'
                . '<p class="decision-card__goal">' . $this->esc($seat['label'] ?? $seat['fingerprint']) . '</p>'
                . '<p class="decision-card__q"><code>' . $this->esc($seat['fingerprint']) . '</code></p>'
                . '<p class="decision-card__facts">' . $this->esc(implode(' ', $seat['scopes'])) . '</p>'
                . '<p class="decision-card__facts">' . $this->esc(sprintf($copy['enrolled_by'], $seat['authorized_by'])) . '</p>'
                . $this->holdingsHtml($seat, $copy)
                . '</li>';
            $name = mb_strtolower(trim((string) ($seat['label'] ?? '')));
            if ($name !== '' && !\in_array($name, $taken, true)) {
                $taken[] = $name;
            }
        }

        // ONE SEAT PER NAME (greenhouse decisions/0536). The fourth rehearsal found «Give the resident a seat» still
        // the primary button with the resident seated, and pressing it minted another invitation for another touch
        // (evidence/1069 §C3). The judge is `identity:seat`, which refuses a name that holds a seat; this only stops
        // offering what it would refuse. The names travel to the module so a taken one costs no touch either.
        $held = null;
        foreach ($seats as $seat) {
            if (mb_strtolower(trim((string) ($seat['label'] ?? ''))) === self::DEFAULT_SEAT) {
                $held = trim((string) $seat['label']);
                break;
            }
        }
        $label = $this->esc($copy['label']);
        $field = static fn (string $value, string $button, string $class): string => '<p class="decision-card__options">'
            . '<label>' . $label . ' <input type="text" class="mui-input" value="' . $value . '" data-seat-label></label> '
            . '<button type="button" class="mui-btn mui-btn--sm' . $class . '" data-seat-give>' . $button . '</button>'
            . '</p>';
        $offer = $held === null
            ? $field(self::DEFAULT_SEAT, $this->esc($copy['give']), ' mui-btn--primary')
            : '<p class="decision-card__facts" data-seat-held>' . $this->esc(sprintf($copy['held'], $held)) . '</p>'
                . '<details data-seat-another><summary>' . $this->esc($copy['another']) . '</summary>'
                . $field('', $this->esc($copy['give_another']), '')
                . '</details>';

        return '<ol class="mui-replay__stream" id="milpa-seats-list">' . $rows . '</ol>'
            . ($rows === '' ? '<div class="mui-empty" id="milpa-seats-empty"><p class="mui-empty__desc">' . $this->esc($copy['empty']) . '</p></div>' : '')
            . '<div class="decision-card decision-card--give-seat" data-seat-give-form data-seat-taken="' . $this->esc((string) json_encode($taken, \JSON_UNESCAPED_UNICODE)) . '">'
            . $offer
            . '<p class="decision-card__facts" data-seat-give-status></p>'
            . '<pre class="decision-card__command" data-seat-command hidden></pre>'
            . '</div>';
    }

    /**
     * What one seat holds of the capabilities built in the house (greenhouse decisions/0590, 0597): what persons
     * admitted to it — and, verb by verb, whether that still stands — and each scope no admission covers, offered
     * with the same contract a refusal's card shows, bound to THIS seat. A runtime that hands neither prints
     * nothing, and the seat reads as it did.
     *
     * @param array<string, mixed>  $seat
     * @param array<string, string> $copy
     */
    private function holdingsHtml(array $seat, array $copy): string
    {
        $html = '';
        foreach (\is_array($seat['admitted'] ?? null) ? $seat['admitted'] : [] as $admitted) {
            if (!\is_array($admitted)) {
                continue;
            }
            $verbs = [];
            foreach (\is_array($admitted['verbs'] ?? null) ? $admitted['verbs'] : [] as $verb => $standing) {
                $verbs[] = (string) $verb . match ($standing) {
                    'changed' => ' — ' . $copy['held_changed'],
                    'gone' => ' — ' . $copy['held_gone'],
                    default => '',
                };
            }
            $html .= '<p class="decision-card__facts" data-seat-admitted>'
                . $this->esc(sprintf($copy['admitted'], (string) ($admitted['scope'] ?? ''), (string) ($admitted['capability'] ?? '')))
                . ' · ' . $this->esc(implode('; ', $verbs))
                . ' · ' . $this->esc(sprintf($copy['admitted_by'], (string) ($admitted['admitted_by'] ?? ''), (string) ($admitted['at'] ?? '')))
                . '</p>';
        }
        $waiting = '';
        foreach (\is_array($seat['unadmitted'] ?? null) ? $seat['unadmitted'] : [] as $card) {
            if (!\is_array($card)) {
                continue;
            }
            $card['permission'] = $card['scope'] ?? '';
            $waiting .= $this->admissionCard(
                $card,
                $copy,
                ' data-admit-seat="' . $this->esc((string) $seat['fingerprint']) . '"',
                '<p class="decision-card__q">' . $this->esc(sprintf($copy['uncovered'], (string) $card['permission'], (string) ($card['capability'] ?? ''))) . '</p>'
                    . (($card['ran_before'] ?? false) === true ? '<p class="decision-card__facts" data-admit-ran-before>' . $this->esc($copy['ran_before']) . '</p>' : ''),
                'data-seat-admit',
            );
        }

        return $html . ($waiting === '' ? '' : '<details class="decision-card__waiting" data-seat-waiting><summary>' . $this->esc($copy['waiting']) . '</summary><ol class="mui-replay__stream">' . $waiting . '</ol></details>');
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

    /** The keys of the admission card's words, as the catalog names them under `frontier.` (decisions/0590). */
    public const array ADMISSION_WORDS = ['admit_q', 'admit_asked', 'admit_opens', 'col_verb', 'col_does', 'col_effects', 'col_state', 'col_runs', 'does_reads', 'does_writes', 'does_confirms', 'does_names', 'standing_admitted', 'standing_changed', 'standing_added', 'state_none', 'state_entities', 'state_declared', 'runs_reads', 'runs_trial', 'runs_house', 'runs_pre', 'runs_nopre', 'runs_asks', 'runs_refused', 'words', 'not_opened', 'house_limit', 'digest', 'blocked', 'admit_ack', 'admit'];

    /** The keys of what «Your seats» says each seat holds, as the catalog names them under `seats.` (decisions/0597). */
    public const array HOLDING_WORDS = ['admitted', 'admitted_by', 'held_changed', 'held_gone', 'waiting', 'uncovered', 'ran_before'];

    /** The name the form offers first: the real resident is one (greenhouse decisions/0536). */
    private const string DEFAULT_SEAT = 'resident';

    /** The English «Your seats» reads when the caller hands no catalog. */
    private const array SEATS_COPY = [
        'empty' => 'You answer for no seat yet.',
        'enrolled_by' => 'enrolled by %s',
        'label' => 'Seat name',
        'give' => 'Give the resident a seat',
        'held' => '«%s» has its seat. A name holds one seat: to give it to another key, revoke that key first.',
        'another' => 'Give a seat to another resident',
        'give_another' => 'Give this seat',
        'admitted' => 'Admitted: %1$s of %2$s',
        'admitted_by' => 'by %1$s, %2$s',
        'held_changed' => 'its contract changed: no longer covered',
        'held_gone' => 'gone from the capability',
        'waiting' => 'Built verbs no admission covers',
        'uncovered' => 'No admission covers %1$s of the capability %2$s',
        'ran_before' => 'It ran these before, by a word it holds. Nothing was migrated: a word carried no contract anybody saw.',
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
        // The admission of a built verb (greenhouse decisions/0590, 0597).
        'admit_q' => 'It asks for %1$s of the capability %2$s',
        'admit_asked' => 'It asked for this in %d sessions; this is the latest.',
        'admit_opens' => 'Admitting opens these verbs to this seat, each with the contract it has now:',
        'col_verb' => 'Verb',
        'col_does' => 'What it does',
        'col_effects' => 'Effects, as declared',
        'col_state' => 'Where its state lives',
        'col_runs' => 'How a call runs here',
        'does_reads' => 'reads',
        'does_writes' => 'changes state',
        'does_confirms' => 'asks each time',
        'does_names' => 'names its target: %s',
        'standing_admitted' => 'you already admitted it',
        'standing_changed' => 'its contract changed since you admitted it',
        'standing_added' => 'added after you admitted this scope',
        'state_none' => 'it keeps none',
        'state_entities' => 'the store of its entities',
        'state_declared' => 'declared by the capability',
        'runs_reads' => 'changes nothing',
        'runs_trial' => 'in a trial; lands by a promotion',
        'runs_house' => 'in the house, confined to that state',
        'runs_pre' => 'what was there is kept',
        'runs_nopre' => 'nothing is kept to return to',
        'runs_asks' => 'a person is asked first: %s',
        'runs_refused' => 'never: %s',
        'words' => 'The capability\'s own words:',
        'not_opened' => 'It does not open the other scopes of %s, any other capability, or writing its code.',
        'house_limit' => 'The house holds each verb to this contract. It did not read the code behind it.',
        'digest' => 'What you approve:',
        'blocked' => 'This cannot be admitted: %s.',
        'admit_ack' => 'I read the contract: admit %1$s of %2$s for this seat',
        'admit' => 'Admit %1$s of %2$s',
    ];

    /** The English a caller with no catalog reads. */
    private const array COPY = [
        'run' => 'Run',
        'approve' => 'Approve',
        'deny' => 'Deny',
        'paused_on' => 'paused on %s — answer below',
        'graph_door' => 'You cannot answer this decision: it needs %s, and you do not hold it. Whoever enrolled you can grant it.',
        'graph_yours' => 'You started this run, so you cannot approve it: somebody else answers.',
        'graph_needs' => '%1$s leads to %2$s, which needs %3$s — you do not hold it.',
    ];

    /**
     * A graph card's options, and the reasons under them — painted FOR WHOEVER IS LOOKING (greenhouse decisions/0584).
     *
     * THE CARD JUDGES NOTHING. Each choice arrives judged by the engine (`may`, and `because`: the rule that refused
     * it), and whether the reader may reach `graph:decide` at all arrives as the door's own verdict (`door`). With
     * neither — an older orchestrator, a page with no reader — every option is a button, as it always was.
     *
     * AN OPTION THE READER MAY NOT TAKE IS SHOWN, switched off, with its reason. Hiding it would say the gate offers
     * less than it does. It carries no `data-graph-decide`, so it posts nothing — and if it did, the door would
     * refuse it exactly as before: this is what the reader is TOLD, not what keeps them out.
     *
     * The reasons are the reader's: where the option leads and what that asks, in the page's locale. A rule this
     * card has no words for is said in the engine's own sentence.
     *
     * @param array<string, mixed>  $g    the decision: `options`, and when judged `choices`, `viewer` and `door`
     * @param array<string, string> $copy
     *
     * @return array{0: string, 1: string} the buttons, and the reasons that follow them
     */
    private function graphOptions(array $g, array $copy): array
    {
        /** @var array<string, array<string, mixed>> $choices */
        $choices = array_column(\is_array($g['choices'] ?? null) ? $g['choices'] : [], null, 'option');
        $door = \is_array($g['door'] ?? null) ? $g['door'] : [];
        $closed = ($door['may'] ?? true) === false;
        $buttons = '';
        $reasons = '';
        $own = false;

        /** @var string $option */
        foreach ($g['options'] as $option) {
            $choice = $choices[$option] ?? [];

            if (!$closed && ($choice['may'] ?? true) !== false) {
                $buttons .= '<button type="button" class="mui-btn mui-btn--sm decision-card__option"'
                    . ' data-graph-decide="' . $this->esc($option) . '">' . $this->esc($option) . '</button>';

                continue;
            }

            $buttons .= '<button type="button" class="mui-btn mui-btn--sm decision-card__option"'
                . ' data-graph-option="' . $this->esc($option) . '" disabled aria-disabled="true">' . $this->esc($option) . '</button>';

            $because = $choice['because'] ?? null;
            if ($because === 'requester') {
                $own = true; // also one reason for the whole card.

                continue;
            }

            /** @var list<string> $needs */
            $needs = \is_array($choice['needs'] ?? null) ? $choice['needs'] : [];
            $said = $because === 'needs'
                ? ' ' . $this->esc(ltrim(sprintf($copy['graph_needs'], '', (string) ($choice['leads_to'] ?? ''), implode(', ', $needs))))
                : ' — ' . $this->esc((string) ($choice['why_not'] ?? ''));

            $reasons .= '<p class="decision-card__facts" data-graph-why="' . $this->esc($option) . '"><strong>' . $this->esc($option) . '</strong>' . $said . '</p>';
        }

        if ($closed) {
            // ONE reason for the whole card, the door's: it replaces whatever each option would have said.
            /** @var list<string> $needs */
            $needs = \is_array($door['needs'] ?? null) ? $door['needs'] : [];
            $reasons = '<p class="decision-card__facts" data-graph-why="">' . $this->esc(sprintf($copy['graph_door'], implode(', ', $needs))) . '</p>';
        } elseif ($own) {
            $reasons .= '<p class="decision-card__facts" data-graph-why="">' . $this->esc($copy['graph_yours']) . '</p>';
        }

        return [$buttons, $reasons];
    }

    private function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES);
    }
}
