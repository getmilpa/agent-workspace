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
    private function markup(): string
    {
        return (new ComposerBar(str_repeat('k', 32)))->render();
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
            '/<p class="composer-degraded" x-show="[^"]*conn\.state[^"]*"/',
            $this->markup(),
            'it must be conditional, not always painted',
        );
    }

    /**
     * It points at where the problem is solved, and does not pretend to solve it here.
     *
     * Starting a container is not something this room may do on somebody's machine.
     */
    public function testItPointsAtTheSectionThatSaysHowToStartIt(): void
    {
        self::assertStringContainsString('Stack', $this->markup());
    }
}
