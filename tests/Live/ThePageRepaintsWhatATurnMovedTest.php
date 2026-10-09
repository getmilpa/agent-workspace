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

namespace Milpa\AgentWorkspace\Tests\Live;

use Milpa\Agent\SessionEvent;
use Milpa\Agent\SessionProjector;
use Milpa\AgentWorkspace\Data\DesktopData;
use Milpa\AgentWorkspace\Live\LiveRegion;
use Milpa\AgentWorkspace\Live\SessionStrip;
use Milpa\Container\DIContainer;
use Milpa\EventStore\Event;
use PHPUnit\Framework\TestCase;

/**
 * A turn that ran on a page leaves the page saying what a reload would (greenhouse decisions/0609, path 1, I4).
 *
 * Measured (evidence/1175 §6): the session strip is painted when the page loads. On the page where a person's first
 * turn creates the session it said «No session open» before the turn and went on saying it after, over the
 * conversation that had just run; and the thread showed that turn's answer and none of its refused calls, with them
 * in the ledger. A page loaded afterwards had both right. The client now re-reads both from the page it is on when a
 * turn comes back (decisions/0563); this is the server's half of that.
 *
 * @guards the strip's row is ONE marked region and its signed envelope stays outside it; each call of the replayed
 *         thread carries its own position in the ledger, so a page can tell a call it shows from one it does not;
 *         the transport reads a pushed call's position from the field the house pushes it in
 *
 * @refuses a strip a re-read cannot find; a signed state swapped for one nobody re-signed for this page; a call
 *          with no name of its own, which a page could only paint twice or not at all
 */
final class ThePageRepaintsWhatATurnMovedTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }
    }

    public function testTheStripsRowIsOneRegionAndItsSignedStateStaysOutside(): void
    {
        $html = (new SessionStrip('secret'))->render();

        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>', \LIBXML_NOERROR);
        $found = (new \DOMXPath($doc))->query('//*[@data-live-region="' . LiveRegion::SESSION_STRIP . '"]');
        self::assertNotFalse($found);
        self::assertCount(1, $found, 'exactly one strip to re-read');
        $node = $found->item(0);
        self::assertInstanceOf(\DOMElement::class, $node);
        $region = '';
        foreach ($node->childNodes as $child) {
            $region .= $doc->saveHTML($child);
        }

        self::assertStringContainsString('id="milpa-session-strip-goal"', $region, 'the line that said «No session open» is what a re-read is for');
        self::assertStringContainsString('id="milpa-embed-session"', $region, 'and the picker, which lists the session the turn opened');
        self::assertStringContainsString('data-new-session', $region);
        self::assertStringNotContainsString('data-milpa-state', $region, 'a signed envelope is not re-read');
        self::assertStringContainsString('data-milpa-state="session-strip"', $html);
    }

    public function testTheStripIsARegionTheClientMayAskFor(): void
    {
        self::assertContains(LiveRegion::SESSION_STRIP, LiveRegion::all());
        self::assertContains(LiveRegion::THREAD, LiveRegion::all());
    }

    public function testEachCallOfTheReplayedThreadCarriesItsOwnPositionInTheLedger(): void
    {
        $refusal = "Missing required permission 'plugins.Blog:write' for plugin 'Blog'.";
        $data = $this->data([
            ['session.turn', ['role' => 'user', 'content' => 'Create a plugin named Blog']],
            ['session.tool_called', ['tool' => 'routes_list', 'result' => '[]', 'ok' => true]],
            ['session.tool_called', ['tool' => 'make', 'result' => $refusal, 'ok' => false]],
            ['session.turn', ['role' => 'assistant', 'content' => 'I could not.']],
        ]);

        self::assertSame([
            ['kind' => 'user', 'text' => 'Create a plugin named Blog'],
            ['kind' => 'tool', 'name' => 'routes_list', 'result' => '[]', 'seq' => 2],
            ['kind' => 'tool', 'name' => 'make', 'result' => $refusal, 'seq' => 3],
            ['kind' => 'agent', 'text' => 'I could not.'],
        ], $data->transcript('s'));
    }

    /**
     * The same call has ONE name on both roads to the thread. The transcript carries the ledger's `seq`; the house
     * pushes each fact as milpa/agent's projector wrote it, and there the position is `at` — so that is the field the
     * transport must read, or a call the stream brings late is painted a second time.
     */
    public function testTheTransportReadsAPushedCallsPositionFromTheFieldTheHousePushesItIn(): void
    {
        $pushed = (new SessionProjector())->project(new Event('agent-session:s', SessionEvent::ToolCalled->value, ['tool' => 'make', 'result' => 'refused', 'ok' => false], 41));

        self::assertIsArray($pushed);
        self::assertSame('activity', $pushed['kind'] ?? null);
        self::assertSame(41, $pushed['at'] ?? null, 'the position in the ledger, as the house pushes it');
        self::assertArrayNotHasKey('seq', $pushed, 'an envelope has no «seq»: a transport that reads one reads nothing');

        $hub = (string) file_get_contents(\dirname(__DIR__, 2) . '/resources/components/desktop-hub/desktop-hub.js');
        self::assertMatchesRegularExpression('/function position\(env\) \{\s*var at = parseInt\(env && env\.at, 10\);/', $hub);
        self::assertStringContainsString("say('tool.call', { name: detail.detail || 'tool', result: detail.result || '', seq: position(env) });", $hub);
    }

    /** @param list<array{0: string, 1: array<string, mixed>}> $events */
    private function data(array $events): DesktopData
    {
        $this->dir = sys_get_temp_dir() . '/milpa-repaints-' . uniqid('', true);
        mkdir($this->dir);
        $ledger = $this->dir . '/agent-sessions.jsonl';
        $rows = [];
        foreach ($events as $i => [$type, $payload]) {
            $rows[] = json_encode(['stream_id' => 'agent-session:s', 'type' => $type, 'payload' => $payload, 'seq' => $i + 1], \JSON_THROW_ON_ERROR);
        }
        file_put_contents($ledger, implode("\n", $rows) . "\n");

        return new DesktopData(new DIContainer(), null, '', null, $ledger);
    }
}
