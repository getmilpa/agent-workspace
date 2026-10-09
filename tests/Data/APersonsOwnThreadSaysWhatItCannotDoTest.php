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

namespace Milpa\AgentWorkspace\Tests\Data;

use Milpa\AgentWorkspace\Data\DesktopData;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * A PERSON'S OWN THREAD SAYS WHAT HER SESSION CANNOT DO (greenhouse decisions/0609, path 1, I2).
 *
 * Measured (evidence/1175): a person asks the composer to build, the house refuses her session with nobody to ask —
 * and in her thread there was nothing that told her what to do. A reloaded thread now carries, right under the call
 * that was refused, a row the client paints as a dimmed card: the grant her session would have needed, that nobody
 * can give it, and the act that works. WHICH calls those are is the house's judgement, read through
 * app-runtime's frontier; this package parses no sentence.
 *
 * @guards the row under the refused call, with exactly what the house said of it; nothing for a session the house
 *         says nothing of; nothing when the house cannot be asked or answers something else
 *
 * @refuses a row invented from the text of a refusal; a row for a seq that is not a call; a thread that breaks when
 *          the house does not read them yet
 *
 * @subject-in milpa/agent-workspace
 */
final class APersonsOwnThreadSaysWhatItCannotDoTest extends TestCase
{
    private const REFUSED = "Missing required permission 'plugins.Blog:write' for plugin 'Blog'. No plugin 'Blog' exists in this house yet.";

    private string $dir = '';

    protected function tearDown(): void
    {
        DesktopData::usePersonFrontier(null);
        if ($this->dir !== '' && is_dir($this->dir)) {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }
    }

    public function testTheRowSitsUnderTheCallThatWasRefusedWithWhatTheHouseSaidOfIt(): void
    {
        $asked = [];
        DesktopData::usePersonFrontier(static function (string $session) use (&$asked): array {
            $asked[] = $session;

            return [['seq' => 2, 'tool' => 'make', 'plugin' => 'Blog', 'permission' => 'plugins.Blog:write', 'call' => ['what' => 'plugin', 'plugin' => 'Blog']]];
        });

        $rows = $this->thread()->transcript('s');

        self::assertSame(['s'], $asked, 'the house is asked once, of this session');
        self::assertSame(['user', 'tool', 'no_frontier', 'agent'], array_column($rows, 'kind'));
        self::assertSame(['kind' => 'no_frontier', 'seq' => 2, 'tool' => 'make', 'plugin' => 'Blog', 'permission' => 'plugins.Blog:write'], $rows[2]);
        self::assertSame(self::REFUSED, $rows[1]['result'], 'the call itself is still shown as it was');
    }

    public function testASessionTheHouseSaysNothingOfIsTheThreadItWas(): void
    {
        DesktopData::usePersonFrontier(static fn (string $session): array => []);

        self::assertSame(['user', 'tool', 'agent'], array_column($this->thread()->transcript('s'), 'kind'), 'a seat\'s session: its refusal is the frontier\'s, in Decisions');
    }

    public function testTheSentenceOfARefusalIsNeverReadForIt(): void
    {
        // The house is not asked at all here: the refusal's text alone, however it reads, makes no card.
        self::assertSame(['user', 'tool', 'agent'], array_column($this->thread()->transcript('s'), 'kind'));
    }

    public function testWhatIsNotSuchARowIsNotPainted(): void
    {
        DesktopData::usePersonFrontier(static fn (string $session): array => [
            ['seq' => 1, 'tool' => 'make', 'plugin' => 'Blog', 'permission' => 'plugins.Blog:write'],   // seq 1 is her turn, not a call
            ['seq' => 2, 'tool' => 'make', 'plugin' => 'Blog'],                                         // no permission
            ['seq' => 2, 'tool' => '', 'permission' => 'plugins.Blog:write'],                           // no tool
            ['seq' => '2', 'tool' => 'make', 'permission' => 'plugins.Blog:write'],                     // a seq that is not a number
            'a sentence',
            ['seq' => 99, 'tool' => 'make', 'permission' => 'plugins.Blog:write'],                      // a seq this thread does not hold
        ]);

        self::assertSame(['user', 'tool', 'agent'], array_column($this->thread()->transcript('s'), 'kind'));
    }

    public function testAPluginTheCallDidNotNameIsSaidAsNone(): void
    {
        DesktopData::usePersonFrontier(static fn (string $session): array => [['seq' => 2, 'tool' => 'edit', 'plugin' => 7, 'permission' => 'plugins.Blog:write']]);

        self::assertNull($this->thread()->transcript('s')[2]['plugin']);
    }

    public function testAHouseThatCannotBeAskedOrAnswersSomethingElseLeavesTheThreadWhole(): void
    {
        DesktopData::usePersonFrontier(static function (string $session): array {
            throw new \RuntimeException('the ledger could not be read');
        });
        self::assertSame(['user', 'tool', 'agent'], array_column($this->thread()->transcript('s'), 'kind'));

        DesktopData::usePersonFrontier(static fn (string $session): string => 'not rows');
        self::assertSame(['user', 'tool', 'agent'], array_column($this->thread()->transcript('s'), 'kind'));
    }

    /** A thread: she asks, the call is refused, the model answers. */
    private function thread(): DesktopData
    {
        $this->dir = sys_get_temp_dir() . '/milpa-no-frontier-' . uniqid('', true);
        mkdir($this->dir);
        $ledger = $this->dir . '/agent-sessions.jsonl';
        $events = [
            ['session.turn', ['role' => 'user', 'content' => 'Build a plugin named Blog']],
            ['session.tool_called', ['tool' => 'make', 'arguments' => ['what' => 'plugin', 'plugin' => 'Blog'], 'result' => self::REFUSED, 'ok' => false]],
            ['session.turn', ['role' => 'assistant', 'content' => 'I could not build it here.']],
        ];
        $lines = [];
        foreach ($events as $i => [$type, $payload]) {
            $lines[] = json_encode(['stream_id' => 'agent-session:s', 'type' => $type, 'payload' => $payload, 'seq' => $i + 1], \JSON_THROW_ON_ERROR);
        }
        file_put_contents($ledger, implode("\n", $lines) . "\n");

        return new DesktopData(new DIContainer(), null, '', null, $ledger);
    }
}
