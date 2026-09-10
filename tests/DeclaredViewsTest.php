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

namespace Milpa\AgentWorkspace\Tests;

use Milpa\AgentWorkspace\Controllers\AssetsController;
use Milpa\AgentWorkspace\Live\DesktopAssets;
use Milpa\AgentWorkspace\Live\DesktopComponentRenderer;
use Milpa\AgentWorkspace\Live\DesktopComponents;
use Milpa\AgentWorkspace\Live\TabsComponent;
use Milpa\Eventing\EventDispatcher;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Http\Routing\RouteResult;
use Milpa\Live\Contracts\Rendering\DeclaresClientAssets;
use Milpa\Live\ValueObjects\ClientAssets;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Live\ValueObjects\ComponentContext;
use Nyholm\Psr7\ServerRequest;
use Milpa\AgentWorkspace\Live\Surfaces;
use Milpa\AgentWorkspace\Live\DeepScreens;
use Milpa\AgentWorkspace\I18n\Catalog;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Declared views, ONE runtime per page (greenhouse decisions/0211).
 *
 * The old contract this replaces was a count of 35 element ids and a set of literal JS fragments in the
 * page: it pinned the shell's hand-stitching, so it could only ever say the stitching had not changed.
 * The new contract is what the doctrine actually claims:
 *
 *   (a) every `desktop-*` renderer DECLARES its own client files — and the declaration is true, the file
 *       is in the package (a `<link>` at a missing file 404s in silence, so the falsifier is the disk);
 *   (b) the page emits its runtime ONLY through `LiveBoot::html()`, in the documented order, each URL once
 *       — no hand-written runtime `<script>` tag survives anywhere in the document;
 *   (c) the page is COMPOSED through the compiler over the ONE registry, and `POST /desktop/live` is built
 *       over that same registry;
 *   (d) the per-component route family actually serves what the renderers declared, and refuses anything
 *       else.
 */
final class DeclaredViewsTest extends TestCase
{
    /** Every stylesheet the plain page's surfaces declare, in the order they are painted. */
    private const STYLE_ORDER = [
        'desktop-tabs', 'desktop-conversation', 'desktop-gate',
        'desktop-work-board', 'desktop-activity', 'desktop-context', 'desktop-composer', 'desktop-thinking',
        'desktop-agent-message', 'desktop-user-message', 'desktop-tool-call', 'desktop-task',
        'desktop-system-notice', 'desktop-result-claim', 'desktop-settings',
        'desktop-skills', 'desktop-screens', 'desktop-decisions',
    ];

    /**
     * A registry populated the way any host populates it.
     *
     * 🚨 IT USED TO BE `new ShellController(...)`, and that was the whole coupling in one line: the
     * declarations were a side effect of the page's controller existing (greenhouse decisions/0283).
     * There is no page to render here any more, and no `page()` helper — every property below is about
     * what the RENDERERS declare, which is why they survived the retirement.
     */
    private function registry(): DesktopComponents
    {
        $live = new DesktopComponents('signing', 'csrf', new EventDispatcher(new NullLogger()));
        (new Surfaces(null, new EventDispatcher(new NullLogger()), new Catalog()))->declareOn($live);
        DeepScreens::declareOn($live, null, null, new Catalog(), hidden: false);

        return $live;
    }

    public function testEveryDeclaredFileIsAFileThePackageShips(): void
    {
        // The falsifier for a lying declaration: a renderer may only name files that exist. Mutating the map
        // (an extension a component does not ship) makes this red — which is the point: a missing stylesheet
        // is invisible in a browser.
        self::assertNotSame([], DesktopAssets::declared());
        foreach (DesktopAssets::declared() as $component) {
            $assets = DesktopAssets::of($component);
            self::assertFalse($assets->isEmpty(), $component . ' declares at least one file');
            foreach ([...$assets->styles, ...$assets->scripts] as $url) {
                self::assertStringStartsWith(DesktopAssets::BASE, $url);
                $path = DesktopAssets::path(substr($url, \strlen(DesktopAssets::BASE)));
                self::assertNotNull($path, $url . ' resolves to a package path');
                self::assertFileExists($path, $url . ' is declared and must exist');
            }
        }
    }

