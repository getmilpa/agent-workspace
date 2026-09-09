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
 * The decisions inbox as a milpa/live component (greenhouse decisions/0211, phase D4).
 *
 * The cross-session backlog of questions agents parked (greenhouse decisions/0195): durable questions, not
 * modals. Its state is how many are waiting, so a re-render says what is actually pending.
 *
 * It declares no live action: an answer goes through the operations surface (`agent:answer`,
 * `sequence:run`) with the passkey session as principal — the same doors a terminal takes — not
 * through the component wire. The state counts what is waiting and what can be run.
 */
final class DecisionsInboxComponent implements ComponentDefinitionInterface
{
    /** The inbox: how many parked questions are waiting. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-decisions',
            contractVersion: '1',
            summary: 'The cross-session inbox of questions agents parked.',
            designContract: '@milpa/design:components/milpa-decisions.contract.json',
            propsSchema: ['pending' => ['type' => 'array', 'default' => []], 'sequences' => ['type' => 'array', 'default' => []]],
            stateSchema: ['pending' => ['type' => 'integer'], 'sequences' => ['type' => 'integer']],
            actions: [],
        );
    }

    /** Mount from props: the count is the state. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'desktop-decisions',
            '1',
            [
                'pending' => \count(\is_array($props['pending'] ?? null) ? $props['pending'] : []),
                'sequences' => \count(\is_array($props['sequences'] ?? null) ? $props['sequences'] : []),
            ],
            [],
        );
    }

    /** Inert: the answer is given in the session's own conversation, at its gate. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
