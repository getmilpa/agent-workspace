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
 * Renders the Desktop shell's Context tab as a {@see ContextComponent} — the sixth surface of the "shell is
 * pure Milpa Components" migration (greenhouse decisions/0189). It mounts the component, produces the
 * design-system panel grid from the panels other plugins contributed, carries the signed state envelope, and
 * emits `desktop.context.before_render` / `after_render` so other plugins can extend it.
 *
 * The panels come from the {@see \Milpa\AgentWorkspace\ShellComposition} (via `addPanel`), assembled per request;
 * this component is their container, unchanged in how a plugin contributes.
 */
final class Context
{
    public const string COMPONENT_ID = 'context';
    public const string BEFORE_RENDER = 'desktop.context.before_render';
    public const string AFTER_RENDER = 'desktop.context.after_render';

    /** The payload key both render events carry their mutable {@see ComposerRender} under. */
    public const string SUBJECT_KEY = 'context';

    private readonly SignedXhtmlStateTransferCodec $codec;

    public function __construct(
        string $signingSecret,
        private readonly ?MilpaEventDispatcherInterface $events = null,
        private readonly ?DesktopData $data = null,
        private readonly ?Catalog $catalog = null,
    ) {
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
    }

    /**
     * The events `render()` dispatches, declared from the same constants it dispatches with (greenhouse decisions/0228).
     *
     * @return list<\Milpa\Interfaces\Event\EventDeclaration>
     */
    public static function events(): array
    {
        return RenderEvents::of(self::class, self::BEFORE_RENDER, self::AFTER_RENDER, self::SUBJECT_KEY, 'the Context tab');
    }

    /**
     * The Context tab's server-rendered HTML — the panel grid as a component, or the empty state.
     *
     * @param list<array{id: string, title: string|null, html: string}> $panels
     */
    public function render(array $panels): string
    {
        $component = new ContextComponent();
        $subject = new ComposerRender(['panels' => $panels]);
        $this->events?->dispatch(self::BEFORE_RENDER, [self::SUBJECT_KEY => $subject]);

        $context = new ComponentContext(componentId: self::COMPONENT_ID);
        $state = $component->mount($subject->props, $context);
        $subject->html = $this->markup($subject->props) . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, [self::SUBJECT_KEY => $subject]);

