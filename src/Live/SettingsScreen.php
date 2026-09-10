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

namespace Milpa\AgentWorkspace\Live;

use Milpa\AgentWorkspace\Data\DesktopData;
use Milpa\AgentWorkspace\Event\RenderEvents;
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Renders the Desktop's Settings screen as a {@see SettingsScreenComponent} (greenhouse decisions/0211,
 * phase B7) — the screen that used to be raw HTML in the shell's template.
 *
 * It mounts the component, produces the four cards (model and provider, default autonomy, context and
 * storage, appearance) and the Save / Discard row, carries the signed state envelope, and emits
 * `desktop.settings.before_render` / `after_render` so plugins can extend it.
 *
 * The endpoint shown is the PERSISTED one when the app has saved one, else the configured one
 * ({@see DesktopData::settings()}, {@see DesktopData::model()}). The badge's copy comes from the
 * catalog, so the server and the client say the same word (greenhouse decisions/0209).
 */
final class SettingsScreen
{
    public const string COMPONENT_ID = 'settings';
    public const string BEFORE_RENDER = 'desktop.settings.before_render';
    public const string AFTER_RENDER = 'desktop.settings.after_render';

    /** The payload key both render events carry their mutable {@see ComposerRender} under. */
    public const string SUBJECT_KEY = 'settings';

    private readonly SignedXhtmlStateTransferCodec $codec;

    private readonly Catalog $catalog;

    /**
     * @param Catalog|null $catalog the copy the badge speaks; null answers in English
     */
    public function __construct(
        string $signingSecret,
        private readonly ?DesktopData $data = null,
        private readonly ?MilpaEventDispatcherInterface $events = null,
        ?Catalog $catalog = null,
        /**
         * Whether anything in this app can say WHO may write a credential — ASKED, not told.
         *
         * An `OperationHttpPolicy` is what judges an operation's scopes against the caller's, and an app
         * without one cannot expose `provider:declare` at all: the framework refuses to boot rather than
         * publish it unguarded (greenhouse decisions/0275). So the screen does not offer a field it
         * knows nothing can accept — a permission problem discovered on submit is a control that lied
         * while you typed into it.
         *
         * 🚨 A CLOSURE AND NOT A BOOLEAN, BECAUSE OF WHEN IT IS KNOWN. The policy is registered by
         * whichever plugin brings it, so whether it is there depends on BOOT ORDER — an app that lists
         * this plugin before the one with the policy would have been told «nobody can judge» forever.
         * Measured on cattle in the same session: `Kernel` is not registered while a plugin boots
         * either, which is the same trap one layer over (greenhouse decisions/0269, decisions/0276).
         *
         * A closure and not the container: the screen gets a QUESTION IT CAN ASK, not a world it can
         * explore.
         *
         * @var (\Closure(): bool)|null
         */
        private readonly ?\Closure $canJudge = null,
        /**
         * Whether a credential is already declared — WHETHER, never which.
         *
         * From `SecretOverlay::declared()`, which answers paths and has no reader that returns a value,
         * so this screen cannot echo a key even by mistake (greenhouse decisions/0267). A closure for
         * the same reason as above, and because a key declared while the app runs must show as declared
         * on the next render rather than on the next restart.
         *
         * @var (\Closure(): bool)|null
         */
        private readonly ?\Closure $keyDeclared = null,
    ) {
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
        $this->catalog = $catalog ?? new Catalog();
    }

    /**
     * The events `render()` dispatches, declared from the same constants it dispatches with (greenhouse decisions/0228).
     *
     * @return list<\Milpa\Interfaces\Event\EventDeclaration>
     */
    public static function events(): array
    {
        return RenderEvents::of(self::class, self::BEFORE_RENDER, self::AFTER_RENDER, self::SUBJECT_KEY, 'the Settings screen');
    }

