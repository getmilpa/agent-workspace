<?php

/**
 * This file is part of Milpa Agent Workspace.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Data;

use Milpa\AppRuntime\Agent\{AcceptanceEvidence,DeliveryScope};
use Milpa\AppRuntime\Operations\AgentOperations;
use Milpa\AppRuntime\Web\{ScreenDrafts,ScreenDraftOperations};
use Milpa\Interfaces\Di\DIContainerInterface;

/** Read native delivery evidence on demand; never cache or mutate the session. @internal */
final readonly class DeliveryEvidence
{
    /** Bind to the app root and its native services; no reads happen at construction. */
    public function __construct(private string $root, private DIContainerInterface $container)
    {
    }
    /**
     * Sample one trusted server-selected session; callers supply their authenticated context.
     *
     * @return array<string, mixed>
     */
    public function read(string $session): array
    {
        if (!class_exists(DeliveryScope::class) || !class_exists(AcceptanceEvidence::class) || !class_exists(\Milpa\Agent\SessionStore::class)) {
            return ['session' => $session, 'termination' => null, 'meaning' => 'sampled_observation_not_a_lock_or_approval',
                'state' => 'unavailable', 'declaration' => null, 'report' => null];
        }
        $ops = new AgentOperations($this->container);
        $store = $ops->sessionStore();
        if ($store === null) {
            return ['session' => $session, 'termination' => null, 'meaning' => 'sampled_observation_not_a_lock_or_approval',
                'state' => 'unavailable', 'declaration' => null, 'report' => null];
        }
        $events = $store->stream($session);
        $declaration = DeliveryScope::read($events, $session);
        $ends = array_values(array_filter($events, static fn ($e) => $e->type === 'session.run_terminated'));
        $last = $ends === [] ? null : $ends[array_key_last($ends)]->payload;
        $base = ['session' => $session, 'termination' => $last, 'meaning' => 'sampled_observation_not_a_lock_or_approval'];
        if ($declaration === null) {
            return $base + ['state' => 'scope_missing', 'declaration' => null, 'report' => null];
        }
        $scope = $declaration['scope'];
        $read = fn (): array => ['ok' => true, 'session' => $session] + AcceptanceEvidence::read(
            $this->root,
            $store->stream($session),
            $scope['workspace'],
            $scope['test'],
            $scope['screen'],
            $this->container->get(ScreenDrafts::class)
        );
        $before = $read();
        $operation = current(array_filter($ops->operations(), static fn ($op) => $op->name === 'acceptance:evidence'));
        $result = ($operation->handler)(['session' => $session, 'workspace' => $scope['workspace'], 'test' => $scope['test'], 'screen' => $scope['screen']]);
        if ($before !== $result || ($before['candidate']['artifact']['path'] ?? null) !== $scope['artifactPath']) {
            throw new \DomainException('Declared artifact or SDK operation mismatch');
        }
        $review = null;
        if (isset($before['screen']['revision'])) {
            $provider = $this->container->get(ScreenDraftOperations::class);
            $op = current(array_filter($provider->operations(), static fn ($op) => $op->name === 'screen:review'));
            $review = ($op->handler)(['revision' => $before['screen']['revision']]);
        }
        $after = $read();
        if (serialize($events) !== serialize($store->stream($session))) {
            throw new \DomainException('Session changed during evidence collection');
        }
        return $base + ['state' => 'observed', 'declaration' => $declaration,
            'evidence' => $before, 'review' => $review,
            'report' => FactualReport::fromSamples($before, $review, $after)];
    }
}
