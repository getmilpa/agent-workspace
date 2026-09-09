<?php

/**
 * This file is part of milpa/agent-workspace — the agent's workspace, a section of the Milpa panel.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent-workspace
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Tests;

use Milpa\AgentWorkspace\AgentWorkspacePlugin;
use Milpa\AgentWorkspace\Controllers\ShellController;
use Milpa\AgentWorkspace\Event\AgentWorkspaceEvents;
use Milpa\Container\DIContainer;
use Milpa\Interfaces\Event\DeclaredEvents;
use Milpa\Interfaces\Event\EventDeclaration;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The falsifier of greenhouse decisions/0228 for this package: what the workspace DECLARES to the
 * dispatcher is exactly what it DISPATCHES — measured by execution, not by reading.
 *
 * A spy dispatcher that also keeps declarations is handed to the plugin the way the kernel hands it
 * (registered in the container before `boot()`); the shell is then served in both its modes, which
 * paints every surface and every prototype. Then: every name dispatched FROM THIS PACKAGE was declared
 * (no undeclared dispatch), the declared names are exactly the list written here (a deleted or renamed
 * declaration goes red), every declared name actually fired (a declaration nothing dispatches is a
 * lie of the other kind), and each declaration's payload key and subject type are what the dispatched
 * payload carried. The control: a dispatcher that keeps no declarations serves the same shell untouched.
 *
 * «From this package» is MEASURED, not assumed: the spy records the file each `dispatch()` was called
 * from. Rendering a Desktop surface also fires milpa/live's own `component.mounting` / `component.mounted`
 * around every `mount()` — those are that package's to declare where it holds the dispatcher, not this
 * one's to claim, and the test asserts they come from vendor code so a name that starts being dispatched
 * from `src/` cannot hide among them.
 *
 * A DECLARATION is sorted the same way, and for the same reason. One dispatcher receives the declarations
 * of every emitter built against it, so once milpa/live's `LiveEventEmitter` began declaring its own eight
 * names (v0.22.0) the spy heard them too — this package's list did not change, the room did. So a
 * declaration is counted as ours when `dispatchedBy` resolves to a class whose file lives under `src/`,
 * and each foreign one is asserted to come from `vendor/`. A count is not used to tell them apart: a
 * dependency may declare one more tomorrow, and that must not turn this package's falsifier red.
 */
final class TheWorkspaceDeclaresEveryEventItDispatchesTest extends TestCase
{
    /**
     * The exact names, hardcoded on purpose: the declaration is built from constants, and this list is
     * the one place that would notice a constant quietly renamed.
     */
    private const array EXPECTED = [
        'desktop.shell.compose',
        'desktop.sidebar.before_render', 'desktop.sidebar.after_render',
        'desktop.topbar.before_render', 'desktop.topbar.after_render',
        'desktop.session_strip.before_render', 'desktop.session_strip.after_render',
        'desktop.tabs.before_render', 'desktop.tabs.after_render',
        'desktop.conversation.before_render', 'desktop.conversation.after_render',
        'desktop.gate.before_render', 'desktop.gate.after_render',
        'desktop.work_board.before_render', 'desktop.work_board.after_render',
        'desktop.activity.before_render', 'desktop.activity.after_render',
        'desktop.context.before_render', 'desktop.context.after_render',
        'desktop.composer_bar.before_render', 'desktop.composer_bar.after_render',
        'desktop.composer.before_render', 'desktop.composer.after_render',
        'desktop.thinking.before_render', 'desktop.thinking.after_render',
        'desktop.agent_message.before_render', 'desktop.agent_message.after_render',
        'desktop.user_message.before_render', 'desktop.user_message.after_render',
        'desktop.tool_call.before_render', 'desktop.tool_call.after_render',
        'desktop.task.before_render', 'desktop.task.after_render',
        'desktop.system_notice.before_render', 'desktop.system_notice.after_render',
        'desktop.result_claim.before_render', 'desktop.result_claim.after_render',
        // The turn's own contents (greenhouse decisions/0254).
        'desktop.ask_grant.before_render', 'desktop.ask_grant.after_render',
        'desktop.compacted.before_render', 'desktop.compacted.after_render',
        'desktop.settings.before_render', 'desktop.settings.after_render',
        'desktop.capabilities.before_render', 'desktop.capabilities.after_render',
        'desktop.skills.before_render', 'desktop.skills.after_render',
        'desktop.screens.before_render', 'desktop.screens.after_render',
        'desktop.decisions.before_render', 'desktop.decisions.after_render',
        'desktop.statusbar.before_render', 'desktop.statusbar.after_render',
        'desktop.auth.before_render', 'desktop.auth.after_render',
    ];

