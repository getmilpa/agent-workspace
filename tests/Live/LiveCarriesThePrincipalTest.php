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

namespace Milpa\AgentWorkspace\Tests\Live;

use Milpa\AgentWorkspace\Live\DesktopComponents;
use Milpa\AgentWorkspace\Tests\Fixtures\PasskeyGateStub;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\ComponentContract;
use Milpa\Live\ValueObjects\InteractionRequest;
use Milpa\Live\ValueObjects\InteractionResult;
use Milpa\Live\ValueObjects\StateSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Identity must travel with an ACTION, not only with the paint.
 *
 * `LiveEndpoint::handle()` takes a principal as its second argument, and this adapter passed none while
 * `ShellController` read one from the same request. So the Desktop knew who you were when it PAINTED and
 * forgot when you ACTED.
 *
 * MEASURED, and not what it first looked like. The ownership check in `ContractInteractionAuthorizer` was
 * not switched off — it was stuck on DENY: state naming an owner was refused to everyone, the owner
 * included, because the caller was always `null`. That is why no component in this package mounts
 * `meta['principal']`: per-principal state could not work, so nobody wrote any.
 *
 * The middle case below is the falsifier — it is the one that fails on the version that passed nothing.
 * The other two are guards: they held before and must keep holding, which is what makes the fix additive
 * rather than a loosening.
 */
#[CoversClass(DesktopComponents::class)]
final class LiveCarriesThePrincipalTest extends TestCase
{
    public function testStateMintedForOnePrincipalIsRefusedToAnother(): void
    {
        $registry = $this->desktop();

        $response = $registry->endpoint()->handle(...$this->interaction($registry, 'someone-else'));

        self::assertSame(403, $response->status);
        self::assertStringContainsString('principal', (string) json_encode($response->body));
    }

    public function testStateMintedForAPrincipalIsAcceptedFromThatPrincipal(): void
    {
        // THE FALSIFIER. Before this adapter passed a principal, the owner was refused their OWN state:
        // the check compares against a caller that was always null, so it could only ever deny.
        $registry = $this->desktop();

        $response = $registry->endpoint()->handle(...$this->interaction($registry, PasskeyGateStub::PRINCIPAL));

        self::assertSame(200, $response->status);
    }

    public function testAnAnonymousCallerCannotDriveStateThatNamesAPrincipal(): void
    {
        // A GUARD, not the falsifier: this already denied, and must keep denying. Passing a principal
        // must not open a door that was shut.
        $registry = $this->desktop();

        $response = $registry->endpoint()->handle(...$this->interaction($registry, null));

        self::assertSame(403, $response->status);
        self::assertStringContainsString('principal', (string) json_encode($response->body));
    }

    /**
     * The registry whose ENDPOINT enforces this, and no controller.
     *
     * 🚨 THE SUBJECT MOVED TO THE THING THAT ACTUALLY CHECKS. It used to be this package's
     * `LiveController`, a PSR-7 adapter over `endpoint()->handle($request, $principal)` — and the
     * adapter went with the `/desktop/live` route when the page was retired. The check was never the
     * controller's: it is the endpoint's authorizer, comparing the state's owner against the principal
     * it was handed (greenhouse decisions/0256, decisions/0283).
     *
     * Verified before moving it: `milpa/admin`'s own live controller passes the principal the same way
     * (`getmilpa-admin/src/Controllers/LiveController.php:116`), so the host that replaced the page
     * upholds this at the HTTP boundary and the property is not weakened, only re-aimed.
     */
    private function desktop(): DesktopComponents
    {
        $registry = new DesktopComponents('sign-secret', 'csrf-secret');
        $registry->declare(new OwnedComponent(), static fn (array $props): string => '<p>owned</p>');

        return $registry;
    }

    /**
     * A signed interaction on state minted for {@see PasskeyGateStub::PRINCIPAL}, and who is calling.
     *
     * @return array{0: \Milpa\Live\Http\LiveHttpRequest, 1: \Milpa\Live\ValueObjects\SecurityPrincipal|null}
     */
    private function interaction(DesktopComponents $registry, ?string $caller): array
    {
        $state = (new OwnedComponent())->mount(
            ['principal' => PasskeyGateStub::PRINCIPAL],
            new ComponentContext('owned-1', DesktopComponents::ROUTE),
        );

        $sid = 'sess-owned-1';

        return [
            new \Milpa\Live\Http\LiveHttpRequest(
                method: 'POST',
                action: 'touch',
                stateEnvelope: $registry->codec()->encodeState($state),
                payload: [],
                sessionId: $sid,
                csrfToken: $registry->csrfToken($sid),
            ),
            // The same shape the HOST hands the endpoint: the actor's id with the component scopes,
            // because the authorization already happened at the door — `milpa/admin`'s live controller
            // builds exactly this (greenhouse decisions/0256).
            $caller === null ? null : new \Milpa\Live\ValueObjects\SecurityPrincipal($caller, ['milpa:*']),
        ];
    }
}

/** A component whose signed state NAMES its owner — the shape the ownership check exists for. */
final class OwnedComponent implements ComponentDefinitionInterface
{
    public static function contract(): ComponentContract
    {
        return new ComponentContract(
            name: 'desktop-owned',
            contractVersion: '1',
            summary: 'A fixture component whose state names the principal it was minted for.',
            actions: ['touch' => []],
        );
    }

    public function mount(array $props, ComponentContext $context): StateSnapshot
    {
        return new StateSnapshot(
            $context->componentId,
            'desktop-owned',
            '1',
            ['touched' => false],
            ['principal' => (string) ($props['principal'] ?? '')],
        );
    }

    public function handle(InteractionRequest $request): InteractionResult
    {
        return new InteractionResult(state: $request->state);
    }
}
