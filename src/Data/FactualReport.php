<?php

/**
 * This file is part of Milpa Agent Workspace.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Data;

/** Presentation of observed receipts, never authorization or approval. @internal */
final class FactualReport
{
    /**
     * Derive a factual report only when both observations and the exact review agree.
     *
     * @param array<string, mixed>      $before
     * @param array<string, mixed>|null $review
     * @param array<string, mixed>      $after
     *
     * @return array<string, mixed>
     */
    public static function fromSamples(array $before, ?array $review, array $after): array
    {
        if ($before !== $after) {
            throw new \DomainException('Evidence changed during report collection');
        }
        $revision = $before['screen']['revision'] ?? null;
        $url = null;
        if ($revision !== null) {
            if (($review['ok'] ?? null) !== true || ($review['result']['id'] ?? null) !== $revision
                || !is_string($review['result']['reviewAt'] ?? null)) {
                throw new \DomainException('Exact review link unavailable');
            }
            $url = $review['result']['reviewAt'];
        }
        $test = [];
        foreach (['state', 'outcome', 'ran', 'tests', 'assertions', 'failures', 'errors', 'coversRequestedScope', 'scope'] as $key) {
            $test[$key] = $before['test'][$key] ?? null;
        }
        return [
            'evidenceState' => $before['state'],
            'candidateWorkspace' => $before['candidate']['workspace'],
            'artifactSha256' => $before['candidate']['artifact']['sha256'],
            'test' => $test,
            'screen' => ['state' => $before['screen']['state'], 'revision' => $revision, 'reviewUrl' => $url],
            'authorization' => $before['authorization'],
            'humanApproval' => $before['humanApproval'],
            'browserBehavior' => $before['scope']['browserBehavior'],
            'readyForHumanReview' => $before['state'] === 'current_evidence',
        ];
    }
}
