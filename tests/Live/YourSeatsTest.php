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

use Milpa\AgentWorkspace\Controllers\PanelDoorController;
use Milpa\AgentWorkspace\Live\DecisionsInboxView;
use PHPUnit\Framework\TestCase;

/**
 * «Your seats» (greenhouse decisions/0499): the seats a reader answers for, with the key to compare against the
 * resident's own, and the one control that gives the resident a seat — a name, never a scope.
 */
final class YourSeatsTest extends TestCase
{
    public function testEachSeatShowsItsKeyScopesAndWhoEnrolledIt(): void
    {
        $html = (new DecisionsInboxView())->seatsHtml([[
            'fingerprint' => '95A3AC7B96F8BC6AA7044F2C09082971DEBAAA50',
            'label' => 'resident',
            'scopes' => ['agent:run', 'agent:read'],
            'authorized_by' => 'passkey:QM1L',
        ]]);

        self::assertStringContainsString('data-seat-key="95A3AC7B96F8BC6AA7044F2C09082971DEBAAA50"', $html);
        self::assertStringContainsString('<code>95A3AC7B96F8BC6AA7044F2C09082971DEBAAA50</code>', $html, 'the full key, to compare with gpg --fingerprint');
        self::assertStringContainsString('agent:run agent:read', $html);
        self::assertStringContainsString('enrolled by passkey:QM1L', $html);
        self::assertStringNotContainsString('milpa-seats-empty', $html);
    }

    public function testTheFormCarriesANameAndNeverAScope(): void
    {
        $html = (new DecisionsInboxView())->seatsHtml([]);

        self::assertStringContainsString('milpa-seats-empty', $html);
        self::assertStringContainsString('data-seat-give-form', $html);
        self::assertStringContainsString('data-seat-label', $html);
        self::assertStringContainsString('data-seat-give', $html);
        self::assertStringContainsString('data-seat-command hidden', $html, 'the command appears only once a seat is given');
        self::assertStringNotContainsString('scope', strtolower(strip_tags($html)), 'what a seat may do is the house\'s to declare');
    }

    public function testTheSeatDoorIsMountedAsAPost(): void
    {
        self::assertSame('/workspace/seat', PanelDoorController::DOORS[PanelDoorController::SEAT]['path']);
        self::assertSame('POST', PanelDoorController::DOORS[PanelDoorController::SEAT]['verb']);
        self::assertSame('identity:seat', PanelDoorController::SEAT);
    }
}
