<?php

/**
 * This file is part of Milpa Agent Workspace.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Admin;

use Milpa\AgentWorkspace\Data\DesktopStore;
use Milpa\Live\ValueObjects\ComponentContext;

/**
 * The panel authority resolves requests against its principal's server-recorded task catalogue.
 * The resolved object travels to composed readers; client props cannot mint that decision.
 *
 * @internal
 */
final readonly class PanelSession
{
    /** A server object carried through composition, never a client prop or serialized claim. */
    public const CONTEXT_KEY = 'milpa.panel.session';

    /** @param list<string> $allowed The sessions this panel may offer to this principal. */
    private function __construct(public ?string $id, public array $allowed, private ?string $principal)
    {
    }

    /** Resolve the same stable session the panel's signed ticket has always carried. */
    public static function forPrincipal(?string $principal): string
    {
        return 'desk-admin-' . substr(hash('sha256', 'milpa/admin|agent|' . ($principal ?? '')), 0, 16);
    }

    /** Resolve a requested task against server-recorded provenance, or reject an explicit unknown choice. */
    public static function fromContext(ComponentContext $context, ?DesktopStore $store = null): self
    {
        $resolved = $context->meta[self::CONTEXT_KEY] ?? null;
        if ($resolved instanceof self && $resolved->principal === $context->principal) {
            return $resolved;
        }
        $default = self::forPrincipal($context->principal);
        $allowed = [$default, ...($store?->panelSessionIds($context->principal ?? '') ?? [])];
        $query = $context->meta['query'] ?? [];
        $requested = \is_array($query) && \array_key_exists('session', $query) ? $query['session'] : $default;

        return new self(\is_string($requested) && \in_array($requested, $allowed, true) ? $requested : null, $allowed, $context->principal);
    }

    /** Carry this resolved choice to the composed readers without granting authority to a string prop. */
    public function inContext(ComponentContext $context): ComponentContext
    {
        return new ComponentContext($context->componentId, $context->principal, $context->locale, $context->route, [self::CONTEXT_KEY => $this] + $context->meta);
    }
}
