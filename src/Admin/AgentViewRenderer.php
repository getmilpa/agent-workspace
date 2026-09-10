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

namespace Milpa\AgentWorkspace\Admin;

use Milpa\AgentWorkspace\Data\DesktopData;
use Milpa\AgentWorkspace\ShellComposition;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\AgentWorkspace\DesktopSettings;
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\AgentWorkspace\Live\CommandListView;
use Milpa\AgentWorkspace\Live\ComposerField;
use Milpa\AgentWorkspace\Live\DesktopAssets;
use Milpa\AgentWorkspace\Live\DesktopComponents;
use Milpa\AgentWorkspace\Live\SessionTicket;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\ComponentRendererInterface;
use Milpa\Live\Contracts\Rendering\DeclaresClientAssets;
use Milpa\Live\ValueObjects\ClientAssets;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use Milpa\Live\ValueObjects\RenderResult;
use Milpa\Live\ValueObjects\RenderTarget;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Paints {@see AgentViewComponent} — the Desktop's conversation region, COMPOSED INSIDE the host's page
 * (greenhouse decisions/0211, slice 3, retiring the frame of 0210).
 *
 * There is no iframe here, and no second runtime. The region is compiled through the Desktop's ONE
 * component registry ({@see DesktopComponents} — the very instance the shell composes `/desktop` with, so
 * the definitions and the renderers are the same objects, never a second rendering path), and every file
 * those renderers DECLARE is handed back on {@see RenderResult::$clientAssets} for the HOST to emit once
 * through `LiveBoot::html()`. The Desktop loads no Alpine, no `milpa-live`, no boot of its own inside
 * somebody else's document: it declares, the host emits.
 *
 * What the region carries, in this order:
 *
 *   1. the guest bar — the `gate: <label>` chip and the link that opens the FULL Desktop in a new tab.
 *      It is what the frame's bar was, and it keeps its purpose: the panel shows the conversation, the
 *      Desktop's own page shows the rest (sidebar, sessions, screens);
 *   2. the session view — the tablist, the four panes (conversation + consent gate, work board, activity,
 *      context) and the composer docked below them: the same components, in the same layout, that
 *      `/desktop` paints;
 *   3. the message prototypes the conversation clones per message (greenhouse decisions/0191), each its
 *      own declared component, each inside the `<template>` the thread looks up by id;
 *   4. the DATA tags the Desktop's runtime modules read — the commands, the catalog, the guard's doors
 *      and the agent session. `type="application/json"`, never executable: the region writes no script.
 *
 * **Contained per surface.** Each surface is compiled on its own and a surface that throws while mounting
 * or rendering paints a small failure region NAMING it, inside the region, while the rest of the Agent
 * stands — the same rule milpa/admin applies per view root ({@see \Milpa\Admin\View\AdminShell}), applied
 * here per surface because the whole region is ONE root of the declared view.
 *
 * **Signed out.** When the Desktop's gate is the passkey gate and the admin authenticated nobody, the view
 * is NOT composed at all: no surface is mounted, no module is asked for, and the region is the sign-in
 * offer with `next` pointing back at this section (greenhouse decisions/0210 §2).
 *
 * **One region, ONE language.** Every word here — the bar, the composed surfaces, the client catalog the
 * modules read and the signals the page is seeded with — comes from the SAME {@see Catalog}: the one the
 * Desktop plugin declared (`desktop.locale`), exactly as `/desktop` does. The host's `?lang=` switches the
 * PANEL's chrome, not the guest's region: the surfaces are the shell's own instances, each holding the
 * declared catalog (that is what «reuse, do not fork» costs), and the seeds are declared with that catalog
 * too — so following the request's locale would translate the bar and leave the conversation, the mode
 * chip and the seeded labels in the other language, in one document. What DOES follow the request is the
 * way back: the sign-in `next` keeps `?lang=`, so the panel returns in the language the human was reading.
 * With no declared catalog at all (a host mounting this renderer by hand) there is nothing to disagree
 * with, and the locale the host resolved is used.
 */
final class AgentViewRenderer implements ComponentRendererInterface, DeclaresClientAssets
{
    /** The component whose declared files this region ships — its own stylesheet, like every Desktop surface. */
    public const string ASSETS = AgentViewComponent::NAME;

