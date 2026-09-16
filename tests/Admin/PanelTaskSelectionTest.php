<?php

/**
 * This file is part of Milpa Agent Workspace.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Tests\Admin;

use Milpa\AgentWorkspace\Admin\{AgentView,AgentViewComponent,AgentViewRenderer,PanelSession};
use Milpa\AgentWorkspace\Controllers\MutationController;
use Milpa\AgentWorkspace\Data\{DesktopData,DesktopStore};
use Milpa\AgentWorkspace\DesktopSettings;
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\AgentWorkspace\Live\{DeliveryEvidenceRenderer,DesktopComponents,SessionTicket,Surfaces};
use Milpa\Container\DIContainer;
use Milpa\Live\ValueObjects\{ComponentContext,RenderRequest};
use Nyholm\Psr7\{ServerRequest,Stream};
use PHPUnit\Framework\TestCase;

/** The admitted task supplies every projection; rejected choices never keep an active composer. */
final class PanelTaskSelectionTest extends TestCase
{
    private string $dir;
    private DesktopStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/panel-tasks-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->store = new DesktopStore($this->dir . '/sessions', $this->dir . '/settings.json');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/sessions/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir . '/sessions')) {
            rmdir($this->dir . '/sessions');
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testCreationRecordsTheAuthenticatedPrincipalAndIgnoresBodyClaims(): void
    {
        $auth = new class () {
            public object $actor;
            public function __construct()
            {
                $this->actor = (object) ['id' => 'owner'];
            }
            public function isAuthenticated(): bool
            {
                return true;
            }
        };
        $request = (new ServerRequest('POST', '/workspace/sessions'))->withAttribute('milpa.auth', $auth)
            ->withBody(Stream::create(json_encode(['goal' => 'B', 'panel_principal' => 'reader', 'principal' => 'reader'], JSON_THROW_ON_ERROR)));
        $response = (new MutationController($this->store))->createSession($request);
        $id = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)['id'];
        self::assertSame([$id], $this->store->panelSessionIds('owner'));
        self::assertSame([], $this->store->panelSessionIds('reader'));
        self::assertSame([], $this->store->panelSessionIds(''));
    }

    public function testUnknownForeignMalformedAndUnownedChoicesAreRejected(): void
    {
        $own = $this->store->createSession('B', 'owner');
        $foreign = $this->store->createSession('C', 'reader');
        $unowned = $this->store->createSession('legacy');
        $local = $this->store->createSession('local', '');
        foreach ([$foreign, $unowned, $local, 'missing', '', '../escape', ['session' => $own], 123] as $requested) {
            $selection = PanelSession::fromContext(new ComponentContext('panel', principal: 'owner', meta: ['query' => ['session' => $requested]]), $this->store);
            self::assertNull($selection->id);
            self::assertSame([PanelSession::forPrincipal('owner'), $own], $selection->allowed);
        }
        $selection = PanelSession::fromContext(new ComponentContext('panel', principal: 'owner'), $this->store);
        self::assertSame(PanelSession::forPrincipal('owner'), $selection->id);
        self::assertSame($selection, PanelSession::fromContext($selection->inContext(new ComponentContext('child', principal: 'owner'))));
        self::assertNotSame($selection, PanelSession::fromContext($selection->inContext(new ComponentContext('child', principal: 'reader'))));
        self::assertSame($local, PanelSession::fromContext(new ComponentContext('local', meta: ['query' => ['session' => $local]]), $this->store)->id);
        file_put_contents($this->dir . '/sessions/desk-invalid.json', '{}');
        file_put_contents($this->dir . '/sessions/desk-0000000000000000.json', 'broken');
        file_put_contents($this->dir . '/sessions/desk-1111111111111111.json', json_encode(['id' => $own, 'panel_principal' => 'owner'], JSON_THROW_ON_ERROR));
        self::assertSame([$own], $this->store->panelSessionIds('owner'));
    }

    public function testConversationCriteriaPickerSignalsAndTicketFollowTheSameAdmittedTask(): void
    {
        $a = PanelSession::forPrincipal('owner');
        $b = $this->store->createSession('Task B', 'owner');
        $c = $this->store->createSession('Hidden task', 'reader');
        $ledger = $this->dir . '/ledger.jsonl';
        $events = [];
        foreach ([$a => 'Task A transcript', $b => 'Task B transcript', $c => 'Foreign transcript'] as $id => $text) {
            $events[] = ['stream_id' => 'agent-session:' . $id, 'type' => 'session.started', 'payload' => ['goal' => $text], 'seq' => count($events) + 1];
            $events[] = ['stream_id' => 'agent-session:' . $id, 'type' => 'session.turn', 'payload' => ['role' => 'user', 'content' => $text], 'seq' => count($events) + 1];
        }
        file_put_contents($ledger, implode("\n", array_map(static fn (array $e): string => json_encode($e, JSON_THROW_ON_ERROR), $events)) . "\n");
        $data = new DesktopData(new DIContainer(), sessionsPath: $this->dir . '/sessions', store: $this->store, ledgerPath: $ledger);
        $live = new DesktopComponents('signing', 'csrf');
        $seen = [];
        $reader = new DeliveryEvidenceRenderer(static function (string $id) use (&$seen): array {
            $seen[] = $id;
            return ['session' => $id, 'state' => 'awaiting_candidate', 'report' => null, 'expectation' => ['expected' => ['test' => ['path' => 'tests/' . $id, 'filter' => ''], 'screen' => ['name' => $id, 'type' => 'todo-list']]]];
        });
        (new Surfaces($data, catalogue: new Catalog(), deliveryEvidence: $reader))->declareOn($live);
        $view = AgentView::of($live, DesktopSettings::fromConfig(null), new Catalog(), $data, '/signin', 'signing');
        $renderer = new AgentViewRenderer($live, $data, signingSecret: 'signing');
        foreach ([$b, $a, $b] as $id) {
            $context = new ComponentContext('agent', principal: 'owner', meta: ['query' => ['session' => $id, 'principal' => 'reader']]);
            self::assertSame(1, $view->resolveSignals($context)['session.turns']);
            $html = $renderer->render(new AgentViewComponent(), new RenderRequest($context, props: ['session' => $c]))->output;
            self::assertStringContainsString('"agent":"' . $id . '"', $html);
            self::assertStringContainsString('tests/' . $id, $html);
            self::assertStringContainsString('<option value="' . $id . '" selected', $html);
            self::assertStringContainsString($id === $b ? 'Task B transcript' : 'Task A transcript', $html);
            self::assertStringNotContainsString('Foreign transcript', $html);
            self::assertStringNotContainsString('Hidden task', $html);
            preg_match('#<script id="milpa-desktop-ticket" type="application/json">(.*?)</script>#', $html, $match);
            $ticket = SessionTicket::open('signing', json_decode($match[1], true, flags: JSON_THROW_ON_ERROR)['ticket']);
            self::assertNotNull($ticket);
            self::assertSame($id, $ticket->sessionId);
            self::assertTrue($ticket->belongsTo('owner'));
            self::assertFalse($ticket->belongsTo('reader'));
        }
        self::assertSame([$b, $a, $b], $seen);
        foreach ([$c, 'missing'] as $id) {
            $context = new ComponentContext('agent', principal: 'owner', meta: ['query' => ['session' => $id]]);
            self::assertSame(0, $view->resolveSignals($context)['session.turns']);
            $html = $renderer->render(new AgentViewComponent(), new RenderRequest($context))->output;
            self::assertStringContainsString('data-panel-session-unavailable', $html);
            self::assertStringNotContainsString('milpa-composer-dock', $html);
            self::assertStringNotContainsString('milpa-desktop-ticket', $html);
        }
        self::assertSame([$b, $a, $b], $seen);
    }
}
