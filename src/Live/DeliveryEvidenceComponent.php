<?php

/**
 * This file is part of Milpa Agent Workspace.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Live;

use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\{ComponentContract,ComponentContext,StateSnapshot,InteractionRequest,InteractionResult};

/** Read-only evidence surface; identity and evidence are never accepted from client props. */
final class DeliveryEvidenceComponent implements ComponentDefinitionInterface
{
    public const string NAME = 'desktop-delivery-evidence';
    /** No action, session selector, evidence prop or durable state is declared. */
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: self::NAME,
            contractVersion: '1',
            summary: 'The observed evidence of the delivery declared in this authenticated session.'
        );
    }
    /** An empty mount: the renderer performs a fresh server-side read. */
    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot($context->componentId, self::NAME, '1', []);
    }
    /** No interaction changes the evidence or grants approval. */
    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state, errors: ['action' => 'This component declares no actions.']);
    }
}
