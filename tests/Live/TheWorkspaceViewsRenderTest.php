<?php

/**
 * This file is part of milpa/agent-workspace.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent-workspace
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Tests\Live;

use Milpa\AgentWorkspace\Live\DecisionsInboxView;
use Milpa\AgentWorkspace\Live\RolesView;
use Milpa\AgentWorkspace\Live\ScreenPreviewView;
use Milpa\AgentWorkspace\Live\SkillsView;
use Milpa\Eventing\EventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * THE WORKSPACE'S VIEWS, RENDERED DIRECTLY — no page, no request, no controller.
 *
 * These fifteen properties lived in `ShellControllerTest` and never touched the page: they build a view
 * and assert what it prints. When `/desktop` was retired, thirty-two of that file's forty-seven methods
 * died with their subject and these fifteen did not — so they moved rather than being deleted, because
 * a property about a VIEW outlives whichever host renders it (greenhouse decisions/0283).
 *
 * The file they came from was named after a controller, which is why they read as page tests. They are
 * not: the decisions inbox, the declared sequences, the skills and roles
 * lists, the screen preview and the composer field are all painted by the admin panel today.
 */
final class TheWorkspaceViewsRenderTest extends TestCase
{
    /*
     * NO HAY PRUEBAS DEL CATÁLOGO DE CAPACIDADES: la vista se retiró con su pantalla, por duplicada.
     * La sección Plugins del panel es nativa de `milpa/admin` y pinta el mismo catálogo
     * (greenhouse decisions/0290).
     */

