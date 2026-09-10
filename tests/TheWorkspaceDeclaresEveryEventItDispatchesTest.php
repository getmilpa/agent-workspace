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
use Milpa\AgentWorkspace\Event\AgentWorkspaceEvents;
use Milpa\Container\DIContainer;
use Milpa\Interfaces\Event\DeclaredEvents;
use Milpa\Interfaces\Event\EventDeclaration;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
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
        // 🚨 LOS DOS DEL SUBAGENTS: `declarations()` no los esparcía, así que este censo esperaba 47
        // donde el paquete declara 49 — y la lista esperada estaba corta por exactamente esos dos, que
        // es la forma en que un censo que no se mide contra el código se queda atrás
        // (greenhouse decisions/0228, decisions/0283).
        'desktop.subagents.before_render',
        'desktop.subagents.after_render',
        'desktop.skills.before_render', 'desktop.skills.after_render',
        'desktop.screens.before_render', 'desktop.screens.after_render',
        'desktop.decisions.before_render', 'desktop.decisions.after_render',
    ];

    public function testWhatItDeclaresIsExactlyWhatItDispatches(): void
    {
        $spy = self::spy();
        $container = new DIContainer();
        // The kernel registers the dispatcher before any plugin boots; the plugin declares where it receives it.
        $container->registerService(MilpaEventDispatcherInterface::class, $spy);
        $plugin = new AgentWorkspacePlugin($container);
        $plugin->boot();

        $declared = self::declaredHere($spy->declared());
        $names = array_map(static fn (EventDeclaration $d): string => $d->name, $declared);

        // (b) The declared set is exactly the expected list — no more, no fewer, none twice.
        self::assertCount(\count(self::EXPECTED), $names, 'one declaration per name');
        $expected = self::EXPECTED;
        sort($expected);
        sort($names);
        self::assertSame($expected, $names, 'the declared names are exactly the expected ones');

        // 🚨 THE REAL PATH IS THE PANEL'S REGION, and it used to be the page served in both modes.
        // Which is why this census could not have caught the coupling: driving the page dispatched every
        // surface's events because the page painted every surface (greenhouse decisions/0283).
        self::region($container, $spy);
        // AND THE DEEP SCREENS, which are SECTIONS of their own — the Agent region does not paint them,
        // so a census that drove only the region would miss ten render events and call the difference a
        // declaration nothing dispatches. Two doors, both driven (greenhouse decisions/0268, 0283).
        self::screens($container, $plugin);

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
        $plugin = new AgentWorkspacePlugin($container);
        $plugin->boot();

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
        $plugin = new AgentWorkspacePlugin($container);
        $plugin->boot();

        // The subject is the PANEL's region now: a dispatcher with no declaration contract must still
        // let every surface paint (greenhouse decisions/0283).
        $body = self::region($container, $plain);
        self::assertStringContainsString('data-milpa-component="desktop-composer"', $body);
        // NAMES what failed rather than counting: a count tells you something broke, and this tells you
        // which surface, which is the difference between a red test and a fixable one.
        preg_match_all('/data-failed-component="([a-z-]+)"/', $body, $failed);
        self::assertSame([], $failed[1], 'every surface paints under a dispatcher with no declaration contract');
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

    /** Paint the panel's Agent region, which is what dispatches every surface's render events now. */
    private static function region(\Milpa\Interfaces\Di\DIContainerInterface $container, mixed $events): string
    {
        $live = $container->get(\Milpa\AgentWorkspace\Live\DesktopComponents::class);

        return (new \Milpa\AgentWorkspace\Admin\AgentViewRenderer(
            $live,
            $container->has(\Milpa\AgentWorkspace\Data\DesktopData::class) ? $container->get(\Milpa\AgentWorkspace\Data\DesktopData::class) : null,
            null,
            '',
            $events,
        ))->render(
            new \Milpa\AgentWorkspace\Admin\AgentViewComponent(),
            new \Milpa\Live\ValueObjects\RenderRequest(
                new \Milpa\Live\ValueObjects\ComponentContext('milpa-admin-section-agent', route: '/milpa/admin'),
                ['gate' => 'loopback'],
            ),
        )->output;
    }

    /**
     * Paint the deep screens the way their sections do — each is its own admin section.
     *
     * 🚨 IT ASKS THE PLUGIN FOR ITS SECTIONS, and does not re-declare them here. They are declared in
     * `adminSections()`, which is what the admin calls on every request; a test that called
     * `DeepScreens::declareOn` itself would be proving a wiring nobody has — the exact mistake the page
     * era left in `TheRegionRepliesTheSessionsThreadTest` (greenhouse decisions/0283).
     *
     * Measured while writing this: a bare `Kernel::boot()` declares 19 surfaces and NO deep screens,
     * because the page's controller used to declare them at boot as a side effect.
     */
    private static function screens(\Milpa\Interfaces\Di\DIContainerInterface $container, AgentWorkspacePlugin $plugin): void
    {
        $plugin->adminSections();
        // 🚨 THE SCREEN DISPATCHES ITS OWN RENDER EVENTS, so the real path is `render()` on each of
        // them — not a compile of their tags. Measured while writing this: compiling
        // `<milpa-desktop-settings/>` off the registry returns ZERO bytes without the props a host
        // passes, so a census built on the compiler would have driven nothing and called every deep
        // screen's pair «a declaration nothing dispatches» (greenhouse decisions/0283).
        $events = $container->get(\Milpa\Interfaces\Event\MilpaEventDispatcherInterface::class);
        $catalog = new \Milpa\AgentWorkspace\I18n\Catalog();
        $secret = str_repeat('k', 32);
        $codec = new \Milpa\Live\Security\SignedXhtmlStateTransferCodec(
            new \Milpa\Live\Transport\XhtmlStateTransferCodec(),
            new \Milpa\Live\Security\HmacStateSigner($secret),
            null,
        );
        (new \Milpa\AgentWorkspace\Live\SettingsScreen($secret, null, $events, $catalog))->render();
        (new \Milpa\AgentWorkspace\Live\SkillsScreen($codec, null, $events, $catalog))->render();
        (new \Milpa\AgentWorkspace\Live\DecisionsInbox($codec, null, $events, $catalog))->render();
        (new \Milpa\AgentWorkspace\Live\SubagentsScreen($codec, null, $events, $catalog))->render();
        (new \Milpa\AgentWorkspace\Live\ScreenPreview($codec, null, $events, $catalog))->render();
    }
}