    public function testEachRendererDeclaresExactlyItsOwnFiles(): void
    {
        // A renderer's assets are the RENDERER's, not the instance's: one renderer per component, each
        // naming its own css (and its own js when it has behaviour) and never another's.
        $components = $this->registry();
        $painted = 0;
        foreach ($components->names() as $name) {
            $renderer = $components->renderers()->resolveFor($name, RenderTarget::HTML);
            self::assertNotNull($renderer, $name . ' has a renderer');
            if (!str_starts_with($name, 'desktop-')) {
                continue; // the shipped form primitives are milpa/live's own, not declared views
            }
            ++$painted;
            self::assertInstanceOf(DeclaresClientAssets::class, $renderer, $name . ' declares its client assets');
            self::assertEquals(DesktopAssets::of($name), $renderer->clientAssets(), $name . ' declares exactly its own files');
        }
        self::assertSame(21, $painted, 'every workspace surface is a declared view — four fewer since the page took its chrome with it (greenhouse decisions/0283), and one fewer since the Capabilities screen was retired as a duplicate of the panel\'s own Plugins section (greenhouse decisions/0290)');
    }

    /**
     * Phase B's whole claim, as one falsifiable pair (greenhouse decisions/0211).
     *
     * A behaviour that MOVED is a behaviour that is gone from the page AND present in exactly one module.
     * Asserting only the first half would pass if the behaviour had simply been deleted; asserting only the
     * second would pass if the page still carried its own copy. The map below is the contract, read both
     * ways — and the shell's own script keeps only the groups a later slice moves.
     *
     * @return iterable<string, array{0: string, 1: list<string>, 2: list<string>}>
     */
    public static function movedBehaviours(): iterable
    {
        yield 'the tab switch' => ['desktop-tabs', ['select: function (key)', 'isActive: function (key)'], ['function showTab(']];
        yield 'the theme' => [['function apply(', 'function remember('], ['function applyTheme(', 'function persistTheme(', "getElementById('milpa-theme')"]];
        yield 'the gate fill' => ['desktop-gate', ['function intentHref(', "MilpaShell.on('gate.opened'"], ["MilpaShell.on('gate.opened'", 'function setGateOpen(']];
        yield 'the activity stream' => ['desktop-activity', ['record: function (type, data)'], ['MilpaShell.onAny(']];
        yield 'the settings save' => ['desktop-settings', ['save: function ()', 'discard: function ()'], ['function showSaved(', "getElementById('milpa-save-settings')"]];
        yield 'the session ceremony' => ['desktop-auth', ['enter: function ()'], ['function openNewSession(', "getElementById('milpa-auth-enter')"]];
        // Phase C: the conversation and its message kinds, the composer, the turn and the commands.
        yield 'the thread' => ['desktop-conversation', ['function append(kind, opts)', 'd.onNotice(function (notice)'], ['function appendMessage(', 'var MSG_PROTOS =', 'function region(root, selector)']];
        yield 'the thinking block' => ['desktop-thinking', ['delta: function (thread, text)', 'end: function ()'], ['function appendReasoning(', 'function endReasoning(']];
        yield "the answer's markdown and tools" => ['desktop-agent-message', ['function renderMarkdown(', 'verdict: function (thread, ok, reasons)'], ['function renderMarkdown(', 'function markAgentVerdict(', '[data-agent-copy]']];
        yield "a tool result's reading" => ['desktop-tool-call', ['function summary(raw)', 'function pretty(raw)'], ['function toolSummary(', 'function prettyMaybe(']];
        yield 'the closure claim' => ['desktop-result-claim', ['fill: function (root, opts, at)'], ['var ok = o.verified !== false;']];
        yield 'the composer' => ['desktop-composer', ['send: function ()', 'applyMode: function (key)'], ['function send(', 'function applyMode(', 'function setComposerText(', "getElementById('milpa-charcount')"]];
        yield 'the governed turn' => ['desktop-turn', ['function run(text)', 'function counters(result)'], ['function runTurn(', 'function updateCounters(', "fetch('/agent'"]];
        yield 'the slash commands' => ['desktop-commands', ['function parse(text)', 'function handlesKey(event)'], ['function parseCommand(', 'function runCommand(', 'function callOp(', 'function opFailure(', 'function refreshCommandList(']];
        // Phase D: the bus, the transport, and the four screens whose behaviour the page still carried.
        yield 'the shell bus' => ['desktop-shell-bus', ['window.MilpaShell = { on: on', 'function status(state)'], ['window.MilpaShell = (function ()', 'statusHandlers.forEach']];
        yield 'the hub connector' => ['desktop-hub', ['function translate(env)', 'new EventSource(url'], ['new EventSource(', "window.MilpaShell.status('offline')", 'MilpaShell.session(env)']];
        yield "the work board's drag" => ['desktop-work-board', ['onDragStart: function (event)', 'onDrop: function (event)'], ["querySelector('.work-board')", "fetch('/workspace/work'", 'col.style.background']];
        yield 'the screen preview' => ['desktop-screens', ['function preview()', 'function chip(button)'], ["getElementById('milpa-preview-frame')", "getElementById('milpa-preview-name')", 'frame.src = src']];
        yield 'the live inbox' => ['desktop-decisions', ['function parked(question)', "bus.on('decision.parked'"], ['addDecision: function (question)', "getElementById('milpa-decisions-list')", "createElement('ol')"]];
    }

