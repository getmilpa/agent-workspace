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

namespace Milpa\AgentWorkspace\Tests\Admin;

use Milpa\Admin\AdminPlugin;
use Milpa\Admin\Section\SectionCatalogue;
use Milpa\Container\DIContainer;
use Milpa\AgentWorkspace\Admin\AgentViewComponent;
use Milpa\AgentWorkspace\AgentWorkspacePlugin;
use Milpa\AgentWorkspace\DesktopSettings;
use Milpa\AgentWorkspace\Live\DesktopAssets;
use Milpa\AgentWorkspace\Live\DesktopComponents;
use Milpa\AgentWorkspace\Tests\Fixtures\PasskeyGateStub;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Runtime\Http\RequestHandler;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The Desktop as the admin's guest, by execution (greenhouse decisions/0210, 0211 slice 3): the REAL
 * milpa/admin panel booted by the real kernel next to the Desktop plugin — a plugin the admin never heard
 * of, found by `instanceof` over the booted plugins — and asked for its section.
 *
 * WHAT CHANGED WITH SLICE 3, and what this file therefore asserts. The section used to render an
 * `<iframe src="/desktop?embed=1">`: a frame, a second document, a second runtime. It now declares a VIEW
 * — the Desktop's own components, with the Desktop's own definitions and renderers — which the admin
 * registers, compiles into its main and emits ONE runtime for. So the assertions moved from «the frame
 * points at the embed path» to «the conversation is IN this document, once, with every file it declared
 * emitted once by the host's LiveBoot».
 */
final class AdminGuestTest extends TestCase
{
    public function testTheAdminListsTheAgentSectionAndComposesTheDesktopInsideItWithNoFrame(): void
    {
        [, $kernel] = self::boot([AdminPlugin::class, AgentWorkspacePlugin::class]);

        // Discovery: the section, its group and who declared it — what the admin's catalogue KNOWS.
        $catalogue = SectionCatalogue::discover($kernel->plugins());
        $agent = $catalogue->find('agent');
        self::assertNotNull($agent, 'the admin discovered the Desktop\'s section');
        self::assertSame('agent', $agent->group, 'under the AGENT group');
        self::assertSame(60, $agent->order, 'after the host\'s own 10..40 (greenhouse decisions/0210)');
        self::assertSame(AgentWorkspacePlugin::class, $catalogue->declaredBy('agent'), 'declared by the Desktop plugin');
        self::assertTrue($agent->hasView(), 'a whole view, not one component (greenhouse decisions/0211)');

        // The sidebar (milpa/admin ≥ 0.11): the item sits under the AGENT group heading, with its glyph.
        $index = self::dispatch($kernel, '/milpa/admin');
        self::assertSame(200, $index->getStatusCode());
        $indexHtml = (string) $index->getBody();
        self::assertMatchesRegularExpression('~data-group="agent"[^>]*>\s*<span class="mui-sidebar__section-label"[^>]*>AGENT</span>~', $indexHtml, 'the AGENT group, painted by the host (its heading id is positional)');
        self::assertMatchesRegularExpression(
            '~<a class="mui-sidebar__item" href="/milpa/admin/s/agent"( aria-current="page")?><span class="mui-sidebar__item-icon" aria-hidden="true">◈</span><span class="mui-sidebar__item-label">Agent</span></a>~',
            $indexHtml,
        );
        // ORDER 60 SITS AFTER THE HOST'S OWN SECTIONS, and what the panel opens on is the host's
        // decision, not this guest's. It used to be Plugins because `order: 10` won a flat sort and
        // nobody chose it; milpa/admin 0.23 made the landing a decision (greenhouse decisions/0264).
        // What this test is FOR is unchanged and is the sentence below: the panel never opens on the
        // guest.
        self::assertNotSame('agent', $catalogue->first()?->id, 'the panel never opens on the guest');
        self::assertSame('house', $catalogue->first()?->id, 'and the host decided which of its own it opens on');
        self::assertStringContainsString('href="/milpa/admin/s/house" aria-current="page"', $indexHtml);

        $section = self::dispatch($kernel, '/milpa/admin/s/agent');
        self::assertSame(200, $section->getStatusCode());
        $html = (string) $section->getBody();

        // The HOST puts the header and the attribution; the guest emits the region.
        self::assertStringContainsString('<span class="mui-kbd">Agent</span>', $html, 'the host header names the section');
        self::assertStringContainsString('<title>Agent · Milpa Admin</title>', $html);
        self::assertStringContainsString('data-declared-by="Milpa\AgentWorkspace\AgentWorkspacePlugin">declared by AgentWorkspacePlugin</span>', $html);
        self::assertStringContainsString('<div class="desktop-agent" id="milpa-agent" data-desktop-agent="live" data-gate="loopback">', $html);

        // THE RETIREMENT, asserted: no frame anywhere on the page, and no embed path pointed at.
        self::assertStringNotContainsString('<iframe', $html, 'the frame of decisions/0210 is gone');
        self::assertStringNotContainsString('embed=1', $html);

        // The conversation, the composer and the consent gate are IN this document.
        self::assertStringContainsString('id="milpa-chat"', $html, 'the thread the conversation module drives');
        self::assertStringContainsString('x-data="desktopConversation()"', $html);
        self::assertStringContainsString('id="milpa-composer-dock"', $html);
        self::assertStringContainsString('id="milpa-send"', $html, 'the send button of the composer');
        self::assertStringContainsString('id="milpa-gate"', $html, 'the consent gate rides the conversation');
        self::assertStringContainsString('<template id="milpa-thinking-proto">', $html, 'the message prototypes the thread clones');
        self::assertStringContainsString('<template id="milpa-result-msg-proto">', $html);

        // The guest bar keeps its purpose: which door, and the way out to the Desktop's own page.
        self::assertStringContainsString('data-gate="loopback">gate: loopback</span>', $html, 'the guest bar says the Desktop\'s gate');
        // 🚨 THERE IS NO «Open the Desktop» BUTTON, and nothing replaced it: there is nowhere to open.
        // It was an `<a target="_blank">` to `/desktop`, which the retirement turned into a link to a
        // 404 — measured on cattle, the section still painted it while the route answered 404. The
        // panel IS the workspace now (greenhouse decisions/0283).
        self::assertStringNotContainsString('desktop-agent__open', $html);
        self::assertStringNotContainsString('href="/desktop"', $html);
    }

