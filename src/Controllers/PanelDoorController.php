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
 * The panel's own doors (greenhouse decisions/0495, 0497): answering a parked question, running or resuming a
 * sequence, deciding a graph — the inbox's three buttons that posted to `/agent/answer`, `/sequence/run` and
 * `/graph/decide` — and the composer's and Settings' six: the turn, `/goal`, a skill, the endpoint and model,
 * the provider's key, and «Find models». Every one posted to a path a fresh app never mounts, because its
 * `config/http.php` exposes nothing (decisions/0248). Measured: pressing Deny on the seat's parked question
 * answered 404 (evidence/1026, B1b), and saving the model in Settings answered 404 and said nothing
 * (evidence/1024, B2).
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

    /** Send the next turn of a session — the composer's send, carrying the chip's mode. */
    public const string TURN = 'agent';

    /** Set, clear or read a session's standing goal — the composer's `/goal`. */
    public const string GOAL = 'agent:goal';

    /** Put a user-invocable skill's body in front of the agent — the composer's `/<skill>`. */
    public const string SKILL = 'skill:invoke';

    /** Write governed configuration — Settings' endpoint and model, and the composer's model chip. */
    public const string CONFIG = 'config:set';

    /** Write the provider's key where the code reads it and git does not — Settings' key field. */
    public const string PROVIDER = 'provider:declare';

    /** Ask the provider which models it serves — Settings' «Find models» and the model chip's menu. */
    public const string MODEL = 'agent:model';

    /** Give the resident a seat: mint the invitation its own key accepts by signing (greenhouse decisions/0499). */
    public const string SEAT = 'identity:seat';

    /**
     * Each door's path and route name — never the operation's own, so none collides with a host that exposes
     * the operation globally.
     *
     * `verb` is how the panel asks: a read projects as GET, everything else as POST — the projector reads the query
     * and the body alike, so the verb is the panel's contract, not the operation's.
     *
     * @var array<string, array{path: string, route: string, method: string, from: string, verb: 'GET'|'POST'}>
     */
    public const array DOORS = [
        self::ANSWER => ['path' => '/workspace/answer', 'route' => 'desktop.door.answer', 'method' => 'answer', 'from' => 'milpa/app-runtime', 'verb' => 'POST'],
        self::SEQUENCE => ['path' => '/workspace/sequence', 'route' => 'desktop.door.sequence', 'method' => 'sequence', 'from' => 'milpa/app-runtime', 'verb' => 'POST'],
        self::DECIDE => ['path' => '/workspace/decide', 'route' => 'desktop.door.decide', 'method' => 'decide', 'from' => 'milpa/orchestrator', 'verb' => 'POST'],
        // greenhouse decisions/0497: the six the composer and Settings posted to paths a fresh app never mounts.
        self::TURN => ['path' => '/workspace/turn', 'route' => 'desktop.door.turn', 'method' => 'turn', 'from' => 'milpa/ai-gateway', 'verb' => 'POST'],
        self::GOAL => ['path' => '/workspace/goal', 'route' => 'desktop.door.goal', 'method' => 'goal', 'from' => 'milpa/app-runtime', 'verb' => 'POST'],
        self::SKILL => ['path' => '/workspace/skill', 'route' => 'desktop.door.skill', 'method' => 'skill', 'from' => 'milpa/app-runtime', 'verb' => 'GET'],
        self::CONFIG => ['path' => '/workspace/config', 'route' => 'desktop.door.config', 'method' => 'config', 'from' => 'milpa/app-runtime', 'verb' => 'POST'],
        self::PROVIDER => ['path' => '/workspace/provider', 'route' => 'desktop.door.provider', 'method' => 'provider', 'from' => 'milpa/app-runtime', 'verb' => 'POST'],
        self::MODEL => ['path' => '/workspace/model', 'route' => 'desktop.door.model', 'method' => 'model', 'from' => 'milpa/app-runtime', 'verb' => 'GET'],
        // greenhouse decisions/0499: station 7 without `config/identity.php` — the human gives the resident a seat here.
        self::SEAT => ['path' => '/workspace/seat', 'route' => 'desktop.door.seat', 'method' => 'seat', 'from' => 'milpa/app-runtime', 'verb' => 'POST'],
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

    /** Project the request as `agent` — the next turn of a session. */
    public function turn(ServerRequestInterface $request): ResponseInterface
    {
        return $this->door(self::TURN, $request);
    }

    /** Project the request as `agent:goal`. */
    public function goal(ServerRequestInterface $request): ResponseInterface
    {
        return $this->door(self::GOAL, $request);
    }

    /** Project the request as `skill:invoke`. */
    public function skill(ServerRequestInterface $request): ResponseInterface
    {
        return $this->door(self::SKILL, $request);
    }

    /** Project the request as `config:set`. */
    public function config(ServerRequestInterface $request): ResponseInterface
    {
        return $this->door(self::CONFIG, $request);
    }

    /** Project the request as `provider:declare`. */
    public function provider(ServerRequestInterface $request): ResponseInterface
    {
        return $this->door(self::PROVIDER, $request);
    }

    /** Project the request as `agent:model`. */
    public function model(ServerRequestInterface $request): ResponseInterface
    {
        return $this->door(self::MODEL, $request);
    }

    /** Project the request as `identity:seat`. */
    public function seat(ServerRequestInterface $request): ResponseInterface
    {
        return $this->door(self::SEAT, $request);
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
        return self::unavailable(\sprintf('This app offers no %s for the panel to run — it comes with %s.', $operation, $from));
    }

    /**
     * The 501 a door answers when the app offers the operation but not over HTTP: the panel does not open what the
     * operation itself did not offer the web (greenhouse decisions/0497).
     */
    public static function offTheWeb(string $operation): ResponseInterface
    {
        return self::unavailable(\sprintf('This app offers %s, but not over HTTP — the panel cannot run it.', $operation));
    }

    /**
     * The 501 a door answers when the operation declares no scope: without one the HTTP policy is never consulted
     * (greenhouse decisions/0082), and a panel door with no judge behind it would run the operation for anyone who
     * reached it. No judge, no act (decisions/0289, 0497) — whatever version of the operation's owner is installed.
     */
    public static function unjudged(string $operation): ResponseInterface
    {
        return self::unavailable(\sprintf('This app\'s %s declares no scope, so no policy can judge who may run it — the panel does not open it.', $operation));
    }

    private static function unavailable(string $sentence): ResponseInterface
    {
        $psr17 = new Psr17Factory();

        return $psr17->createResponse(501)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($psr17->createStream((string) json_encode(
                ['ok' => false, 'error' => $sentence],
                \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            )));
    }

    private function door(string $name, ServerRequestInterface $request): ResponseInterface
    {
        $operation = $this->operation($name);
        if ($operation === null || !class_exists(HttpProjector::class)) {
            return self::absent($name, self::DOORS[$name]['from']);
        }
        if (!$operation->supportsSurface('http')) {
            return self::offTheWeb($name);
        }
        if ($operation->scopes === [] && $operation->permission === null) {
            return self::unjudged($name);
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