    public function testWhatItDeclaresIsExactlyWhatItDispatches(): void
    {
        $spy = self::spy();
        $container = new DIContainer();
        // The kernel registers the dispatcher before any plugin boots; the plugin declares where it receives it.
        $container->registerService(MilpaEventDispatcherInterface::class, $spy);
        (new AgentWorkspacePlugin($container))->boot();

        $declared = self::declaredHere($spy->declared());
        $names = array_map(static fn (EventDeclaration $d): string => $d->name, $declared);

        // (b) The declared set is exactly the expected list — no more, no fewer, none twice.
        self::assertCount(\count(self::EXPECTED), $names, 'one declaration per name');
        $expected = self::EXPECTED;
        sort($expected);
        sort($names);
        self::assertSame($expected, $names, 'the declared names are exactly the expected ones');

        // Drive the real path: the shell served in both modes paints every surface and every prototype.
        $controller = $container->get(ShellController::class);
        self::assertInstanceOf(ShellController::class, $controller);
        $controller->shell(new ServerRequest('GET', '/desktop'));
        $controller->shell(new ServerRequest('GET', '/desktop?embed=1'));

        $dispatched = $spy->dispatched();
        $src = \dirname(__DIR__) . '/src/';
        $ours = array_values(array_filter($dispatched, static fn (string $name): bool => str_starts_with($spy->origins[$name], $src)));
        $foreign = array_values(array_diff($dispatched, $ours));

        // (a) No undeclared dispatch from this package — and no declaration nothing dispatches.
        self::assertSame([], array_values(array_diff($ours, $names)), 'every name dispatched from src/ was declared');
        self::assertSame([], array_values(array_diff($names, $dispatched)), 'every declared name was dispatched by the render');

        // What a dependency dispatches is that dependency's to declare, so it is named here rather than
        // claimed — and it is proven foreign by where the call came from, not by its spelling.
        self::assertSame(['component.mounting', 'component.mounted'], $foreign, 'the only foreign names are milpa/live\'s mount pair');
        foreach ($foreign as $name) {
            self::assertStringContainsString('/vendor/milpa/live/', $spy->origins[$name], $name . ' is dispatched by milpa/live, not by this package');
        }

        // (c) Each declaration describes the payload its name actually carried.
        foreach ($declared as $declaration) {
            $payload = $spy->payloads[$declaration->name];
            self::assertArrayHasKey($declaration->subjectKey, $payload, $declaration->name . ' carries its subject under the declared key');
            self::assertNotNull($declaration->subjectType, $declaration->name . ' names its subject type');
            self::assertInstanceOf($declaration->subjectType, $payload[$declaration->subjectKey], $declaration->name . ' carries the declared subject type');
            self::assertTrue($declaration->mutable, $declaration->name . ' carries a subject the subscriber may change');
            self::assertFalse($declaration->interceptable, $declaration->name . ' carries no interception slot');
            self::assertArrayNotHasKey('slot', $payload, $declaration->name . ' carries no interception slot');
            self::assertTrue(class_exists($declaration->dispatchedBy), $declaration->name . ' is dispatched by a class that exists');
            self::assertNotSame('', $declaration->when);
        }
    }

    public function testTheDeclarationsAreBuiltOnceAndDeclaredToTheDispatcherAsIs(): void
    {
        $spy = self::spy();
        $container = new DIContainer();
        $container->registerService(MilpaEventDispatcherInterface::class, $spy);
        (new AgentWorkspacePlugin($container))->boot();

        self::assertEquals(AgentWorkspaceEvents::declarations(), self::declaredHere($spy->declared()), 'the plugin declares the holder\'s list, unchanged');
    }

    /**
     * The declarations this package made, told apart from a dependency's by WHERE the declaring class lives.
     *
     * The dispatcher is shared, so an emitter a Desktop surface builds declares into the same spy. Sorting
     * by the file behind `dispatchedBy` — not by a name or a count — keeps that honest in both directions:
     * a foreign declaration cannot inflate this package's list, and a name that starts being declared from
     * `src/` cannot hide among the foreign ones.
     *
     * @param list<EventDeclaration> $declared
     *
     * @return list<EventDeclaration>
     */
    private static function declaredHere(array $declared): array
    {
        $src = \dirname(__DIR__) . '/src/';
        $ours = [];
        foreach ($declared as $declaration) {
            $file = (new \ReflectionClass($declaration->dispatchedBy))->getFileName();
            self::assertIsString($file, $declaration->name . ' is declared by a class with a file');
            if (str_starts_with($file, $src)) {
                $ours[] = $declaration;
                continue;
            }
            self::assertStringContainsString('/vendor/', $file, $declaration->name . ' is declared by a dependency, not by this package');
        }

        return $ours;
    }

