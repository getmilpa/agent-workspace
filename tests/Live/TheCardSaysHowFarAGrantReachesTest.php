<?php

/**
 * This file is part of Milpa Agent Workspace.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/agent-workspace
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Tests\Live;

use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\AgentWorkspace\Live\DecisionsInboxView;
use PHPUnit\Framework\TestCase;

/**
 * The card of a grant says how far it reaches, before the touch (greenhouse decisions/0602).
 *
 * A person who opens the works of a plugin the house already has — knowingly, by its name — opens them for the
 * session they grant: the house then writes inside that plugin without asking about each piece. That is part of
 * what the act does, and the button only names a scope. The terminal says it in its answer; Rod decided the rule
 * with one condition, that the panel say it too, before the touch. The view judges nothing: whether a grant
 * stands, and for what, arrives from the house as a fact of the refusal.
 */
final class TheCardSaysHowFarAGrantReachesTest extends TestCase
{
    private const SEAT = 'EEEE5555FFFF6666AAAA7777BBBB8888CCCC9999';

    public function testTheGrantOverExistingWorkSaysWhatWillNoLongerBeAsked(): void
    {
        self::assertStringContainsString(
            '<p class="decision-card__facts" data-seat-stands>In this session the house will then write inside Prestamos without asking you again about each piece.</p>',
            $this->card(['stands_for' => 'session']),
        );
    }

    /** Before the touch: after what the grant opens, and above the box a person ticks and the button they press. */
    public function testItIsSaidBeforeTheBoxAndTheButton(): void
    {
        $html = $this->card(['stands_for' => 'session']);

        $opens = strpos($html, 'data-seat-opens');
        $stands = strpos($html, 'data-seat-stands');
        $box = strpos($html, 'data-seat-ack');
        $button = strpos($html, 'data-seat-grant');
        self::assertIsInt($opens);
        self::assertIsInt($stands);
        self::assertIsInt($box);
        self::assertIsInt($button);
        self::assertLessThan($stands, $opens);
        self::assertLessThan($box, $stands);
        self::assertLessThan($button, $box);
    }

    /** A runtime that does not carry the rule sends no such fact, and the card reads as it did. */
    public function testAHouseThatDoesNotSayItIsShownNothing(): void
    {
        $plain = $this->card([]);

        self::assertStringNotContainsString('data-seat-stands', $plain);
        self::assertStringNotContainsString('without asking you again', $plain);
        self::assertSame($plain, $this->card(['stands_for' => null]));
        // Only the reach the house names is said: a word this panel does not know promises nothing.
        foreach (['seat', 'always', true, 1, ['session']] as $unknown) {
            self::assertSame($plain, $this->card(['stands_for' => $unknown]));
        }
    }

    /** One touch over a plugin the house does not have names no plugin's work: nothing to say. */
    public function testACardWithoutAPluginSaysNothingOfIt(): void
    {
        self::assertStringNotContainsString('data-seat-stands', $this->card(['stands_for' => 'session', 'plugin' => null, 'target' => null, 'consent' => 'touch']));
    }

    public function testTheSentenceIsTheHousesWordsEscaped(): void
    {
        self::assertStringContainsString(
            'data-seat-stands>In this session the house will then write inside A&lt;b&gt; without asking',
            $this->card(['stands_for' => 'session', 'plugin' => 'A<b>']),
        );
    }

    public function testBothLanguagesHaveTheSentenceAndItReachesTheCard(): void
    {
        $en = (new Catalog('en'))->all()['frontier.stands_session'] ?? null;
        $es = (new Catalog('es'))->all()['frontier.stands_session'] ?? null;
        self::assertSame('In this session the house will then write inside %s without asking you again about each piece.', $en);
        self::assertIsString($es);
        self::assertNotSame($en, $es);
        self::assertStringContainsString('%s', $es);
        // The inbox hands the view the catalogue's words by this list.
        self::assertContains('stands_session', DecisionsInboxView::ADMISSION_WORDS);

        self::assertStringContainsString(
            'data-seat-stands>' . htmlspecialchars(sprintf($es, 'Prestamos'), ENT_QUOTES) . '</p>',
            $this->card(['stands_for' => 'session'], ['stands_session' => $es]),
        );
    }

    /**
     * @param array<string, mixed>  $more
     * @param array<string, string> $copy
     */
    private function card(array $more, array $copy = []): string
    {
        return (new DecisionsInboxView())->frontierHtml([[
            'session' => 'taller', 'goal' => '', 'seat' => 'key:' . self::SEAT,
            'refusals' => [$more + ['seq' => 12, 'tool' => 'edit', 'plugin' => 'Prestamos', 'permission' => 'plugins.Prestamos:write', 'call' => ['plugin' => 'Prestamos'], 'target' => 'existing', 'named' => true, 'consent' => 'informed']],
        ]], copy: $copy);
    }
}
