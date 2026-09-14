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

namespace Milpa\AgentWorkspace;

use Milpa\AgentWorkspace\Admin\{
    AdminGuest,
    AgentView,
    AgentViewComponent,
    ScreenView,
};
use Milpa\AgentWorkspace\Config\WorkspaceKeys;
use Milpa\AgentWorkspace\Controllers\{
    AssetsController,
    HubController,
    MutationController,
};
use Milpa\AgentWorkspace\Data\{
    DesktopData,
    DesktopStore,
};
use Milpa\AgentWorkspace\Event\AgentWorkspaceEvents;
use Milpa\AgentWorkspace\Http\LoopbackOnlyMiddleware;
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\AgentWorkspace\Live\{
    Activity,
    AgentMessage,
    ComposerBar,
    ComposerField,
    ComposerMessageComponent,
    Context,
    Conversation,
    DeepScreens,
    DesktopAssets,
    DesktopComponents,
    Gate,
    MercureConfig,
    MercurePublisher,
    MercureServiceDeclaration,
    MessagePrototypes,
    PanelLink,
    ScreenPreviewComponent,
    Screens,
    SessionStrip,
    SettingsScreen,
    SettingsScreenComponent,
    ShellChangeRecorder,
    ShellEvent,
    ShellEventLog,
    SkillsScreenComponent,
    SubagentsScreenComponent,
    Surfaces,
    Tabs,
    Thinking,
    WorkBoard,
};
use Milpa\Admin\Section\AdminSection;
use Milpa\AppRuntime\Config\SecretOverlay;
use Milpa\Attributes\PluginMetadata;
use Milpa\Auth\Contracts\AuthContextFactory;
use Milpa\Command\OperationHttpPolicy;
use Milpa\Http\Routing\Route;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Event\{
    DeclaredEvents,
    MilpaEventDispatcherInterface,
};
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Live\Contracts\Component\{
    ComponentDefinitionInterface,
    DeclaresComponents,
};
use Milpa\Mercure\MercureService;
use Milpa\Plugin\Contracts\AppRoot;
use Milpa\Runtime\{
    Config,
    Kernel,
};
use Milpa\Runtime\Http\RouteProviderInterface;
use Milpa\Runtime\Stack\{
    ServiceDeclaration,
    StackProviderInterface,
};
use Milpa\Runtime\Support\RootResolver;

/**
 * The agent's workspace: a Milpa app SERVES ITS OWN SHELL over HTTP, at a real origin, and gains desktop
 * hands by installing this plugin (greenhouse decisions/0188).
 *
 * What it declares to the host, and under which contract:
 * - its routes ({@see RouteProviderInterface}), every one behind the door the app declared under
 *   `workspace.middleware` — loopback-only by default, `[]` open on purpose (decisions/0209);
 * - the Mercure hub it needs, as data ({@see StackProviderInterface}, decisions/0201);
 * - its components, read from the sites that declare them ({@see DeclaresComponents}, decisions/0214, 0388);
 * - one section of the admin panel and the deep screens under it, as the admin's GUEST
 *   ({@see AdminGuest}: the contract is honored when the admin is there, and a house without it boots
 *   untouched — decisions/0210, 0211, 0268).
 *
 * Installing the plugin IS the activation: a Milpa without it simply has no workspace.
 */
#[PluginMetadata(
    version: '0.75.0', // x-release-please-version
    author: 'Rodrigo Vicente - TeamX Agency',
    site: 'https://teamx.agency',
    name: 'AgentWorkspace',
    type: 'Web',
)]
final class AgentWorkspacePlugin implements PluginInterface, RouteProviderInterface, StackProviderInterface, AdminGuest, DeclaresComponents
{
    /** A plugin dispatches this (with a {@see ShellEvent} in `payload['shellEvent']`) to push a live update. */
    public const CHANGED_EVENT = 'desktop.shell.changed';

    /** The sign-in door the admin section offers a signed-out human — app-runtime's default, the one the shell's guard reads from the 401 too. */
    public const SIGNIN_PATH = '/webauthn/signin';

    /**
     * Which deep screens become sections of their own under Agent, keyed by the sidebar key that names them.
     *
     * Not every screen: `sessions` IS the Agent section, `decisions` is a tab of that region, and
     * `capabilities` is the panel's own Plugins section — two doors to one fact is the defect
     * (greenhouse decisions/0268).
     *
     * @var array<string, class-string<ComponentDefinitionInterface>>
     */
    private const SCREEN_SECTIONS = [
        'settings' => SettingsScreenComponent::class,
        'skills' => SkillsScreenComponent::class,
        'subagents' => SubagentsScreenComponent::class,
        'preview' => ScreenPreviewComponent::class,
    ];

    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /** The container this plugin was booted with. */
    public function container(): DIContainerInterface
    {
        return $this->container;
    }