    /**
     * ONE REGION, ONE LANGUAGE — the guest's declared one, whatever the host's `?lang=` says.
     *
     * The panel's chrome follows the request; the region does NOT. The composed surfaces are the shell's
     * own instances, each holding the catalog `desktop.locale` declared, and the page's signal seeds were
     * declared with that same catalog — so a bar that followed `?lang=` would be the only Spanish thing on
     * an English conversation, and the mode chip would read one language until the first module rewrote it
     * in the other. What DOES follow the request is the way back: the sign-in `next` keeps `?lang=`.
     */
    public function testTheRegionSpeaksTheDeclaredLocaleEvenWhenTheHostAnswersInAnother(): void
    {
        [, $kernel] = self::boot([AdminPlugin::class, AgentWorkspacePlugin::class]);
        $spanish = (string) self::dispatch($kernel, '/milpa/admin/s/agent?lang=es')->getBody();

        // The HOST answered in Spanish: its own chrome, its own chips, its own seeds.
        self::assertStringContainsString('<html lang="es"', $spanish, 'the panel followed the request');
        self::assertStringContainsString('"admin.locale":"es"', $spanish);

        // The GUEST's region, all of it, in the locale the Desktop declared — bar, surfaces, client
        // catalog and seeds alike.
        self::assertStringContainsString('gate: loopback', $spanish, 'the bar');
        self::assertStringContainsString('data-gate="loopback">gate: loopback</span>', $spanish, 'and its gate chip');
        self::assertStringContainsString('placeholder="Write to the session…"', $spanish, 'a server-rendered surface');
        self::assertStringNotContainsString('Escribe a la sesión', $spanish);
        self::assertStringContainsString('"composer.mode.label":"Ask before changing"', $spanish, 'the seed the chip shows');
        self::assertMatchesRegularExpression('~"session\.state\.idle":"Idle"~', $spanish, 'and the client catalog the modules read');
        self::assertStringNotContainsString('"session.state.idle":"Inactivo"', $spanish, 'never the other language on the same page');
    }

