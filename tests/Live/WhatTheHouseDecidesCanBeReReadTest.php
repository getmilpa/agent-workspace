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

use Milpa\AgentWorkspace\Data\DesktopData;
use Milpa\AgentWorkspace\Data\LedgerSession;
use Milpa\AgentWorkspace\Live\DecisionsInboxView;
use Milpa\AgentWorkspace\Live\DesktopAssets;
use Milpa\AgentWorkspace\Live\LiveRegion;
use Milpa\AgentWorkspace\Live\WorkBoard;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * What the house decides can be re-read without a reload (greenhouse decisions/0563).
 *
 * Measured (evidence/1095): a window that received 1,209 pushes showed the frontier card, the question's card,
 * the grant's notice and «Verified by the house» only after a reload — and the Desktop's window has none. The
 * client now re-reads a region from the page it is on; this is the server's half of that contract.
 *
 * @guards each surface the house derives is ONE marked region holding the whole of what a re-read must
 *         replace — the list and its empty line, the verdict and its cards; the module that re-reads them is
 *         a declared runtime module, emitted before the transport; the house's own turn is a notice in the
 *         replayed thread
 *
 * @refuses a signed envelope inside a region (a re-read would swap a state nobody re-signed for the page);
 *          a region name the client asks for that the server never prints; a person's turn read as a notice
 */
