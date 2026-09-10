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

use Milpa\Container\DIContainer;
use Milpa\AgentWorkspace\Data\DesktopData;
use Milpa\AgentWorkspace\AgentWorkspacePlugin;
use Milpa\AgentWorkspace\Live\ShellEvent;
use Milpa\AgentWorkspace\Live\ShellEventLog;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * The data seam reads REAL runtime data (greenhouse decisions/0481): capabilities from the booted plugins'
 * #[PluginMetadata], the model from config — not mocks.
 */
final class DesktopDataTest extends TestCase
{
    public function testCapabilitiesAreTheBootedPluginsMetadata(): void
    {
        $kernel = Kernel::boot(['root' => sys_get_temp_dir(), 'plugins' => [AgentWorkspacePlugin::class]]);
        // Apps register the kernel in their container (the skeleton front controller does); the data reads it.
        $kernel->container()->registerService(Kernel::class, $kernel);

        $caps = (new DesktopData($kernel->container()))->capabilities();

        $names = array_column($caps, 'name');
        self::assertContains('AgentWorkspace', $names);
        $desktop = $caps[array_search('AgentWorkspace', $names, true)];
        self::assertSame('Web', $desktop['type']);
        self::assertSame('Rodrigo Vicente - TeamX Agency', $desktop['author']);
    }

    public function testCapabilitiesAreEmptyWithoutAKernel(): void
    {
        self::assertSame([], (new DesktopData(new DIContainer()))->capabilities());
    }

    public function testPendingDecisionsAreEmptyWithoutTheAgentStore(): void
    {
        // The inbox degrades to none when the agent's SessionStore is not in the container (greenhouse
        // decisions/0195) — an app without the agent shows nothing rather than failing.
        self::assertSame([], (new DesktopData(new DIContainer()))->pendingDecisions());
    }

    public function testSkillsAreEmptyWithoutAKernel(): void
    {
        // Skills degrade to none without a booted kernel (greenhouse decisions/0197) — an app without the
        // runtime shows nothing rather than failing.
        self::assertSame([], (new DesktopData(new DIContainer()))->skills());
    }

    public function testCommandsAreTheHouseOnesWithoutAKernel(): void
    {
        // Without a booted kernel there are no skills to turn into commands, but the house's own commands
        // stand (greenhouse decisions/0202): /goal, /mode and /help are the composer's, not a skill's.
        $commands = (new DesktopData(new DIContainer()))->commands();

        self::assertSame(['goal', 'mode', 'help'], array_column($commands, 'name'));
        self::assertSame(['house', 'house', 'house'], array_column($commands, 'kind'));
        self::assertSame($commands, DesktopData::houseCommands());
    }

    public function testCommandsForAddsOnlyTheUserInvocableSkills(): void
    {
        // A user-invocable skill becomes `/<name> [args]`; a model-only skill is not a command — the human has
        // no surface for it (greenhouse decisions/0202). The house commands come first, in their order. Each
        // command carries the METHOD of its http projection: POST for the mutating house ops, GET for a skill
        // (skill:invoke is a read), none for /help.
        $commands = DesktopData::commandsFor([
            ['name' => 'systematic-debugging', 'description' => 'A method for finding a bug by evidence', 'model_invocable' => true, 'user_invocable' => false],
            ['name' => 'brainstorming', 'description' => 'Frame the question before building', 'model_invocable' => true, 'user_invocable' => true],
        ]);

        self::assertSame(['goal', 'mode', 'help', 'brainstorming'], array_column($commands, 'name'));
        self::assertSame(['POST', 'POST', '', 'GET'], array_column($commands, 'method'));
        self::assertSame(
            ['name' => 'brainstorming', 'kind' => 'skill', 'description' => 'Frame the question before building', 'usage' => '/brainstorming [args]', 'method' => 'GET'],
            $commands[3],
        );
        foreach ($commands as $c) {
            self::assertSame(['name', 'kind', 'description', 'usage', 'method'], array_keys($c));
        }
    }