    /**
     * The surfaces the region composes, in document order — the Desktop's own conversation, said once.
     *
     * @var list<string>
     */
    public const array SURFACES = [
        'desktop-tabs',
        'desktop-conversation',
        'desktop-gate',
        'desktop-work-board',
        'desktop-activity',
        'desktop-context',
        'desktop-composer',
        // 🚨 THE SESSION STRIP, AND NOT THE STATUS BAR. I adopted the status bar first and measured
        // it on the rendered page: three of its four facts were ALREADY there — the counters in the
        // composer's chips, the model in the composer's line, the connection in the hub warning — and
        // the fourth, the machine and version, is provenance that belongs in the panel's own footer.
        // A fourth duplicate door in one arc, caught by measuring the page instead of by somebody
        // pointing at it.
        //
        // The strip covers the fact nothing here covered: WHICH SESSION this is, and how to move to
        // another. It was built for embed mode (greenhouse decisions/0210) and the panel is that host
        // now (decisions/0270).
        'desktop-session-strip',
        // 🚨 THE DECISIONS INBOX, WHICH WAS PAINTED NOWHERE. Measured on the rendered panel: nothing
        // carried it. I had claimed an hour earlier that it «stays a region of the conversation rather
        // than a screen» — a fact I asserted about the page without measuring it. A governed agent
        // that parks a question needs somebody to see it (greenhouse decisions/0195, decisions/0270).
        'desktop-decisions',
        'desktop-thinking',
        'desktop-agent-message',
        'desktop-user-message',
        'desktop-tool-call',
        'desktop-task',
        'desktop-system-notice',
        'desktop-result-claim',
        'desktop-ask-grant',
        'desktop-compacted',
    ];

    /**
     * The message kinds the conversation clones, and the `<template>` id each one is looked up by — the
     * ids the message modules resolve (`desktop-conversation.js`, `desktop-thinking.js`…).
     *
     * @var array<string, string> component name => template id
     */
    private const array PROTOTYPES = [
        'desktop-thinking' => 'milpa-thinking-proto',
        'desktop-agent-message' => 'milpa-agent-msg-proto',
        'desktop-user-message' => 'milpa-user-msg-proto',
        'desktop-tool-call' => 'milpa-tool-msg-proto',
        'desktop-task' => 'milpa-task-msg-proto',
        'desktop-system-notice' => 'milpa-system-msg-proto',
        'desktop-result-claim' => 'milpa-result-msg-proto',
        // The embedded region paints the SAME prototypes the standalone shell does. A kind missing here
        // is a kind that works at /desktop and silently does not inside the panel — the surface split in
        // half, which is exactly the shape this house keeps paying for.
        'desktop-ask-grant' => 'milpa-ask-grant-proto',
        'desktop-compacted' => 'milpa-compacted-proto',
    ];

    /**
     * The door is NOT a dependency here: which gate this Desktop stands behind, and where its sign-in
     * lives, are PROPS the section declared and the component put in its state — so the region says what
     * the plugin declared, never what it re-read.
     *
     * @param DesktopComponents $live    the Desktop's ONE registry — the same instance the shell composes with
     * @param DesktopData|null  $data    the session seam, for the commands the composer completes
     * @param Catalog|null      $catalog the Desktop's copy in its declared locale; null answers in English
     */
    public function __construct(
        private readonly DesktopComponents $live,
        private readonly ?DesktopData $data = null,
        private readonly ?Catalog $catalog = null,
        /** The app's signing secret — what seals this region's session ticket (greenhouse decisions/0256). */
        private readonly string $signingSecret = '',
        /**
         * The dispatcher this region composes through — how a third-party plugin still gets into the
         * Context tab now that the page is gone.
         *
         * 🚨 THE COMPOSE SEAM HAD EXACTLY ONE HOST AND IT WAS THE PAGE. `ShellController` was the only
         * dispatcher of `desktop.shell.compose` AND the only renderer of the sections it collects, so
         * retiring `/desktop` would have taken a published extension point with it — silently, because
         * a contribution nobody paints looks identical to a plugin that contributed nothing. This region
         * deliberately passed `'sections' => []`, which was honest while the page existed and would have
         * become the whole story (greenhouse decisions/0283).
         *
         * Optional: a host that hands over no dispatcher composes no third-party sections, which is the
         * same answer this region gave before — it just is not the only answer any more.
         */
        private readonly ?MilpaEventDispatcherInterface $events = null,
    ) {
    }