    /** The screen's server-rendered HTML — a component with its signed envelope and a signal-bound badge. */
    public function render(bool $hidden = true): string
    {
        $component = new SettingsScreenComponent();
        $subject = new ComposerRender([
            // The host's call, not this screen's — see ScreenVisibility. Hidden by default, so the
            // shell's stacked views paint exactly as they did.
            'hidden' => $hidden,
            'endpoint' => $this->endpoint(),
            'sessionsPath' => '.milpa/sessions/',
            'savedLabel' => $this->catalog->tr('settings.saved'),
        ]);
        $this->events?->dispatch(self::BEFORE_RENDER, [self::SUBJECT_KEY => $subject]);

        $context = new ComponentContext(componentId: self::COMPONENT_ID);
        $state = $component->mount($subject->props, $context);
        $subject->html = $this->markup($subject->props) . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, [self::SUBJECT_KEY => $subject]);

        return $subject->html;
    }

    /**
     * THE ENDPOINT SHOWN IS THE ONE THE AGENT READS — one address, and it is not this screen's.
     *
     * 🚨 IT USED TO PREFER ITS OWN SAVED VALUE, AND THAT IS WHY THE FIELD LOOKED LIKE IT WORKED. The
     * form posted `endpoint` into `.milpa/desktop-settings.json` and this method read it back, so the
     * value round-tripped through the screen and never reached anything — measured on fresh cattle,
     * where the door answered `{"ok":true}`, the file held the address, and `coa agent:model` kept
     * answering `endpoint_from: none` (greenhouse decisions/0280).
     *
     * A self-read is the most convincing kind of lie a form can tell: every reload confirms it.
     *
     * So the value comes from {@see DesktopData::model()} — which is `AgentEndpoint::baseUrl()`, what
     * every turn actually resolves — and the field writes there through `config:set`, never here.
     *
     * EMPTY IS AN ANSWER. The default it once fell back to was `http://llama.local:11438`, a host that
     * stopped resolving when that machine moved to Tailscale (greenhouse decisions/0266). A form
     * pre-filled with a dead address reads as «this is what you are talking to», and saving the form
     * without touching it would DECLARE it. An empty field asks the question.
     */
    private function endpoint(): string
    {
        $configured = $this->data?->model()['endpoint'] ?? null;

        return \is_string($configured) ? $configured : '';
    }

    /** @param array<string, mixed> $props */
    private function markup(array $props): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);

        // A DECLARED VIEW (greenhouse decisions/0211): the look is `desktop-settings.css`, the behaviour is
        // the `desktopSettings` factory of `desktop-settings.js` — Save posts and reports what the door
        // answered, Discard reloads the persisted values, and the theme buttons set the SHARED `ui.theme`
        // signal the topbar's module owns, so there is one theme, not two.
        return '<div class="view milpa-settings" data-view="settings" data-milpa-runtime="alpine"'
            . ' data-milpa-component="desktop-settings" data-milpa-component-id="' . self::COMPONENT_ID . '" x-data="desktopSettings()"' . ScreenVisibility::attr($props) . '>'
            . '<div class="milpa-settings__grid">'
            . $this->modelCard($e((string) ($props['endpoint'] ?? '')))
            . $this->autonomyCard()
            . $this->storageCard($e((string) ($props['sessionsPath'] ?? '.milpa/sessions/')))
            . $this->appearanceCard()
            . '</div>'
            . $this->actions($e((string) ($props['savedLabel'] ?? 'Saved')))
            . '</div>';
    }

    /**
     * One catalog message, escaped for HTML text — every human string on this screen goes through here, so
     * a `desktop.locale = es` app reads a Spanish Settings screen and not an English island in it.
     */
    private function t(string $key, string ...$args): string
    {
        return htmlspecialchars($this->catalog->tr($key, ...$args), ENT_QUOTES);
    }

    private function modelCard(string $endpoint): string
    {
        return '<div class="mui-card mui-card--raised">'
            . '<div class="mui-card__header"><h2 class="mui-card__title">' . $this->t('settings.model.title') . '</h2></div>'
            . '<div class="mui-card__body mui-stack">'
            . $this->endpointField($endpoint)
            . $this->keyField()
            . '</div></div>';
    }

    /**
     * WHERE THE AGENT'S ADDRESS IS ACCEPTED — through `config:set`, the same way the key goes through
     * `provider:declare`.
     *
     * 🚨 THE SCREEN DOES NOT WRITE THIS FILE. `agent.baseUrl` is where every prompt and every piece of
     * context this app sends will go; two same-origin POSTs once moved it with no session at all, WITH
     * a policy registered, because `config:set` declared no scope for that policy to match. It declares
     * `config:write` now, and the framework refuses at boot to publish it unjudged (greenhouse
     * decisions/0278, decisions/0279).
     *
     * So the two states are the key field's, for the key field's reason: an app where nothing can say
     * WHO may reconfigure the agent does not get a field that will fail on submit — it gets told what
     * to install. Configuring the agent from a browser is a governed act or it is not offered.
     */
    private function endpointField(string $endpoint): string
    {
        if (!$this->ask($this->canJudge)) {
            return '<div class="mui-field milpa-settings__endpoint" data-endpoint-state="unjudgeable">'
                . '<span class="mui-field__label">' . $this->t('settings.model.endpoint') . '</span>'
                . '<div class="mui-alert mui-alert--warning" role="note">'
                . '<span class="mui-alert__icon" aria-hidden="true">⚠</span>'
                . '<div class="mui-alert__content"><p class="mui-alert__desc">' . $this->t('settings.model.endpoint.unjudgeable') . '</p>'
                . '<p class="mui-alert__desc"><code>' . $this->t('settings.model.endpoint.unjudgeable_command') . '</code></p></div>'
                . '</div></div>';
        }

        return '<div class="mui-field milpa-settings__endpoint" data-endpoint-state="' . ($endpoint === '' ? 'absent' : 'declared') . '">'
            . '<label class="mui-field__label" for="set-end">' . $this->t('settings.model.endpoint') . '</label>'
            . '<input id="set-end" class="mui-input milpa-settings__mono" value="' . $endpoint . '">'
            . '<span class="mui-field__hint">' . $this->t('settings.model.endpoint_hint') . '</span>'
            . '<button type="button" class="mui-btn mui-btn--sm milpa-settings__key-save" data-declare-endpoint'
            . ' @click="declareEndpoint()">' . $this->t('settings.model.endpoint.save') . '</button>'
            . '</div>';
    }

    /**
     * WHERE A PROVIDER CREDENTIAL IS ACCEPTED — and the three states are all measured facts.
     *
     * 🚨 IT DOES NOT RIDE IN THE SETTINGS BLOB, and that is measured rather than preferred: `POST
     * /desktop/settings` writes `.milpa/desktop-settings.json`, and on a real app's `.gitignore` —
     * checked on a fresh repository with the template's own rules — that file IS COMMITTED, while
     * `.milpa/secrets.json` is not. A key riding along with the endpoint and the theme would be a key
     * in somebody's git history (greenhouse decisions/0267, decisions/0276).
     *
     * So it goes through `provider:declare`, which demands identity for exactly this reason — two
     * same-origin POSTs once wrote a credential with no session at all until that was closed
     * (greenhouse decisions/0274).
     *
     * THE THREE STATES:
     *   - NOBODY CAN JUDGE: this app registered no `OperationHttpPolicy`, so nothing here can say who
     *     may write a credential. The field is not offered — it is not a permission problem to discover
     *     on submit — and the screen names the capability that brings one.
     *   - NO KEY: the field, empty and writable.
     *   - A KEY IS THERE: said, never shown. `SecretOverlay` has no reader that can return a value, so
     *     this screen cannot echo one even by mistake; what it knows is that a path holds one.
     */
    /** One of the two questions, asked now — false when nobody handed it over. */
    private function ask(?\Closure $question): bool
    {
        return $question instanceof \Closure && $question() === true;
    }

    private function keyField(): string
    {
        if (!$this->ask($this->canJudge)) {
            return '<div class="mui-field milpa-settings__key" data-key-state="unjudgeable">'
                . '<span class="mui-field__label">' . $this->t('settings.model.key') . '</span>'
                . '<div class="mui-alert mui-alert--warning" role="note">'
                . '<span class="mui-alert__icon" aria-hidden="true">⚠</span>'
                . '<div class="mui-alert__content"><p class="mui-alert__desc">' . $this->t('settings.model.key.unjudgeable') . '</p>'
                . '<p class="mui-alert__desc"><code>' . $this->t('settings.model.key.unjudgeable_command') . '</code></p></div>'
                . '</div></div>';
        }

        $held = $this->ask($this->keyDeclared);

        return '<div class="mui-field milpa-settings__key" data-key-state="' . ($held ? 'held' : 'absent') . '">'
            . '<label class="mui-field__label" for="set-key">' . $this->t('settings.model.key') . '</label>'
            . '<input id="set-key" class="mui-input milpa-settings__mono" type="password" autocomplete="off"'
            . ' placeholder="' . $this->t($held ? 'settings.model.key.replace' : 'settings.model.key.placeholder') . '">'
            . '<span class="mui-field__hint">' . $this->t($held ? 'settings.model.key.held' : 'settings.model.key.hint') . '</span>'
            // The verb, not a listener: the screen's root carries `x-data="desktopSettings()"`, so the
            // button asks the module the same way Save does — one runtime, no second wiring
            // (greenhouse decisions/0273: a surface owns the controls it prints).
            . '<button type="button" class="mui-btn mui-btn--sm milpa-settings__key-save" data-declare-key'
            . ' @click="declareKey()">' . $this->t('settings.model.key.save') . '</button>'
            . '</div>';
    }

    /**
     * The three autonomy choices. The BADGES («ask», «acknowledge», «auto») are the mode's own values —
     * what `/mode` takes and what the turn carries — so they are not copy and are not translated.
     */
    private function autonomyCard(): string
    {
        // WHAT IS STORED, NEVER `ask` BY HABIT. The three were printed with `ask` checked no matter what
        // the app had saved, so the screen forgot your choice on the next render while the composer's
        // chip — reading the same key — showed the mode you had picked. Two surfaces, one value, and
        // only one of them was reading it (greenhouse decisions/0280).
        $settings = $this->data?->settings() ?? [];
        $mode = \is_string($settings['mode'] ?? null) && $settings['mode'] !== '' ? (string) $settings['mode'] : 'ask';
        $choice = fn (string $mode, bool $checked): string => '<label class="mui-choice"><input class="mui-radio" type="radio" name="set-mode" value="' . $mode . '"' . ($checked ? ' checked="checked"' : '') . '>'
            . '<span class="mui-choice__text">' . $this->t('settings.autonomy.' . $mode) . ' <span class="mui-badge milpa-settings__badge">' . $mode . '</span>'
            . '<span class="mui-choice__hint">' . $this->t('settings.autonomy.' . $mode . '_hint') . '</span></span></label>';

        return '<div class="mui-card mui-card--raised">'
            . '<div class="mui-card__header"><h2 class="mui-card__title">' . $this->t('settings.autonomy.title') . '</h2></div>'
            . '<div class="mui-card__body mui-stack mui-stack--sm">'
            . $choice('ask', $mode === 'ask') . $choice('acknowledge', $mode === 'acknowledge') . $choice('auto', $mode === 'auto')
            . '<div class="mui-alert mui-alert--info" role="note"><span class="mui-alert__icon" aria-hidden="true">i</span><div class="mui-alert__content"><p class="mui-alert__desc">' . $this->t('settings.autonomy.note') . '</p></div></div>'
            . '</div></div>';
    }

    /**
     * Context and storage — and the compaction SWITCH is gone, because there is nothing to switch.
     *
     * It posted `compact: true|false` into the settings blob and nothing in this package or the
     * framework read it. Worse than unread: `agent.compaction` is not a boolean at all, it is a policy
     * of three numbers (`maxTurns`, `keepLast`, `maxTokens`), so the control offered an off position
     * the framework does not have. The honest form of this control is those three numbers through
     * `config:set`, and it returns when someone needs them (greenhouse decisions/0280).
     */
    private function storageCard(string $sessionsPath): string
    {
        return '<div class="mui-card">'
            . '<div class="mui-card__header"><h2 class="mui-card__title">' . $this->t('settings.storage.title') . '</h2></div>'
            . '<div class="mui-card__body mui-stack mui-stack--sm">'
            . '<p class="milpa-settings__note">' . $this->t('settings.storage.compact_note') . '</p>'
            . '<div class="mui-field"><label class="mui-field__label" for="set-path">' . $this->t('settings.storage.folder') . '</label><input id="set-path" class="mui-input mui-input--sm milpa-settings__mono" value="' . $sessionsPath . '" readonly="readonly"></div>'
            . '</div></div>';
    }

    /**
     * Appearance: the three theme buttons set the SHARED `ui.theme` signal, and `aria-pressed` BINDS to it
     * — so the chrome's toggle and these buttons can never disagree about what the shell is showing.
     *
     * 🚨 THE INTERFACE-SCALE ROW IS GONE, and it is the cheapest lesson on this screen: three buttons
     * with no `@click`, no `data-*`, and a hardcoded `aria-pressed="true"` on the first. Nothing read
     * them because nothing could — there is no `--mui-scale` in `milpa-design` for a scale to mean
     * anything. It comes back when the design system has one to bind to (greenhouse decisions/0280).
     */
    private function appearanceCard(): string
    {
        $buttons = '';
        foreach (['system', 'dark', 'light'] as $key) {
            $buttons .= sprintf(
                '<button type="button" class="mui-btn mui-btn--sm" data-theme-set="%s"%s @click="setTheme(\'%s\')" :aria-pressed="isTheme(\'%s\')">%s</button>',
                $key,
                $key === 'dark' ? ' aria-pressed="true"' : '',
                $key,
                $key,
                $this->t('settings.theme.' . $key),
            );
        }

        return '<div class="mui-card">'
            . '<div class="mui-card__header"><h2 class="mui-card__title">' . $this->t('settings.appearance.title') . '</h2></div>'
            . '<div class="mui-card__body mui-stack mui-stack--sm">'
            . '<div class="mui-field"><span class="mui-field__label">' . $this->t('settings.appearance.theme') . '</span><div class="mui-cluster mui-cluster--sm">' . $buttons . '</div></div>'
            . '</div></div>';
    }

    /**
     * The action row. The badge is the `settings.saved` signal: hidden until a save is reported, green on
     * a 2xx, a warning naming the status on anything else — never a «Saved» the server did not say.
     */
    private function actions(string $savedLabel): string
    {
        return '<div class="mui-cluster milpa-settings__actions">'
            . '<span id="milpa-settings-saved" class="mui-badge mui-badge--success" hidden'
            . ' x-text="savedText" :hidden="!saved" :class="{ \'mui-badge--success\': savedOk, \'mui-badge--warning\': !savedOk }">' . $savedLabel . '</span>'
            . '<button type="button" class="mui-btn" id="milpa-discard" @click="discard()">' . $this->t('settings.discard') . '</button>'
            . '<button type="button" class="mui-btn mui-btn--primary" id="milpa-save-settings" @click="save()">' . $this->t('settings.save') . '</button>'
            . '</div>';
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }
}