    public function testCommandsForKeepsOnlyAddressableNamesThatShadowNoHouseCommand(): void
    {
        // The parser addresses `^[a-z0-9-]+$`: a skill named otherwise is no command (it could never be typed
        // as one, and its name would reach the page verbatim); a skill that calls itself `goal` does not take
        // the house's `/goal`; a second skill with a taken name is not a second command.
        $commands = DesktopData::commandsFor([
            ['name' => 'goal', 'description' => 'shadows the house', 'model_invocable' => true, 'user_invocable' => true],
            ['name' => 'Bad Name', 'description' => 'has a space and capitals', 'model_invocable' => true, 'user_invocable' => true],
            ['name' => '../etc', 'description' => 'has a path', 'model_invocable' => true, 'user_invocable' => true],
            ['name' => 'review-2', 'description' => 'fine', 'model_invocable' => false, 'user_invocable' => true],
            ['name' => 'review-2', 'description' => 'a twin', 'model_invocable' => false, 'user_invocable' => true],
        ]);

        self::assertSame(['goal', 'mode', 'help', 'review-2'], array_column($commands, 'name'));
        self::assertSame('house', $commands[0]['kind'], 'the house keeps /goal');
        self::assertSame('fine', $commands[3]['description'], 'the first of two twins is the command');
    }

    public function testRolesAreEmptyWithoutAKernel(): void
    {
        // Specialist roles degrade to none without a booted kernel (greenhouse decisions/0197).
        self::assertSame([], (new DesktopData(new DIContainer()))->roles());
    }

    public function testDeclaredScreensAreEmptyAndLiveRouteDefaultsWithoutAKernel(): void
    {
        // The preview degrades to none without a booted kernel; the live route falls back to /live
        // (greenhouse decisions/0197).
        $data = new DesktopData(new DIContainer());
        self::assertSame([], $data->declaredScreens());
        self::assertSame('/live', $data->liveRoute());
    }