    /**
     * ONE runtime for the whole page, and every file the guest DECLARED emitted once by the HOST
     * (greenhouse decisions/0211): the guest loads no Alpine, no `milpa-live`, no boot of its own.
     */
    public function testTheHostEmitsOneRuntimeAndEveryDeclaredFileExactlyOnce(): void
    {
        [, $kernel] = self::boot([AdminPlugin::class, AgentWorkspacePlugin::class]);
        $html = (string) self::dispatch($kernel, '/milpa/admin/s/agent')->getBody();

        foreach (['alpine.min.js', 'milpa-live.js', 'milpa-live-remote.js'] as $runtime) {
            self::assertSame(1, preg_match_all('~src="[^"]*' . preg_quote($runtime, '~') . '"~', $html), $runtime . ' is emitted exactly once for the page');
        }
        self::assertSame(1, substr_count($html, 'id="milpa-live-boot"'), 'one boot');
        self::assertSame(1, substr_count($html, 'id="milpa-live-signals"'), 'one signals seed');
        // Alpine goes last of the scripts — the runtime cannot be guarded, only emitted once (evidence/0524).
        self::assertGreaterThan(strrpos($html, 'milpa-live-remote.js'), strrpos($html, 'alpine.min.js'));

        // Every file the guest's renderers declared: present, and present ONCE.
        $declared = 0;
        foreach (DesktopAssets::declared() as $component) {
            foreach (DesktopAssets::of($component)->toArray() as $urls) {
                foreach ((array) $urls as $url) {
                    $seen = substr_count($html, '"' . $url . '"');
                    self::assertLessThanOrEqual(1, $seen, $url . ' is never emitted twice');
                    $declared += $seen;
                }
            }
        }
        self::assertGreaterThan(20, $declared, 'the region declared its surfaces\' files, not a handful');

        // The five runtime modules the page owns lead, and the region's own frame is among the styles.
        foreach (DesktopAssets::runtimeModules() as $module) {
            self::assertStringContainsString('src="' . DesktopAssets::url($module, 'js') . '"', $html, $module . ' reaches the host\'s boot');
        }
        self::assertStringContainsString('href="' . DesktopAssets::url('desktop-agent', 'css') . '"', $html);
        // …and nothing of the retired frame is served or asked for any more.
        self::assertStringNotContainsString('desktop-agent-guest', $html);
        self::assertNull(DesktopAssets::path('desktop-agent-guest.js'));

        // The seeds the view declared reached the page's own tag, merged with the panel's — DECODED
        // rather than pattern-matched. The old regex bounded itself with `[^<]*`, which was a cheap way
        // to stay inside the tag and broke the moment the payload legitimately carried a bracket: the
        // catalog rides here now, and `command.goal.none` reads «/goal <text> sets one»
        // (greenhouse decisions/0277). Decoding asserts the property instead of the punctuation.
        self::assertSame(1, preg_match('~<script id="milpa-live-signals" type="application/json">(.*?)</script>~s', $html, $seed));
        $signals = json_decode(str_replace('<\/', '</', $seed[1]), true);
        self::assertIsArray($signals);
        self::assertSame('chat', $signals['desktop.tab'] ?? null, 'the guest\'s seed');
        self::assertSame('agent', $signals['admin.section'] ?? null, 'and the host\'s own, in the same tag');
        self::assertIsArray($signals['desktop.i18n'] ?? null, 'and the client catalog, which is why a screen section has copy at all');
    }