    /**
     * The component definitions this plugin declares, read from the sites that declare them: the region's
     * surfaces ({@see Surfaces::components()}), the deep screens ({@see DeepScreens::components()}), and the
     * two that never enter the shell's registry — the composer's field, which the registry itself registers,
     * and {@see AgentViewComponent}, built inside `AgentView::of()` for the admin's guest section. A component
     * nobody can discover is a capability nobody can use (greenhouse decisions/0214); the second list of the
     * same fact was retired in decisions/0388.
     *
     * @return list<class-string<ComponentDefinitionInterface>>
     */
    public function declaredComponents(): array
    {
        return [
            ...Surfaces::components(),
            ...DeepScreens::components(),
            ComposerMessageComponent::class,
            AgentViewComponent::class,
        ];
    }

    /**
     * Build the shell's surfaces, declare them on the one registry, and wire the live change feed.
     *
     * The kernel registers the dispatcher and the Config bag before any plugin boots. What enters the
     * container is only what somebody resolves from it: the door, the three controllers, the data, the
     * registry, the settings screen and the Mercure hub — the surfaces and the store are handed to what
     * uses them, not to the container: thirteen registrations nothing read came out without a byte of
     * output changing (greenhouse evidence/0705).
     */
    public function boot(): void
    {
        // Refuse while the app still declares the legacy `desktop` key — first, before a single service
        // is registered, so the error reaches whoever wrote `config/app.php` (greenhouse decisions/0279, 0284).
        WorkspaceKeys::refuseLegacy($this->configBag());

        $events = $this->container->get(MilpaEventDispatcherInterface::class);
        assert($events instanceof MilpaEventDispatcherInterface);

        // Every event this package dispatches, declared where the dispatcher enters the package, so the house
        // counts them from the emitter and never from a scan of source (greenhouse decisions/0228).
        if ($events instanceof DeclaredEvents) {
            $events->declare(...AgentWorkspaceEvents::declarations());
        }

        // The door, judged once, in the declared locale — unless the app registered its own instance first.
        // «Absent» is asked of the UNDERLYING container: the wrapper's has() also says yes to anything it could
        // auto-wire, and an auto-wired gate would speak English whatever the app declared (decisions/0209).
        $settings = $this->settings();
        $catalog = $settings->catalog();
        if (!$this->container->getContainer()->has(LoopbackOnlyMiddleware::class)) {
            $this->container->registerService(LoopbackOnlyMiddleware::class, new LoopbackOnlyMiddleware($catalog));
        }

        $log = new ShellEventLog($this->logPath());

        $store = new DesktopStore($this->sessionsPath(), $this->settingsPath());
        // The controllers the router resolves from the container on every request, kept explicit: the
        // container's auto-wiring would build them, but the core contract only promises it MAY
        // (greenhouse decisions/0388 — promoting that MAY is a core acta, not this plugin's call).
        $this->container->registerService(MutationController::class, new MutationController($store));
        $this->container->registerService(AssetsController::class, new AssetsController());

        $data = new DesktopData($this->container, $log, $this->sessionsPath(), $store);
        $this->container->registerService(DesktopData::class, $data);

        // The hub, under milpa/mercure's own name, so a governed turn over HTTP streams its session events to
        // the SAME hub the shell reads (greenhouse decisions/0190). No hub configured: nothing registered, and the
        // turn simply does not stream.
        $mercure = $this->mercure();
        if ($mercure !== null) {
            $this->container->registerService(MercureService::class, $mercure->service());
        }

        // ONE registry holds every workspace component and its renderer: the page composes through it and the
        // live endpoint re-renders through the same one (greenhouse decisions/0189, 0211).
        $desktopComponents = new DesktopComponents($this->liveSecret('signing'), $this->liveSecret('csrf'), $events);
        $this->container->registerService(DesktopComponents::class, $desktopComponents);
        $composerField = new ComposerField($this->liveSecret('signing'), $this->liveSecret('csrf'), $events, $desktopComponents, $catalog);

        // The region's surfaces (greenhouse decisions/0189, 0191): built here and handed to the registry through
        // {@see Surfaces}. Context receives DesktopData directly; contributed panels are additive, never the
        // primary source of context (decisions/0288).
        $tabs = new Tabs($this->liveSecret('signing'), $events, $catalog);
        $workBoard = new WorkBoard($this->liveSecret('signing'), $data, $events);
        $activity = new Activity($this->liveSecret('signing'), $data, $events);
        $context = new Context($this->liveSecret('signing'), $events, $data, $catalog);
        $gate = new Gate($this->liveSecret('signing'), $events);
        $thinking = new Thinking($this->liveSecret('signing'), $events);
        $agentMessage = new AgentMessage($this->liveSecret('signing'), $events);
        $messages = new MessagePrototypes($this->liveSecret('signing'), $events);
        $conversation = new Conversation($this->liveSecret('signing'), $events, $data, $catalog);
        $sessionStrip = new SessionStrip($this->liveSecret('signing'), $data, $events, $catalog);

        // The Settings screen asks the app two facts and assumes neither (greenhouse decisions/0267, 0275, 0285):
        // whether a credential write can be AUTHORIZED — named as what is missing, a judge (`OperationHttpPolicy`)
        // or a door (`AuthContextFactory`), because a judge with nobody it can judge is not an answer — and
        // WHETHER a key is declared, never which. Both asked of the underlying container; `AppRoot` is what a
        // booting plugin can see, and its path is a property (decisions/0276).
        $container = $this->container;
        $settingsScreen = new SettingsScreen(
            $this->liveSecret('signing'),
            $data,
            $events,
            $catalog,
            static function () use ($container): string {
                $registered = $container->getContainer();
                if (!$registered->has(OperationHttpPolicy::class)) {
                    return SettingsScreen::NO_POLICY;
                }
                if (!interface_exists(AuthContextFactory::class) || !$registered->has(AuthContextFactory::class)) {
                    return SettingsScreen::NO_DOOR;
                }

                return '';
            },
            static function () use ($container): bool {
                if (!class_exists(SecretOverlay::class)) {
                    return false;
                }
                $root = $container->getContainer()->has(AppRoot::class) ? $container->get(AppRoot::class) : null;
                $path = $root instanceof AppRoot ? $root->path : '';

                return $path !== '' && \in_array('agent.apiKey', SecretOverlay::declared($path), true);
            },
        );
        $this->container->registerService(SettingsScreen::class, $settingsScreen);

        // Where the panel's Stack section is, resolved ONCE and handed down as a prop: the bar never asks whether
        // milpa/admin is installed (greenhouse decisions/0255).
        $stackUrl = PanelLink::fromConfig($this->configBag())->stack();
        $composerBar = new ComposerBar($this->liveSecret('signing'), $data, $composerField, $events, $catalog, $stackUrl);

        // Declared by the HOST, once, before anything captures the registry: `declare()` builds a fresh renderer
        // per call and a view captures the instances the registry holds when it is built, so a second declaration
        // site would let two sections carry two renderers for one name (greenhouse decisions/0211, 0283).
        (new Surfaces(
            $data,
            $events,
            $catalog,
            $composerField,
            $sessionStrip,
            $composerBar,
            $tabs,
            $workBoard,
            $activity,
            $context,
            $gate,
            $thinking,
            $agentMessage,
            $conversation,
            $messages,
        ))->declareOn($desktopComponents);

        // The same wiring the shell page uses, offered to the surfaces that cannot write headers.
        $this->container->registerService(HubController::class, new HubController($this->mercure(), $this->liveSecret('signing')));

        $publisher = $mercure !== null ? new MercurePublisher($mercure->service(), $mercure->topic) : null;
        $recorder = new ShellChangeRecorder($log, $publisher);

        $events->subscribe(self::CHANGED_EVENT, static function (string $eventName, array $payload) use ($recorder): void {
            $shellEvent = $payload['shellEvent'] ?? null;
            if ($shellEvent instanceof ShellEvent) {
                $recorder->record($shellEvent);
            }
        });
    }