    /**
     * (d) The control: a dispatcher that keeps no declarations is asked nothing, and the shell renders as before.
     *
     * The control is BUILT rather than borrowed. It used to be `Milpa\Eventing\EventDispatcher`, and the
     * family's own dispatcher has since implemented `DeclaredEvents` — so naming a concrete class as «the
     * one without the contract» is a moving target that turns the control green for the wrong reason. An
     * anonymous class implementing only {@see MilpaEventDispatcherInterface} cannot drift that way.
     */
    public function testADispatcherThatKeepsNoDeclarationsStillServesTheShell(): void
    {
        $plain = self::plainDispatcher();
        self::assertNotInstanceOf(DeclaredEvents::class, $plain, 'the control is a dispatcher without the contract');

        $container = new DIContainer();
        $container->registerService(MilpaEventDispatcherInterface::class, $plain);
        (new AgentWorkspacePlugin($container))->boot();

        $controller = $container->get(ShellController::class);
        self::assertInstanceOf(ShellController::class, $controller);
        $body = (string) $controller->shell(new ServerRequest('GET', '/desktop'))->getBody();
        self::assertStringContainsString('Milpa Desktop', $body);
        self::assertStringContainsString('data-milpa-component="desktop-sidebar"', $body);
    }

    /** A dispatcher that implements the dispatch contract and NOTHING else — the control's whole point. */
    private static function plainDispatcher(): MilpaEventDispatcherInterface
    {
        return new class () implements MilpaEventDispatcherInterface {
            /** @var array<string, list<callable>> */
            private array $handlers = [];

            public function dispatch(string $eventName, array $payload = [], bool $async = false): void
            {
                foreach ($this->handlers[$eventName] ?? [] as $handler) {
                    $handler($eventName, $payload);
                }
            }

            public function subscribe(string $eventName, callable $handler, int $priority = 0): void
            {
                $this->handlers[$eventName][] = $handler;
            }

            public function getSubscribers(string $eventName): array
            {
                return $this->handlers[$eventName] ?? [];
            }

            public function hasSubscribers(string $eventName): bool
            {
                return ($this->handlers[$eventName] ?? []) !== [];
            }
        };
    }

    /**
     * A dispatcher that keeps declarations AND records what it dispatched — with the payload each name
     * carried the first time and the FILE the call came from, so both a declaration and its authorship
     * can be checked against the real thing.
     *
     * @return MilpaEventDispatcherInterface&DeclaredEvents&object{payloads: array<string, array<string, mixed>>, origins: array<string, string>}
     */
    private static function spy(): MilpaEventDispatcherInterface&DeclaredEvents
    {
        return new class () implements MilpaEventDispatcherInterface, DeclaredEvents {
            /** @var array<string, array<string, mixed>> */
            public array $payloads = [];

            /** @var array<string, string> the file each name was first dispatched from */
            public array $origins = [];

            /** @var list<EventDeclaration> */
            private array $declared = [];

            /** @var list<string> */
            private array $dispatched = [];

            /** @var array<string, list<callable>> */
            private array $handlers = [];

            public function declare(EventDeclaration ...$events): void
            {
                $known = array_map(static fn (EventDeclaration $d): string => $d->name, $this->declared);
                foreach ($events as $event) {
                    if (!\in_array($event->name, $known, true)) {
                        $this->declared[] = $event;
                        $known[] = $event->name;
                    }
                }
            }

            public function declared(): array
            {
                return $this->declared;
            }

            public function dispatched(): array
            {
                return $this->dispatched;
            }

            public function dispatch(string $eventName, array $payload = [], bool $async = false): void
            {
                if (!\in_array($eventName, $this->dispatched, true)) {
                    $this->dispatched[] = $eventName;
                    $this->payloads[$eventName] = $payload;
                    $frame = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
                    $this->origins[$eventName] = \is_string($frame['file'] ?? null) ? $frame['file'] : '';
                }
                foreach ($this->handlers[$eventName] ?? [] as $handler) {
                    $handler($eventName, $payload);
                }
            }

            public function subscribe(string $eventName, callable $handler, int $priority = 0): void
            {
                $this->handlers[$eventName][] = $handler;
            }

            public function getSubscribers(string $eventName): array
            {
                return $this->handlers[$eventName] ?? [];
            }

            public function hasSubscribers(string $eventName): bool
            {
                return ($this->handlers[$eventName] ?? []) !== [];
            }
        };
    }
}