    /**
     * The DATA the guest's modules read, and the one tag deliberately absent (G4/the hub): a component
     * rendered inside somebody else's response sets no cookie, so it cannot mint the hub credential.
     */
    public function testTheRegionCarriesItsDataTagsAndNoExecutableScriptOfItsOwn(): void
    {
        [, $kernel] = self::boot([AdminPlugin::class, AgentWorkspacePlugin::class]);
        $html = (string) self::dispatch($kernel, '/milpa/admin/s/agent')->getBody();

        foreach (['milpa-commands', 'milpa-live-signals', 'milpa-desktop-guard', 'milpa-desktop-session'] as $tag) {
            self::assertSame(1, substr_count($html, '<script id="' . $tag . '" type="application/json">'), $tag . ' is written once, as data');
        }
        self::assertStringNotContainsString('id="milpa-desktop-hub"', $html, 'no hub tag: only GET /desktop can set the subscriber cookie');

        // The region writes no executable script: what it emits without a `src` is JSON or a signed envelope.
        $start = strpos($html, 'class="desktop-agent"');
        self::assertIsInt($start);
        $region = substr($html, $start, (int) strpos($html, '</main>') - $start);
        preg_match_all('~<script(?![^>]*\bsrc=)([^>]*)>~', $region, $m);
        self::assertNotSame([], $m[1]);
        foreach ($m[1] as $attributes) {
            self::assertMatchesRegularExpression('~type="application/(json|milpa\+xhtml)"~', $attributes, 'the region executes no script of its own');
        }
    }

    public function testTheDeclaredLocaleNamesTheSectionInSpanish(): void
    {
        [, $kernel] = self::boot([AdminPlugin::class, AgentWorkspacePlugin::class], ['desktop' => ['locale' => 'es'], 'admin' => ['locale' => 'es']]);

        $index = (string) self::dispatch($kernel, '/milpa/admin')->getBody();
        self::assertStringContainsString('<span class="mui-sidebar__item-label">Agente</span>', $index);
        $section = (string) self::dispatch($kernel, '/milpa/admin/s/agent')->getBody();
        self::assertStringContainsString('<span class="mui-kbd">Agente</span>', $section);
        self::assertStringContainsString('>puerta: loopback</span>', $section);
        self::assertStringContainsString('Escribe a la sesión', $section, 'the composed surfaces speak the declared locale too');
        // 🚨 THE TABLIST, THROUGH THE APP'S OWN WIRING. Asserted HERE and not only where the surface is
        // built by hand: making the labels catalog keys and never passing the catalog left the tablist
        // in English while the source looked correct, and every direct-construction test still passed.
        // A fix declared and not wired reads exactly like a capability (greenhouse decisions/0213,
        // decisions/0270).
        self::assertStringContainsString('data-tab="chat" @click="select(\'chat\')" :aria-selected="isActive(\'chat\')" aria-selected="true">Conversación<', $section, 'the tablist got the catalog the plugin built');
        self::assertStringContainsString('data-tab="decisions"', $section, 'and the decisions inbox has a pane at last');
        self::assertStringContainsString('>Decisiones<', $section);
    }

    public function testWithoutTheDesktopPluginTheAdminHasNoAgentSection(): void
    {
        // The negative control: the section comes from the Desktop plugin and nowhere else.
        [, $kernel] = self::boot([AdminPlugin::class]);

        self::assertNull(SectionCatalogue::discover($kernel->plugins())->find('agent'));
        $missing = self::dispatch($kernel, '/milpa/admin/s/agent');
        self::assertSame(404, $missing->getStatusCode());
        self::assertStringNotContainsString('href="/milpa/admin/s/agent"', (string) self::dispatch($kernel, '/milpa/admin')->getBody());
    }