    /**
     * The shell's routes, each behind the EFFECTIVE gate ({@see DesktopSettings::effectiveMiddleware()}, greenhouse
     * decisions/0209): the declared stack when every entry names a PSR-15 middleware class (an empty list included),
     * loopback-only the moment the declaration is anything else. The component assets carry none: public package
     * files, and a JSON refusal to a `<link>` or `<script>` would break the page silently.
     *
     * The hub route exists because a surface inside the panel contributes markup to somebody else's response and
     * cannot mint the cookie the hub reads (greenhouse decisions/0253); the assets route is ONE family, `{file}`
     * capturing `<name>.css` and `<name>.js` alike for {@see DesktopAssets::path()} to judge (decisions/0211).
     */
    public function routes(): array
    {
        return [
            ...Route::behind(
                $this->settings()->effectiveMiddleware(),
                Route::get('/workspace/hub', [HubController::class, 'connect'], 'desktop.hub'),
                Route::post('/workspace/settings', [MutationController::class, 'saveSettings'], 'desktop.settings.save'),
                Route::post('/workspace/sessions', [MutationController::class, 'createSession'], 'desktop.sessions.create'),
                Route::post('/workspace/work', [MutationController::class, 'moveWork'], 'desktop.work.move'),
            ),
            Route::get(DesktopAssets::BASE . '{file}', [AssetsController::class, 'component'], 'desktop.assets.component'),
        ];
    }

