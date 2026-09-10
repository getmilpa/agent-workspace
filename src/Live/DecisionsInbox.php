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
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Renders the {@see DecisionsInboxComponent} — the decisions screen (greenhouse decisions/0211, phase D4).
 *
 * The cards themselves stay the pure {@see DecisionsInboxView} (tested with fixtures); this renderer wraps
 * them with the screen's lede, its signed envelope, its lifecycle events — and the CARD PROTOTYPE the
 * module clones when a question is parked while the page is open.
 *
 * That prototype is what makes the live inbox a declared view: the page's inline script used to build a
 * card with `createElement`, so its markup existed only inside a function. `desktop-decisions.js` clones
 * this `<template>` and fills two regions, exactly the way a conversation message kind lands.
 */
final class DecisionsInbox
{
    public const string COMPONENT_ID = 'decisions';

    /** Dispatched with a mutable {@see ComposerRender} BEFORE the render — a subscriber may change its props. */
    public const string BEFORE_RENDER = 'desktop.decisions.before_render';

    /** Dispatched with a mutable {@see ComposerRender} AFTER the render — a subscriber may change its html. */
    public const string AFTER_RENDER = 'desktop.decisions.after_render';

    /** The payload key both render events carry their mutable {@see ComposerRender} under. */
    public const string SUBJECT_KEY = 'decisions';

    public function __construct(
        private readonly SignedXhtmlStateTransferCodec $codec,
        private readonly ?DesktopData $data = null,
        private readonly ?MilpaEventDispatcherInterface $events = null,
        private readonly ?Catalog $catalog = null,
        private readonly string $principal = '',
    ) {
    }

    /**
     * The events `render()` dispatches, declared from the same constants it dispatches with (greenhouse decisions/0228).
     *
     * @return list<\Milpa\Interfaces\Event\EventDeclaration>
     */
    public static function events(): array
    {
        return RenderEvents::of(self::class, self::BEFORE_RENDER, self::AFTER_RENDER, self::SUBJECT_KEY, 'the Decisions inbox');
    }

    /** The screen, with its signed envelope, after the render events a plugin may extend it through. */
    public function render(bool $hidden = true): string
    {
        $subject = new ComposerRender([
            // The host's call, not this screen's — see ScreenVisibility. Hidden by default, so the
            // shell's stacked views paint exactly as they did.
            'hidden' => $hidden,
            'pending' => $this->data?->pendingDecisions() ?? [],
            'graphs' => $this->data?->pendingGraphDecisions() ?? [],
            'sequences' => $this->data?->declaredSequences() ?? [],
            'principal' => $this->principal,
        ]);
        $this->events?->dispatch(self::BEFORE_RENDER, [self::SUBJECT_KEY => $subject]);

        $state = (new DecisionsInboxComponent())->mount($subject->props, new ComponentContext(componentId: self::COMPONENT_ID));
        $subject->html = $this->markup($subject->props) . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, [self::SUBJECT_KEY => $subject]);

        return $subject->html;
    }

    /** @param array<string, mixed> $props */
    private function markup(array $props): string
    {
        /** @var list<array{session: string, goal: string, question: string, operation: string, reason: string}> $pending */
        $pending = \is_array($props['pending'] ?? null) ? $props['pending'] : [];
        /** @var list<array{graph: string, instance: string, question: string, options: list<string>, requester: string}> $graphs */
        $graphs = \is_array($props['graphs'] ?? null) ? $props['graphs'] : [];
        /** @var list<array{name: string, steps: list<string>, session: string, paused: bool, pending_operation: string}> $sequences */
        $sequences = \is_array($props['sequences'] ?? null) ? $props['sequences'] : [];
        $copy = [
            'run' => $this->plain('decisions.run'),
            'approve' => $this->plain('decisions.approve'),
            'deny' => $this->plain('decisions.deny'),
            'paused_on' => $this->plain('decisions.paused_on'),
        ];
        $view = new DecisionsInboxView();

        return '<div class="view milpa-decisions" data-view="decisions"'
            . ' data-milpa-component="desktop-decisions" data-milpa-component-id="' . self::COMPONENT_ID . '"' . ScreenVisibility::attr($props) . '>'
            . '<p class="milpa-decisions__intro">' . $this->tr('decisions.intro') . '</p>'
            . $view->html($pending, $this->plain('decisions.empty'), $graphs, (string) ($props['principal'] ?? ''), $copy)
            // THE SEQUENCES THIS APP DECLARED, to run from here (greenhouse decisions/0223, F4): a deployment
            // is a list, and the place a human authorizes everything else is where its run starts and where
            // its pause is answered.
            . '<h3 class="milpa-decisions__heading">' . $this->tr('decisions.sequences') . '</h3>'
            . '<p class="milpa-decisions__intro">' . $this->tr('decisions.sequences_intro') . '</p>'
            . $view->sequencesHtml($sequences, $this->plain('decisions.sequences_empty'), $copy)
            // The prototype for a GRAPH card, always printed: the module reaches for these hooks, and a hook
            // no page ever carries is a module talking to itself (this package's DOM contract refuses it).
            . '<template id="milpa-graph-decision-proto">'
            . '<li class="decision-card decision-card--graph" data-graph data-graph-instance data-graph-principal>'
            . '<p class="decision-card__goal"></p><p class="decision-card__q"></p>'
            . '<p class="decision-card__options"><button type="button" class="mui-btn mui-btn--sm decision-card__option" data-graph-decide></button></p>'
            . '</li>'
            . '</template>'
            . '<template id="milpa-decision-proto">'
            . '<li class="decision-card"><p class="decision-card__q" data-decision-question></p>'
            . '<p class="decision-card__facts" data-decision-facts></p></li>'
            . '</template>'
            . '</div>';
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }

    /** One catalog message, escaped for the markup. */
    private function tr(string $key): string
    {
        return htmlspecialchars($this->plain($key), ENT_QUOTES);
    }

    /** One catalog message, RAW — for a view that escapes what it is handed. */
    private function plain(string $key): string
    {
        return ($this->catalog ?? new Catalog())->tr($key);
    }
}
