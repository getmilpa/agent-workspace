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

    /**
     * NOTHING CAN SAY WHO MAY WRITE: the app registered no `OperationHttpPolicy`.
     *
     * The framework refuses to boot rather than publish a consent-demanding operation unguarded
     * (greenhouse decisions/0279), so an app in this state cannot expose `provider:declare` or
     * `config:set` at all. The capability that brings a policy is the sentence to say.
     */
    public const string NO_POLICY = 'no-policy';

    /**
     * NOBODY CAN BE JUDGED: a policy is registered and no door can produce a principal.
     *
     * 🚨 THIS STATE IS WHY THE QUESTION CHANGED. Measured on cattle with `milpa/auth` installed and
     * `passkey.rpId` absent: the policy is in the container, `AuthContextFactory` is not, and the
     * passkey door mounts ZERO routes — so the fields were offered and pressing save answered 500
     * (`AuthMiddlewareNotInstalledException`, correctly a host misconfiguration rather than a 401).
     *
     * A judge with nobody it can judge is not an answer, and «there is a policy» was the wrong
     * question to have asked (greenhouse decisions/0285).
     */
    public const string NO_DOOR = 'no-door';
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
         * 🚨 IT ANSWERS WHAT IS MISSING, NOT YES OR NO. It used to return `bool` and the boolean could
         * only carry one sentence — so an app with `milpa/auth` installed and no `passkey.rpId` was told
         * to install a package it already had. `''` offers the fields; {@see NO_POLICY} and
         * {@see NO_DOOR} each name their own fix (greenhouse decisions/0285).
         *
         * @var (\Closure(): string)|null
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
            . (SettingsControls::offered('set-end') ? $this->endpointField($endpoint) : '')
            . (SettingsControls::offered('set-model') ? $this->modelField() : '')
            . (SettingsControls::offered('set-key') ? $this->keyField() : '')
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
        $blocker = $this->blocker();
        if ($blocker !== '') {
            return $this->cannotWrite('endpoint', 'settings.model.endpoint', $blocker);
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
     * WHICH MODEL, out of what the provider actually serves — and the list is NOT fetched on render.
     *
     * 🚨 THE TIMING IS THE DESIGN, and it is measured. Against a dead endpoint (TEST-NET 192.0.2.1)
     * `agent:model` costs 5.0 s and `agent:model --ask=false` costs 0.06 s. So this field paints from
     * what was DECLARED — a config read, no egress — and the provider is asked only when a person
     * presses «Find models». A screen that populated the list while rendering would reintroduce, at
     * the operation, the exact five seconds that greenhouse decisions/0266 took out of five surfaces.
     *
     * (The flag works and the default is to probe: `ask` defaults true, which is the declared
     * contract. It is the CALLER's job to pass `ask=false` when it is only painting.)
     *
     * The select carries the declared model even when the provider was never asked, because «what this
     * app declared» is knowable without a network and is the answer a person came for.
     */
    private function modelField(): string
    {
        $declared = $this->data?->model()['model'] ?? null;
        $declared = \is_string($declared) ? $declared : '';
        $option = $declared === ''
            ? '<option value="">' . $this->t('settings.model.model.none') . '</option>'
            : '<option value="' . htmlspecialchars($declared, ENT_QUOTES) . '" selected="selected">' . htmlspecialchars($declared, ENT_QUOTES) . '</option>';

        return '<div class="mui-field milpa-settings__model" data-model-state="' . ($declared === '' ? 'absent' : 'declared') . '">'
            . '<label class="mui-field__label" for="set-model">' . $this->t('settings.model.model') . '</label>'
            . '<span class="mui-select-wrap"><select id="set-model" class="mui-select" @change="declareModel()">' . $option . '</select></span>'
            . '<span class="mui-field__hint">' . $this->t('settings.model.model_hint') . '</span>'
            . '<button type="button" class="mui-btn mui-btn--sm milpa-settings__key-save" data-find-models'
            . ' @click="findModels()">' . $this->t('settings.model.model.find') . '</button>'
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
    /** One of the yes-or-no questions, asked now — false when nobody handed it over. */
    private function ask(?\Closure $question): bool
    {
        return $question instanceof \Closure && $question() === true;
    }

    /**
     * What stops a write from being authorized here: `''`, {@see NO_POLICY} or {@see NO_DOOR}.
     *
     * A host that hands over no question gets {@see NO_POLICY}: a screen that cannot ask must not
     * offer, and the safer of the two sentences is the one that names a capability to install.
     */
    /**
     * THE FIELD IS NOT OFFERED, AND THE NOTICE NAMES WHICH OF THE TWO THINGS IS MISSING.
     *
     * One renderer for both fields: the endpoint's refusal and the key's said the same shape twice,
     * differing only in a label and two catalog keys. And the shape is the rule this screen exists to
     * keep — a permission problem discovered on SUBMIT is a control that lied while you typed into it
     * (greenhouse decisions/0276).
     *
     * `$blocker` picks the sentence: {@see NO_POLICY} names the capability to install,
     * {@see NO_DOOR} names the config key that mounts the door. Two causes, two fixes, and telling
     * somebody to install a package they already have is the failure this replaced
     * (greenhouse decisions/0285).
     */
    private function cannotWrite(string $state, string $label, string $blocker): string
    {
        $why = $blocker === self::NO_DOOR ? 'settings.write.no_door' : 'settings.write.no_policy';
        $how = $blocker === self::NO_DOOR ? 'settings.write.no_door_command' : 'settings.write.no_policy_command';

        return '<div class="mui-field milpa-settings__' . $state . '" data-' . $state . '-state="unjudgeable" data-blocked-by="' . $blocker . '">'
            . '<span class="mui-field__label">' . $this->t($label) . '</span>'
            . '<div class="mui-alert mui-alert--warning" role="note">'
            . '<span class="mui-alert__icon" aria-hidden="true">⚠</span>'
            . '<div class="mui-alert__content"><p class="mui-alert__desc">' . $this->t($why) . '</p>'
            . '<p class="mui-alert__desc"><code>' . $this->t($how) . '</code></p></div>'
            . '</div></div>';
    }

    private function blocker(): string
    {
        if (!$this->canJudge instanceof \Closure) {
            return self::NO_POLICY;
        }
        return $this->canJudge->__invoke();
    }

    private function keyField(): string
    {
        $blocker = $this->blocker();
        if ($blocker !== '') {
            return $this->cannotWrite('key', 'settings.model.key', $blocker);
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
            . (SettingsControls::offered('set-mode')
                ? $choice('ask', $mode === 'ask') . $choice('acknowledge', $mode === 'acknowledge') . $choice('auto', $mode === 'auto')
                : '')
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
            . (SettingsControls::offered('set-path')
                ? '<div class="mui-field"><label class="mui-field__label" for="set-path">' . $this->t('settings.storage.folder') . '</label><input id="set-path" class="mui-input mui-input--sm milpa-settings__mono" value="' . $sessionsPath . '" readonly="readonly"></div>'
                : '')
            . '</div></div>';
    }

    /*
     * NO HAY TARJETA «APARIENCIA» AQUÍ, Y ES UNA CONSECUENCIA DEL RETIRO DE LA PÁGINA.
     *
     * 🚨 UN INVITADO NO ES DUEÑO DEL TEMA DEL DOCUMENTO. Los tres botones de tema llamaban
     * `MilpaLive.desktop.theme.set()`, un objeto que crea ÚNICAMENTE `desktop-topbar.js` — y el panel
     * nunca emite ese módulo, porque ninguna sección pinta el topbar. Así que en el panel esos botones
     * **ya no hacían nada, en silencio**, exactamente la clase de control que `decisions/0280` retiró de
     * esta misma pantalla; mi propio falsificador no lo vio porque revisa el MAPA, no si el lector está
     * presente en la superficie que se está pintando.
     *
     * Y midiendo se ve por qué no era un cableado que faltaba: `milpa/admin` tiene su PROPIO tema, con
     * su `earlyThemeScript()` escribiendo el mismo `data-theme` de la raíz y recordándolo bajo su propia
     * llave. Eran dos puertas para un hecho, y la del invitado perdía por no cargarse.
     *
     * Mientras existió `/desktop` el workspace ERA el anfitrión y su tema tenía sentido. Sin esa página
     * el workspace es siempre invitado, y el tema del documento es del anfitrión (greenhouse
     * decisions/0283). La escala se fue por la misma regla un acta antes.
     */

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