    /**
     * 🚨 THIS TEST CARRIED THE DEFECT IN ITS OWN NAME AND IN ITS FIXTURE.
     *
     * It declared `agent.base_url` — snake_case, a key `AgentKeys` DOES NOT DECLARE; the authority
     * reads `agent.baseUrl` — and asserted the source returned it. So it proved that a surface read
     * a key nobody can set, which is why every real house fell through to the environment and, with
     * no environment, to a hardcoded `http://llama.local:11438` that had stopped resolving
     * (greenhouse decisions/0266).
     *
     * The source asks {@see AgentEndpoint} now: one precedence, resolved once, in the class that
     * exists because it was written twice (evidence/0165).
     */
    public function testTheModelIsWhatTheAuthorityResolvesAndNotAKeyNobodyDeclares(): void
    {
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [],
            'config' => ['agent' => ['model' => 'qwen-test', 'baseUrl' => 'http://hub.test:9000']],
        ]);
        $kernel->container()->registerService(Kernel::class, $kernel);

        $model = (new DesktopData($kernel->container()))->model();

        self::assertSame('qwen-test', $model['model']);
        self::assertSame('http://hub.test:9000', $model['endpoint']);
        self::assertSame('config', $model['model_from'], 'and it says where the value came from');
        self::assertSame('config', $model['endpoint_from']);
    }

    /**
     * THE CONTROL: the key this file used to read returns NOTHING, because nothing declares it.
     *
     * Without this the fix looks like a rename. What it actually is: the surface stopped reading a
     * key that never existed, and a house that declares only the wrong spelling now hears «no model
     * declared» instead of being handed a dead host.
     */
    public function testTheKeyItUsedToReadDeclaresNothing(): void
    {
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [],
            'config' => ['agent' => ['base_url' => 'http://hub.test:9000']],
        ]);
        $kernel->container()->registerService(Kernel::class, $kernel);

        $model = (new DesktopData($kernel->container()))->model();

        self::assertNull($model['endpoint'], 'snake_case declares nothing');
        self::assertSame('none', $model['endpoint_from']);
        self::assertNull($model['reached'], 'no endpoint, no question');
        self::assertSame([], $model['models']);
    }

    /** Nothing declared anywhere is said, never filled in with a name the reader never chose. */
    public function testWithNothingDeclaredItSaysNothingRatherThanNamingAHost(): void
    {
        $model = (new DesktopData(new DIContainer()))->model();

        self::assertNull($model['model']);
        self::assertNull($model['endpoint']);
        self::assertNull($model['reached']);
        self::assertNull($model['serves_declared']);
    }

    public function testToArrayCarriesEveryDataSource(): void
    {
        $snapshot = (new DesktopData(new DIContainer()))->toArray();

        foreach (['capabilities', 'model', 'sessions', 'counters', 'work', 'audit'] as $key) {
            self::assertArrayHasKey($key, $snapshot);
        }
    }

    public function testSessionsCountersAndWorkComeFromTheSessionStore(): void
    {
        $dir = sys_get_temp_dir() . '/milpa-sessions-' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/s1.json', json_encode([
            'id' => 's1', 'goal' => 'Publish the site', 'state' => 'working',
            'turns' => 2, 'steps' => 10, 'tokens' => 500, 'tool_calls' => 41,
            'work' => [
                ['title' => 'List plugins', 'status' => 'done', 'origin' => 'planned'],
                ['title' => 'Verify each', 'status' => 'in_progress', 'origin' => 'found'],
                'not-an-array-ignored',
            ],
        ], JSON_THROW_ON_ERROR));

        $data = new DesktopData(new DIContainer(), null, $dir);

        $sessions = $data->sessions();
        self::assertCount(1, $sessions);
        self::assertSame(['id' => 's1', 'goal' => 'Publish the site', 'state' => 'working'], $sessions[0]);

        $counters = $data->counters();
        self::assertSame(2, $counters['turns']);
        self::assertSame(41, $counters['tool_calls']);
        self::assertSame('working', $counters['state']);

        $work = $data->work();
        self::assertCount(2, $work);
        self::assertSame('List plugins', $work[0]['title']);
        self::assertSame('in_progress', $work[1]['status']);

        unlink($dir . '/s1.json');
        rmdir($dir);
    }

    public function testAuditComesFromTheEventLog(): void
    {
        $path = sys_get_temp_dir() . '/milpa-audit-' . uniqid('', true) . '.log';
        $log = new ShellEventLog($path);
        $log->append(new ShellEvent('gate.opened', ['operation' => 'capabilities.enable']));
        $log->append(new ShellEvent('badge.updated', ['text' => 'hi']));

        $audit = (new DesktopData(new DIContainer(), $log))->audit();

        self::assertCount(2, $audit);
        self::assertSame(1, $audit[0]['seq']);
        self::assertSame('gate.opened', $audit[0]['type']);
        self::assertStringContainsString('capabilities.enable', $audit[0]['data']);

        // steps defaults to the audit count when the session has no explicit counter.
        self::assertSame(2, (new DesktopData(new DIContainer(), $log))->counters()['steps']);

        unlink($path);
    }

    public function testEmptySourcesDegradeGracefully(): void
    {
        $data = new DesktopData(new DIContainer());

        self::assertSame([], $data->sessions());
        self::assertSame([], $data->work());
        self::assertSame([], $data->audit());
        self::assertSame(0, $data->counters()['turns']);
        self::assertSame('', $data->currentSessionId());
    }

    public function testContextWindowUsageFromTheSessionAndConfig(): void
    {
        $dir = sys_get_temp_dir() . '/milpa-ctx-' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/s.json', json_encode(['tokens' => 8192], JSON_THROW_ON_ERROR));
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(), 'plugins' => [],
            'config' => ['agent' => ['context_window' => 32768]],
        ]);
        $kernel->container()->registerService(Kernel::class, $kernel);

        $ctx = (new DesktopData($kernel->container(), null, $dir))->context();

        self::assertSame(8192, $ctx['tokens']);
        self::assertSame(32768, $ctx['window']);
        self::assertSame(25, $ctx['used_pct']);
        self::assertSame(24576, $ctx['free']);

        unlink($dir . '/s.json');
        rmdir($dir);
    }
    /**
     * THE NAMED SESSION IS READ FROM THE LEDGER (greenhouse evidence/0561): counters, context, work and activity
     * come from the agent's facts, the store's file is not consulted — and a session the ledger holds is listed
     * even when the Desktop never wrote a record for it.
     */
    public function testANamedSessionIsReadFromTheLedgerNotFromTheStore(): void
    {
        $dir = sys_get_temp_dir() . '/milpa-data-ledger-' . uniqid('', true);
        mkdir($dir . '/sessions', 0o775, true);
        // A store record with the SAME id and stale zeros — the file the Desktop wrote at creation.
        file_put_contents($dir . '/sessions/desk-1111111111111111.json', json_encode(['id' => 'desk-1111111111111111', 'goal' => 'stale', 'state' => 'ready', 'turns' => 0, 'tokens' => 0, 'work' => [['title' => 'stale card', 'status' => 'pending']]], JSON_THROW_ON_ERROR));
        $ledger = $dir . '/agent-sessions.jsonl';
        $rows = [
            ['stream_id' => 'agent-session:desk-1111111111111111', 'type' => 'session.started', 'payload' => ['goal' => 'run the rollout sequence', 'mode' => 'ask'], 'seq' => 1],
            ['stream_id' => 'agent-session:desk-1111111111111111', 'type' => 'session.turn', 'payload' => ['role' => 'user', 'content' => 'go'], 'seq' => 2],
            ['stream_id' => 'agent-session:desk-1111111111111111', 'type' => 'session.model_called', 'payload' => [], 'seq' => 3],
            ['stream_id' => 'agent-session:desk-1111111111111111', 'type' => 'session.model_returned', 'payload' => ['usage' => ['prompt_tokens' => 800, 'total_tokens' => 1500]], 'seq' => 4],
            ['stream_id' => 'agent-session:desk-1111111111111111', 'type' => 'session.tool_called', 'payload' => ['tool' => 'plugins_list', 'ok' => true, 'result' => '{}'], 'seq' => 5],
            ['stream_id' => 'agent-session:desk-1111111111111111', 'type' => 'session.todo_changed', 'payload' => ['id' => 't1', 'text' => 'List plugins', 'status' => 'in_progress'], 'seq' => 6],
            ['stream_id' => 'agent-session:sequence:deploy', 'type' => 'session.started', 'payload' => ['goal' => 'run the deploy sequence', 'mode' => 'ask'], 'seq' => 7],
        ];
        file_put_contents($ledger, implode("\n", array_map(static fn (array $r): string => json_encode($r, JSON_THROW_ON_ERROR), $rows)) . "\n");
        $data = new DesktopData(new DIContainer(), null, $dir . '/sessions', null, $ledger);

        $data->select('desk-1111111111111111');
        self::assertTrue($data->hasSession());
        self::assertSame('desk-1111111111111111', $data->currentSessionId());
        self::assertSame(['turns' => 1, 'steps' => 1, 'tokens' => 1500, 'tool_calls' => 1, 'state' => 'working'], $data->counters(), 'the ledger, not the stale file');
        self::assertSame(800, $data->context()['tokens'], 'the last prompt is what the context holds');
        self::assertSame([['title' => 'List plugins', 'status' => 'in_progress', 'origin' => 'planned', 'draggable' => false]], $data->work(), 'the agent\'s todos, not dragged by hand');
        self::assertSame('session.tool_called', $data->audit()[4]['type']);
        self::assertSame(['desk-1111111111111111', 'sequence:deploy'], array_column($data->sessions(), 'id'), 'every session the ledger holds is listed');
        self::assertSame('run the rollout sequence', $data->sessions()[0]['goal']);

        // A sequence session — no store record at all — is a session too.
        $data->select('sequence:deploy');
        self::assertTrue($data->hasSession());
        self::assertSame(0, $data->counters()['turns']);
        self::assertSame('run the deploy sequence', $data->sessions()[1]['goal']);

        // THE CONTROL: an id nobody knows selects, but holds nothing — and borrows nobody else's record.
        $data->select('desk-nobody');
        self::assertSame('desk-nobody', $data->currentSessionId());
        self::assertFalse($data->hasSession());
        self::assertSame(0, $data->counters()['turns']);
        self::assertSame([], $data->work());
        self::assertSame('idle', $data->counters()['state']);

        array_map('unlink', glob($dir . '/sessions/*') ?: []);
        rmdir($dir . '/sessions');
        unlink($ledger);
        rmdir($dir);
    }

    public function testWithNoShellStoreTheCurrentSessionIsTheLastTheLedgerStarted(): void
    {
        // THE DEFECT THIS PINS (greenhouse decisions/0258, third time in this family): a host that never
        // writes the shell's file store — the admin's Agent section is one, it has no sidebar to select
        // from — named NO session, so the counters seeded zero while the ledger held the real figures.
        $ledger = tempnam(sys_get_temp_dir(), 'ledger');
        $rows = [
            ['stream_id' => 'agent-session:desk-old', 'type' => 'session.started', 'payload' => ['goal' => 'the older one'], 'seq' => 1],
            ['stream_id' => 'agent-session:desk-live', 'type' => 'session.started', 'payload' => ['goal' => 'the one on screen'], 'seq' => 2],
            ['stream_id' => 'agent-session:desk-live', 'type' => 'session.turn', 'payload' => ['role' => 'user', 'content' => 'hi'], 'seq' => 3],
            ['stream_id' => 'agent-session:desk-live', 'type' => 'session.turn', 'payload' => ['role' => 'assistant', 'content' => 'hello'], 'seq' => 4],
            ['stream_id' => 'agent-session:desk-live', 'type' => 'session.tool_called', 'payload' => ['name' => 'plugins_list'], 'seq' => 5],
            ['stream_id' => 'agent-session:desk-live', 'type' => 'session.model_returned', 'payload' => ['usage' => ['total_tokens' => 154737, 'prompt_tokens' => 9001]], 'seq' => 6],
        ];
        file_put_contents($ledger, implode("\n", array_map(static fn (array $r): string => json_encode($r, \JSON_THROW_ON_ERROR), $rows)));

        // No shell store at all: sessionsPath is '' — exactly what the admin's Agent section runs with.
        $data = new DesktopData(new DIContainer(), null, '', null, $ledger);

        self::assertSame('desk-live', $data->currentSessionId(), 'the LAST session the ledger started');
        self::assertTrue($data->hasSession());
        $counters = $data->counters();
        self::assertSame(1, $counters['turns'], 'the user turn — the assistant answer is not a second one');
        self::assertSame(1, $counters['tool_calls']);
        self::assertSame(154737, $counters['tokens']);
        self::assertSame(9001, $data->context()['tokens'], 'the context is the LAST call prompt, not the spend');

        // THE CONTROL, and it is the one that can say no: a SELECTION still wins over the ledger, so this
        // is a fallback and not a new authority over which session is open.
        $data->select('desk-old');
        self::assertSame('desk-old', $data->currentSessionId());
        self::assertSame(0, $data->counters()['turns']);

        unlink($ledger);
    }

    public function testWithNeitherStoreNorLedgerThereIsNoSession(): void
    {
        // THE CONTROL for the fallback itself: an empty ledger names nothing rather than inventing an id,
        // so «no session open» stays a state the page can reach.
        $data = new DesktopData(new DIContainer(), null, '', null, tempnam(sys_get_temp_dir(), 'empty'));

        self::assertSame('', $data->currentSessionId());
        self::assertFalse($data->hasSession());
        self::assertSame(0, $data->counters()['turns']);
    }
}