    public function testTheDecisionsInboxRendersAParkedQuestionAcrossSessions(): void
    {
        // Populated inbox (pure view, greenhouse decisions/0195): a card carries the goal, the question, its
        // facts, and a link to open the session it was raised in.
        $html = (new DecisionsInboxView())->html([
            ['session' => 's-42', 'goal' => 'Publish the site', 'question' => 'Enable milpa/data?', 'operation' => 'capabilities:enable', 'reason' => 'privileged'],
        ]);

        self::assertStringContainsString('milpa-decisions-list', $html);
        self::assertStringContainsString('Enable milpa/data?', $html);
        self::assertStringContainsString('Publish the site', $html);
        self::assertStringContainsString('capabilities:enable', $html);
        // A QUERY-ONLY HREF: it stays on the host's page and switches the session, instead of
        // navigating to a surface that no longer exists (greenhouse decisions/0283).
        self::assertStringContainsString('href="?session=s-42"', $html);
        self::assertStringNotContainsString('/desktop?session=', $html);
    }
    /**
     * AN AGENT'S CARD ANSWERS HERE (greenhouse decisions/0223, F4): two buttons carrying what `agent:answer`
     * reads, and the sequence the session is parked on so an approval can resume the run in place.
     */
    public function testAParkedQuestionCarriesItsAnswersAndTheSequenceItPausedOn(): void
    {
        $html = (new DecisionsInboxView())->html([
            ['session' => 'sequence:deploy', 'goal' => 'run the deploy sequence', 'question' => 'El agente quiere correr «config:set». ¿Lo autorizas?', 'operation' => 'config:set', 'reason' => 'permission', 'sequence' => 'deploy'],
        ], copy: ['approve' => 'Aprobar', 'deny' => 'Rechazar']);

        self::assertStringContainsString('data-decision-sequence="deploy"', $html);
        self::assertStringContainsString('data-agent-answer="sí"', $html);
        self::assertStringContainsString('data-agent-answer="no"', $html);
        self::assertStringContainsString('>Aprobar<', $html, 'the caller\'s words');
        self::assertStringContainsString('data-decision-status', $html, 'the line the outcome lands in is printed, not invented');

        // A question that is NOT a sequence pause still prints the attribute — empty — so the module's hook
        // exists on every card (the DOM contract), and the module reads «no sequence» from its emptiness.
        $plain = (new DecisionsInboxView())->html([
            ['session' => 's-1', 'goal' => 'g', 'question' => 'q', 'operation' => '', 'reason' => ''],
        ]);
        self::assertStringContainsString('data-decision-sequence=""', $plain);
    }
    /**
     * THE SEQUENCES THIS APP DECLARED are cards that RUN from here: the steps in order, a run button, the
     * answers hidden until a run parks — and shown, with the status line, when it already is.
     */
    public function testTheDeclaredSequencesRenderAsRunnableCardsThatAnswerTheirPauseInPlace(): void
    {
        $html = (new DecisionsInboxView())->sequencesHtml([
            ['name' => 'deploy', 'steps' => ['plugins:list', 'config:set'], 'session' => 'sequence:deploy', 'paused' => false, 'pending_operation' => ''],
            ['name' => 'rollout', 'steps' => ['plugins:list'], 'session' => 'sequence:rollout', 'paused' => true, 'pending_operation' => 'plugins:lock'],
        ], copy: ['run' => 'Correr', 'paused_on' => 'pausada en %s']);

        self::assertStringContainsString('milpa-sequences-list', $html);
        self::assertStringContainsString('data-sequence="deploy"', $html);
        self::assertStringContainsString('data-sequence-session="sequence:deploy"', $html);
        self::assertStringContainsString('plugins:list → config:set', $html, 'the steps, in order');
        self::assertStringContainsString('>Correr<', $html);
        self::assertStringContainsString('data-agent-answer="sí" hidden', $html, 'no run parked: the answers wait, hidden');
        self::assertStringContainsString('data-sequence-paused', $html, 'the parked one says so');
        self::assertStringContainsString('pausada en plugins:lock', $html, 'and at which step');
        self::assertStringNotContainsString('milpa-sequences-empty', $html);
    }
    public function testTheDeclaredSequencesShowAnEmptyLineWhenTheAppDeclaresNone(): void
    {
        $html = (new DecisionsInboxView())->sequencesHtml([], 'Ninguna secuencia.');

        self::assertStringContainsString('milpa-sequences-list', $html, 'the list is always rendered');
        self::assertStringContainsString('milpa-sequences-empty', $html);
        self::assertStringContainsString('Ninguna secuencia.', $html);
    }
    public function testTheDecisionsInboxShowsAnEmptyStateWhenNothingIsParked(): void
    {
        $html = (new DecisionsInboxView())->html([]);

        self::assertStringContainsString('milpa-decisions-empty', $html);
        self::assertStringContainsString('No decisions to make', $html);
    }
    public function testTheSkillsViewRendersEachSkillWithWhoMayInvokeIt(): void
    {
        // Populated (pure view, greenhouse decisions/0197): a card per skill, its description, and who may
        // reach for it — the agent, the human, or both.
        $html = (new SkillsView())->html([
            ['name' => 'systematic-debugging', 'description' => 'A method for finding a bug by evidence', 'model_invocable' => true, 'user_invocable' => false],
            ['name' => 'brainstorming', 'description' => 'Frame the question before building', 'model_invocable' => true, 'user_invocable' => true],
        ]);

        self::assertStringContainsString('systematic-debugging', $html);
        self::assertStringContainsString('A method for finding a bug by evidence', $html);
        self::assertStringContainsString('agent &amp; you', $html, 'both-invocable badge');
        self::assertStringContainsString('>agent<', $html, 'agent-only badge');
    }
    public function testTheSkillsViewShowsAnEmptyStateWhenThereAreNone(): void
    {
        $html = (new SkillsView())->html([]);

        self::assertStringContainsString('mui-empty', $html);
        self::assertStringContainsString('SKILL.md', $html);
    }
    public function testTheRolesViewRendersASpecialistWithItsSkillsAndDenies(): void
    {
        // Populated (pure view, greenhouse decisions/0197): a role card with what it produces, the skills it
        // preloads, and the tools it is denied.
        $html = (new RolesView())->html([
            ['name' => 'reviewer', 'produces' => 'a review report', 'deny' => ['shell'], 'skills' => ['systematic-debugging']],
        ]);

        self::assertStringContainsString('reviewer', $html);
        self::assertStringContainsString('a review report', $html);
        self::assertStringContainsString('systematic-debugging', $html);
        self::assertStringContainsString('shell', $html);
        self::assertStringContainsString('denied', $html);
    }
    public function testTheRolesViewShowsAnEmptyStateWhenThereAreNone(): void
    {
        $html = (new RolesView())->html([]);

        self::assertStringContainsString('mui-empty', $html);
        self::assertStringContainsString('agent:role:declare', $html);
    }
    public function testTheScreenPreviewRendersAChipCarryingItsServedPath(): void
    {
        // Populated (pure view, greenhouse decisions/0197): a chip per declared screen, carrying the exact path
        // the live wire serves it at, so the preview iframe points straight there.
        $html = (new ScreenPreviewView())->html([
            ['name' => 'tasks', 'type' => 'data-table', 'served_at' => '/live/page?component=tasks'],
        ]);

        self::assertStringContainsString('data-screen-name="tasks"', $html);
        self::assertStringContainsString('data-screen-src="/live/page?component=tasks"', $html);
        self::assertStringContainsString('data-table', $html);
    }
    public function testTheScreenPreviewShowsAnEmptyStateWhenNoScreensAreDeclared(): void
    {
        $html = (new ScreenPreviewView())->html([]);

        self::assertStringContainsString('mui-empty', $html);
        self::assertStringContainsString('screen:declare', $html);
    }
    public function testTheComposerFieldValidatesOnBlurAndDeclaresTheStatusRepaint(): void
    {
        // End-to-end demo of cross-component reactivity (greenhouse evidence/0491): on blur the field
        // validates on the server and DECLARES a RenderEffect that re-paints the sibling status component.
        $field = new \Milpa\AgentWorkspace\Live\ComposerMessageComponent();
        $context = new \Milpa\Live\ValueObjects\ComponentContext('composer-message');
        $state = $field->mount(['name' => 'message'], $context);

        $ok = $field->handle(new \Milpa\Live\ValueObjects\InteractionRequest('composer-message', 'textarea', 'blur', $state, ['value' => 'hello world']));
        $render = null;
        foreach ($ok->effects as $effect) {
            if (($effect['type'] ?? null) === 'render') {
                $render = $effect;
            }
        }
        self::assertNotNull($render, 'blur declares a render effect');
        self::assertSame('composer-status', $render['target']);
        self::assertSame('input', $render['component']);
        self::assertStringContainsString('chars · ready', (string) $render['props']['value']);
        self::assertSame([], $ok->errors);

        $empty = $field->handle(new \Milpa\Live\ValueObjects\InteractionRequest('composer-message', 'textarea', 'blur', $state, ['value' => '   ']));
        self::assertArrayHasKey('value', $empty->errors, 'an empty value is rejected on the server');
    }
    public function testTheComposerFieldEmitsRenderEventsSoPluginsCanExtendIt(): void
    {
        // Milpa is event-driven: a component emits lifecycle events so other plugins can subscribe and
        // extend it (greenhouse decisions/0189). before_render mutates the props, after_render the HTML.
        $events = new EventDispatcher(new NullLogger());
        $events->subscribe(\Milpa\AgentWorkspace\Live\ComposerField::BEFORE_RENDER, static function (string $n, array $p): void {
            $p['composer']->props['placeholder'] = 'Extended by a plugin';
        });
        $events->subscribe(\Milpa\AgentWorkspace\Live\ComposerField::AFTER_RENDER, static function (string $n, array $p): void {
            $p['composer']->html .= '<!-- plugin appended -->';
        });

        $html = (new \Milpa\AgentWorkspace\Live\ComposerField('sign', 'csrf', $events))->render();

        self::assertStringContainsString('Extended by a plugin', $html, 'the before_render subscriber changed the props');
        self::assertStringContainsString('plugin appended', $html, 'the after_render subscriber changed the html');
    }
}