    public function testBehindThePasskeyGateWithNobodySignedInTheSectionOffersTheDoorAndComposesNothing(): void
    {
        // The Desktop names app-runtime's passkey gate (the fixture bears its exact name); the admin's own
        // door is the default loopback gate, which authenticates nobody: the region must offer sign-in, and
        // NOT compose a conversation the human could not use (greenhouse decisions/0210 §2).
        if (!class_exists(DesktopSettings::PASSKEY_GATE)) {
            require __DIR__ . '/../Fixtures/app-runtime-passkey-gate.php';
        }
        [, $kernel] = self::boot([AdminPlugin::class, AgentWorkspacePlugin::class], ['desktop' => ['middleware' => [DesktopSettings::PASSKEY_GATE]]]);

        $section = self::dispatch($kernel, '/milpa/admin/s/agent');
        self::assertSame(200, $section->getStatusCode());
        $html = (string) $section->getBody();
        self::assertStringContainsString('<span class="mui-kbd">Agent</span>', $html);
        self::assertStringContainsString('data-desktop-agent="signed-out" data-gate="passkey">', $html);
        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringContainsString('<span class="mui-alert__content">Sign in to open the Agent</span>', $html);
        self::assertStringContainsString('href="/webauthn/signin?next=%2Fmilpa%2Fadmin%2Fs%2Fagent">Sign in</a>', $html, 'next points back at this section');

        // NOT the view: no thread, no composer, no prototypes — and none of their files asked for.
        self::assertStringNotContainsString('id="milpa-chat"', $html);
        self::assertStringNotContainsString('id="milpa-composer-dock"', $html);
        self::assertStringNotContainsString('<template id="milpa-thinking-proto">', $html);
        self::assertStringNotContainsString(DesktopAssets::url('desktop-conversation', 'js'), $html);
        self::assertStringNotContainsString('id="milpa-desktop-session"', $html);

        // A Spanish page comes back to a Spanish page: the request's `lang` reaches the guest as the host's
        // `meta['query']` and travels in `next`, so the panel returns in the language the human was
        // reading — while the offer itself speaks the DECLARED locale, like the rest of the region.
        $spanish = (string) self::dispatch($kernel, '/milpa/admin/s/agent?lang=es')->getBody();
        self::assertStringContainsString('href="/webauthn/signin?next=%2Fmilpa%2Fadmin%2Fs%2Fagent%3Flang%3Des">Sign in</a>', $spanish);

        // The admin mounted elsewhere: the way back follows its mount point, read from the context's route.
        [, $panel] = self::boot([AdminPlugin::class, AgentWorkspacePlugin::class], ['desktop' => ['middleware' => [DesktopSettings::PASSKEY_GATE]], 'admin' => ['route' => '/panel']]);
        self::assertStringContainsString('href="/webauthn/signin?next=%2Fpanel%2Fs%2Fagent">', (string) self::dispatch($panel, '/panel/s/agent')->getBody());
    }

    /**
     * The state the decision names for a passkey gate AND a principal — the composed view — measured
     * through the real admin, which fills `principal:` in the one context every section mounts with
     * (milpa/admin ≥ 0.11, greenhouse decisions/0210).
     */
    public function testWithAPrincipalTheAdminSaysWhoSignedInAndTheRegionIsTheComposedView(): void
    {
        if (!class_exists(DesktopSettings::PASSKEY_GATE)) {
            require __DIR__ . '/../Fixtures/app-runtime-passkey-gate.php';
        }
        $container = new DIContainer();
        $container->registerService(PasskeyGateStub::class, new PasskeyGateStub());
        [, $kernel] = self::boot(
            [AdminPlugin::class, AgentWorkspacePlugin::class],
            ['admin' => ['middleware' => [PasskeyGateStub::class]], 'desktop' => ['middleware' => [DesktopSettings::PASSKEY_GATE]]],
            $container,
        );

        $section = self::dispatch($kernel, '/milpa/admin/s/agent', '203.0.113.9');
        self::assertSame(200, $section->getStatusCode(), 'the admin\'s gate let the LAN in by identity');
        $html = (string) $section->getBody();
        self::assertStringContainsString('data-principal="passkey:stub">signed in as passkey:stub</span>', $html, 'the admin knows who signed in');

        self::assertStringContainsString('data-desktop-agent="live" data-gate="passkey">', $html);
        self::assertStringContainsString('id="milpa-chat"', $html);
        // Not the sign-in offer — asserted on the region's own STATE, because the catalog the modules read
        // travels on this page as data and carries every sentence, this one included.
        self::assertStringNotContainsString('data-desktop-agent="signed-out"', $html, 'the region agrees with the topbar');
        self::assertStringNotContainsString('desktop-agent__signin-link', $html);

        // The agent session the region drives is DERIVED from the principal — stable across reloads, and
        // not the same one another principal behind the same door would drive.
        preg_match('~id="milpa-desktop-session" type="application/json">\{"agent":"([^"]+)"~', $html, $mine);
        self::assertNotSame([], $mine);
        $again = (string) self::dispatch($kernel, '/milpa/admin/s/agent', '203.0.113.9')->getBody();
        self::assertStringContainsString('"agent":"' . $mine[1] . '"', $again, 'the same human returns to the same session');
        // …and a panel that authenticated NOBODY drives a different one: the id is derived, not a constant.
        [, $anonymous] = self::boot([AdminPlugin::class, AgentWorkspacePlugin::class]);
        self::assertStringNotContainsString('"agent":"' . $mine[1] . '"', (string) self::dispatch($anonymous, '/milpa/admin/s/agent')->getBody(), 'a different principal, a different session');
    }

