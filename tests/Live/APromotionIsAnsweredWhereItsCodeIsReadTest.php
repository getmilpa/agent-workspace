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
        self::assertStringContainsString('decision-card__open', $html, 'the control: a session the panel opens keeps its link');
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
        // Read as a DOCUMENT, not as a string: the module fills these by their nesting, so the nesting is the contract
        // (the first independent review of this half, L1 — a substring check passed a prototype that showed no code).
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?>' . (new MessagePrototypes('secret'))->askGrant());
        $xpath = new \DOMXPath($doc);
        $one = static fn (string $query): int => $xpath->query($query)?->length ?? 0;

        self::assertSame(1, $one('//*[@data-grant-claim]//*[@data-claim-lands]'), 'the region sits inside the claim');
        self::assertSame(1, $one('//*[@data-claim-lands]/*[@data-claim-lands-title]'));
        self::assertSame(1, $one('//*[@data-claim-lands]/*[@data-claim-digest]'));
        self::assertSame(1, $one('//*[@data-claim-lands]//*[@data-claim-files]/*[@data-claim-file]'), 'one file row, the template');
        foreach (['data-claim-file-path', 'data-claim-file-status'] as $hook) {
            self::assertSame(1, $one("//*[@data-claim-file]//*[@{$hook}]"), "{$hook} inside the file row");
        }
        self::assertSame(1, $one('//*[@data-claim-file]//pre[@data-claim-file-shown]'), 'the code, in a block of its own, inside the file row');
        self::assertSame(1, $one('//*[@data-claim-lands]//*[@data-claim-judged-rows]/*[@data-claim-judged]'));
        self::assertSame(1, $one('//*[@data-claim-judged]/*[@data-claim-judged-name]'));
        self::assertSame(1, $one('//*[@data-claim-judged]/*[@data-claim-judged-value]'));
        self::assertSame(1, $one('//*[@data-claim-lands]/following::*[@data-grant-options]'), 'the code comes before the buttons');
    }

    public function testASequencePausedOnAPromotionOffersNoAnswerOnItsCard(): void
    {
        $paused = ['name' => 'promote-probe', 'steps' => ['sandbox:promote'], 'session' => 'sequence:promote-probe', 'paused' => true, 'pending_operation' => 'sandbox:promote'];

        $html = (new DecisionsInboxView())->sequencesHtml([$paused]);

        self::assertStringNotContainsString('data-agent-answer', $html, 'no approve where the code is not shown (H1)');
        self::assertStringContainsString('paused on sandbox:promote — Its code is not shown here', $html, 'and no «answer below»');
        self::assertStringContainsString('read it with agent:show and answer it with agent:answer in the terminal', $html, 'where it IS read: a sequence\'s session is not one the panel opens');
        self::assertStringContainsString('A yes lands the code; any other answer discards the trial.', $html, 'and what each answer does');
        self::assertStringNotContainsString('--answer=', $html, 'no line to paste: a pasted placeholder answered no');
        self::assertStringContainsString('data-sequence-run', $html, 'the run itself is still offered');
    }

    public function testTheControlASequencePausedOnAnotherStepKeepsItsAnswers(): void
    {
        $paused = ['name' => 'deploy', 'steps' => ['config:set'], 'session' => 'sequence:deploy', 'paused' => true, 'pending_operation' => 'config:set'];

        self::assertStringContainsString('data-agent-answer="yes"', (new DecisionsInboxView())->sequencesHtml([$paused]));
    }

    public function testTheInboxCardOfASequencesPromotionNamesTheTerminal(): void
    {
        $row = ['session' => 'sequence:promote-probe', 'goal' => 'g', 'question' => 'Promote?', 'operation' => 'sandbox:promote', 'reason' => 'permission', 'sequence' => 'promote-probe']
            + DesktopData::whatItLands(['lands' => [['path' => 'src/F.php', 'status' => 'added']], 'digest' => self::DIGEST]);

        $html = (new DecisionsInboxView())->html([$row]);

        self::assertStringContainsString('read it with agent:show and answer it with agent:answer in the terminal', $html);
        self::assertStringNotContainsString('Read the code in its session', $html, 'not a session the panel can open');
        self::assertStringNotContainsString('decision-card__open', $html, 'and no link to a page that does not open');
        self::assertStringNotContainsString('data-agent-answer', $html);
    }

    public function testAPromotionWithoutItsFilesIsStillNotApprovedFromTheInbox(): void
    {
        $row = ['session' => 's-3', 'goal' => 'g', 'question' => 'Promote?', 'operation' => 'sandbox:promote', 'reason' => 'permission'];

        $html = (new DecisionsInboxView())->html([$row]);

        self::assertStringNotContainsString('data-agent-answer', $html);
        self::assertStringContainsString('Read the code in its session', $html);
    }

    /**
     * THE INBOX AS THE HOUSE RENDERS IT, from a real ledger (the first independent review of this half, L2): the row
     * the data seam reads carries the promotion's files, and the card says it in the house's words.
     */
    public function testTheInboxReadFromALedgerSendsAPromotionToItsSession(): void
    {
        $root = sys_get_temp_dir() . '/milpa-inbox-lands-' . bin2hex(random_bytes(6));
        mkdir($root . '/var', 0o700, true);
        try {
            $store = new \Milpa\Agent\SessionStore(new \Milpa\EventStore\FileEventStore($root . '/var/agent-sessions.jsonl'));
            $store->start('s-ledger', 'build a feed');
            $store->ask('s-ledger', new \Milpa\Agent\PendingQuestion('perm:sandbox:promote', 'The agent wants to promote trial «w1»…', ['yes', 'no'], (string) json_encode([
                'operation' => 'sandbox:promote',
                'arguments' => ['workspace' => 'w1'],
                'digest' => self::DIGEST,
                'lands' => [['path' => 'plugins/Blog/Feed.php', 'status' => 'added', 'sha256' => null, 'shown' => "+<?php\n"]],
            ]), reason: 'permission'));
            $kernel = \Milpa\Runtime\Kernel::boot(['root' => $root, 'plugins' => []]);
            $kernel->container()->registerService(\Milpa\Runtime\Kernel::class, $kernel);
            $codec = new \Milpa\Live\Security\SignedXhtmlStateTransferCodec(new \Milpa\Live\Transport\XhtmlStateTransferCodec(), new \Milpa\Live\Security\HmacStateSigner('inbox-lands-secret-0123456789'), null);

            foreach (['en' => 'Read the code in its session', 'es' => 'Lee el código en su sesión'] as $locale => $said) {
                $html = (new \Milpa\AgentWorkspace\Live\DecisionsInbox($codec, new DesktopData($kernel->container()), null, new \Milpa\AgentWorkspace\I18n\Catalog($locale)))->render(false);

                self::assertStringContainsString('<code>plugins/Blog/Feed.php</code>', $html, $locale);
                self::assertStringContainsString($said, $html, $locale);
                self::assertStringNotContainsString('data-agent-answer="yes"', $html, $locale);
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }
}
