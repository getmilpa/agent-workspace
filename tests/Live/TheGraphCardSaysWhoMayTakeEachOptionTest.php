<?php

/**
 * This file is part of milpa/agent-workspace.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent-workspace
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Tests\Live;

use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\AgentWorkspace\Live\DecisionsInboxView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A graph's card says, to whoever is looking at it, which of its options they may take.
 *
 * The card printed every option as a button for whoever opened the inbox. Whether that person could take one was
 * found out by pressing it: the door answered 403 to somebody without `graph:decide`, and an option that leads to a
 * node the person may not run was refused after the press (greenhouse evidence/1122, decisions/0584).
 *
 * The card now says it first. It judges NOTHING: each choice arrives judged by the engine (`may`, `because`), and
 * whether the reader may reach `graph:decide` at all arrives as the door's own needs. An option the reader may not
 * take is SHOWN switched off, with its reason — hiding it would say the gate offers less than it does.
 */
#[CoversClass(DecisionsInboxView::class)]
final class TheGraphCardSaysWhoMayTakeEachOptionTest extends TestCase
{
    private const array RUN = ['graph' => 'essay:review', 'instance' => 'run-1', 'question' => 'editor_call_gate', 'options' => ['accept_as_is', 'one_more_round', 'abandon'], 'requester' => 'actor:agent-7'];

    private const array PUBLISH = ['option' => 'accept_as_is', 'leads_to' => 'publish', 'operation' => 'essay:publish', 'needs' => ['essay:publish']];

    private const array ROUND = ['option' => 'one_more_round', 'leads_to' => 'sketch', 'operation' => 'nit:sketch', 'needs' => []];

    private const array ABANDON = ['option' => 'abandon', 'leads_to' => 'shelve', 'operation' => 'essay:shelve', 'needs' => []];

    public function testACardNobodyJudgedIsPaintedAsItAlwaysWas(): void
    {
        // An older orchestrator, or a page with no reader: no choices arrive, and every option is a button.
        $html = (new DecisionsInboxView())->html([], graphs: [self::RUN]);

        self::assertSame(3, substr_count($html, 'data-graph-decide='));
        self::assertStringNotContainsString('disabled', $html);
        self::assertStringNotContainsString('data-graph-why', $html);
    }

    public function testAnOptionTheReaderMayNotTakeIsShownSwitchedOffWithItsReason(): void
    {
        $html = (new DecisionsInboxView())->html([], graphs: [self::RUN + [
            'viewer' => ['is_requester' => false, 'verified' => true],
            'door' => ['may' => true, 'needs' => ['graph:decide']],
            'choices' => [
                self::PUBLISH + ['may' => false, 'because' => 'needs', 'why_not' => "it leads to the node 'publish' (essay:publish), and it needs the scope essay:publish, which actor:passkey:B8kQ does not hold"],
                self::ROUND + ['may' => true, 'because' => null, 'why_not' => null],
                self::ABANDON + ['may' => true, 'because' => null, 'why_not' => null],
            ],
        ]]);

        self::assertStringContainsString('data-graph-decide="one_more_round"', $html);
        self::assertStringContainsString('data-graph-decide="abandon"', $html);
        self::assertStringNotContainsString('data-graph-decide="accept_as_is"', $html, 'what may not be taken is not a button that posts');
        self::assertMatchesRegularExpression('/<button[^>]*data-graph-option="accept_as_is"[^>]*disabled[^>]*aria-disabled="true"[^>]*>accept_as_is<\/button>/', $html, 'it is still SHOWN: the gate offers it');
        self::assertStringContainsString(
            '<p class="decision-card__facts" data-graph-why="accept_as_is"><strong>accept_as_is</strong> leads to publish, which needs essay:publish — you do not hold it.</p>',
            $html,
            'with the reason in the reader\'s words: where it leads, and what that asks',
        );
        self::assertSame(1, substr_count($html, 'data-graph-why='), 'and only for the option that is off');
    }

    public function testAReaderWhoMayNotAnswerAtAllIsToldWhatAnsweringNeeds(): void
    {
        // The first passkey of a fresh house: it holds no graph:decide, so the door would answer 403 to any press.
        $html = (new DecisionsInboxView())->html([], graphs: [self::RUN + [
            'viewer' => ['is_requester' => false, 'verified' => true],
            'door' => ['may' => false, 'needs' => ['graph:decide']],
            'choices' => [
                self::PUBLISH + ['may' => false, 'because' => 'needs', 'why_not' => '…'],
                self::ROUND + ['may' => true, 'because' => null, 'why_not' => null],
                self::ABANDON + ['may' => true, 'because' => null, 'why_not' => null],
            ],
        ]]);

        self::assertStringNotContainsString('data-graph-decide=', $html, 'nothing on this card posts an answer');
        self::assertSame(3, substr_count($html, 'data-graph-option='), 'every option is shown, switched off');
        self::assertSame(3, substr_count($html, ' disabled '));
        self::assertStringContainsString(
            '<p class="decision-card__facts" data-graph-why="">You cannot answer this decision: it needs graph:decide, and you do not hold it. Whoever enrolled you can grant it.</p>',
            $html,
        );
        self::assertSame(1, substr_count($html, 'data-graph-why='), 'one reason, the door\'s — not one per option');
    }