    /**
     * CONTAINED ERRORS, reinterpreted for a composed view (greenhouse decisions/0211, H6): a Desktop
     * surface that throws while being painted costs its own node — a small region NAMING it — and the rest
     * of the Agent, the panel's header, its sidebar and its chrome all stand. Never a 500 for the panel.
     *
     * The positive control is in the same run: the surfaces AROUND the broken one are still painted, so a
     * page that had simply stopped rendering could not pass.
     */
    public function testASurfaceThatThrowsPaintsItsOwnRegionAndTheRestOfThePanelStands(): void
    {
        $container = new DIContainer();
        [, $kernel] = self::boot([AdminPlugin::class, AgentWorkspacePlugin::class], [], $container);

        // Break ONE surface, in the registry the region composes from — the same instance the shell uses.
        $live = $container->get(DesktopComponents::class);
        self::assertInstanceOf(DesktopComponents::class, $live);
        $live->renderers()->registerFor('desktop-work-board', new class () implements ComponentRendererInterface {
            public function supportsTarget(RenderTarget $target): bool
            {
                return true;
            }

            public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
            {
                throw new \RuntimeException('this surface cannot be painted');
            }
        });

        $section = self::dispatch($kernel, '/milpa/admin/s/agent');
        self::assertSame(200, $section->getStatusCode(), 'never a 500 for the whole panel');
        $html = (string) $section->getBody();

        self::assertStringContainsString('data-failed-component="desktop-work-board"', $html, 'the failure names the surface');
        self::assertStringContainsString('This part of the Agent could not be shown (desktop-work-board).', $html);
        self::assertStringContainsString('this surface cannot be painted', $html, 'and says why — the panel is behind a gate');

        // The positive control: everything around it still painted, and the panel is whole.
        self::assertStringContainsString('id="milpa-chat"', $html);
        self::assertStringContainsString('id="milpa-composer-dock"', $html);
        self::assertStringContainsString('<span class="mui-kbd">Agent</span>', $html);
        self::assertStringContainsString('href="/milpa/admin/s/plugins"', $html, 'the panel\'s own sections are still listed');
    }

