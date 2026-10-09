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
use Milpa\AgentWorkspace\Data\Rehearsed;
use Milpa\AgentWorkspace\Event\RenderEvents;
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Renders the Desktop shell's Work board as a {@see WorkBoardComponent} — the fourth surface of the "shell is
 * pure Milpa Components" migration (greenhouse decisions/0189). It mounts the component, produces the
 * design-system board (columns by status, draggable cards), carries the signed state envelope, and emits
 * `desktop.work_board.before_render` / `after_render` so other plugins can extend it (decorate columns/cards).
 *
 * Moving a card persists through the dedicated `/desktop/work` mutation (greenhouse decisions/0484). Since
 * phase D of the declared views (greenhouse decisions/0211) the gesture is the board's OWN module:
 * `desktop-work-board.js` (declared by this renderer, delegated on the root) and `desktop-work-board.css`,
 * so the drag's look is CSS state and not a style assigned from JavaScript.
 */
final class WorkBoard
{
    public const string COMPONENT_ID = 'work-board';
    public const string BEFORE_RENDER = 'desktop.work_board.before_render';
    public const string AFTER_RENDER = 'desktop.work_board.after_render';

    /** The payload key both render events carry their mutable {@see ComposerRender} under. */
    public const string SUBJECT_KEY = 'workBoard';

    /** @var array<string, string> */
    private const COLUMNS = ['pending' => 'Pending', 'in_progress' => 'In progress', 'done' => 'Done', 'blocked' => 'Blocked'];

    private readonly SignedXhtmlStateTransferCodec $codec;

    private readonly Catalog $catalog;

    public function __construct(
        string $signingSecret,
        private readonly ?DesktopData $data = null,
        private readonly ?MilpaEventDispatcherInterface $events = null,
        ?Catalog $catalog = null,
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
        return RenderEvents::of(self::class, self::BEFORE_RENDER, self::AFTER_RENDER, self::SUBJECT_KEY, 'the work board');
    }

    /** The board's server-rendered HTML — a component with its signed envelope, or the empty state. */
    public function render(): string
    {
        $component = new WorkBoardComponent();
        $props = [
            'work' => $this->data?->work() ?? [],
            'sessionId' => $this->data?->currentSessionId() ?? '',
            'closure' => $this->data?->closure(),
        ];
        $subject = new ComposerRender($props);
        $this->events?->dispatch(self::BEFORE_RENDER, [self::SUBJECT_KEY => $subject]);

        $context = new ComponentContext(componentId: self::COMPONENT_ID);
        $state = $component->mount($subject->props, $context);
        // ONE REGION, the envelope outside it (greenhouse decisions/0563): the verdict and the cards are re-read
        // from the house when a pushed fact moves them; the signed state was signed for the page that was served.
        $subject->html = LiveRegion::of(LiveRegion::WORK, $this->markup($subject->props)) . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, [self::SUBJECT_KEY => $subject]);

