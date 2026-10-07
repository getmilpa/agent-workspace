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
            // Judged for whoever is reading (greenhouse decisions/0584): the engine says which options THIS reader may take.
            'graphs' => $this->data?->pendingGraphDecisions($this->principal) ?? [],
            'sequences' => $this->data?->declaredSequences() ?? [],
            // The seats this reader enrolled, and what they were refused (greenhouse decisions/0493).
            'frontier' => $this->principal === '' ? [] : ($this->data?->seatFrontier($this->principal) ?? []),
            // The seats this reader answers for, and the way to give the resident one (greenhouse decisions/0499).
            'seats' => $this->principal === '' ? [] : ($this->data?->seats($this->principal) ?? []),
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
        /** @var list<array<string, mixed>> $graphs */
        $graphs = \is_array($props['graphs'] ?? null) ? $props['graphs'] : [];
        /** @var list<array{name: string, steps: list<string>, session: string, paused: bool, pending_operation: string}> $sequences */
        $sequences = \is_array($props['sequences'] ?? null) ? $props['sequences'] : [];
        $copy = [
            'run' => $this->plain('decisions.run'),
            'approve' => $this->plain('decisions.approve'),
            'deny' => $this->plain('decisions.deny'),
            'paused_on' => $this->plain('decisions.paused_on'),
            'graph_door' => $this->plain('decisions.graph.door'),
            'graph_yours' => $this->plain('decisions.graph.yours'),
            'graph_needs' => $this->plain('decisions.graph.needs'),
        ];
        /** @var list<array{session: string, goal: string, seat: string, refusals: list<array{seq: int, tool: string, plugin: ?string, permission: string}>}> $frontier */
        $frontier = \is_array($props['frontier'] ?? null) ? $props['frontier'] : [];
        /** @var list<array{fingerprint: string, label: string|null, scopes: list<string>, authorized_by: string}> $seats */
        $seats = \is_array($props['seats'] ?? null) ? $props['seats'] : [];
        $view = new DecisionsInboxView();

        return '<div class="view milpa-decisions" data-view="decisions"'
            . ' data-milpa-component="desktop-decisions" data-milpa-component-id="' . self::COMPONENT_ID . '"' . ScreenVisibility::attr($props) . '>'
            . '<p class="milpa-decisions__intro">' . $this->tr('decisions.intro') . '</p>'
            . $view->html($pending, $this->plain('decisions.empty'), $graphs, $copy)
            // THE FRONTIER OF THE SEATS YOU ENROLLED (greenhouse decisions/0493): a seat's missing scope is a
            // refusal, not a question — this is where the human who answers for the seat sees it and decides.
            . '<h3 class="milpa-decisions__heading">' . $this->tr('frontier.heading') . '</h3>'
            . '<p class="milpa-decisions__intro">' . $this->tr('frontier.intro') . '</p>'
            . $view->frontierHtml($frontier, $this->plain('frontier.empty'), [
                'refused' => $this->plain('frontier.refused'),
                'plugin' => $this->plain('frontier.plugin'),
                'lacks' => $this->plain('frontier.lacks'),
                'grant' => $this->plain('frontier.grant'),
                'open' => $this->plain('frontier.open'),
                'call' => $this->plain('frontier.call'),
                'opens_new' => $this->plain('frontier.opens_new'),
                'opens_existing' => $this->plain('frontier.opens_existing'),
                'unnamed' => $this->plain('frontier.unnamed'),
                'ack' => $this->plain('frontier.ack'),
                'grant_existing' => $this->plain('frontier.grant_existing'),
            ])
            // YOUR SEATS (greenhouse decisions/0499): the seats this reader answers for, and the one place a human
            // gives the resident a seat — no file edited, the resident's own key proving itself by signing.
            . '<h3 class="milpa-decisions__heading">' . $this->tr('seats.heading') . '</h3>'
            . '<p class="milpa-decisions__intro">' . $this->tr('seats.intro') . '</p>'
            . $view->seatsHtml($seats, [
                'empty' => $this->plain('seats.empty'),
                'enrolled_by' => $this->plain('seats.enrolled_by'),
                'label' => $this->plain('seats.label'),
                'give' => $this->plain('seats.give'),
                'held' => $this->plain('seats.held'),
                'another' => $this->plain('seats.another'),
                'give_another' => $this->plain('seats.give_another'),
            ])
            // THE SEQUENCES THIS APP DECLARED, to run from here (greenhouse decisions/0223, F4): a deployment
            // is a list, and the place a human authorizes everything else is where its run starts and where
            // its pause is answered.
            . '<h3 class="milpa-decisions__heading">' . $this->tr('decisions.sequences') . '</h3>'
            . '<p class="milpa-decisions__intro">' . $this->tr('decisions.sequences_intro') . '</p>'
            . $view->sequencesHtml($sequences, $this->plain('decisions.sequences_empty'), $copy)
            // The prototype for a GRAPH card, always printed: the module reaches for these hooks, and a hook
            // no page ever carries is a module talking to itself (this package's DOM contract refuses it).
            . '<template id="milpa-graph-decision-proto">'
            . '<li class="decision-card decision-card--graph" data-graph data-graph-instance>'
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