final class WhatTheHouseDecidesCanBeReReadTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }
    }

    public function testTheParkedQuestionsAreOneRegionWithTheirEmptyLine(): void
    {
        $view = new DecisionsInboxView();

        $empty = self::region($view->html([]), LiveRegion::DECISIONS_PENDING);
        self::assertStringContainsString('id="milpa-decisions-list"', $empty);
        self::assertStringContainsString('id="milpa-decisions-empty"', $empty, 'the empty line is re-read with the list, or it would stay beside a card');

        $parked = self::region($view->html([['session' => 's1', 'goal' => 'Build the blog', 'question' => 'Confirm edit?', 'operation' => 'edit', 'reason' => '']]), LiveRegion::DECISIONS_PENDING);
        self::assertStringContainsString('data-decision-session="s1"', $parked);
        self::assertStringContainsString('data-agent-answer="yes"', $parked, 'the card a re-read brings is the one that can be answered');
        self::assertStringNotContainsString('milpa-decisions-empty', $parked);
    }

    public function testTheFrontierIsOneRegionWithItsEmptyLine(): void
    {
        $view = new DecisionsInboxView();

        $empty = self::region($view->frontierHtml([]), LiveRegion::DECISIONS_FRONTIER);
        self::assertStringContainsString('id="milpa-frontier-list"', $empty);
        self::assertStringContainsString('id="milpa-frontier-empty"', $empty);

        $open = self::region($view->frontierHtml([[
            'session' => 'camino-blog', 'goal' => 'Build the blog', 'seat' => 'key:95A3',
            'refusals' => [['seq' => 42, 'tool' => 'make', 'plugin' => 'Blog', 'permission' => 'plugins.Blog:write']],
        ]]), LiveRegion::DECISIONS_FRONTIER);
        self::assertStringContainsString('data-seat-seq="42"', $open);
        self::assertStringContainsString('data-seat-grant', $open);
    }

    public function testTheBoardAndTheVerdictAreOneRegionAndTheSignedStateStaysOutside(): void
    {
        $html = (new WorkBoard('secret', $this->data([
            ['session.turn', ['role' => 'user', 'content' => 'Build the blog']],
            ['session.todo_changed', ['id' => 't1', 'text' => 'Serve GET /blog', 'status' => 'done']],
            ['session.turn', ['role' => 'assistant', 'content' => 'Done.']],
            ['session.closure_derived', ['verified' => true, 'reasons' => [], 'scope' => 'recorded_work']],
        ])))->render();

        $region = self::region($html, LiveRegion::WORK);
        self::assertStringContainsString('data-work-closure data-verified="1"', $region, 'the verdict is what a re-read is for');
        self::assertStringContainsString('class="work-board"', $region);
        self::assertStringNotContainsString('data-milpa-state', $region, 'a signed envelope is not re-read');
        self::assertStringContainsString('data-milpa-state="work-board"', $html);
    }

    public function testAnEmptyBoardIsStillARegionSoItsFirstCardHasWhereToLand(): void
    {
        $region = self::region((new WorkBoard('secret', $this->data([['session.started', ['goal' => 'g']]])))->render(), LiveRegion::WORK);

        self::assertStringContainsString('mui-empty', $region);
    }

    /**
     * A module names the region it re-reads in a `…_REGION` variable, never inline — so this test can read every
     * name a module asks for and hold it against the names the server prints.
     */
    public function testEveryRegionTheModulesAskForIsOneTheServerNames(): void
    {
        $asked = [];
        foreach (glob(\dirname(__DIR__, 2) . '/resources/components/*/*.js') ?: [] as $module) {
            $source = (string) file_get_contents($module);
            self::assertDoesNotMatchRegularExpression("/reread\\(\\[\\s*'/", $source, basename($module) . ' names a region inline, where this test cannot read it');
            preg_match_all("/var [A-Z_]*REGION = '([^']+)'/", $source, $found);
            foreach ($found[1] as $name) {
                $asked[$name] = true;
            }
        }
        $names = array_keys($asked);
        sort($names);
        $declared = LiveRegion::all();
        sort($declared);

        self::assertSame($declared, $names, 'the modules ask for exactly the regions the server names — a renamed one fails here, not in a browser');
    }

    public function testTheModuleThatReReadsIsDeclaredBeforeTheTransport(): void
    {
        $modules = DesktopAssets::runtimeModules();

        self::assertContains(DesktopAssets::REGIONS, $modules);
        self::assertLessThan(array_search(DesktopAssets::REGIONS, $modules, true), array_search(DesktopAssets::BUS, $modules, true), 'it listens to the bus');
        self::assertLessThan(array_search(DesktopAssets::HUB, $modules, true), array_search(DesktopAssets::REGIONS, $modules, true), 'and is there before the stream opens');
        self::assertNotNull(DesktopAssets::path(DesktopAssets::REGIONS . '.js'), 'a declared module that is not shipped is a 404 in silence');
        self::assertContains(DesktopAssets::url(DesktopAssets::REGIONS, 'js'), DesktopAssets::runtimeAssets()->scripts);
    }

    public function testTheHousesOwnTurnIsANoticeInTheReplayedThreadAndAPersonsIsNot(): void
    {
        $notice = LedgerSession::HOUSE_VOICE . 'passkey:rod granted this seat the scope «plugins.Blog:write».';
        $data = $this->data([
            ['session.turn', ['role' => 'user', 'content' => 'Build the blog']],
            ['session.turn', ['role' => 'user', 'content' => $notice]],
            ['session.turn', ['role' => 'user', 'content' => 'what does [house] mean?']],
            ['session.turn', ['role' => 'assistant', 'content' => LedgerSession::HOUSE_VOICE . 'is not mine to say']],
        ]);

        self::assertSame([
            ['kind' => 'user', 'text' => 'Build the blog'],
            ['kind' => 'notice', 'text' => $notice],
            ['kind' => 'user', 'text' => 'what does [house] mean?'],
            ['kind' => 'agent', 'text' => LedgerSession::HOUSE_VOICE . 'is not mine to say'],
        ], $data->transcript('s'));
    }

    public function testTheTransportReadsTheHousesVoiceByTheSameWords(): void
    {
        $hub = (string) file_get_contents(\dirname(__DIR__, 2) . '/resources/components/desktop-hub/desktop-hub.js');

        self::assertStringContainsString("var HOUSE_VOICE = '" . LedgerSession::HOUSE_VOICE . "';", $hub);
        if (class_exists(\Milpa\AppRuntime\Agent\SeatFrontier::class)) {
            self::assertSame(\Milpa\AppRuntime\Agent\SeatFrontier::NOTICE_PREFIX, LedgerSession::HOUSE_VOICE);
        }
    }

    /** The inner HTML of the ONE element marked as this region; fails when there is none, or more than one. */
    private static function region(string $html, string $name): string
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>', \LIBXML_NOERROR);
        $found = (new \DOMXPath($doc))->query('//*[@data-live-region="' . $name . '"]');
        self::assertNotFalse($found);
        self::assertCount(1, $found, \sprintf('exactly one «%s» region', $name));
        $node = $found->item(0);
        self::assertInstanceOf(\DOMElement::class, $node);
        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= $doc->saveHTML($child);
        }

        return $inner;
    }

    /** @param list<array{0: string, 1: array<string, mixed>}> $events */
    private function data(array $events): DesktopData
    {
        $this->dir = sys_get_temp_dir() . '/milpa-reread-' . uniqid('', true);
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
