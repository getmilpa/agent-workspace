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

use Milpa\AgentWorkspace\Live\ComposerBar;
use PHPUnit\Framework\TestCase;

/**
 * A room that runs degraded has to say so.
 *
 * Without the Mercure hub the workspace still works — the browser polls the shared log instead of
 * being pushed to — and it looked IDENTICAL to the pushed version. Somebody read a working screen and
 * had no way to know their live feed was a poll. Rod, looking at the panel: «"Agent" lo veo normal,
 * como si el hub no fuera requerido… que no haga parecer al usuario que todo está bien»
 * (greenhouse decisions/0252).
 */
final class TheRoomSaysWhenItIsDegradedTest extends TestCase
{
    private function markup(string $stackUrl = '/milpa/admin/s/stack'): string
    {
        return (new ComposerBar(str_repeat('k', 32), stackUrl: $stackUrl))->render();
    }

    /**
     * It binds the transport's own signal rather than probing a port.
     *
     * The Stack reports the PORT; this reports THIS CONNECTION. Two facts about one service, not two
     * truths that can disagree — and the bus already publishes `conn.state` on open and on error, so
     * nothing new has to learn how to ask.
     */
    public function testItSaysTheUpdatesAreArrivingOnAPoll(): void
    {
        $html = $this->markup();

        self::assertStringContainsString('composer-degraded', $html);
        self::assertStringContainsString('updates arrive on a poll', $html);
        self::assertStringContainsString("conn.state'] !== 'live'", $html, 'bound to the transport, not to a probe');
    }

    /**
     * And it is hidden until the transport says otherwise: a permanent notice is noise, and noise is
     * how a warning stops being read.
     */
    public function testItIsHiddenWhileTheHubIsLive(): void
    {
        self::assertMatchesRegularExpression(
            '/<p class="composer-degraded"[^>]*x-show="[^"]*conn\.state[^"]*"/',
            $this->markup(),
            'it must be conditional, not always painted',
        );
    }

    /**
     * It points at where the problem is solved — with a LINK — and does not pretend to solve it here.
     *
     * Starting a container is not something this room may do on somebody's machine.
     *
     * 🚨 This test used to be `assertStringContainsString('Stack', …)`, and it was green the whole time
     * there was no link at all: a grep for a word in prose cannot fail. Rod had to look at the screen to
     * find what the suite could not (greenhouse decisions/0255, and the same shape as evidence/0565).
     */
    public function testItPointsAtTheSectionThatSaysHowToStartIt(): void
    {
        self::assertMatchesRegularExpression(
            '/<a class="composer-degraded__link" href="\/milpa\/admin\/s\/stack">[^<]+<\/a>/',
            $this->markup(),
            'the way out is a link to that section, not a sentence naming it',
        );
    }

    /** It reads as a WARNING: the mark is the component's, in the warning tier, and never an alarm. */
    public function testItReadsAsAWarningAndNotAsAnAlarm(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../resources/components/desktop-composer/desktop-composer.css');
        $rule = (string) strstr($css, '.composer-degraded {');
        $rule = substr($rule, 0, (int) strpos($rule, '}') + 1);

        self::assertStringContainsString('var(--warning)', $rule, 'the warning tier, from the token that exists');
        self::assertStringNotContainsString('var(--danger', $rule, 'degraded is not an alarm: a red here teaches people to ignore reds');
        self::assertStringContainsString("content: '\\26A0'", $css, 'and it carries a warning mark');
    }

    /**
     * AN APP WITH NO PANEL GETS A WAY OUT THAT IS NOT A LINK — and no dead anchor either.
     *
     * 🚨 IT USED TO GET NOTHING, and this test used to certify that as correct. The reasoning was right
     * about the anchor — never point at a section this app does not serve — and wrong about the
     * consequence: dropping the way out told a person something was broken and nothing about what to do.
     * Measured on fresh cattle with a hub ALREADY RUNNING on the declared port, which is the cruellest
     * version: the answer was one command away and the app said nothing (greenhouse decisions/0282).
     *
     * `coa stack` is the way out every app has, panel or not.
     */
    public function testWithNoPanelItNamesTheCommandInsteadOfOfferingADeadLink(): void
    {
        $html = $this->markup('');

        self::assertStringContainsString('updates arrive on a poll', $html, 'the state is still said');
        self::assertStringContainsString('php bin/coa stack', $html, 'the way out every app has');
        self::assertStringContainsString('See what this app declared with', $html);
        self::assertStringNotContainsString('composer-degraded__link', $html);
        self::assertStringNotContainsString('<a', substr($html, (int) strpos($html, 'composer-degraded')), 'no anchor after the notice');
    }

    /** And with a panel it links there and does NOT also print the command — one way out, not two. */
    public function testWithAPanelTheLinkIsTheWayOutAndTheCommandIsNotAlsoPrinted(): void
    {
        $html = $this->markup('/milpa/admin/s/stack');

        self::assertStringContainsString('composer-degraded__link', $html);
        self::assertStringNotContainsString('php bin/coa stack', $html, 'two ways out is a choice a person did not ask for');
    }

    /** And the link honours the route the app DECLARED, never a copied default. */
    public function testItHonoursTheRouteTheAppDeclared(): void
    {
        self::assertStringContainsString('href="/panel/s/stack"', $this->markup('/panel/s/stack'));
    }
}
