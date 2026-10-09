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

namespace Milpa\AgentWorkspace\Data;

/**
 * What a session REHEARSED AND DID NOT APPLY, as the house says it beside its verdict (greenhouse decisions/0605, R2).
 *
 * Since app-runtime 0.218.0 the session that wrote an operation may be handed, after the refusal, what its call
 * answered in a rehearsal — a copy of the house that is discarded. That refusal no longer holds the closure, so the
 * verdict carries the difference: `rehearsed: {calls, of_verbs_that_change_state, applied: false}`. This package kept
 * `{verified, reasons, scope}` of a verdict and dropped it, so «verified» read the same for a builder that tried its
 * own operations as for one that tried nothing (evidence/1179).
 *
 * ONE READING OF THE DATUM, for every surface that shows a verdict. It is read as the house writes it or not at all:
 * a count of calls, how many of them were of an operation that changes state, and that NOTHING was applied. A value
 * that says something was applied is not this datum, and is not shown as if it were. Nothing here reads a reason's
 * sentence: a verdict that speaks of a rehearsal in its reasons says a reason.
 */
final class Rehearsed
{
    /**
     * The datum, or null when the verdict carries none that reads as one.
     *
     * @return array{calls: int, of_verbs_that_change_state: int, applied: false}|null
     */
    public static function of(mixed $said): ?array
    {
        if (!\is_array($said) || ($said['applied'] ?? null) !== false) {
            return null;
        }
        $calls = $said['calls'] ?? null;
        $writes = $said['of_verbs_that_change_state'] ?? null;
        if (!\is_int($calls) || !\is_int($writes) || $calls < 1 || $writes < 0 || $writes > $calls) {
            return null;
        }

        return ['calls' => $calls, 'of_verbs_that_change_state' => $writes, 'applied' => false];
    }

    /**
     * The datum as the key a verdict carries it under, to add to one — or nothing to add.
     *
     * @return array{rehearsed?: array{calls: int, of_verbs_that_change_state: int, applied: false}}
     */
    public static function beside(mixed $said): array
    {
        $rehearsed = self::of($said);

        return $rehearsed === null ? [] : ['rehearsed' => $rehearsed];
    }
}