    /** HTML only: the admin is a web panel; a TUI host would mount the Desktop's TUI target, not this. */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }

    /** Exactly this component's own file — the region's layout. Every other file is a surface's, declared by its renderer. */
    public function clientAssets(): ClientAssets
    {
        return DesktopAssets::of(self::ASSETS);
    }

    /**
     * The region's HTML for the state given, or for a fresh mount from the request's props and context,
     * carrying on the result every file the composed surfaces declared plus the Desktop's runtime modules.
     *
     * @throws \InvalidArgumentException for a component other than {@see AgentViewComponent}, or a target other than HTML
     */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        $name = $component::contract()->name;
        if ($name !== AgentViewComponent::NAME) {
            throw new \InvalidArgumentException(\sprintf('%s renders only «%s», not «%s».', self::class, AgentViewComponent::NAME, $name));
        }
        if (!$this->supportsTarget($request->target)) {
            throw new \InvalidArgumentException(\sprintf('%s renders HTML only, not «%s».', self::class, $request->target->value));
        }

        $state = $request->state ?? $component->mount($request->props, $request->context);
        $catalog = $this->catalogFor($state);
        $assets = ClientAssets::empty();

        $html = ($state->data['state'] ?? null) === AgentViewComponent::STATE_SIGNED_OUT
            ? $this->signedOut($state, $catalog)
            : $this->live($state, $request->context, $catalog, $assets);

        return new RenderResult(output: $html, state: $state, clientAssets: $assets);
    }

    /**
     * The ONE catalog the whole region answers in: the Desktop's declared one.
     *
     * It is not the request's, on purpose. The composed surfaces are the shell's own instances, each built
     * with the declared catalog, and the page's seeds ({@see \Milpa\AgentWorkspace\Live\ShellSignals}) were
     * declared with it too — a bar that followed `?lang=` would be the only thing in the region that did.
     * Only when NO catalog was declared (a renderer built by hand, with no plugin behind it) is there
     * nothing to disagree with, and then the locale the host resolved is the best answer available.
     */
    private function catalogFor(StateSnapshot $state): Catalog
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }
        $locale = $state->meta['locale'] ?? null;

        return \is_string($locale) && \in_array($locale, Catalog::locales(), true) ? new Catalog($locale) : new Catalog();
    }

    /**
     * The live region: the guest bar, the session view, the prototypes and the data tags.
     *
     * The runtime modules the PAGE declares ({@see DesktopAssets::runtimeModules()} — the guard, the bus,
     * the hub, the turn and the commands, none of them a surface) lead the asset list exactly as they do
     * on `/desktop`, so the host emits them before the component modules that call them.
     */
    private function live(StateSnapshot $state, ComponentContext $context, Catalog $catalog, ClientAssets &$assets): string
    {
        $assets = $assets->merge(new ClientAssets(scripts: array_map(
            static fn (string $module): string => DesktopAssets::url($module, 'js'),
            DesktopAssets::runtimeModules(),
        )));

        $id = self::attr($state->componentId);
        $gate = self::meta($state, 'gate', DesktopSettings::GATE_LOOPBACK);
        // A closure that captures `$assets` BY REFERENCE: an arrow function captures by value, and the
        // files every surface declared would be merged into a copy the page never sees.
        $paint = function (string $component) use ($context, $catalog, &$assets, $state): string {
            return $this->paint($component, $context, $catalog, $assets, $state);
        };

        $panes = '<section class="tabpane milpa-chat" data-pane="chat" id="milpa-chat" data-milpa-component="desktop-conversation" data-milpa-component-id="conversation"'
                . ' x-data="desktopConversation()" @click="onClick($event)" :hidden="$store.milpa[\'desktop.tab\'] !== \'chat\'">'
                . $paint('desktop-conversation')
                . $paint('desktop-gate')
                . '</section>'
            . '<section class="tabpane" data-pane="decisions" hidden :hidden="$store.milpa[\'desktop.tab\'] !== \'decisions\'">' . $paint('desktop-decisions') . '</section>'
            . '<section class="tabpane" data-pane="work" hidden :hidden="$store.milpa[\'desktop.tab\'] !== \'work\'">' . $paint('desktop-work-board') . '</section>'
            . '<section class="tabpane tabpane--activity" data-pane="activity" hidden :hidden="$store.milpa[\'desktop.tab\'] !== \'activity\'">' . $paint('desktop-activity') . '</section>'
            . '<section class="tabpane" data-pane="context" hidden :hidden="$store.milpa[\'desktop.tab\'] !== \'context\'">' . $paint('desktop-context') . '</section>';

        $prototypes = '';
        foreach (self::PROTOTYPES as $component => $template) {
            $prototypes .= '<template id="' . $template . '">' . $paint($component) . '</template>';
        }

        return '<div class="desktop-agent" id="' . $id . '" data-desktop-agent="' . AgentViewComponent::STATE_LIVE . '" data-gate="' . self::attr($gate) . '">'
            . $this->bar($state, $catalog, $gate)
            // Above the conversation: it names WHICH session you are reading, which is a question you
            // ask before the messages, not after them.
            . $paint('desktop-session-strip')
            . '<div class="view view--session" data-view="session" x-data>'
            . $paint('desktop-tabs')
            . '<div class="view--session__scroll">' . $panes . '</div>'
            . '<div id="milpa-composer-dock" class="view--session__dock" :hidden="$store.milpa[\'desktop.tab\'] !== \'chat\'">' . $paint('desktop-composer') . '</div>'
            . '</div>'

            . $prototypes
            . $this->dataTags($state, $catalog)
            . '</div>';
    }

    /** The guest bar: what door this Desktop stands behind, and the way out to its own page. */
    private function bar(StateSnapshot $state, Catalog $catalog, string $gate): string
    {
        return '<div class="desktop-agent__bar">'
            // 🚨 NO HAY BOTÓN «Open the Desktop», Y NO ES QUE SE HAYA MOVIDO: no hay a dónde abrir.
            // Este `<a target="_blank">` apuntaba a `/desktop`, y con la página retirada apuntaba a una
            // 404 — medido en ganado: la sección seguía pintando el botón y la ruta contestaba 404. El
            // panel ES el workspace ahora (greenhouse decisions/0283).
            . '<span class="mui-badge desktop-chip desktop-chip--gate" data-gate="' . self::attr($gate) . '">' . self::attr($catalog->tr('chip.gate', $catalog->tr('gate.kind.' . $gate))) . '</span>'
            . '</div>';
    }

    /**
     * One surface, compiled through the Desktop's own registry and contained on its own.
     *
     * A surface that throws while mounting or rendering paints a failure region naming it, and the rest of
     * the Agent — and the whole panel around it — stands (greenhouse decisions/0211, «contained errors»).
     * Whatever a surface declared is collected only when it RENDERED: a throwing surface declares nothing,
     * because nothing of it is on the page to need a file.
     *
     * **The host's context is not dropped at the door.** Each surface mounts with the `principal` the
     * admin authenticated and the `meta` it handed over (the gate label, the active section, the request's
     * query — greenhouse decisions/0211, H5), so a surface that wants to know who is reading can ask. Two
     * fields are the region's own and not the host's: the component id (each surface mounts under its own,
     * inside the region) and the `route`, which is the Desktop's live wire — what its envelopes are bound
     * to — not the panel's URL. The locale is the region's ONE catalog, never the request's ({@see
     * self::catalogFor()}).
     */
    private function paint(string $component, ComponentContext $context, Catalog $catalog, ClientAssets &$assets, ?StateSnapshot $state = null): string
    {
        try {
            $compiled = $this->live->compiler($this->propsFor($state))->compileFragment(
                '<milpa-' . $component . '/>',
                new ComponentContext(
                    componentId: 'agent-' . $component,
                    principal: $context->principal,
                    locale: $catalog->locale(),
                    route: ComposerField::ROUTE,
                    meta: $context->meta,
                ),
            );
        } catch (\Throwable $broken) {
            return '<div class="mui-alert mui-alert--warning desktop-agent__failure" role="alert" data-failed-component="' . self::attr($component) . '">'
                . '<strong>' . self::attr($catalog->tr('agent.surface.failed', $component)) . '</strong> '
                . '<span class="desktop-agent__failure-why">' . self::attr($broken->getMessage()) . '</span>'
                . '</div>';
        }
        $assets = $assets->merge($compiled->clientAssets());

        return $compiled->output;
    }

    /**
     * The props the region's surfaces mount with — the Desktop's shell chrome FOLDED, because the host
     * brings its own: the context tab shows no plugin panels the panel did not compose, and the sidebar
     * and topbar are not part of the region at all.
     *
     * @return array<string, array<string, mixed>>
     */
    /**
     * The sections third-party plugins contributed, gathered the way the page used to gather them.
     *
     * @return list<array{id: string, title: string|null, html: string}>
     */
    private function contributedSections(): array
    {
        if ($this->events === null) {
            return [];
        }
        $composition = new ShellComposition();
        $this->events->dispatch(ShellComposition::EVENT, [ShellComposition::SUBJECT_KEY => $composition]);

        return $composition->sections();
    }

    /** @return array<string, array<string, mixed>> */
    private function propsFor(?StateSnapshot $state = null): array
    {
        return [
            'desktop-context' => ['sections' => $this->contributedSections()],
            // THE CONVERSATION IS THE SESSION'S, NOT THE DEVICE'S (greenhouse decisions/0258).
            //
            // The seam was always here — `ConversationComponent` takes an `agent` prop and replays that
            // session's thread from the ledger — and this region never used it, so the panel handed an
            // EMPTY transcript while the ledger held every turn. A surface that knows which session it
            // inhabits and does not tell the thing that paints the session is the same shape as
            // decisions/0256, one surface further along.
            'desktop-conversation' => ['agent' => $state !== null ? self::agentSession($state) : ''],
        ];
    }

    /**
     * The DATA the Desktop's modules read on this page — the same four tags `/desktop` writes, minus the
     * hub's.
     *
     * The hub PAYLOAD is absent and the hub is not: this region cannot set the cookie the browser would
     * present, so the connector asks `GET /desktop/hub` for it and returns the sealed ticket written
     * below (greenhouse decisions/0253, 0256). What used to be named here as «does not reach the panel»
     * — the live reasoning of a turn in flight, and the activity stream — does reach it now, and so does
     * the agent's answer (decisions/0258): the answer no longer travels only on the `POST /agent`
     * response, which assumed whoever asked and whoever watches are the same person.
     */
    private function dataTags(StateSnapshot $state, Catalog $catalog): string
    {
        $json = static fn (mixed $value): string => (string) json_encode($value, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $gate = self::meta($state, 'gate', DesktopSettings::GATE_LOOPBACK);

        return '<script id="milpa-commands" type="application/json">' . CommandListView::json($this->data?->commands() ?? DesktopData::houseCommands()) . '</script>'
            // The client's copy is not emitted here any more: it rides in the signals every host seeds
            // once per page, which is the only way a screen SECTION ever gets it (greenhouse
            // decisions/0277).
            . '<script id="milpa-desktop-guard" type="application/json">' . $json(['signin' => $gate === DesktopSettings::GATE_PASSKEY ? self::meta($state, 'signin', AgentViewComponent::DEFAULT_SIGNIN) : '']) . '</script>'
            . '<script id="milpa-desktop-session" type="application/json">' . $json(['agent' => self::agentSession($state)]) . '</script>'
            // THE HOUSE'S DECISION, SEALED (greenhouse decisions/0256). This region cannot set a cookie, so
            // the session it inhabits travels as a signed ticket the connector returns in a header. It is
            // not a session id a client may change: the id inside is covered by the signature, and the
            // ticket names the principal it was issued to, so one lifted from another browser is inert.
            . '<script id="' . SessionTicket::TAG . '" type="application/json">'
            . $json(['ticket' => SessionTicket::issue($this->signingSecret, self::agentSession($state), \is_string($state->meta['principal'] ?? null) ? $state->meta['principal'] : '')])
            . '</script>';
    }

    /**
     * The agent session the region drives, DERIVED from who the admin authenticated.
     *
     * `/desktop` mints an id and keeps it in a cookie, so a reload continues the same governed session; a
     * component rendered inside the host's response cannot set one. Deriving it from the principal buys
     * the same continuity by other means — the same human returning to the panel returns to the same
     * session — and keeps two humans behind the same door out of each other's. With no principal (a panel
     * on the loopback gate, one operator by construction) the id is the panel's own, stable and shared.
     */
    private static function agentSession(StateSnapshot $state): string
    {
        $principal = $state->meta['principal'] ?? '';

        return 'desk-admin-' . substr(hash('sha256', 'milpa/admin|agent|' . (\is_string($principal) ? $principal : '')), 0, 16);
    }

    /** The signed-out region: no view is composed — the door, with the way back to this section. */
    private function signedOut(StateSnapshot $state, Catalog $catalog): string
    {
        $id = self::attr($state->componentId);
        $gate = self::meta($state, 'gate', DesktopSettings::GATE_PASSKEY);
        $href = self::meta($state, 'signin', AgentViewComponent::DEFAULT_SIGNIN) . '?next=' . rawurlencode(self::meta($state, 'next', AgentViewComponent::sectionPath(null)));

        return '<div class="desktop-agent" id="' . $id . '" data-desktop-agent="' . AgentViewComponent::STATE_SIGNED_OUT . '" data-gate="' . self::attr($gate) . '">'
            . '<p class="mui-alert mui-alert--info desktop-agent__signin" role="note">'
            . '<span class="mui-alert__icon" aria-hidden="true">i</span>'
            . '<span class="mui-alert__content">' . self::attr($catalog->tr('agent.signin')) . '</span> '
            . '<a class="mui-btn mui-btn--primary mui-btn--sm desktop-agent__signin-link" href="' . self::attr($href) . '">' . self::attr($catalog->tr('agent.signin.action')) . '</a>'
            . '</p>'
            . '</div>';
    }

    private static function meta(StateSnapshot $state, string $key, string $default): string
    {
        $value = $state->meta[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : $default;
    }

    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