    /**
     * The workspace's one section in the admin panel, «Agent» — the conversation composed INLINE in the panel's
     * document, behind the same door — plus the deep screens as sections under it (greenhouse decisions/0211,
     * 0268). The section declares a whole VIEW ({@see AgentView}); the admin registers it under a layer of its
     * own and emits one runtime for the page.
     *
     * Called by milpa/admin at request time, and by nothing else.
     *
     * @return list<AdminSection>
     */
    public function adminSections(): array
    {
        $settings = $this->settings();
        $catalog = $settings->catalog();
        // Asked of the UNDERLYING container: neither can be auto-wired, and a plugin whose boot() never ran must
        // declare what it can instead of fataling inside the admin's discovery.
        $registered = $this->container->getContainer();
        $live = $registered->has(DesktopComponents::class) ? $this->container->get(DesktopComponents::class) : null;
        $data = $registered->has(DesktopData::class) ? $this->container->get(DesktopData::class) : null;

        // The deep screens, declared here ONCE before any view is built, so every section captures the same
        // instances: the panel refuses one name painted by different renderers in two sections
        // (greenhouse decisions/0211, 0268).
        if ($live instanceof DesktopComponents) {
            $screen = $registered->has(SettingsScreen::class) ? $this->container->get(SettingsScreen::class) : null;
            DeepScreens::declareOn(
                $live,
                $data instanceof DesktopData ? $data : null,
                null,
                $catalog,
                hidden: false,
                settings: $screen instanceof SettingsScreen ? $screen : null,
            );
        }

        return [
            AdminSection::ofView(
                id: AgentViewComponent::SECTION,
                title: $catalog->tr(Screens::title(AgentViewComponent::SECTION)),
                view: AgentView::of(
                    $live instanceof DesktopComponents ? $live : new DesktopComponents($this->liveSecret('signing'), $this->liveSecret('csrf')),
                    $settings,
                    $catalog,
                    $data instanceof DesktopData ? $data : null,
                    self::SIGNIN_PATH,
                    $this->liveSecret('signing'),
                ),
                order: 60,
                group: 'agent',
                icon: Screens::icon(AgentViewComponent::SECTION),
            ),
            ...self::screenSections($live instanceof DesktopComponents ? $live : null, $data, $catalog),
        ];
    }

    /**
     * The deep screens as sections under Agent, behind its gear: `{route}/s/{id}` already routes any declared id
     * and one middleware stack covers every panel route; what a screen needed was a way to say whose it is —
     * `parent` (greenhouse decisions/0268). Titles and order are the workspace's own ({@see Screens}), so the two
     * doors cannot disagree about a screen's name. Empty when the registry is absent: a section whose components
     * nothing can resolve is a menu entry that 500s.
     *
     * @return list<AdminSection>
     */
    private static function screenSections(?DesktopComponents $live, ?DesktopData $data, Catalog $catalog): array
    {
        if ($live === null) {
            return [];
        }
        $sections = [];
        $order = 10;
        foreach (self::SCREEN_SECTIONS as $key => $component) {
            $sections[] = AdminSection::ofView(
                id: AgentViewComponent::SECTION . '-' . $key,
                title: $catalog->tr(Screens::title($key)),
                view: ScreenView::of($live, $component::contract()->name, AgentViewComponent::SECTION . '-' . $key . '-region', $catalog),
                order: $order,
                group: 'agent',
                icon: Screens::icon($key),
                parent: AgentViewComponent::SECTION,
            );
            $order += 10;
        }

        return $sections;
    }

