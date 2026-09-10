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
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;

/**
 * EVERY WORKSPACE SURFACE, DECLARED ON A REGISTRY — one list, and the host is a caller.
 *
 * 🚨 THIS LIST USED TO LIVE IN A CONTROLLER'S CONSTRUCTOR, AND THE PANEL DEPENDED ON THAT BY ACCIDENT.
 * `ShellController` declared these 17 surfaces as a side effect of being built during `boot()`, and the
 * admin panel — which paints 17 of them — got them because that construction happened to run first.
 * Nothing asserted the panel could stand without the page's controller.
 *
 * And nothing would have noticed: `AgentViewRenderer::paint()` wraps every surface in
 * `catch (\Throwable)` and returns a warning div per failure, so a panel with no behaviour at all
 * still answers HTTP 200 and renders pixel-perfect server-side. Measured while mapping the page's
 * retirement: the Agent view against a registry the panel populated ALONE came back 10 170 bytes with
 * SEVENTEEN `data-failed-component` markers and no exception (greenhouse decisions/0283).
 *
 * So the declarations moved out of the page before the page was deleted, and
 * {@see \Milpa\AgentWorkspace\Tests\Admin\ThePanelPaintsEverySurfaceWithoutThePageTest} counts the
 * markers at ZERO — a count, because an assertion that the view contains SOME component would have
 * passed the whole time.
 *
 * The mold is {@see DeepScreens}, which already carried the deep screens for two doors: one list, and
 * whoever wants them declares them on their own registry.
 */
final class Surfaces
{
    public function __construct(
        private readonly ?DesktopData $data = null,
        private readonly ?MilpaEventDispatcherInterface $events = null,
        private readonly ?Catalog $catalogue = null,
        private readonly ?ComposerField $composerField = null,
        private readonly ?SessionStrip $sessionStrip = null,
        private readonly ?ComposerBar $composerBar = null,
        private readonly ?Tabs $tabs = null,
        private readonly ?WorkBoard $workBoard = null,
        private readonly ?Activity $activity = null,
        private readonly ?Context $context = null,
        private readonly ?Gate $gate = null,
        private readonly ?Thinking $thinking = null,
        private readonly ?AgentMessage $agentMessage = null,
        private readonly ?Conversation $conversation = null,
        private readonly ?MessagePrototypes $messages = null,
    ) {
    }

    /**
     * Declare every surface on `$live`, each bound to the service that owns its markup, its lifecycle
     * events, its signed envelope and its client files.
     *
     * Eagerly, and by the HOST: the registry is shared with the live endpoint, so a surface must be
     * resolvable there whether or not anything has rendered yet. Declaring twice on one registry is
     * what the panel's own guard refuses — «painted by different renderers in section A and section B»
     * — so exactly one caller per registry (greenhouse decisions/0211).
     */
    public function declareOn(DesktopComponents $live): void
    {
        $live->declare(new TabsComponent(), fn (array $props): string => $this->tabsOf()->render());
        $live->declare(new SessionStripComponent(), fn (array $props): string => $this->sessionStripOf()->render());
        $live->declare(new ConversationComponent(), fn (array $props): string => $this->conversationOf()->render(\is_string($props['agent'] ?? null) ? $props['agent'] : ''));
        $live->declare(new ComposerBarComponent(), fn (array $props): string => $this->composerBarOf()->render());
        $live->declare(new GateComponent(), fn (array $props): string => $this->gateOf()->render());
        $live->declare(new WorkBoardComponent(), fn (array $props): string => $this->workBoardOf()->render());
        $live->declare(new ActivityComponent(), fn (array $props): string => $this->activityOf()->render());
        $live->declare(new ContextComponent(), fn (array $props): string => $this->contextOf()->render(\is_array($props['sections'] ?? null) ? $props['sections'] : []));
        $live->declare(new ThinkingComponent(), fn (array $props): string => $this->thinkingOf()->render());
        $live->declare(new AgentMessageComponent(), fn (array $props): string => $this->agentMessageOf()->render());
        $live->declare(new UserMessageComponent(), fn (array $props): string => $this->messages()->user());
        $live->declare(new ToolCallComponent(), fn (array $props): string => $this->messages()->tool());
        $live->declare(new TaskComponent(), fn (array $props): string => $this->messages()->task());
        $live->declare(new SystemNoticeComponent(), fn (array $props): string => $this->messages()->system());
        $live->declare(new ResultClaimComponent(), fn (array $props): string => $this->messages()->resultClaim());
        // The turn's own contents (greenhouse decisions/0254): the question it parked, and the boundary a
        // compaction left behind.
        $live->declare(new AskGrantComponent(), fn (array $props): string => $this->messages()->askGrant());
        $live->declare(new CompactedComponent(), fn (array $props): string => $this->messages()->compacted());
        // The two screens phase B took out of the template (greenhouse decisions/0211): the Settings screen
        // and the entry overlay were the last raw HTML the shell hand-wrote.

        // Phase D: the four screens whose markup the template still carried and whose behaviour and CSS
        // the page's own inline script and `<style>` still paid for. Each is a declared view now, and each
        // is built HERE from the registry's own codec — one signing key per page (greenhouse
        // decisions/0211), which is also why they need no constructor argument of their own.
        // ONE LIST, TWO DOORS: the panel declares these same screens as sections under Agent, and a
        // second declaration site here would be free to drift (greenhouse decisions/0268).
        // 🚨 THE DECISIONS INBOX IS A REGION SURFACE, NOT A DEEP SCREEN, and it was declared with the
        // screens. No section paints it — `SCREEN_SECTIONS` are settings, skills, subagents and preview
        // — while the Agent REGION paints it as a tab, so the region depended on the sections path
        // having run first.
        //
        // Caught by the control for a dispatcher with no declaration contract: it painted the region
        // after `boot()` and `desktop-decisions` was the ONLY surface carrying
        // `data-failed-component`. Same class of coupling this arc took out of the page's controller,
        // one layer in (greenhouse decisions/0283).
        $live->declare(
            new DecisionsInboxComponent(),
            fn (array $props): string => (new DecisionsInbox(
                $live->codec(),
                $this->data,
                $this->events,
                $this->catalog(),
                // The render request's prop wins over the caller's default: the shell knows who is
                // signed in per request, and a screen answering as the wrong principal is worse than
                // one answering as nobody.
                \is_string($props['principal'] ?? null) && $props['principal'] !== '' ? $props['principal'] : '',
            ))->render(false),
        );
    }

