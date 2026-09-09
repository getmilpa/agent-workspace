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

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * The session compacted its window, said where it happened (greenhouse decisions/0254).
 *
 * ── WHY THIS IS A RULE AND NOT A MESSAGE ────────────────────────────────────────────────────────
 *
 * Because nobody said it. `session.compacted` has been in {@see \Milpa\Agent\SessionEvent} carrying its
 * `summary` and the `through` sequence, and no surface painted it — so a conversation would quietly lose
 * its earlier turns to a summary and read as though they had simply never happened.
 *
 * It is not the agent speaking and it is not an error: it is something that happened TO the session,
 * between two turns. So it renders as a separator across the thread at the point it occurred — which is
 * also the only honest place for it, because it is the boundary itself that carries the information:
 * above the rule the model is working from a summary, below it from the turns themselves.
 *
 * Nothing is rewritten by a compaction (`compactedThrough` marks how far a summary reaches, it deletes no
 * turn), so the rule says how far it reached rather than claiming anything was removed.
 */
final class CompactedComponent implements ComponentDefinitionInterface
{
    /** A compaction boundary: how far the summary reaches. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-compacted',
            contractVersion: '1',
            summary: 'A conversation separator: the session compacted its context window here.',
            designContract: '@milpa/design:components/milpa-compacted.contract.json',
            propsSchema: [
                'through' => ['type' => 'int', 'default' => 0],
                'summary' => ['type' => 'string', 'default' => ''],
            ],
            stateSchema: ['through' => ['type' => 'int']],
            actions: [],
        );
    }

    /** Mount from props: how far the summary reaches, and the summary itself for the title. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'desktop-compacted',
            '1',
            ['through' => (int) ($props['through'] ?? 0)],
            ['summary' => (string) ($props['summary'] ?? '')],
        );
    }

    /** A boundary is inert: compacting is the session's, and it already happened. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
