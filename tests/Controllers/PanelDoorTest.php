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

namespace Milpa\AgentWorkspace\Tests\Controllers;

use Milpa\AgentWorkspace\Controllers\PanelDoorController;
use Milpa\Command\Operation;
use Milpa\Command\OperationHttpPolicy;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The panel's own doors (greenhouse decisions/0495, 0497): each reaches the judge the app published, says 501
 * when the app does not offer the operation or offers it off the web, and finds the operation in the app's own
 * catalogue.
 */
final class PanelDoorTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function doors(): iterable
    {
        foreach (PanelDoorController::DOORS as $operation => $door) {
            yield $operation => [$operation, $door['method']];
        }
    }

    #[DataProvider('doors')]
    public function testEachDoorAsksTheJudgeTheAppPublished(string $operation, string $method): void
    {
        // The judge the app registers under milpa/command's name (evidence/1026: a door that asked the console's
        // namesake found none and refused everyone for the wrong reason). This one refuses everyone, naming what
        // it judged — reaching it is the proof.
        $container = new DIContainer();
        $container->registerService(OperationHttpPolicy::class, self::judge());
        $door = new PanelDoorController($container, static fn (string $name): ?Operation => self::scoped($name));

        $answer = $door->{$method}(self::request());

        self::assertSame(403, $answer->getStatusCode());
        self::assertStringContainsString('judged: ' . $operation, (string) $answer->getBody());
    }

    #[DataProvider('doors')]
    public function testADoorWhoseOperationTheAppLacksSays501(string $operation, string $method): void
    {
        $door = new PanelDoorController(new DIContainer(), static fn (string $name): ?Operation => null);

        $answer = $door->{$method}(self::request());

        self::assertSame(501, $answer->getStatusCode());
        self::assertStringContainsString('offers no ' . $operation, (string) $answer->getBody());
        self::assertStringContainsString(PanelDoorController::DOORS[$operation]['from'], (string) $answer->getBody());
    }

    public function testADoorWithNoJudgeSaysSoInsteadOfRunning(): void
    {
        $door = new PanelDoorController(new DIContainer(), static fn (string $name): ?Operation => self::scoped($name));

        $answer = $door->answer(self::request());

        self::assertSame(501, $answer->getStatusCode(), 'agent:answer declares a scope; with no policy the panel cannot run it');
        self::assertStringContainsString('no policy to judge who may run agent:answer', (string) $answer->getBody());
    }

    public function testWithoutAKernelThereIsNoCatalogueToAsk(): void
    {
        self::assertSame(501, (new PanelDoorController(new DIContainer()))->answer(self::request())->getStatusCode());
    }

    public function testTheDoorFindsTheOperationInTheAppsCatalogue(): void
    {
        // The app-runtime catalogue, asked the way the app asks it: agent:answer and sequence:run are there,
        // graph:decide is not (it comes with milpa/orchestrator, which a fresh app does not install).
        $container = new DIContainer();
        $container->registerService(Kernel::class, $this->kernel());
        $container->registerService(OperationHttpPolicy::class, self::judge());
        $door = new PanelDoorController($container);

        self::assertStringContainsString('judged: agent:answer', (string) $door->answer(self::request())->getBody());
        self::assertStringContainsString('judged: sequence:run', (string) $door->sequence(self::request())->getBody());
        self::assertSame(501, $door->decide(self::request())->getStatusCode());
    }

    public function testADoorDoesNotOpenWhatTheOperationDidNotOfferTheWeb(): void
    {
        // greenhouse decisions/0497: the door projects the operation the app declared, and an operation that
        // declares no `http` surface stays off the web — said, never run.
        $offTheWeb = new Operation(
            name: PanelDoorController::MODEL,
            description: 'An operation the terminal offers and the web does not',
            handler: static fn (): array => ['ok' => true],
            surfaces: ['cli', 'tui'],
        );
        $container = new DIContainer();
        $container->registerService(OperationHttpPolicy::class, self::judge());
        $door = new PanelDoorController($container, static fn (string $name): ?Operation => $offTheWeb);

        $answer = $door->model(self::request());

        self::assertSame(501, $answer->getStatusCode());
        self::assertStringContainsString('offers agent:model, but not over HTTP', (string) $answer->getBody());
    }

    public function testTheComposerAndSettingsDoorsFindTheirOperationsInTheAppsCatalogue(): void
    {
        // greenhouse decisions/0497: the six the composer and Settings posted to 404, asked of the app-runtime
        // catalogue the way the app asks it. Each reaches the judge under its own operation's name.
        $container = new DIContainer();
        $container->registerService(Kernel::class, $this->kernel([
            'Milpa\\AppRuntime\\Operations\\AgentOperations',
            'Milpa\\AppRuntime\\Operations\\SessionOperations',
            'Milpa\\AppRuntime\\Operations\\ConfigOperations',
        ]));
        $container->registerService(OperationHttpPolicy::class, self::judge());
        $door = new PanelDoorController($container);

        foreach (['goal' => 'agent:goal', 'skill' => 'skill:invoke', 'config' => 'config:set', 'provider' => 'provider:declare', 'turn' => 'agent'] as $method => $operation) {
            self::assertStringContainsString('judged: ' . $operation, (string) $door->{$method}(self::request())->getBody(), $operation);
        }
        // agent:model gained its scope in the same slice (app-runtime, decisions/0497). Before that release it
        // declares none — and then the door says so rather than run it for anyone: judged, or not opened, never run.
        $model = $door->model(self::request());
        $said = (string) $model->getBody();
        self::assertTrue(
            str_contains($said, 'judged: agent:model') || ($model->getStatusCode() === 501 && str_contains($said, 'declares no scope')),
            $said,
        );
    }

    public function testADoorDoesNotOpenAnOperationNoPolicyCanJudge(): void
    {
        // An operation with no scope is never put before the HTTP policy (decisions/0082): measured, the published
        // agent:model ran its provider probe through this door for a caller no judge had seen (decisions/0497).
        $ran = false;
        $unscoped = new Operation(
            name: PanelDoorController::MODEL,
            description: 'An operation that declares no scope',
            handler: static function () use (&$ran): array {
                $ran = true;

                return ['ok' => true];
            },
        );
        $container = new DIContainer();
        $container->registerService(OperationHttpPolicy::class, self::judge());
        $door = new PanelDoorController($container, static fn (string $name): ?Operation => $unscoped);

        $answer = $door->model(self::request());

        self::assertSame(501, $answer->getStatusCode());
        self::assertStringContainsString('declares no scope, so no policy can judge', (string) $answer->getBody());
        self::assertFalse($ran, 'the operation never ran');
    }

    /** @param list<string> $providers the operation providers the app declares; the inbox's two when unsaid */
    private function kernel(array $providers = ['Milpa\\AppRuntime\\Operations\\SessionOperations', 'Milpa\\AppRuntime\\Operations\\SequenceOperations']): Kernel
    {
        $root = sys_get_temp_dir() . '/milpa-panel-door-' . bin2hex(random_bytes(4));
        mkdir($root . '/config', 0o777, true);
        $this->dirs[] = $root;
        file_put_contents($root . '/config/operations.php', '<?php return ' . var_export($providers, true) . ';');
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $root, 'commands' => []] as $name => $value) {
            $p = new \ReflectionProperty(Kernel::class, $name);
            $p->setValue($kernel, $value);
        }
        $container = new \ReflectionProperty(Kernel::class, 'container');
        $container->setValue($kernel, new DIContainer());

        return $kernel;
    }

    private static function judge(): OperationHttpPolicy
    {
        return new class () implements OperationHttpPolicy {
            public function enforce(Operation $op, ServerRequestInterface $request): ?ResponseInterface
            {
                return new Response(403, ['Content-Type' => 'application/json'], '{"error":"judged: ' . $op->name . '"}');
            }
        };
    }

    /** The operation as its owner declares it — scoped and mutating — without needing that owner here. */
    private static function scoped(string $name): Operation
    {
        return new Operation(
            name: $name,
            description: 'A scoped, mutating operation',
            handler: static fn (): array => ['ok' => false],
            mutating: true,
            scopes: [$name],
            surfaces: ['cli', 'http'],
        );
    }

    private static function request(): ServerRequest
    {
        return (new ServerRequest('POST', '/workspace/answer', ['Content-Type' => 'application/json']))
            ->withBody(Stream::create('{"session":"camino-blog","answer":"no"}'));
    }
}
