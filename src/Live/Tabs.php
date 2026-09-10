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

use Milpa\AgentWorkspace\Event\RenderEvents;
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Renders the Desktop shell's tablist as a {@see TabsComponent} — the third surface of the "shell is pure Milpa
 * Components" migration (greenhouse decisions/0189). It mounts the component, produces the design-system
 * tablist, binds each tab to the shared `desktop.tab` signal (click sets it, aria-selected tracks it), carries
 * the signed state envelope, and emits `desktop.tabs.before_render` / `after_render` so plugins can extend it.
 *
 * The panes below and the composer dock read the same `desktop.tab` signal to show/hide — no imperative JS.
 */
final class Tabs
{
    public const string COMPONENT_ID = 'tabs';
    public const string BEFORE_RENDER = 'desktop.tabs.before_render';
    public const string AFTER_RENDER = 'desktop.tabs.after_render';

    /** The payload key both render events carry their mutable {@see ComposerRender} under. */
    public const string SUBJECT_KEY = 'tabs';

    /**
     * The panes, and THE CATALOG KEY EACH IS NAMED BY.
     *
     * 🚨 These were English literals — the same defect as `Sidebar::NAV`, one file over, found the
     * same day: a person who chose Spanish got a Spanish panel with English tabs
     * (greenhouse decisions/0139, decisions/0270).
     *
     * `decisions` is new here. The inbox of parked questions was declared and painted NOWHERE:
     * measured on the rendered panel, nothing carried it. I had claimed an hour earlier that it
     * «stays a region of the conversation rather than a screen» — a fact I asserted about the page
     * without measuring it. A governed agent that parks a question needs somebody to see it, and a
     * tab is where a count of waiting decisions can live (greenhouse decisions/0195).
     *
     * @var list<array{key: string, title: string}>
     */
    private const TABS = [
        ['key' => 'chat', 'title' => 'tab.chat'],
        ['key' => 'decisions', 'title' => 'tab.decisions'],
        ['key' => 'work', 'title' => 'tab.work'],
        ['key' => 'activity', 'title' => 'tab.activity'],
        ['key' => 'context', 'title' => 'tab.context'],
    ];

    private readonly SignedXhtmlStateTransferCodec $codec;

    private readonly Catalog $catalog;

    public function __construct(
        string $signingSecret,
        private readonly ?MilpaEventDispatcherInterface $events = null,
        ?Catalog $catalog = null,
    ) {
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
        // Optional and defaulted, like every other surface here: a host that has not chosen a locale
        // gets the English default rather than a fatal.
        $this->catalog = $catalog ?? new Catalog();
    }

    /**
     * The events `render()` dispatches, declared from the same constants it dispatches with (greenhouse decisions/0228).
     *
     * @return list<\Milpa\Interfaces\Event\EventDeclaration>
     */
    public static function events(): array
    {
        return RenderEvents::of(self::class, self::BEFORE_RENDER, self::AFTER_RENDER, self::SUBJECT_KEY, 'the tablist');
    }

    /** The tablist's server-rendered HTML — a component with its signed envelope and a signal-driven active tab. */
    public function render(): string
    {
        $component = new TabsComponent();
        $props = ['tabs' => self::TABS, 'activeTab' => 'chat'];
        $subject = new ComposerRender($props);
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
        $active = (string) ($props['activeTab'] ?? 'chat');
        /** @var list<array{key: string, title?: string, label?: string}> $tabs */
        $tabs = \is_array($props['tabs'] ?? null) ? $props['tabs'] : self::TABS;

        // A DECLARED VIEW (greenhouse decisions/0211): the look is `desktop-tabs.css`, the behaviour is the
        // `desktopTabs` factory of `desktop-tabs.js` — both declared by the renderer, neither inline.
        return '<div class="mui-tabs milpa-tabs" role="tablist" data-milpa-runtime="alpine" data-milpa-component="desktop-tabs" data-milpa-component-id="' . self::COMPONENT_ID . '"'
            . ' x-data="desktopTabs({ signal: \'' . TabsComponent::TAB_SIGNAL . '\', active: \'' . htmlspecialchars($active, ENT_QUOTES) . '\' })">'
            . $this->tabButtons($tabs, $active)
            . '</div>';
    }

    /**
     * @param list<array{key: string, title?: string, label?: string}> $tabs
     */
    private function tabButtons(array $tabs, string $active): string
    {
        $out = '';
        foreach ($tabs as $tab) {
            $key = $tab['key'];
            // The active tab is the shared `desktop.tab` signal: the component's own factory sets it (instant
            // switch), the panes and the composer dock read the same signal, and aria-selected tracks it —
            // one truth, no imperative JS and no `$store` reached into from the markup.
            $out .= sprintf(
                '<button class="mui-tabs__tab" role="tab" type="button" data-tab="%s" @click="select(\'%s\')" :aria-selected="isActive(\'%s\')" aria-selected="%s">%s</button>',
                $key,
                $key,
                $key,
                $key === $active ? 'true' : 'false',
                // A subscriber may hand a literal `label`; the panes this surface declares carry a
                // catalog `title` instead, so the tablist answers in the page's language.
                htmlspecialchars(
                    isset($tab['title']) ? $this->catalog->tr($tab['title']) : (string) ($tab['label'] ?? $tab['key']),
                    ENT_QUOTES,
                ),
            );
        }

        return $out;
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }
}