    public function testARendererRefusesAComponentItDoesNotAnswerFor(): void
    {
        $renderer = new DesktopComponentRenderer('desktop-tabs', static fn (array $props): string => 'painted');

        self::assertSame('desktop-tabs', $renderer->component());
        self::assertTrue($renderer->supportsTarget(RenderTarget::HTML));
        self::assertFalse($renderer->supportsTarget(RenderTarget::TUI));

        $request = new RenderRequest(context: new ComponentContext(componentId: 'x'));
        self::assertSame('painted', $renderer->render(new TabsComponent(), $request)->output);

        // A DIFFERENT component: the renderer answers for exactly one name.
        $this->expectException(\InvalidArgumentException::class);
        $renderer->render(new \Milpa\AgentWorkspace\Live\GateComponent(), $request);
    }

    public function testARendererRefusesATargetItDoesNotPaint(): void
    {
        $renderer = new DesktopComponentRenderer('desktop-tabs', static fn (array $props): string => 'painted', new ClientAssets());

        $this->expectException(\InvalidArgumentException::class);
        $renderer->render(new TabsComponent(), new RenderRequest(context: new ComponentContext(componentId: 'x'), target: RenderTarget::TUI));
    }

    public function testTheRegistryAnswersForAComponentDeclaredAfterTheEndpointWasBuilt(): void
    {
        // The registry is shared by reference: the endpoint holds it, so a surface declared later is still
        // resolvable over the wire — that is what makes "one registry, page and endpoint" true.
        $registry = new DesktopComponents('sign', 'csrf');
        $endpoint = $registry->endpoint();
        self::assertFalse($registry->has('desktop-tabs'));

        $registry->declare(new TabsComponent(), static fn (array $props): string => 'late');

        self::assertTrue($registry->has('desktop-tabs'));
        self::assertNotNull($registry->renderers()->resolveFor('desktop-tabs', RenderTarget::HTML));
        self::assertNotSame('', $registry->csrfToken('sess-1'));
        self::assertSame(405, $endpoint->handle(new \Milpa\Live\Http\LiveHttpRequest('GET', 'change', '', [], 'sess', ''))->status, 'the same endpoint object still answers');
    }

    public function testTheComponentAssetRouteServesWhatTheRenderersDeclaredAndNothingElse(): void
    {
        $controller = new AssetsController();
        $route = new Route(path: DesktopAssets::BASE . '{file}', methods: HttpMethod::GET, handler: new HandlerReference(AssetsController::class, 'component'));
        $serve = static function (string $file) use ($controller, $route) {
            $request = (new ServerRequest('GET', DesktopAssets::BASE . $file))
                ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($route, ['file' => $file]));

            return $controller->component($request);
        };

        $css = $serve('desktop-thinking.css');
        self::assertSame(200, $css->getStatusCode());
        self::assertStringContainsString('text/css', $css->getHeaderLine('Content-Type'));
        self::assertStringContainsString('.milpa-think', (string) $css->getBody());

        $js = $serve('desktop-guard.js');
        self::assertSame(200, $js->getStatusCode());
        self::assertStringContainsString('javascript', $js->getHeaderLine('Content-Type'));
        self::assertStringContainsString('MilpaLive', (string) $js->getBody());

        // The URL carries NO version, and the file behind it changes with every release — so it may not be
        // immutable for a year: a browser holding last release's module against this release's markup is a
        // dead surface. One hour, the same policy the runtime files get (LiveController::serveAsset()).
        foreach ([$css, $js] as $response) {
            self::assertSame(AssetsController::BEHAVIOUR_CACHE, $response->getHeaderLine('Cache-Control'));
            self::assertStringNotContainsString('immutable', $response->getHeaderLine('Cache-Control'));
        }

        // Anything the map does not name is a 404 — never a guess at a path, never a traversal.
        foreach (['desktop-user-message.js', 'desktop-task.js', 'unknown.css', '../../composer.json', 'desktop-guard.css', 'desktop-turn.css', 'desktop-guard'] as $refused) {
            self::assertSame(404, $serve($refused)->getStatusCode(), $refused . ' is refused');
        }
        // And a request that carries no route result at all.
        self::assertSame(404, $controller->component(new ServerRequest('GET', DesktopAssets::BASE))->getStatusCode());
    }
}
