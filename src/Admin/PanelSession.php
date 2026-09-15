<?php

/**
 * This file is part of Milpa Agent Workspace.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Admin;

/**
 * The panel's existing session rule, shared by its renderer and contextual signal reader.
 * Input is the principal supplied by the host's authenticated ComponentContext, never a query.
 *
 * @internal
 */
final class PanelSession
{
    /** Resolve the same stable session the panel's signed ticket has always carried. */
    public static function forPrincipal(?string $principal): string
    {
        return 'desk-admin-' . substr(hash('sha256', 'milpa/admin|agent|' . ($principal ?? '')), 0, 16);
    }
}
