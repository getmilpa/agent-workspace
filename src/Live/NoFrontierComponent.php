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
 * A person's own conversation, told what it cannot do — dimmed, with its why (greenhouse decisions/0609, path 1, I2).
 *
 * ── WHAT WAS MISSING, MEASURED (greenhouse evidence/1175) ──────────────────────────────────────────────────────────
 *
 * A person writes «build a plugin…» in this composer. Her session runs with her passkey: it is nobody's seat, the
 * house's frontier only knows seats, and so the house refuses the call and has nobody to ask. A seat making the same
 * call gets a card in Decisions and a person's touch grants it. In HER thread there was nothing: no card, no notice,
 * no button that decided anything — and the model, with no way out, called it a debt of the house.
 *
 * ── WHAT THIS IS ───────────────────────────────────────────────────────────────────────────────────────────────────
 *
 * The option she cannot take, shown dimmed, with its why (decisions/0584): the grant her session would have needed,
 * as a button that is disabled and wired to nothing; that nobody can grant it to a person's session; and the act that
 * works today — to give a resident a seat and grant it that scope from Decisions.
 *
 * ── WHAT THIS IS NOT ───────────────────────────────────────────────────────────────────────────────────────────────
 *
 * AUTHORITY. It grants nothing, runs nothing and posts nothing: it has no action, and its markup carries none of the
 * hooks the page's click delegates act on. Whether a call is one of these is the HOUSE's to say — the turn's result
 * and the replayed transcript carry it as data (`no_frontier`); this component never reads a sentence.
 *
 * It names no design contract: the design system has none for it yet.
 */
final class NoFrontierComponent implements ComponentDefinitionInterface
{
    /** One call a person's session was refused: the tool, the plugin it named and the permission it lacked. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-no-frontier',
            contractVersion: '1',
            summary: "A conversation notice: what a person's own session cannot do, and the act that works today.",
            propsSchema: [
                'tool' => ['type' => 'string', 'default' => ''],
                'plugin' => ['type' => 'string', 'default' => ''],
                'permission' => ['type' => 'string', 'default' => ''],
            ],
            stateSchema: ['permission' => ['type' => 'string']],
            actions: [],
        );
    }

    /** Mount from props: the permission in state, what was called beside it. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'desktop-no-frontier',
            '1',
            ['permission' => (string) ($props['permission'] ?? '')],
            ['tool' => (string) ($props['tool'] ?? ''), 'plugin' => (string) ($props['plugin'] ?? '')],
        );
    }

    /** It is inert: there is nothing here a person can decide. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
