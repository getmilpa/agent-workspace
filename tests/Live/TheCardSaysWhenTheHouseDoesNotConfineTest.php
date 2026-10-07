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
 * The admission card has words for a house that does not run a call confined to its state (greenhouse decisions/0588).
 *
 * The runtime now says `open`, with why, where the house switched confinement off or its agent runtime cannot record
 * where work ran; a panel without words for it left that cell empty. And the column says whose call it is about:
 * one the house runs, in a session — a call a seat signs on the terminal runs in the signer's own process.
 */
final class TheCardSaysWhenTheHouseDoesNotConfineTest extends TestCase
{
    public function testAnOpenCallIsSaidNotConfinedWithTheHousesReason(): void
    {
        $html = $this->card(['how' => 'open', 'why' => 'confinement is switched off in this house']);

        self::assertStringContainsString('<td>not confined to that state: confinement is switched off in this house</td>', $html);
        self::assertStringNotContainsString('in the house, confined', $html);
        self::assertStringNotContainsString('what was there is kept', $html);
    }

    public function testWhatThePanelHasNoWordsForIsLeftUnsaid(): void
    {
        self::assertStringContainsString('<td></td></tr>', $this->card(['how' => 'somewhere-new']));
    }

    public function testTheColumnSaysItIsAboutACallTheHouseRunsInASession(): void
    {
        self::assertStringContainsString('<th scope="col">How the house runs a call of it, in a session</th>', $this->card(['how' => 'reads']));
        self::assertSame('How the house runs a call of it, in a session', (new Catalog('en'))->all()['frontier.col_runs'] ?? null);
        self::assertSame('Cómo corre la casa una llamada suya, en una sesión', (new Catalog('es'))->all()['frontier.col_runs'] ?? null);
    }

    public function testBothLanguagesHaveTheWords(): void
    {
        self::assertSame('not confined to that state: %s', (new Catalog('en'))->all()['frontier.runs_open'] ?? null);
        self::assertSame('sin confinar a ese estado: %s', (new Catalog('es'))->all()['frontier.runs_open'] ?? null);
        self::assertContains('runs_open', DecisionsInboxView::ADMISSION_WORDS, 'and the inbox hands them to the card');
    }

    /** @param array<string, mixed> $runs */
    private function card(array $runs): string
    {
        return (new DecisionsInboxView())->frontierHtml([[
            'session' => 'taller', 'goal' => '', 'seat' => 'key:BD93', 'refusals' => [],
            'admissions' => [[
                'seq' => 9, 'tool' => 'herramientas_prestar', 'capability' => 'Prestamos', 'permission' => 'herramientas:write', 'scope' => 'herramientas:write',
                'contract' => 'sha256:' . str_repeat('a', 64), 'not_admissible' => null,
                'opens' => [['verb' => 'herramientas.prestar', 'mutating' => true, 'standing' => 'never', 'state' => ['paths' => ['var/herramientas.json'], 'source' => 'entities'], 'runs' => $runs]],
            ]],
        ]]);
    }
}