    /** The runtime's config bag, or null when this plugin booted without a kernel (as in unit tests). */
    private function configBag(): ?Config
    {
        $config = $this->container->get(Config::class);

        return $config instanceof Config ? $config : null;
    }

    /**
     * The door as the app declared it, judged (greenhouse decisions/0209): `workspace.middleware` and
     * `workspace.locale` from the runtime's config bag, the defaults when the plugin has no bag — read on
     * demand, so `routes()` answers before `boot()` too.
     */
    public function settings(): DesktopSettings
    {
        return DesktopSettings::fromConfig($this->configBag());
    }

    /**
     * The backing services this plugin needs the host to run (greenhouse decisions/0201): the Mercure hub the
     * shell and the agent sessions stream through. Declared, not started — the operator lists it, probes it and
     * projects a compose fragment. The declaration reads `workspace.mercure.*`, so the hub it describes is the
     * hub the app publishes to; the keys travel as secret config references, never as values.
     *
     * @return list<ServiceDeclaration>
     */
    public function services(): array
    {
        return [MercureServiceDeclaration::fromConfig($this->configBag())];
    }

    /** The Mercure hub wiring, when the app configured `workspace.mercure.*`; null otherwise (log-only). */
    private function mercure(): ?MercureConfig
    {
        $config = $this->configBag();

        return $config !== null ? MercureConfig::fromConfig($config) : null;
    }

    /**
     * Where this app lives: the kernel first, else the platform's own {@see RootResolver} walking up to the
     * nearest composer.json — never the working directory, whose meaning changes with how `php -S` was
     * launched and once put a session store under `public/` (greenhouse evidence/0535). Asked of the PSR-11
     * REGISTRY: the wrapper's has() would auto-wire a kernel rooted wherever its constructor decided
     * (evidence/0522).
     */
    private function root(): string
    {
        if ($this->container->getContainer()->has(Kernel::class)) {
            $kernel = $this->container->get(Kernel::class);

            if ($kernel instanceof Kernel) {
                return $kernel->root();
            }
        }

        return (new RootResolver())->resolve();
    }

    /** Where persisted Desktop settings live: `workspace.settings.path` in config, else `.milpa/desktop-settings.json`. */
    private function settingsPath(): string
    {
        $configured = WorkspaceKeys::read($this->configBag(), 'settings.path');

        return is_string($configured) && $configured !== '' ? $configured : $this->root() . '/.milpa/desktop-settings.json';
    }

    /** Where the app's session store lives: `workspace.sessions.path` in config, else `.milpa/sessions/`. */
    private function sessionsPath(): string
    {
        $configured = WorkspaceKeys::read($this->configBag(), 'sessions.path');

        return is_string($configured) && $configured !== '' ? $configured : $this->root() . '/.milpa/sessions';
    }

    /**
     * The HMAC secret every workspace component signs its state envelope and its CSRF token with.
     *
     * ONE signing key per page (greenhouse decisions/0211): `workspace.live.<kind>_secret` wins when declared,
     * else the house's own `live.secret` — the key every other milpa/live endpoint verifies with — and only
     * when the house declares neither, a stable per-install value derived from this package's path, which is
     * a default and not a secret.
     */
    private function liveSecret(string $kind): string
    {
        $config = $this->configBag();
        if ($config === null) {
            return hash('sha256', __DIR__ . '|milpa-live|' . $kind);
        }

        $configured = WorkspaceKeys::read($config, 'live.' . $kind . '_secret');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $house = $config->get('live.secret');
        if (is_string($house) && $house !== '') {
            return $house;
        }

        return hash('sha256', __DIR__ . '|milpa-live|' . $kind);
    }

    /** Where the shared event log lives: `workspace.events.log` in config, else a per-app temp file. */
    private function logPath(): string
    {
        $configured = WorkspaceKeys::read($this->configBag(), 'events.log');

        return is_string($configured) && $configured !== ''
            ? $configured
            : sys_get_temp_dir() . '/milpa-desktop-shell-events.log';
    }

    /** No persistent state to create: the shell is served, not stored. */
    public function install(): void
    {
    }

    /** No persistent state to remove. */
    public function uninstall(): void
    {
    }

    /** Enabling is declaring it in config/plugins.php; serving the shell is the whole effect. */
    public function enable(): void
    {
    }

    /** Disabling removes it from config/plugins.php; nothing here to tear down. */
    public function disable(): void
    {
    }
}