    /**
     * The host's context reaches EVERY composed surface, not just the region's root: the principal the
     * admin authenticated and the facts it hands over in `meta` (greenhouse decisions/0211, H5). A surface
     * mounted with an empty context could not tell who is reading, which door let them in or which section
     * it is inside — the host would have carried them to the door and dropped them there.
     *
     * Two fields are deliberately the region's own: the component id (each surface under its own, inside
     * the region) and the `route`, which is the Desktop's live wire, not the panel's URL. The locale is the
     * region's ONE catalog — the declared one — never the request's.
     */
    public function testEveryComposedSurfaceMountsWithTheHostsPrincipalAndMeta(): void
    {
        if (!class_exists(DesktopSettings::PASSKEY_GATE)) {
            require __DIR__ . '/../Fixtures/app-runtime-passkey-gate.php';
        }
        $container = new DIContainer();
        $container->registerService(PasskeyGateStub::class, new PasskeyGateStub());
        [, $kernel] = self::boot(
            [AdminPlugin::class, AgentWorkspacePlugin::class],
            ['admin' => ['middleware' => [PasskeyGateStub::class]], 'desktop' => ['middleware' => [DesktopSettings::PASSKEY_GATE]]],
            $container,
        );

        $live = $container->get(DesktopComponents::class);
        self::assertInstanceOf(DesktopComponents::class, $live);
        $recorder = new class () implements ComponentRendererInterface {
            public ?ComponentContext $seen = null;

            public function supportsTarget(RenderTarget $target): bool
            {
                return true;
            }

            public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
            {
                $this->seen = $request->context;

                return new RenderResult(output: '<div data-recorded="1"></div>', state: $component->mount($request->props, $request->context));
            }
        };
        $live->renderers()->registerFor('desktop-work-board', $recorder);

        self::assertSame(200, self::dispatch($kernel, '/milpa/admin/s/agent?tab=work', '203.0.113.9')->getStatusCode());

        $seen = $recorder->seen;
        self::assertNotNull($seen, 'the surface was composed');
        self::assertSame('passkey:stub', $seen->principal, 'who the admin authenticated');
        self::assertSame('custom', $seen->meta['gate'] ?? null, 'the gate in effect is the ADMIN\'s door, as its topbar chip names it — not the Desktop\'s');
        self::assertSame('agent', $seen->meta['section'] ?? null, 'the section it is inside');
        self::assertSame(['tab' => 'work'], $seen->meta['query'] ?? null, 'the request\'s query');
        self::assertSame('en', $seen->locale, 'the region\'s ONE catalog, not the request\'s');
        self::assertStringStartsWith('agent-desktop-work-board', $seen->componentId, 'its own id inside the region, minted from the region\'s own root');
        self::assertSame('/desktop/live', $seen->route, 'the Desktop\'s wire, not the panel\'s URL');
    }

    /** The region's root is what the admin mounts, and its contract is the one the section named. */
    public function testTheRegionsComponentIsTheOneTheSectionDeclared(): void
    {
        self::assertSame('desktop-agent', AgentViewComponent::NAME);
        self::assertSame('agent', AgentViewComponent::SECTION);
    }

    /**
     * @param list<class-string>   $plugins
     * @param array<string, mixed> $config
     *
     * @return array{0: DIContainer, 1: Kernel}
     */
    private static function boot(array $plugins, array $config = [], ?DIContainer $container = null): array
    {
        $container ??= new DIContainer();
        $kernel = Kernel::boot(['root' => sys_get_temp_dir(), 'plugins' => $plugins, 'config' => $config, 'container' => $container]);
        // The admin reads the booted plugins from the kernel in the container, as an app's public/index.php registers it.
        $container->registerService(Kernel::class, $kernel);

        return [$container, $kernel];
    }