    /**
     * The session strip of embed mode (greenhouse decisions/0210): the current session's goal, a `<select>` of
     * every session and a «New session» control — the sidebar's reach, in one row above the conversation, when
     * the sidebar is folded. A milpa/live component ({@see SessionStrip}) over the same data the sidebar reads;
     * a fallback is built from that data when none was injected.
     */
    private function sessionStripOf(): SessionStrip
    {
        return $this->sessionStrip ?? new SessionStrip('desktop-session-strip-fallback', $this->data, $this->events, $this->catalog());
    }


    /**
     * The composer bar surface (greenhouse decisions/0211, C2) — the injected one, else a fallback.
     *
     * The last surface the shell stitched by hand: it is a declared view now ({@see ComposerBar}), so its
     * markup comes from a renderer, its behaviour from `desktop-composer.js` and its state travels in a
     * signed envelope like every other component's.
     */
    private function composerBarOf(): ComposerBar
    {
        return $this->composerBar ?? new ComposerBar(
            hash('sha256', __DIR__ . '|milpa-live|signing'),
            $this->data,
            $this->composerField,
            $this->events,
            $this->catalog(),
        );
    }

    /** The main tablist surface (greenhouse decisions/0189) — the panes and composer dock read its `desktop.tab` signal. */
    private function tabsOf(): Tabs
    {
        return $this->tabs ?? new Tabs('desktop-tabs-fallback', $this->events, $this->catalog());
    }

    /** The Work board surface (greenhouse decisions/0189) — moving a card still persists through /desktop/work. */
    private function workBoardOf(): WorkBoard
    {
        return $this->workBoard ?? new WorkBoard('desktop-work-board-fallback', $this->data, $this->events);
    }

    /** The Activity tab surface (greenhouse decisions/0189) — facts arrive live over the hub, prepended to #milpa-activity. */
    private function activityOf(): Activity
    {
        return $this->activity ?? new Activity('desktop-activity-fallback', $this->data, $this->events);
    }

    /** The Context tab surface (greenhouse decisions/0189) — plugins contribute panels through the composition (addPanel). */
    private function contextOf(): Context
    {
        // IT GETS THE DATA, like every sibling here. `WorkBoard` and `Activity` were built with
        // `$this->data` from the start and `Context` was not, so the tab NAMED Context was the one
        // surface in this region with nothing to read — it rendered only what plugins contributed, and
        // an app with no contributing plugin opened it and read a note about `addPanel()`
        // (greenhouse decisions/0288).
        return $this->context ?? new Context('desktop-context-fallback', $this->events, $this->data, $this->catalogue);
    }

    /** The consent gate surface (greenhouse decisions/0189) — its visibility is the `desktop.gate.open` signal. */
    private function gateOf(): Gate
    {
        return $this->gate ?? new Gate('desktop-gate-fallback', $this->events);
    }

    /** The conversation surface (greenhouse decisions/0191): the empty state + envelope inside the chat container. */
    private function conversationOf(): Conversation
    {
        return $this->conversation ?? new Conversation('desktop-conversation-fallback', $this->events, $this->data, $this->catalog());
    }

    /** The thinking prototype's surface (greenhouse decisions/0191): cloned per turn, fed the reasoning by events. */
    private function thinkingOf(): Thinking
    {
        return $this->thinking ?? new Thinking('desktop-thinking-fallback', $this->events);
    }

    /** The agent-message prototype's surface (greenhouse decisions/0191): cloned per answer, its foot tools delegated. */
    private function agentMessageOf(): AgentMessage
    {
        return $this->agentMessage ?? new AgentMessage('desktop-agent-message-fallback', $this->events);
    }

    /** The plainer message prototypes (user/tool/task/system), or a fallback set (greenhouse decisions/0191). */
    private function messages(): MessagePrototypes
    {
        return $this->messages ?? new MessagePrototypes('desktop-messages-fallback', $this->events);
    }

    /**
     * The catalog these surfaces speak in — the one the caller handed over, else English.
     *
     * It does NOT resolve `DesktopSettings` for itself any more, and that is the point of moving: the
     * host already knows its locale (the plugin reads it once at boot) and hands it down. A guest that
     * re-derives what its host resolved is a second answer waiting to disagree
     * (greenhouse decisions/0209, decisions/0283).
     */
    private function catalog(): Catalog
    {
        return $this->catalogue ?? new Catalog();
    }
}
