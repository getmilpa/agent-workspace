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

namespace Milpa\AgentWorkspace\Live;

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * THE SPECIALIST AGENTS THIS APP DECLARES — a screen of their own.
 *
 * They used to be the second half of the Skills screen, which put two subjects under one title: what
 * the agent CARRIES (skills guide judgment) and WHO ELSE it can hand work to (a role is a named
 * authority with the skills it preloads and the tools it is denied). One screen naming two things is a
 * screen a person has to read to know which one they are looking at (greenhouse decisions/0268).
 *
 * 🚨 IT READS, IT DOES NOT COMPOSE — YET. A role is `.milpa/agents/<name>.md`, written by
 * `agent:role:declare`. This screen shows what is declared and names the command that declares one;
 * composing a role and assembling a workflow from the panel is the next slice, and saying otherwise
 * would be a control that promises what nothing behind it does.
 */
final class SubagentsScreenComponent implements ComponentDefinitionInterface
{
    /** The screen: how many specialist roles the app declares. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-subagents',
            contractVersion: '1',
            summary: 'The specialist agents this app declares: each a named authority with the skills it preloads and the tools it is denied.',
            propsSchema: [
                'roles' => ['type' => 'array', 'default' => []],
            ],
            stateSchema: [
                'roles' => ['type' => 'integer'],
            ],
            actions: [],
        );
    }

    /** Mount from props: the count is the state. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'desktop-subagents',
            '1',
            ['roles' => \count(\is_array($props['roles'] ?? null) ? $props['roles'] : [])],
            [],
        );
    }

    /** Inert: a role is handed work by the agent, never invoked from this list. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