    public function testWhoeverStartedTheRunIsToldItIsTheirOwn(): void
    {
        $own = 'actor:passkey:7_JJ opened this gate, so it cannot approve it: the work and its approval need two different people';
        $html = (new DecisionsInboxView())->html([], graphs: [['requester' => 'actor:passkey:7_JJ'] + self::RUN + [
            'viewer' => ['is_requester' => true, 'verified' => true],
            'door' => ['may' => true, 'needs' => ['graph:decide']],
            'choices' => [
                self::PUBLISH + ['may' => false, 'because' => 'requester', 'why_not' => $own],
                self::ROUND + ['may' => false, 'because' => 'requester', 'why_not' => $own],
                self::ABANDON + ['may' => false, 'because' => 'requester', 'why_not' => $own],
            ],
        ]]);

        self::assertStringNotContainsString('data-graph-decide=', $html);
        self::assertSame(3, substr_count($html, ' disabled '));
        self::assertStringContainsString('<p class="decision-card__facts" data-graph-why="">You started this run, so you cannot approve it: somebody else answers.</p>', $html);
        self::assertSame(1, substr_count($html, 'data-graph-why='), 'said once, not three times');
    }

    public function testWhoeverStartedARunAndLacksANodeIsToldBoth(): void
    {
        $html = (new DecisionsInboxView())->html([], graphs: [self::RUN + [
            'viewer' => ['is_requester' => true, 'verified' => true],
            'door' => ['may' => true, 'needs' => ['graph:decide']],
            'choices' => [
                self::PUBLISH + ['may' => false, 'because' => 'needs', 'why_not' => '…'],
                self::ROUND + ['may' => false, 'because' => 'requester', 'why_not' => '…'],
                self::ABANDON + ['may' => false, 'because' => 'requester', 'why_not' => '…'],
            ],
        ]]);

        self::assertStringContainsString('data-graph-why="accept_as_is"', $html);
        self::assertStringContainsString('You started this run', $html);
        self::assertSame(2, substr_count($html, 'data-graph-why='));
    }

    public function testAReasonTheCardHasNoWordsForIsSaidInTheEnginesOwn(): void
    {
        $html = (new DecisionsInboxView())->html([], graphs: [self::RUN + [
            'viewer' => ['is_requester' => false, 'verified' => false],
            'door' => ['may' => true, 'needs' => ['graph:decide']],
            'choices' => [
                self::PUBLISH + ['may' => false, 'because' => 'unverified', 'why_not' => 'a gate is answered by a verified actor — and whoever is looking is not one'],
                self::ROUND + ['may' => false, 'because' => 'a-rule-of-tomorrow', 'why_not' => 'the moon is <full>'],
                self::ABANDON + ['may' => true, 'because' => null, 'why_not' => null],
            ],
        ]]);

        self::assertStringContainsString('data-graph-why="accept_as_is"><strong>accept_as_is</strong> — a gate is answered by a verified actor — and whoever is looking is not one</p>', $html);
        self::assertStringContainsString('data-graph-why="one_more_round"><strong>one_more_round</strong> — the moon is &lt;full&gt;</p>', $html, 'and what the engine said is escaped like everything else');
        self::assertStringContainsString('data-graph-decide="abandon"', $html);
    }

    public function testTheReasonsAreSaidInTheLocaleOfThePage(): void
    {
        $catalog = new Catalog('es');
        $html = (new DecisionsInboxView())->html([], graphs: [
            self::RUN + ['viewer' => ['is_requester' => false, 'verified' => true], 'door' => ['may' => false, 'needs' => ['graph:decide']], 'choices' => [self::PUBLISH + ['may' => true, 'because' => null, 'why_not' => null]]],
            ['instance' => 'run-2'] + self::RUN + ['viewer' => ['is_requester' => true, 'verified' => true], 'door' => ['may' => true, 'needs' => ['graph:decide']], 'choices' => [
                self::PUBLISH + ['may' => false, 'because' => 'needs', 'why_not' => '…'],
                self::ROUND + ['may' => false, 'because' => 'requester', 'why_not' => '…'],
            ]],
        ], copy: [
            'graph_door' => $catalog->tr('decisions.graph.door'),
            'graph_yours' => $catalog->tr('decisions.graph.yours'),
            'graph_needs' => $catalog->tr('decisions.graph.needs'),
        ]);

        self::assertStringContainsString('No puedes contestar esta decisión: pide graph:decide, y no lo tienes.', $html);
        self::assertStringContainsString('<strong>accept_as_is</strong> lleva a publish, que pide essay:publish — y no lo tienes.', $html);
        self::assertStringContainsString('Tú arrancaste este run, así que no puedes aprobarlo', $html);
    }

    public function testNothingTheHouseSaysReachesThePageUnescaped(): void
    {
        $html = (new DecisionsInboxView())->html([], graphs: [[
            'graph' => 'g', 'instance' => 'run-1', 'question' => 'q', 'requester' => '', 'options' => ['"><script>x</script>'],
            'viewer' => ['is_requester' => false, 'verified' => true],
            'door' => ['may' => true, 'needs' => ['graph:decide']],
            'choices' => [['option' => '"><script>x</script>', 'leads_to' => '<b>node</b>', 'operation' => 'o', 'needs' => ['<i>scope</i>'], 'may' => false, 'because' => 'needs', 'why_not' => '<u>why</u>']],
        ]]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>node</b>', $html);
        self::assertStringNotContainsString('<i>scope</i>', $html);
    }
}
