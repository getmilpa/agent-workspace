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
 * The agent's parked question, in the conversation where it was raised (greenhouse decisions/0254).
 *
 * ── WHY THE OPTIONS ARE NOT A YES AND A NO ──────────────────────────────────────────────────────
 *
 * Because the agent proposes them. {@see \Milpa\Agent\PendingQuestion} carries `options` precisely so a
 * surface does not have to interpret an answer again — *«que sean opciones y no texto libre es lo que
 * permite contestar "2" desde una terminal, un TUI o un chat»*. A hardcoded Authorize/Deny pair would be
 * this surface inventing a fork the agent never offered.
 *
 * It also carries `why` (the prose) and `reason` (a STABLE code — `permission`, `signature`,
 * `target_not_named`), and the reason is what a projection groups by: the text gets rewritten and
 * translated, the code does not.
 *
 * What this component does NOT do is answer. Answering is `POST /agent/answer` — the operation's own
 * door, taken with the passkey session, walking the house's confirm gate when the door asks for one. The
 * same door the decisions inbox takes, and the same one a terminal takes.
 */
final class AskGrantComponent implements ComponentDefinitionInterface
{
    /** A parked question: what was asked, why, and the options the agent itself proposed. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-ask-grant',
            contractVersion: '1',
            summary: 'A conversation message: a question the agent parked, answerable in place.',
            designContract: '@milpa/design:components/milpa-ask-grant.contract.json',
            propsSchema: [
                'id' => ['type' => 'string', 'default' => ''],
                'text' => ['type' => 'string', 'default' => ''],
                'why' => ['type' => 'string', 'default' => ''],
                'reason' => ['type' => 'string', 'default' => ''],
                'options' => ['type' => 'array', 'default' => []],
            ],
            stateSchema: [
                'id' => ['type' => 'string'],
                'answered' => ['type' => 'string|null'],
            ],
            actions: [],
        );
    }

    /** Mount from props: the question's identity in state, its words in meta. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        /** @var list<string> $options */
        $options = array_values(array_filter(
            \is_array($props['options'] ?? null) ? $props['options'] : [],
            static fn (mixed $option): bool => \is_string($option) && $option !== '',
        ));

        return new StateSnapshot(
            $context->componentId,
            'desktop-ask-grant',
            '1',
            ['id' => (string) ($props['id'] ?? ''), 'answered' => null],
            [
                'text' => (string) ($props['text'] ?? ''),
                'why' => (string) ($props['why'] ?? ''),
                'reason' => (string) ($props['reason'] ?? ''),
                'options' => $options,
            ],
        );
    }

    /**
     * Inert here on purpose: the answer is adjudicated by `agent:answer`, not by a live component.
     *
     * A component `handle()` that wrote the answer would be a SECOND way to answer a parked question,
     * with its own authorization story — and the whole point of the parked question is that exactly one
     * governed door decides it, whichever surface the human happened to be standing in front of.
     */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
