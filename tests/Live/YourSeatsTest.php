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
            'fingerprint' => 'CCCC3333DDDD4444EEEE5555FFFF6666AAAA7777',
            'label' => 'resident',
            'scopes' => ['agent:run', 'agent:read'],
            'authorized_by' => 'passkey:QM1L',
        ]]);

        self::assertStringContainsString('data-seat-key="CCCC3333DDDD4444EEEE5555FFFF6666AAAA7777"', $html);
        self::assertStringContainsString('<code>CCCC3333DDDD4444EEEE5555FFFF6666AAAA7777</code>', $html, 'the full key, to compare with gpg --fingerprint');
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

    /**
     * The fourth rehearsal (greenhouse evidence/1069 §C3): with the resident listed, «Give the resident a seat» was
     * still the primary button, and pressing it minted another invitation for another touch. A name holds one seat
     * (greenhouse decisions/0536): with the resident seated, the panel says so and offers only another name.
     */
    public function testASeatedResidentIsNotOfferedASecondSeat(): void
    {
        $html = (new DecisionsInboxView())->seatsHtml([[
            'fingerprint' => 'CCCC3333DDDD4444EEEE5555FFFF6666AAAA7777',
            'label' => 'Resident',
            'scopes' => ['agent:run'],
            'authorized_by' => 'passkey:QM1L',
        ]]);

        self::assertStringNotContainsString('Give the resident a seat', $html);
        self::assertStringNotContainsString('mui-btn--primary', $html, 'nothing here is the next step any more');
        self::assertStringContainsString('data-seat-held', $html);
        self::assertStringContainsString('«Resident» has its seat.', $html);
        self::assertStringContainsString('<details data-seat-another><summary>Give a seat to another resident</summary>', $html);
        self::assertStringContainsString('value="" data-seat-label', $html, 'the name is the person\'s to choose, not «resident» again');
        self::assertStringContainsString('data-seat-taken="[&quot;resident&quot;]"', $html, 'the module refuses a taken name before any touch');
        self::assertStringNotContainsString('scope', strtolower(strip_tags($html)));
    }

    public function testAnotherSeatLeavesTheResidentsOfferAsItWas(): void
    {
        $html = (new DecisionsInboxView())->seatsHtml([[
            'fingerprint' => 'ABCDEF0123456789ABCDEF0123456789ABCDEF01',
            'label' => 'reviewer',
            'scopes' => ['agent:read'],
            'authorized_by' => 'passkey:QM1L',
        ], [
            'fingerprint' => 'ABCDEF0123456789ABCDEF0123456789ABCDEF02',
            'label' => null,
            'scopes' => ['agent:read'],
            'authorized_by' => 'passkey:QM1L',
        ]]);

        self::assertStringContainsString('value="resident" data-seat-label', $html);
        self::assertStringContainsString('mui-btn--primary" data-seat-give>Give the resident a seat</button>', $html);
        self::assertStringNotContainsString('data-seat-held', $html);
        self::assertStringContainsString('data-seat-taken="[&quot;reviewer&quot;]"', $html);
    }

    public function testTheSeatDoorIsMountedAsAPost(): void
    {
        self::assertSame('/workspace/seat', PanelDoorController::DOORS[PanelDoorController::SEAT]['path']);
        self::assertSame('POST', PanelDoorController::DOORS[PanelDoorController::SEAT]['verb']);
        self::assertSame('identity:seat', PanelDoorController::SEAT);
    }
}
