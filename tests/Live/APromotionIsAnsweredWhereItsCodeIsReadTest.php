<?php

/**
 * This file is part of milpa/agent-workspace.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Tests\Live;

use Milpa\AgentWorkspace\Data\DesktopData;
use Milpa\AgentWorkspace\Live\DecisionsInboxView;
use Milpa\AgentWorkspace\Live\MessagePrototypes;
use PHPUnit\Framework\TestCase;

/**
 * A PROMOTION IS ANSWERED WHERE ITS CODE CAN BE READ (greenhouse decisions/0628).
 *
 * Code an agent wrote boots in the live house with its secrets once promoted, and the runtime asks a person in
 * every mode, with the changed files and the digest the yes covers in the question's claim. A surface that offers
 * the buttons without that code asks for a yes to something nobody could see: the request in the conversation
 * shows it before its buttons, and the decisions inbox — which shows no code — lists the files and sends the
 * reader there instead of offering an approve of its own.
 */
final class APromotionIsAnsweredWhereItsCodeIsReadTest extends TestCase
{
    private const DIGEST = 'diff:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testTheInboxCardOfAPromotionListsItsFilesAndOffersNoApprove(): void
    {
        $row = ['session' => 's-1', 'goal' => 'build a feed', 'question' => 'The agent wants to promote trial «w1»…', 'operation' => 'sandbox:promote', 'reason' => 'permission']
            + DesktopData::whatItLands([
                'operation' => 'sandbox:promote',
                'digest' => self::DIGEST,
                'lands' => [
                    ['path' => 'plugins/Blog/Feed.php', 'status' => 'added', 'shown' => "+<?php\n"],
                    ['path' => 'config/<app>.php', 'status' => 'modified', 'shown' => '@@ line 1 @@'],
                ],
            ]);

        $html = (new DecisionsInboxView())->html([$row]);

        self::assertStringContainsString('<code>plugins/Blog/Feed.php</code> new file', $html);
        self::assertStringContainsString('<code>config/&lt;app&gt;.php</code> changed', $html, 'a path is text, escaped');
        self::assertStringContainsString(self::DIGEST, $html);
        self::assertStringContainsString('Read the code in its session', $html);
        self::assertStringNotContainsString('data-agent-answer', $html, 'no approve where the code is not shown');
        self::assertStringContainsString('href="?session=s-1"', $html, 'the way to where it is shown');
    }

    public function testTheControlAQuestionThatIsNotAPromotionKeepsItsButtons(): void
    {
        $row = ['session' => 's-2', 'goal' => 'g', 'question' => 'May I set a key?', 'operation' => 'config:set', 'reason' => 'permission']
            + DesktopData::whatItLands(['operation' => 'config:set', 'arguments' => ['key' => 'x']]);

        $html = (new DecisionsInboxView())->html([$row]);

        self::assertStringContainsString('data-agent-answer="yes"', $html);
        self::assertStringNotContainsString('decision-card__lands', $html);
    }

    public function testTheConversationsRequestPrintsTheRegionTheCodeIsShownIn(): void
    {
        $html = (new MessagePrototypes('secret'))->askGrant();

        foreach (['data-claim-lands', 'data-claim-digest', 'data-claim-file', 'data-claim-file-path', 'data-claim-file-status', 'data-claim-file-shown', 'data-claim-judged'] as $hook) {
            self::assertStringContainsString($hook, $html, "the server prints {$hook} for the module to fill");
        }
        self::assertLessThan(strpos($html, 'data-grant-options'), strpos($html, 'data-claim-lands'), 'the code comes before the buttons');
    }
}