    /**
     * 🚨 THE DEEP SCREENS REACH THE PANEL, behind Agent's gear and out of the main navigation.
     *
     * Settings, skills, capabilities and the preview were reachable through exactly one door: the
     * `/desktop` page, whose own sidebar switched between them. So the panel showed the conversation
     * and nothing else about the agent, and the page could not be retired without orphaning every
     * screen behind it (greenhouse decisions/0268).
     */
    public function testTheDeepScreensAreSectionsUnderAgentAndReachableOnTheirOwnUrl(): void
    {
        [, $kernel] = self::boot([AdminPlugin::class, AgentWorkspacePlugin::class]);
        $catalogue = SectionCatalogue::discover($kernel->plugins());

        self::assertSame(
            ['agent-settings', 'agent-skills', 'agent-subagents', 'agent-preview'],
            array_map(static fn (object $s): string => $s->id, $catalogue->children('agent')),
            'the order the Desktop\'s own sidebar lists them in',
        );
        $ids = array_map(static fn (object $s): string => $s->id, $catalogue->sections());
        self::assertNotContains('agent-sessions', $ids, 'the conversation IS the Agent section, not a child of itself');
        // 🚨 AND NO CAPABILITIES SECTION, which is a duplicate removed rather than an omission: the
        // panel's own Plugins section already carries the capability catalogue and can enable from it.
        // Two doors to one fact is the defect this arc is about, and this one was caught by looking at
        // the painted panel, not by a test (greenhouse decisions/0268).
        self::assertNotContains('agent-capabilities', $ids, 'the panel\'s Plugins section already is this door');

        // Out of the main navigation, behind the gear — and each one still its own page.
        $index = (string) self::dispatch($kernel, '/milpa/admin')->getBody();
        self::assertStringNotContainsString('class="mui-sidebar__item" href="/milpa/admin/s/agent-settings"', $index, 'not a menu entry');
        self::assertStringContainsString('class="mui-sidebar__subitem" href="/milpa/admin/s/agent-settings"', $index, 'behind Agent\'s gear');
        self::assertStringContainsString('aria-label="Settings for this section"', $index, 'and the gear is named by the host\'s catalog');

        $settings = self::dispatch($kernel, '/milpa/admin/s/agent-settings');
        self::assertSame(200, $settings->getStatusCode(), 'the same URL shape as any section, and the same door');
    }

    /** The section titles are the SAME catalog keys the Desktop's own sidebar names those screens with. */
    public function testTheScreenSectionsAreNamedByTheDesktopsOwnCatalog(): void
    {
        [, $kernel] = self::boot([AdminPlugin::class, AgentWorkspacePlugin::class]);
        $titles = [];
        foreach (SectionCatalogue::discover($kernel->plugins())->children('agent') as $child) {
            $titles[$child->id] = $child->title;
        }

        self::assertSame('Settings', $titles['agent-settings'] ?? null);
        self::assertSame('Skills', $titles['agent-skills'] ?? null);
        self::assertSame('Subagents', $titles['agent-subagents'] ?? null);
        self::assertSame('Preview', $titles['agent-preview'] ?? null);
    }

    /**
     * 🚨 THE DESKTOP RUNTIME REACHES THE PANEL, and a shipped screen is why this exists.
     *
     * The Settings section rendered perfectly in the panel and its Save button fired NO request at
     * all: the console said `desktop-guard not loaded`. The guard is one of the Desktop's shared
     * runtime modules, which have no surface to be painted, so the declared-view contract — which
     * collects a view's assets by walking the components it RENDERS — never emitted them. The
     * standalone page emitted them by hand in its own template, which is exactly why the same screen
     * worked there and shipped dead here (greenhouse decisions/0272).
     *
     * 🚨 AND EVERY CONSOLE CHECK OF THAT PAGE WAS CLEAN, because nothing had called `save()` yet. A
     * console read without interaction proves the page loaded, never that it is wired.
     */
    public function testEverySectionCarriesTheDesktopRuntimeItsSurfacesHangOff(): void
    {
        [, $kernel] = self::boot([AdminPlugin::class, AgentWorkspacePlugin::class]);

        foreach (['/milpa/admin/s/agent', '/milpa/admin/s/agent-settings', '/milpa/admin/s/agent-skills'] as $path) {
            $html = (string) self::dispatch($kernel, $path)->getBody();
            foreach (DesktopAssets::runtimeModules() as $module) {
                self::assertStringContainsString(
                    DesktopAssets::url($module, 'js'),
                    $html,
                    $path . ' does not emit ' . $module . ' — a surface that hangs off it cannot behave',
                );
            }
            // Once, not once per surface: a double <script> is a double execution.
            self::assertSame(
                1,
                substr_count($html, DesktopAssets::url(DesktopAssets::GUARD, 'js')),
                $path . ' emits the guard more than once',
            );
        }
    }

    private static function dispatch(Kernel $kernel, string $path, string $address = '127.0.0.1'): ResponseInterface
    {
        $request = new ServerRequest('GET', $path, ['Accept' => 'text/html'], null, '1.1', ['REMOTE_ADDR' => $address]);

        return (new RequestHandler($kernel, new Psr17Factory()))->handle($request);
    }
}
