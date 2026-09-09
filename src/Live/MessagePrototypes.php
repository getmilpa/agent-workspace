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
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Renders the plainer conversation message components as PROTOTYPES (greenhouse decisions/0191): the user's
 * message, a tool call, a task row, a system notice, a parked question, a compaction boundary. Each is mounted + rendered once (server-side) with a
 * signed envelope, and each fires its own `before_render` / `after_render` event carrying a mutable subject so
 * a plugin can extend that message type once, at its prototype. The conversation clones each per message and
 * fills its data regions. One service because each of these is small; the richer types (agent message,
 * thinking) keep their own services.
 */
final class MessagePrototypes
{
    public const string USER_BEFORE = 'desktop.user_message.before_render';
    public const string USER_AFTER = 'desktop.user_message.after_render';
    public const string TOOL_BEFORE = 'desktop.tool_call.before_render';
    public const string TOOL_AFTER = 'desktop.tool_call.after_render';
    public const string TASK_BEFORE = 'desktop.task.before_render';
    public const string TASK_AFTER = 'desktop.task.after_render';
    public const string SYSTEM_BEFORE = 'desktop.system_notice.before_render';
    public const string SYSTEM_AFTER = 'desktop.system_notice.after_render';
    public const string RESULT_BEFORE = 'desktop.result_claim.before_render';
    public const string RESULT_AFTER = 'desktop.result_claim.after_render';
    public const string GRANT_BEFORE = 'desktop.ask_grant.before_render';
    public const string GRANT_AFTER = 'desktop.ask_grant.after_render';
    public const string COMPACTED_BEFORE = 'desktop.compacted.before_render';
    public const string COMPACTED_AFTER = 'desktop.compacted.after_render';

    /** The payload keys each prototype's render events carry their mutable {@see ComposerRender} under. */
    public const string USER_KEY = 'userMessage';
    public const string TOOL_KEY = 'toolCall';
    public const string TASK_KEY = 'task';
    public const string SYSTEM_KEY = 'systemNotice';
    public const string RESULT_KEY = 'resultClaim';
    public const string GRANT_KEY = 'askGrant';
    public const string COMPACTED_KEY = 'compacted';

    private readonly SignedXhtmlStateTransferCodec $codec;