        return $subject->html;
    }

    /** @param array<string, mixed> $props */
    private function markup(array $props): string
    {
        /** @var list<array{title: string, status: string, origin: string, draggable?: bool}> $work */
        $work = \is_array($props['work'] ?? null) ? $props['work'] : [];
        $wrap = 'data-milpa-component="desktop-work-board" data-milpa-component-id="' . self::COMPONENT_ID . '"';
        $closure = \is_array($props['closure'] ?? null) ? $this->closure($props['closure']) : '';

        if ($work === []) {
            return $closure . '<div class="mui-empty" ' . $wrap . '><p class="mui-empty__title">No work board yet</p>'
                . '<p class="mui-empty__desc">A session writes its plan as work items; they appear here by status.</p></div>';
        }

        $session = htmlspecialchars((string) ($props['sessionId'] ?? ''), ENT_QUOTES);
        $byStatus = ['pending' => '', 'in_progress' => '', 'done' => '', 'blocked' => ''];
        foreach ($work as $i => $item) {
            $status = \array_key_exists($item['status'], self::COLUMNS) ? $item['status'] : 'pending';
            $byStatus[$status] .= sprintf(
                '<article class="mui-card mui-card--compact work-card" draggable="%s" data-index="%d"><div class="mui-card__body"><p class="work-card__title">%s</p><span class="mui-badge">%s</span></div></article>',
                ($item['draggable'] ?? true) ? 'true' : 'false',
                $i,
                htmlspecialchars($item['title'], ENT_QUOTES),
                htmlspecialchars($item['origin'], ENT_QUOTES),
            );
        }

        // Every drag event is DELEGATED on the board's own root (greenhouse decisions/0211, D3), so a card
        // or a column painted later is draggable without anything re-wiring listeners onto it.
        $out = $closure . '<div class="work-board" ' . $wrap . ' data-session="' . $session . '"'
            . ' x-data="desktopWorkBoard()" @dragstart="onDragStart($event)" @dragend="onDragEnd($event)"'
            . ' @dragover="onDragOver($event)" @dragleave="onDragLeave($event)" @drop="onDrop($event)">';
        foreach (self::COLUMNS as $key => $label) {
            $out .= sprintf(
                '<section class="work-col" data-status="%s"><div class="mui-cluster mui-cluster--sm work-col__head"><span class="mui-section__kicker work-col__title">%s</span></div>%s</section>',
                htmlspecialchars($key, ENT_QUOTES),
                htmlspecialchars($label, ENT_QUOTES),
                $byStatus[$key],
            );
        }

        return $out . '</div>';
    }

    /**
     * The house's verdict on the work, ABOVE the cards (greenhouse decisions/0509 §7).
     *
     * The cards are the session's own claim — its todos, moved to DONE by the session. Measured
     * (evidence/1036): eight cards in DONE beside «the blog is built, tested, and live», while the house had
     * written `verified: false` and no screen said so. The line says what the house verified and on what, or
     * that it did not and why — so DONE never reads as the house's word when it is only the session's.
     *
     * @param array<string, mixed> $closure
     */
    private function closure(array $closure): string
    {
        $verified = ($closure['verified'] ?? null) === true;
        $scope = \is_string($closure['scope'] ?? null) ? $closure['scope'] : '';
        $scopeKey = 'work.closure.scope.' . $scope;
        $scopeText = $this->catalog->tr($scopeKey);
        $desc = $verified
            ? ($scopeText !== $scopeKey ? $scopeText : '')
            : $this->catalog->tr('work.closure.unverified.desc');
        $reasons = '';
        if (! $verified) {
            foreach (\is_array($closure['reasons'] ?? null) ? $closure['reasons'] : [] as $reason) {
                if (\is_string($reason)) {
                    $reasons .= '<li>' . htmlspecialchars($reason, ENT_QUOTES) . '</li>';
                }
            }
        }

        // WHAT THE SESSION REHEARSED AND DID NOT APPLY, in one line under the verdict whichever it is (greenhouse
        // decisions/0605, R2 — decided by Rod on 2026-10-09). A builder that tried its own operations closes as one
        // that tried nothing does; this line is the difference, and it is drawn from the datum alone.
        $tried = Rehearsed::of($closure['rehearsed'] ?? null);
        $rehearsed = $tried === null ? '' : '<p class="mui-alert__desc work-closure__rehearsed" data-work-rehearsed="' . $tried['calls'] . '">'
            . '<strong>' . htmlspecialchars($this->catalog->tr('work.closure.rehearsed'), ENT_QUOTES) . '</strong> '
            . htmlspecialchars($this->catalog->tr('verdict.rehearsed.why', (string) $tried['calls'], (string) $tried['of_verbs_that_change_state']), ENT_QUOTES)
            . '</p>';

        return '<div class="mui-alert mui-alert--' . ($verified ? 'success' : 'warning') . ' work-closure"'
            . ' role="status" data-work-closure data-verified="' . ($verified ? '1' : '0') . '"'
            . ' data-scope="' . htmlspecialchars($scope, ENT_QUOTES) . '">'
            . '<span class="mui-alert__icon" aria-hidden="true">' . ($verified ? '✓' : '⚠') . '</span>'
            . '<div class="mui-alert__content">'
            . '<p class="mui-alert__title">' . htmlspecialchars($this->catalog->tr($verified ? 'work.closure.verified' : 'work.closure.unverified'), ENT_QUOTES) . '</p>'
            . ($desc !== '' ? '<p class="mui-alert__desc">' . htmlspecialchars($desc, ENT_QUOTES) . '</p>' : '')
            . ($reasons !== '' ? '<ul class="mui-alert__desc work-closure__reasons">' . $reasons . '</ul>' : '')
            . $rehearsed
            . '</div></div>';
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }
}
