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

namespace Milpa\AgentWorkspace\Tests;

use Milpa\AgentWorkspace\Live\MessagePrototypes;
use Milpa\AgentWorkspace\Live\SystemNoticeComponent;
use Milpa\AgentWorkspace\Live\TaskComponent;
use Milpa\AgentWorkspace\Live\ToolCallComponent;
use Milpa\AgentWorkspace\Live\UserMessageComponent;
use Milpa\Eventing\EventDispatcher;
use Milpa\Live\ValueObjects\ComponentContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The plainer message types — user, tool, task, system — are milpa/live components too (greenhouse
 * decisions/0191): each a declared contract rendered as a signed prototype the conversation clones, each
 * extensible by hooking its render events.
 */
final class MessagePrototypesTest extends TestCase
{
    public function testEachPrototypeIsAComponentWithASignedEnvelopeAndRegions(): void
    {
        $p = new MessagePrototypes('secret');

        $user = $p->user();
        self::assertStringContainsString('data-milpa-component="desktop-user-message"', $user);
        self::assertStringContainsString('data-milpa-state="user-message"', $user);
        self::assertStringContainsString('data-user-body', $user);

        $tool = $p->tool();
        self::assertStringContainsString('data-milpa-component="desktop-tool-call"', $tool);
        self::assertStringContainsString('data-tool-name', $tool);
        self::assertStringContainsString('data-tool-summary', $tool);
        self::assertStringContainsString('data-tool-body', $tool);
        self::assertStringContainsString('data-tool-toggle', $tool);

        $result = $p->resultClaim();
        self::assertStringContainsString('data-milpa-component="desktop-result-claim"', $result);
        self::assertStringContainsString('data-result-mark', $result);
        self::assertStringContainsString('data-result-text', $result);
        // The verdict explains itself: a hoverable tooltip (role="tooltip") says WHAT the ledger judged, and
        // the line is focusable so keyboard users reach it too.
        self::assertStringContainsString('data-result-tip', $result);
        self::assertStringContainsString('role="tooltip"', $result);
        self::assertStringContainsString('tabindex="0"', $result);

        $task = $p->task();
        self::assertStringContainsString('data-milpa-component="desktop-task"', $task);
        self::assertStringContainsString('data-task-title', $task);
        self::assertStringContainsString('data-task-status', $task);

        $system = $p->system();
        self::assertStringContainsString('data-milpa-component="desktop-system-notice"', $system);
        self::assertStringContainsString('data-system-body', $system);
        self::assertStringContainsString('security="signed"', $system);
    }

    public function testEachPrototypeEmitsRenderEventsSoPluginsCanExtendIt(): void
    {
        $events = new EventDispatcher(new NullLogger());
        $events->subscribe(MessagePrototypes::USER_AFTER, static function (string $n, array $p): void {
            $p['userMessage']->html .= '<!-- user extended -->';
        });
        $events->subscribe(MessagePrototypes::TOOL_AFTER, static function (string $n, array $p): void {
            $p['toolCall']->html .= '<!-- tool extended -->';
        });

        $p = new MessagePrototypes('secret', $events);

        self::assertStringContainsString('user extended', $p->user());
        self::assertStringContainsString('tool extended', $p->tool());
    }

    /**
     * WHAT A PERSON'S OWN SESSION CANNOT DO (greenhouse decisions/0609, path 1, I2) — the option she cannot take, dimmed:
     * one button, disabled. Y5: it grants nothing and runs nothing. Every act on the page rides a click delegate keyed
     * on a `data-*` hook, so a hook copied into this markup would ARM it; it carries none of them.
     */
    public function testTheNoFrontierPrototypeShowsADisabledOptionAndCarriesNoHookThatActs(): void
    {
        $card = (new MessagePrototypes('secret'))->noFrontier();

        self::assertStringContainsString('data-milpa-component="desktop-no-frontier"', $card);
        self::assertStringContainsString('role="note"', $card);
        foreach (['data-no-frontier-title', 'data-no-frontier-why', 'data-no-frontier-option', 'data-no-frontier-blocked', 'data-no-frontier-works'] as $region) {
            self::assertStringContainsString($region, $card);
        }
        self::assertSame(1, substr_count($card, '<button'), 'one option: the one she cannot take');
        self::assertSame(1, preg_match('~<button[^>]*>~', $card, $button));
        self::assertStringContainsString(' disabled', $button[0]);
        self::assertStringContainsString('aria-disabled="true"', $button[0]);
        self::assertSame(0, preg_match('~<(a|form|input|select|textarea)\b~', $card), 'no link, no form, no field: nothing to send');
        foreach (['data-seat-grant', 'data-seat-admit', 'data-seat-withdraw', 'data-seat-give', 'data-agent-answer', 'data-sequence-run', 'data-graph-decide',
            'data-grant-option', 'data-gate-approve', 'data-agent-regenerate', 'data-new-session', 'data-seat-session', 'data-seat-seq', 'data-tool-toggle'] as $hook) {
            self::assertStringNotContainsString($hook, $card, $hook . ' would arm a click delegate');
        }
        self::assertSame([], \Milpa\AgentWorkspace\Live\NoFrontierComponent::contract()->actions, 'and the component declares no action');
    }

    public function testTheContractsAreDeclaredAndInert(): void
    {
        self::assertSame('desktop-user-message', UserMessageComponent::contract()->name);
        self::assertSame('desktop-tool-call', ToolCallComponent::contract()->name);
        self::assertSame('desktop-task', TaskComponent::contract()->name);
        self::assertSame('desktop-system-notice', SystemNoticeComponent::contract()->name);
        self::assertSame('desktop-result-claim', \Milpa\AgentWorkspace\Live\ResultClaimComponent::contract()->name);

        $tool = new ToolCallComponent();
        $state = $tool->mount(['name' => 'capabilities.list', 'result' => '6 capabilities'], new ComponentContext('tool-call'));
        self::assertSame('capabilities.list', $state->data['name']);
        self::assertSame('6 capabilities', $state->meta['result']);
    }
}
