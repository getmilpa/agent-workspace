<?php

/**
 * This file is part of milpa/agent-workspace — the agent's workspace inside a Milpa app.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent-workspace
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Tests\Controllers;

use Milpa\AgentWorkspace\Controllers\SeatGrantController;
use Milpa\AgentWorkspace\Live\DecisionsInboxView;
use Milpa\Command\Operation;
use Milpa\Command\OperationHttpPolicy;
use Milpa\Container\DIContainer;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The panel's door to `identity:grant` and the frontier cards, judged without the runtime that owns the
 * relation (greenhouse decisions/0493): the door must reach the judge the app published, say so when there is
 * none, and the cards must carry the refused call — never a scope to send.
 */
final class SeatGrantDoorTest extends TestCase
{
    public function testTheDoorAsksTheJudgeTheAppPublished(): void
    {
        // The app publishes its judge under milpa/command's name (App\Http\IdentityWiring). A door that asked for
        // another name found none and answered «no policy» to EVERY caller — measured in the lab, where it
        // refused the stranger for the wrong reason (greenhouse evidence/1026). This judge refuses everyone, so
        // reaching it is the proof the door asked the right name.
        $container = new DIContainer();
        $container->registerService(OperationHttpPolicy::class, new class () implements OperationHttpPolicy {
            public function enforce(Operation $op, ServerRequestInterface $request): ?ResponseInterface
            {
                return new Response(403, ['Content-Type' => 'application/json'], '{"error":"judged: ' . $op->name . '"}');
            }
        });

        $answer = (new SeatGrantController($container, self::grant()))->grant(self::request());

        self::assertSame(403, $answer->getStatusCode());
        self::assertStringContainsString('judged: identity:grant', (string) $answer->getBody());
    }

    public function testTheDoorSaysSoWhenTheAppWiredNoJudge(): void
    {
        $answer = (new SeatGrantController(new DIContainer(), self::grant()))->grant(self::request());

        self::assertSame(501, $answer->getStatusCode(), 'identity:grant declares a scope; with no policy the panel cannot run it');
        self::assertStringContainsString('no policy to judge who may grant', (string) $answer->getBody());
    }

    public function testTheCardCarriesTheRefusedCallAndTheGrantNotAScopeToSend(): void
    {
        $html = (new DecisionsInboxView())->frontierHtml([[
            'session' => 'camino-blog',
            'goal' => 'Build the blog',
            'seat' => 'key:95A3',
            'refusals' => [['seq' => 42, 'tool' => 'make', 'plugin' => 'Blog', 'permission' => 'plugins.Blog:write']],
        ]]);

        self::assertStringContainsString('data-seat-session="camino-blog"', $html);
        self::assertStringContainsString('data-seat-seq="42"', $html);
        self::assertStringContainsString('data-seat-grant', $html);
        self::assertStringContainsString('It lacks plugins.Blog:write', $html);
        self::assertStringContainsString('key:95A3 was refused make · plugin Blog', $html);
        self::assertStringContainsString('href="?session=camino-blog"', $html);
        self::assertStringNotContainsString('milpa-frontier-empty', $html);
        self::assertStringContainsString('milpa-frontier-empty', (new DecisionsInboxView())->frontierHtml([]));
    }

    /** The operation as app-runtime declares it — scoped and confirmed — without needing that runtime here. */
    private static function grant(): Operation
    {
        return new Operation(
            name: 'identity:grant',
            description: 'Grant a seat the scope its refusal names',
            handler: static fn (): array => ['ok' => false],
            mutating: true,
            requiresConfirmation: true,
            scopes: ['identity:enroll'],
            surfaces: ['cli', 'http'],
        );
    }

    private static function request(): ServerRequest
    {
        return (new ServerRequest('POST', '/workspace/grant', ['Content-Type' => 'application/json']))
            ->withBody(Stream::create('{"session":"camino-blog","seq":2}'));
    }
}
