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

use Milpa\Admin\Http\GovernedAct;
use Milpa\Command\Operation;
use Milpa\Command\OperationHttpPolicy;
use Milpa\Console\ConfirmTokenStore;
use Milpa\Console\FileConfirmTokenStore;
use Milpa\Console\Http\HttpProjector;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The inbox's own doors (greenhouse decisions/0495): answering a parked question, running or resuming a
 * sequence, deciding a graph — the three buttons that posted to `/agent/answer`, `/sequence/run` and
 * `/graph/decide`, paths a fresh app never mounts because its `config/http.php` exposes nothing
 * (decisions/0248). Measured: the human who enrolled a seat saw its parked question and pressing Deny
 * answered 404 (evidence/1026, B1b).
 *
 * Each door is the `/workspace/grant` pattern: the operation projected over HTTP through the admin's
 * {@see GovernedAct}, judged by the policy the app published under milpa/command's name, with the house's
 * confirm gate. The operation is looked up in the app's own catalogue at request time, so a door whose
 * operation the app does not offer says so (501) instead of answering something else. Mounting a door grants
 * nothing: who may answer, run or decide is the operation's to judge — for a seat's question, the line that
 * enrolled the seat (decisions/0493, 0495) — never this controller's.
 */
final class PanelDoorController
{
    /** Answer the question that parked a session. */
    public const string ANSWER = 'agent:answer';

    /** Run, or resume, a declared sequence. */
    public const string SEQUENCE = 'sequence:run';

    /** Answer the decision a graph run waits on. */
    public const string DECIDE = 'graph:decide';

    /**
     * Each door's path and route name — never the operation's own, so none collides with a host that exposes
     * the operation globally.
     *
     * @var array<string, array{path: string, route: string, method: string, from: string}>
     */
    public const array DOORS = [
        self::ANSWER => ['path' => '/workspace/answer', 'route' => 'desktop.door.answer', 'method' => 'answer', 'from' => 'milpa/app-runtime'],
        self::SEQUENCE => ['path' => '/workspace/sequence', 'route' => 'desktop.door.sequence', 'method' => 'sequence', 'from' => 'milpa/app-runtime'],
        self::DECIDE => ['path' => '/workspace/decide', 'route' => 'desktop.door.decide', 'method' => 'decide', 'from' => 'milpa/orchestrator'],
    ];

    /** Named as a string: milpa/app-runtime owns the catalogue, and this package does not require it. */
    private const string CATALOGUE = 'Milpa\\AppRuntime\\Support\\Operations';

    /**
     * @param (\Closure(string): ?Operation)|null $lookup the operation for a name; null asks the app's catalogue
     */
    public function __construct(
        private readonly DIContainerInterface $container,
        private readonly ?\Closure $lookup = null,
    ) {
    }

    /** Project the request as `agent:answer`. */
    public function answer(ServerRequestInterface $request): ResponseInterface
    {
        return $this->door(self::ANSWER, $request);
    }

    /** Project the request as `sequence:run`. */
    public function sequence(ServerRequestInterface $request): ResponseInterface
    {
        return $this->door(self::SEQUENCE, $request);
    }

    /** Project the request as `graph:decide`. */
    public function decide(ServerRequestInterface $request): ResponseInterface
    {
        return $this->door(self::DECIDE, $request);
    }

    /**
     * Run one operation through the governed act the panel's other buttons walk — or say this app lacks it.
     *
     * Shared with {@see SeatGrantController}: one projection, so the refusal a person reads is the same on
     * every door.
     */
    public static function project(
        DIContainerInterface $container,
        Operation $operation,
        ServerRequestInterface $request,
        string $noJudge,
    ): ResponseInterface {
        $psr17 = new Psr17Factory();
        $kernel = $container->has(Kernel::class) ? $container->get(Kernel::class) : null;
        $judge = $container->has(OperationHttpPolicy::class) ? $container->get(OperationHttpPolicy::class) : null;
        $projector = new HttpProjector(
            [$operation],
            $container,
            $psr17,
            $psr17,
            // The same store the app's own projector and the admin's buttons use: one confirm ceremony.
            tokens: $kernel instanceof Kernel ? new FileConfirmTokenStore($kernel->root() . '/storage/confirm-tokens.json') : new ConfirmTokenStore(),
            policy: $judge instanceof OperationHttpPolicy ? $judge : null,
        );

        return GovernedAct::run($projector, $operation->name, $request, $psr17, $noJudge);
    }

    /** The 501 a door answers when the app does not offer what it projects. */
    public static function absent(string $operation, string $from): ResponseInterface
    {
        $psr17 = new Psr17Factory();

        return $psr17->createResponse(501)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($psr17->createStream((string) json_encode(
                ['ok' => false, 'error' => \sprintf('This app offers no %s for the panel to run — it comes with %s.', $operation, $from)],
                \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            )));
    }

    private function door(string $name, ServerRequestInterface $request): ResponseInterface
    {
        $operation = $this->operation($name);
        if ($operation === null || !class_exists(HttpProjector::class)) {
            return self::absent($name, self::DOORS[$name]['from']);
        }

        return self::project(
            $this->container,
            $operation,
            $request,
            \sprintf('This app wired no policy to judge who may run %s over HTTP, so the panel cannot run it.', $name),
        );
    }

    private function operation(string $name): ?Operation
    {
        if ($this->lookup !== null) {
            return ($this->lookup)($name);
        }
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel || !class_exists(self::CATALOGUE)) {
            return null;
        }
        $catalogue = self::CATALOGUE;
        /** @var iterable<Operation> $operations */
        $operations = $catalogue::all($kernel, $kernel->root());
        foreach ($operations as $operation) {
            if ($operation->name === $name) {
                return $operation;
            }
        }

        return null;
    }
}