    public function __construct(
        string $signingSecret,
        private readonly ?MilpaEventDispatcherInterface $events = null,
    ) {
        $this->codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner($signingSecret), null);
    }

    /**
     * The fourteen events the prototypes dispatch — seven render pairs — declared from the same constants
     * each prototype hands `wrap()` (greenhouse decisions/0228). `wrap()` computes nothing: the names
     * it dispatches are these constants, one pair per public prototype.
     *
     * @return list<\Milpa\Interfaces\Event\EventDeclaration>
     */
    public static function events(): array
    {
        return [
            ...RenderEvents::of(self::class, self::USER_BEFORE, self::USER_AFTER, self::USER_KEY, 'the user-message prototype'),
            ...RenderEvents::of(self::class, self::TOOL_BEFORE, self::TOOL_AFTER, self::TOOL_KEY, 'the tool-call prototype'),
            ...RenderEvents::of(self::class, self::TASK_BEFORE, self::TASK_AFTER, self::TASK_KEY, 'the task prototype'),
            ...RenderEvents::of(self::class, self::SYSTEM_BEFORE, self::SYSTEM_AFTER, self::SYSTEM_KEY, 'the system-notice prototype'),
            ...RenderEvents::of(self::class, self::RESULT_BEFORE, self::RESULT_AFTER, self::RESULT_KEY, 'the result-claim prototype'),
            ...RenderEvents::of(self::class, self::GRANT_BEFORE, self::GRANT_AFTER, self::GRANT_KEY, 'the ask-grant prototype'),
            ...RenderEvents::of(self::class, self::COMPACTED_BEFORE, self::COMPACTED_AFTER, self::COMPACTED_KEY, 'the compaction-boundary prototype'),
        ];
    }

    /** The user-message prototype (`desktop-user-message`). */
    public function user(): string
    {
        $markup = '<div class="msg msg--user" data-milpa-component="desktop-user-message" data-milpa-component-id="user-message">'
            . '<div><span class="msg__meta">you · now</span>'
            . '<p data-user-body style="margin:var(--space-2) 0 0;font-size:var(--text-sm);white-space:pre-wrap"></p></div></div>';

        return $this->wrap(new UserMessageComponent(), 'user-message', $markup, self::USER_BEFORE, self::USER_AFTER, self::USER_KEY);
    }

    /** The tool-call prototype (`desktop-tool-call`) — the name and a summary, the raw result collapsed. */
    public function tool(): string
    {
        $markup = '<div class="msg msg--tool" data-milpa-component="desktop-tool-call" data-milpa-component-id="tool-call" data-open="0">'
            . '<button type="button" class="msg__tool-head" data-tool-toggle>'
            . '<span class="msg__tool-name" data-tool-name>tool</span>'
            . '<span class="msg__tool-summary" data-tool-summary></span></button>'
            . '<pre class="msg__tool-raw" data-tool-body></pre></div>';

        return $this->wrap(new ToolCallComponent(), 'tool-call', $markup, self::TOOL_BEFORE, self::TOOL_AFTER, self::TOOL_KEY);
    }

    /** The task prototype (`desktop-task`). */
    public function task(): string
    {
        $markup = '<div class="msg msg--task" data-milpa-component="desktop-task" data-milpa-component-id="task">'
            . '<div><span class="msg__mark">+</span><span class="msg__title" data-task-title></span>'
            . '<span class="mui-badge" data-task-status style="margin-inline-start:auto">todo</span></div></div>';

        return $this->wrap(new TaskComponent(), 'task', $markup, self::TASK_BEFORE, self::TASK_AFTER, self::TASK_KEY);
    }

    /** The system-notice prototype (`desktop-system-notice`). */
    public function system(): string
    {
        $markup = '<div class="msg msg--system" data-milpa-component="desktop-system-notice" data-milpa-component-id="system-notice" data-system-body></div>';

        return $this->wrap(new SystemNoticeComponent(), 'system-notice', $markup, self::SYSTEM_BEFORE, self::SYSTEM_AFTER, self::SYSTEM_KEY);
    }

    /** The result-claim prototype (`desktop-result-claim`) — the closure verdict as a conversation message. */
    public function resultClaim(): string
    {
        // Hoverable: an ⓘ affordance + a tooltip say WHAT the ledger verified (the closure verdict's meaning),
        // filled per instance. `aria-label` (set in fill) carries the same text to a screen reader — no
        // duplicate-id `aria-describedby`, since the prototype is cloned many times.
        $markup = '<div class="msg msg--result" data-milpa-component="desktop-result-claim" data-milpa-component-id="result-claim" data-verified="1" tabindex="0">'
            . '<span class="msg__result-mark" data-result-mark aria-hidden="true">✓</span> <span data-result-text>verified</span>'
            . '<span class="msg__result-info" aria-hidden="true">ⓘ</span>'
            . '<span class="msg__result-tip" role="tooltip" data-result-tip>The ledger backs this turn: every completed step carries evidence, nothing was left open, and no artifact&#39;s latest check is red.</span>'
            . '</div>';

        return $this->wrap(new ResultClaimComponent(), 'result-claim', $markup, self::RESULT_BEFORE, self::RESULT_AFTER, self::RESULT_KEY);
    }

    /**
     * The ask-grant prototype (`desktop-ask-grant`) — the agent's parked question, answerable in place.
     *
     * The options are NOT in the prototype: the agent proposes them per question, so the client clones one
     * `[data-grant-option]` button per option it was actually given. Baking a yes and a no in here would be
     * this surface inventing a fork the agent never offered (greenhouse decisions/0254).
     */
    public function askGrant(): string
    {
        $markup = '<div class="msg msg--grant" data-milpa-component="desktop-ask-grant" data-milpa-component-id="ask-grant" data-grant-state="open" data-grant-id="">'
            . '<div class="msg__grant-head"><span class="msg__grant-mark" aria-hidden="true">⏸</span>'
            . '<span class="msg__grant-kind" data-grant-kind></span></div>'
            . '<p class="msg__grant-question" data-grant-question></p>'
            // WHAT IS BEING AUTHORIZED, as a painted claim and not a JSON dump (greenhouse decisions/0259).
            //
            // The gate stores this `why` as machine data ON PURPOSE — `SessionToolGate` re-reads it to
            // hold a consent to these exact arguments — so it is not the emitter being sloppy: it is the
            // surface's job to paint it. *«Pintar el dato es trabajo de la superficie»*, says the TUI,
            // which has painted it for a while; the web was dumping 500 characters of JSON at a human and
            // overflowing its own container doing it.
            //
            // The axes are the TUI's, in the TUI's order — most-commonly-tightened first — because two
            // surfaces of one fact must not teach two vocabularies. The `[data-claim-axis]` row and the
            // `[data-claim-arg]` row are TEMPLATES the fill clones: how many axes a ceiling declares is
            // the ceiling's business, not the prototype's.
            . '<p class="msg__grant-why" data-grant-why></p>'
            . '<div class="msg__claim" data-grant-claim hidden>'
            . '<p class="msg__claim-what"><code class="msg__claim-op" data-claim-operation></code>'
            . '<span class="msg__claim-over" data-claim-over></span></p>'
            . '<ul class="msg__claim-args" data-claim-args>'
            . '<li class="msg__claim-arg" data-claim-arg hidden><span class="msg__claim-arg-name" data-claim-arg-name></span>'
            . '<span class="msg__claim-arg-value" data-claim-arg-value></span></li>'
            . '</ul>'
            . '<ul class="msg__claim-axes" data-claim-axes>'
            . '<li class="msg__claim-axis" data-claim-axis hidden><span class="msg__claim-axis-name" data-claim-axis-name></span>'
            . '<span class="msg__claim-axis-value" data-claim-axis-value></span></li>'
            . '</ul>'
            // THE HONEST FALLBACK, and it is the TUI's rule: «si no parsea, se enseña tal cual —
            // inventar una frase sobre algo que no se entendió sería peor que el JSON, porque el JSON
            // al menos es cierto». It scrolls inside itself so an unparseable claim never blows the
            // thread's width, which is what the raw dump was doing.
            . '<pre class="msg__claim-raw" data-claim-raw hidden></pre>'
            . '</div>'
            . '<div class="msg__grant-options" data-grant-options role="group">'
            . '<button type="button" class="mui-btn msg__grant-option" data-grant-option hidden></button>'
            . '</div>'
            . '<p class="msg__grant-status" data-grant-status role="status"></p></div>';

        return $this->wrap(new AskGrantComponent(), 'ask-grant', $markup, self::GRANT_BEFORE, self::GRANT_AFTER, self::GRANT_KEY);
    }

    /** The compaction prototype (`desktop-compacted`) — a boundary across the thread, not a message. */
    public function compacted(): string
    {
        $markup = '<div class="msg msg--compacted" data-milpa-component="desktop-compacted" data-milpa-component-id="compacted" role="separator">'
            . '<span class="msg__compacted-line" aria-hidden="true"></span>'
            . '<span class="msg__compacted-text" data-compacted-text></span>'
            . '<span class="msg__compacted-line" aria-hidden="true"></span></div>';

        return $this->wrap(new CompactedComponent(), 'compacted', $markup, self::COMPACTED_BEFORE, self::COMPACTED_AFTER, self::COMPACTED_KEY);
    }

    /** Mount the component, fire before/after render with a mutable subject, and cap with the signed envelope. */
    private function wrap(ComponentDefinitionInterface $component, string $id, string $markup, string $before, string $after, string $key): string
    {
        $subject = new ComposerRender([]);
        $this->events?->dispatch($before, [$key => $subject]);

        $state = $component->mount($subject->props, new ComponentContext(componentId: $id));
        $subject->html = $markup . $this->envelope($id, $state);

        $this->events?->dispatch($after, [$key => $subject]);

        return $subject->html;
    }

    private function envelope(string $id, StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . $id . '">' . $this->codec->encodeState($state) . '</script>';
    }
}