        return $subject->html;
    }

    /** @param array<string, mixed> $props */
    private function markup(array $props): string
    {
        /** @var list<array{id: string, title: string|null, html: string}> $panels */
        $panels = \is_array($props['panels'] ?? null) ? $props['panels'] : [];
        $wrap = 'data-milpa-component="desktop-context" data-milpa-component-id="' . self::COMPONENT_ID . '"';

        $body = $this->contextCards();

        $contributed = '';
        foreach ($panels as $panel) {
            $header = $panel['title'] !== null
                ? '<div class="mui-card__header"><h2 class="mui-card__title">' . htmlspecialchars($panel['title'], ENT_QUOTES) . '</h2></div>'
                : '';
            $contributed .= sprintf(
                '<section class="mui-card" data-panel="%1$s" data-plugin="%1$s">%2$s<div class="mui-card__body" data-panel-body>%3$s</div></section>',
                htmlspecialchars($panel['id'], ENT_QUOTES),
                $header,
                $panel['html'],
            );
        }
        // A PLUGIN'S PANELS ARE AN ADDITION, NOT THE CONTENT. When nothing contributed one this region
        // simply is not there — no note, no placeholder. The tab used to consist of nothing BUT this
        // region, so an app with no contributing plugin opened «Context» and read «No plugin has
        // contributed a panel yet», which answers a question about the extension mechanism to somebody
        // who asked what the agent can see (greenhouse decisions/0288, Rod's reading of it).
        if ($contributed !== '') {
            $body .= '<h2 class="ctx-heading">' . $this->tr('context.panels.title') . '</h2>'
                . '<div class="panel-grid">' . $contributed . '</div>';
        }

        return '<div class="ctx" ' . $wrap . '>' . $body . '</div>';
    }

    /**
     * THE CONTEXT ITSELF: what the model receives on the next turn, from data this package already had.
     *
     * Every number here is read, never derived for display: the window and its usage from
     * {@see DesktopData::context()} — which the composer's chip was ALREADY reading while the tab named
     * Context read nothing — the model and where each value was declared from
     * {@see DesktopData::model()}, the turn counters from {@see DesktopData::counters()}, and the counts
     * of what the agent carries from the same lists the Skills and Subagents screens paint.
     *
     * Absent a `DesktopData` (as in the fallback surface and in unit tests) this is empty and the tab is
     * the panel region alone, which is what it was before.
     */
    private function contextCards(): string
    {
        if ($this->data === null) {
            return '';
        }

        return '<div class="ctx-grid">'
            . $this->windowCard()
            . $this->modelCard()
            . $this->sessionCard()
            . $this->carriesCard()
            . '</div>';
    }

    /** The window: how much of what the model can hold is already spoken for. */
    private function windowCard(): string
    {
        $ctx = $this->data?->context() ?? ['tokens' => 0, 'window' => 0, 'used_pct' => 0, 'free' => 0];
        $tokens = (int) $ctx['tokens'];
        $window = (int) $ctx['window'];
        $pct = (int) $ctx['used_pct'];

        // THE VALUE IS ALWAYS THE NUMBER, and the sentence is always the note. The first build put
        // «nothing has been sent yet» in `mui-stat__value` when usage was zero, which set a whole
        // sentence in display type and made the one card carrying a measurement the hardest to read.
        // A stat's value slot is for the quantity; what the quantity MEANS goes in the meta.
        //
        // Both strings are already safe: `tr()` escapes the copy and `compact()` yields digits with a
        // suffix. Escaping them AGAIN would double-encode any entity a locale puts in the message.
        $value = self::compact($tokens) . ' / ' . self::compact($window);
        $meta = $tokens === 0
            ? $this->tr('context.window.undeclared')
            : sprintf($this->tr('context.window.meta'), $pct, self::compact((int) $ctx['free']));

        return '<section class="mui-card ctx-card"><div class="mui-card__body">'
            . '<p class="mui-stat__label">' . $this->tr('context.window.title') . '</p>'
            . '<p class="mui-stat__value">' . $value . '</p>'
            . '<div class="ctx-meter" role="img" aria-label="' . $meta . '">'
            . '<span class="ctx-meter__fill" style="width:' . max(0, min(100, $pct)) . '%"></span></div>'
            . '<p class="mui-stat__meta">' . $meta . '</p>'
            . '</div></section>';
    }

    /** The model, and WHERE each value was declared — a name with no provenance is a guess. */
    private function modelCard(): string
    {
        $model = $this->data?->model() ?? [];
        $name = \is_string($model['model'] ?? null) ? $model['model'] : '';
        $endpoint = \is_string($model['endpoint'] ?? null) ? $model['endpoint'] : '';
        $from = \is_string($model['model_from'] ?? null) ? $model['model_from'] : 'none';

        $rows = $name === ''
            ? '<p class="mui-stat__meta">' . $this->tr('context.model.none') . '</p>'
            : '<p class="mui-stat__value ctx-mono">' . $this->esc($name) . '</p>'
                . '<p class="mui-stat__meta">' . sprintf($this->tr('context.model.source'), $this->esc($from)) . '</p>';

        $rows .= $endpoint === ''
            ? '<p class="mui-stat__meta">' . $this->tr('context.model.unreachable') . '</p>'
            : '<p class="mui-stat__meta ctx-mono">' . $this->tr('context.model.endpoint') . ': ' . $this->esc($endpoint) . '</p>';

        return '<section class="mui-card ctx-card"><div class="mui-card__body">'
            . '<p class="mui-stat__label">' . $this->tr('context.model.title') . '</p>' . $rows
            . '</div></section>';
    }

    /** What this session has spent so far. */
    private function sessionCard(): string
    {
        if ($this->data?->hasSession() !== true) {
            return '<section class="mui-card ctx-card"><div class="mui-card__body">'
                . '<p class="mui-stat__label">' . $this->tr('context.session.title') . '</p>'
                . '<p class="mui-stat__meta">' . $this->tr('context.session.none') . '</p>'
                . '</div></section>';
        }

        $c = $this->data->counters();

        return '<section class="mui-card ctx-card"><div class="mui-card__body">'
            . '<p class="mui-stat__label">' . $this->tr('context.session.title') . '</p>'
            . '<dl class="ctx-pairs">'
            . $this->pair($this->tr('context.session.turns'), (string) (int) $c['turns'])
            . $this->pair($this->tr('context.session.steps'), (string) (int) $c['steps'])
            . $this->pair($this->tr('context.session.tools'), (string) (int) $c['tool_calls'])
            . $this->pair($this->tr('context.session.state'), (string) $c['state'])
            . '</dl></div></section>';
    }

    /** Skills, roles and tools: what the agent brings to the turn besides the transcript. */
    private function carriesCard(): string
    {
        $skills = \count($this->data?->skills() ?? []);
        $roles = \count($this->data?->roles() ?? []);
        $tools = \count($this->data?->commands() ?? []);

        return '<section class="mui-card ctx-card"><div class="mui-card__body">'
            . '<p class="mui-stat__label">' . $this->tr('context.carries.title') . '</p>'
            . '<dl class="ctx-pairs">'
            . $this->pair($this->tr('context.carries.skills'), (string) $skills)
            . $this->pair($this->tr('context.carries.roles'), (string) $roles)
            . $this->pair($this->tr('context.carries.tools'), (string) $tools)
            . '</dl></div></section>';
    }

    /** One label/value row of a definition list. */
    private function pair(string $label, string $value): string
    {
        // The label arrives from `tr()` already escaped; the value is raw data and is escaped here.
        return '<dt>' . $label . '</dt><dd>' . $this->esc($value) . '</dd>';
    }

    /**
     * A token count a person can read at a glance: `1.2K`, `32.8K`, `1.4M`.
     *
     * The composer's chip shows `0.00K/32.77K` because a budget wants precision; a context card wants
     * the magnitude, so this rounds to one decimal and drops a trailing `.0`.
     */
    private static function compact(int $n): string
    {
        if ($n >= 1_000_000) {
            return rtrim(rtrim(number_format($n / 1_000_000, 1, '.', ''), '0'), '.') . 'M';
        }
        if ($n >= 1000) {
            return rtrim(rtrim(number_format($n / 1000, 1, '.', ''), '0'), '.') . 'K';
        }

        return (string) $n;
    }

    /** One catalog message, escaped for the markup. */
    private function tr(string $key): string
    {
        return $this->esc(($this->catalog ?? new Catalog())->tr($key));
    }

    /** Escaped for an HTML text node or a double-quoted attribute. */
    private function esc(string $raw): string
    {
        return htmlspecialchars($raw, ENT_QUOTES);
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }
}
