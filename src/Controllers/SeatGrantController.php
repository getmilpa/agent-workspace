<?php

/**
 * This file is part of milpa/agent-workspace — the agent's workspace inside a Milpa app.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent-workspace
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Controllers;

use Milpa\Console\Http\HttpProjector;
use Milpa\Interfaces\Di\DIContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The panel's door to `identity:grant` (greenhouse decisions/0493): the human who answers for a seat grants
 * it the scope one of its refusals names, from the inbox, with their passkey.
 *
 * It is the same governed act the admin's Install button walks — the operation projected over HTTP, judged by
 * the policy the app's identity package published, with the house's confirm gate — through the admin's own
 * {@see GovernedAct}, so the refusal a person reads is the one the other governed buttons give. A fresh app
 * exposes no operation in `config/http.php`, so the panel mounts its own route rather than a global one
 * (greenhouse decisions/0248). Who may decide, and which scope, are the operation's to judge, never this door's.
 */
final class SeatGrantController
{
    /** The operation this door projects. */
    public const string OPERATION = 'identity:grant';

    /** The route's own name — never the operation's, so it cannot collide with a host that exposes it globally. */
    public const string ROUTE_NAME = 'desktop.seat.grant';

    /** Named as a string: milpa/app-runtime is where the operation lives, and this package does not require it. */
    private const string OPERATIONS = 'Milpa\\AppRuntime\\Operations\\SessionOperations';

    /**
     * @param \Milpa\Command\Operation|null $operation the operation to project; null asks milpa/app-runtime for it
     */
    public function __construct(
        private readonly DIContainerInterface $container,
        private readonly ?\Milpa\Command\Operation $operation = null,
    ) {
    }

    /** Project the request as `identity:grant` and answer what its ceremony answers. */
    public function grant(ServerRequestInterface $request): ResponseInterface
    {
        $operation = $this->operation();
        if ($operation === null || !class_exists(HttpProjector::class)) {
            return PanelDoorController::absent(self::OPERATION, 'milpa/app-runtime');
        }

        return PanelDoorController::project($this->container, $operation, $request, 'This app wired no policy to judge who may grant a seat a scope, so the panel cannot run the act.');
    }

    private function operation(): ?\Milpa\Command\Operation
    {
        if ($this->operation !== null) {
            return $this->operation;
        }
        if (!class_exists(self::OPERATIONS)) {
            return null;
        }
        $class = self::OPERATIONS;
        /** @var iterable<\Milpa\Command\Operation> $operations */
        $operations = (new $class($this->container))->operations();
        foreach ($operations as $operation) {
            if ($operation->name === self::OPERATION) {
                return $operation;
            }
        }

        return null;
    }
}
