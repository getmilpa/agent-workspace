<?php

/**
 * This file is part of milpa/desktop-app — a Milpa app hosts itself as a desktop app.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/desktop-app
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Data;

use Milpa\Attributes\PluginMetadata;
use Milpa\AgentWorkspace\Live\ShellEventLog;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Config;
use Milpa\Runtime\Kernel;

/**
 * The Desktop's data seam — real data the screens consume, not mocks (greenhouse decisions/0481, 0482).
 *
 * It reads what the running app actually knows: CAPABILITIES from the booted plugins' `#[PluginMetadata]`
 * (off the {@see Kernel} the app registers), the MODEL from {@see Config}, the SESSIONS from the app's
 * on-disk session store (JSON files under `desktop.sessions.path`, default `.milpa/sessions/`) with the
 * current session's COUNTERS and WORK board read from that same file, and the AUDIT facts from the shared
 * {@see ShellEventLog}. Missing sources degrade to empty — the screens show nothing rather than invent it.
 */
final class DesktopData
{
    public function __construct(
        private readonly DIContainerInterface $container,
        private readonly ?ShellEventLog $log = null,
        private readonly string $sessionsPath = '',
        private readonly ?DesktopStore $store = null,
        private readonly ?string $ledgerPath = null,
    ) {
    }

    /**
     * The thread of ONE agent session, replayed from the ledger the agent writes (greenhouse evidence/0561).
     *
     * A reload used to paint nothing: the thread lived in the page's memory, and the ledger — the one truth,
     * `var/agent-sessions.jsonl` — was never read into it. This reads that stream by its format (one JSON
     * line per event: `stream_id`, `type`, `payload`, `seq`) and keeps what a human reads as a conversation:
     * the turns, the tool calls, the questions parked and answered, a sequence pausing and resuming. The
     * client paints each row with the same prototypes a live turn uses, so a replayed thread and a live one
     * are the same markup.
     *
     * @return list<array<string, mixed>>
     */
    public function transcript(string $agentSid): array
    {
        $file = $this->ledgerFile();
        if ($agentSid === '' || $file === null || !is_file($file)) {
            return [];
        }
        $rows = [];
        foreach (file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $event = json_decode($line, true);
            if (!\is_array($event) || ($event['stream_id'] ?? null) !== 'agent-session:' . $agentSid) {
                continue;
            }
            $rows[] = $event;
        }
        usort($rows, static fn (array $a, array $b): int => (int) ($a['seq'] ?? 0) <=> (int) ($b['seq'] ?? 0));

        $out = [];
        foreach ($rows as $event) {
            $p = \is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $row = match ($event['type'] ?? '') {
                'session.turn' => ($p['role'] ?? '') === 'assistant'
                    ? ['kind' => 'agent', 'text' => $this->str($p['content'] ?? null)]
                    : ['kind' => 'user', 'text' => $this->str($p['content'] ?? null)],
                'session.tool_called' => ['kind' => 'tool', 'name' => $this->str($p['tool'] ?? null) ?: 'tool', 'result' => $this->str($p['result'] ?? null)],
                'session.question_asked' => ['kind' => 'question', 'text' => $this->str($p['question'] ?? null), 'id' => $this->str($p['id'] ?? null), 'reason' => $this->str($p['reason'] ?? null)],
                'session.question_answered' => ['kind' => 'answered', 'answer' => $this->str($p['answer'] ?? null), 'by' => $this->str(\is_array($p['by'] ?? null) ? ($p['by']['id'] ?? null) : null)],
                'session.sequence_paused' => ['kind' => 'sequence_paused', 'sequence' => $this->str($p['sequenceId'] ?? null)],
                'session.sequence_resumed' => ['kind' => 'sequence_resumed', 'sequence' => $this->str($p['sequenceId'] ?? null)],
                default => null,
            };
            if ($row !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /** @var array<string, array<string, mixed>>|null every session the ledger holds, folded once per request */
    private ?array $ledger = null;

    /**
     * Every session the agent's ledger holds, folded by {@see LedgerSession} — the truth the surfaces read.
     *
     * @return array<string, array<string, mixed>>
     */
    public function ledgerSessions(): array
    {
        return $this->ledger ??= LedgerSession::all($this->ledgerFile() ?? '');
    }

    /**
     * One agent session as the ledger tells it, or `null` when the ledger holds no stream by that id.
     *
     * @return array<string, mixed>|null
     */
    public function session(string $id): ?array
    {
        return $this->ledgerSessions()[$id] ?? null;
    }

    /**
     * Whether the current id names a session that EXISTS — in the ledger or in the store. A freshly minted id
     * names nothing yet, and the page says «No session open» rather than borrowing another session's record.
     */
    public function hasSession(): bool
    {
        $id = $this->currentSessionId();

        return $id !== '' && ($this->session($id) !== null || $this->storeRecord($id) !== null);
    }

    /** The ledger the agent writes: the one handed to the constructor, else the booted app's `var/agent-sessions.jsonl`. */
    private function ledgerFile(): ?string
    {
        if ($this->ledgerPath !== null) {
            return $this->ledgerPath;
        }
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;

        return $kernel instanceof Kernel ? $kernel->root() . '/var/agent-sessions.jsonl' : null;
    }

    /**
     * The persisted Desktop settings (write side, decisions/0483), or an empty array if none saved.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return $this->store?->settings() ?? [];
    }

    /**
     * The installed capabilities: every booted plugin that declares `#[PluginMetadata]`.
     *
     * @return list<array{name: string, version: string, type: string, author: string}>
     */
    public function capabilities(): array
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return [];
        }

        $out = [];
        foreach ($kernel->plugins() as $plugin) {
            // A plugin the kernel booted always declares #[PluginMetadata]; iterating (0 or 1) needs no guard.
            foreach ((new \ReflectionClass($plugin))->getAttributes(PluginMetadata::class) as $attribute) {
                $meta = $attribute->newInstance();
                $out[] = ['name' => $meta->name, 'version' => $meta->version, 'type' => $meta->type, 'author' => $meta->author];
            }
        }

        return $out;
    }

    /**
     * The capability catalogue the runtime reports: what is installed, and what is available to install.
     *
     * Read server-side from the same {@see \Milpa\AppRuntime\Support\Capabilities} answer the `capabilities`
     * operation returns — so the human sees EXACTLY what the agent sees, and installing one (through the
     * gated `capabilities:enable` over HTTP, greenhouse decisions/0193) is instantly available to both.
     *
     * @return array{installed: list<array<string, mixed>>, available: list<array<string, mixed>>, source: string}
     *
     * @codeCoverageIgnore reads through to the app-runtime Capabilities registry; exercised by integration
     *                     on a booted app (greenhouse evidence/0507), not by the standalone unit suite
     */
    public function capabilityCatalogue(): array
    {
        if (!class_exists(\Milpa\AppRuntime\Support\Capabilities::class)) {
            return ['installed' => [], 'available' => [], 'source' => ''];
        }

        $answer = \Milpa\AppRuntime\Support\Capabilities::answer();

        return [
            'installed' => is_array($answer['installed'] ?? null) ? array_values($answer['installed']) : [],
            'available' => is_array($answer['available'] ?? null) ? array_values($answer['available']) : [],
            'source' => is_string($answer['source'] ?? null) ? $answer['source'] : '',
        ];
    }

    /**
     * The decisions DECLARED GRAPHS are waiting on — the other half of the same inbox.
     *
     * A graph parks in an append-only log rather than holding a process, so its question outlives the run
     * that raised it and can be answered from anywhere. Unlike an agent's parked question, which is answered
     * in the conversation of its own session, a graph decision is answered HERE: its options are the cases of
     * the enum its routes were declared with, so the buttons and the machine cannot drift apart.
     *
     * Guarded so an app without `milpa/orchestrator`, or one that declares no graphs, degrades to none.
     *
     * @return list<array{graph: string, instance: string, question: string, options: list<string>, requester: string}>
     *
     * @codeCoverageIgnore reads through to GraphRuns; exercised on a booted app, not by the standalone suite
     */
    public function pendingGraphDecisions(): array
    {
        if (!class_exists(\Milpa\Orchestrator\Declaration\GraphRuns::class)
            || !$this->container->getContainer()->has(\Milpa\Orchestrator\Declaration\GraphRuns::class)) {
            return [];
        }

        $runs = $this->container->get(\Milpa\Orchestrator\Declaration\GraphRuns::class);

        if (!$runs instanceof \Milpa\Orchestrator\Declaration\GraphRuns) {
            return [];
        }

        $rows = [];

        foreach ($runs->pending() as $row) {
            /** @var list<string> $options */
            $options = \is_array($row['options'] ?? null) ? array_values(array_filter($row['options'], 'is_string')) : [];

            $rows[] = [
                'graph' => (string) ($row['graph'] ?? ''),
                'instance' => (string) ($row['instance_id'] ?? ''),
                'question' => (string) ($row['gate_id'] ?? ''),
                'options' => $options,
                'requester' => (string) ($row['requester'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * The decisions inbox: every session that has a question an agent parked, across all sessions.
     *
     * The live gate lives in the conversation of the session it belongs to; this is the cross-session
     * backlog — the durable questions waiting for a human, wherever they were raised. Read from the agent's
     * {@see \Milpa\Agent\SessionStore} (each session's `->question`), guarded so an app without the agent
     * package degrades to none rather than failing.
     *
     * Each row also names the SEQUENCE the session is parked on, when it is one (greenhouse decisions/0223):
     * a governed sequence pauses at the step that needs consent, and the card that answers it can then
     * resume the run — the same `sequence:run` a terminal would call again.
     *
     * @return list<array{session: string, goal: string, question: string, operation: string, reason: string, sequence: string}>
     *
     * @codeCoverageIgnore reads through to the agent SessionStore; exercised by integration on a booted app
     *                     (greenhouse evidence/0509), not by the standalone unit suite
     */
    public function pendingDecisions(): array
    {
        // The agent op builds its own store at `<root>/var/agent-sessions.jsonl` rather than registering one
        // ({@see \Milpa\AppRuntime\Operations\AgentOperations}); read the SAME file so the inbox sees the
        // questions the agent parked. Guarded so an app without the agent/event-store packages degrades to none.
        if (!class_exists(\Milpa\Agent\SessionStore::class) || !class_exists(\Milpa\EventStore\FileEventStore::class)) {
            return [];
        }
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return [];
        }
        $file = $kernel->root() . '/var/agent-sessions.jsonl';
        if (!is_file($file)) {
            return [];
        }
        $store = new \Milpa\Agent\SessionStore(new \Milpa\EventStore\FileEventStore($file));

        $out = [];
        foreach ($store->loadAll() as $session) {
            if ($session->question === null) {
                continue;
            }
            $q = $session->question;
            $operation = '';
            if (is_string($q->why) && $q->why !== '') {
                $decoded = json_decode($q->why, true);
                if (is_array($decoded) && is_string($decoded['operation'] ?? null)) {
                    $operation = $decoded['operation'];
                }
            }
            $out[] = [
                'session' => $session->id,
                'goal' => $session->goal,
                'question' => $q->question,
                'operation' => $operation,
                'reason' => is_string($q->reason) ? $q->reason : '',
                'sequence' => $session->pausedSequence !== null ? $session->pausedSequence->sequenceId : '',
            ];
        }

        return $out;
    }

    /**
     * The sequences this app declared in `config/sequences.php`, each with the pause it may be parked at.
     *
     * A deployment is a list (greenhouse decisions/0223), and the Desktop is where a human authorizes
     * everything else — so the list is offered here to RUN, and when a step needs consent the run pauses
     * and the card answers it in place. The session a sequence runs in is the one `sequence:run` derives
     * from its name, so the card can name it without the run having started.
     *
     * Guarded so an app without the runtime's sequences, or without the agent store, degrades to none.
     *
     * @return list<array{name: string, steps: list<string>, session: string, paused: bool, pending_operation: string}>
     *
     * @codeCoverageIgnore reads through to app-runtime's DeclaredSequences and the agent SessionStore;
     *                     exercised by integration on a booted app (greenhouse evidence/0561), not by the
     *                     standalone unit suite
     */
    public function declaredSequences(): array
    {
        if (!class_exists(\Milpa\AppRuntime\Sequence\DeclaredSequences::class)) {
            return [];
        }
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return [];
        }
        $declared = \Milpa\AppRuntime\Sequence\DeclaredSequences::underRoot($kernel->root());

        // Which sessions are parked on a sequence, and at which operation — read from the same ledger the
        // inbox reads. A session id here is the one `sequence:run` derives: `sequence:<name>`.
        $parked = [];
        $file = $kernel->root() . '/var/agent-sessions.jsonl';
        if (class_exists(\Milpa\Agent\SessionStore::class) && class_exists(\Milpa\EventStore\FileEventStore::class) && is_file($file)) {
            $store = new \Milpa\Agent\SessionStore(new \Milpa\EventStore\FileEventStore($file));
            foreach ($store->loadAll() as $session) {
                if ($session->pausedSequence === null) {
                    continue;
                }
                $operation = '';
                $why = $session->question?->why;
                if (is_string($why) && $why !== '') {
                    $decoded = json_decode($why, true);
                    if (is_array($decoded) && is_string($decoded['operation'] ?? null)) {
                        $operation = $decoded['operation'];
                    }
                }
                $parked[$session->id] = $operation;
            }
        }

        $rows = [];
        foreach ($declared->names() as $name) {
            $steps = [];
            foreach ($declared->stepsOf($name) ?? [] as $step) {
                $steps[] = $step->operation;
            }
            $sessionId = 'sequence:' . $name;
            $rows[] = [
                'name' => $name,
                'steps' => $steps,
                'session' => $sessionId,
                'paused' => \array_key_exists($sessionId, $parked),
                'pending_operation' => $parked[$sessionId] ?? '',
            ];
        }

        return $rows;
    }

    /**
     * The skills the agent carries — each one's name, description, and who may invoke it (greenhouse
     * decisions/0197). A skill guides judgment; it is not a tool that runs. Read from the same
     * {@see \Milpa\AppRuntime\Agent\Skill\SkillRegistry} (`<root>/skills/*​/SKILL.md`) the agent reads, so
     * the human sees exactly what the agent can reach for. Guarded so an app without the runtime degrades to none.
     *
     * @return list<array{name: string, description: string, model_invocable: bool, user_invocable: bool}>
     *
     * @codeCoverageIgnore reads through to the app-runtime SkillRegistry; exercised by integration on a booted
     *                     app (greenhouse evidence/0511), not by the standalone unit suite
     */
    public function skills(): array
    {
        if (!class_exists(\Milpa\AppRuntime\Agent\Skill\SkillRegistry::class)) {
            return [];
        }
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return [];
        }

        $out = [];
        foreach ((new \Milpa\AppRuntime\Agent\Skill\SkillRegistry($kernel->root()))->all() as $skill) {
            $out[] = [
                'name' => $skill->name,
                'description' => $skill->description,
                'model_invocable' => $skill->modelInvocable,
                'user_invocable' => $skill->userInvocable,
            ];
        }

        return $out;
    }

    /**
     * The composer's commands (greenhouse decisions/0202): the house's own — `/goal`, `/mode`, `/help` — plus
     * every user-invocable skill as `/<skill-name>`. Each command names a governed OPERATION of the house
     * (`agent:goal`, `agent:mode`, `skill:invoke`) reached over its http projection, and carries the http
     * METHOD that projection answers to — `POST` for a mutating op, `GET` for a read, none for `/help`, which
     * runs no operation. The Desktop invents no action. Read through the same {@see self::skills()} seam, so
     * an app without the runtime still offers the house commands.
     *
     * @return list<array{name: string, kind: string, description: string, usage: string, method: string}>
     */
    public function commands(): array
    {
        return self::commandsFor($this->skills());
    }

    /**
     * The house's own commands — what the composer offers even when no skill is user-invocable.
     *
     * `/goal` and `/mode` are mutating operations (`POST`); `/help` is the composer's own listing and names no
     * operation (an empty method).
     *
     * @return list<array{name: string, kind: string, description: string, usage: string, method: string}>
     */
    public static function houseCommands(): array
    {
        return [
            ['name' => 'goal', 'kind' => 'house', 'description' => "Set, show or clear the session's standing goal", 'usage' => '/goal <text> | /goal clear | /goal', 'method' => 'POST'],
            ['name' => 'mode', 'kind' => 'house', 'description' => 'Choose how much the agent asks', 'usage' => '/mode ask|acknowledge|auto', 'method' => 'POST'],
            ['name' => 'help', 'kind' => 'house', 'description' => 'List the commands this composer understands', 'usage' => '/help', 'method' => ''],
        ];
    }

    /**
     * The house commands followed by one `/<name>` per user-invocable skill (a model-only skill is not a
     * command: the human has no surface for it, greenhouse decisions/0202). A skill runs through
     * `skill:invoke`, a read projected as `GET`. Only a name the parser can address becomes a command
     * (`^[a-z0-9-]+$`), and never one that shadows a house command — `/goal` stays the house's whatever a
     * skill calls itself. Pure, so it is tested with fixtures.
     *
     * @param list<array{name: string, description: string, model_invocable: bool, user_invocable: bool}> $skills
     *
     * @return list<array{name: string, kind: string, description: string, usage: string, method: string}>
     */
    public static function commandsFor(array $skills): array
    {
        $out = self::houseCommands();
        $taken = array_column($out, 'name');
        foreach ($skills as $skill) {
            if (!$skill['user_invocable'] || preg_match('/^[a-z0-9-]+$/', $skill['name']) !== 1 || \in_array($skill['name'], $taken, true)) {
                continue;
            }
            $taken[] = $skill['name'];
            $out[] = [
                'name' => $skill['name'],
                'kind' => 'skill',
                'description' => $skill['description'],
                'usage' => '/' . $skill['name'] . ' [args]',
                'method' => 'GET',
            ];
        }

        return $out;
    }

    /**
     * The specialist agent roles this app declares (greenhouse decisions/0197): each a named authority with
     * the skills it preloads, the tools it is denied, and what it produces. A role names authority that already
     * governs; the skills only suggest. Read from the same {@see \Milpa\AppRuntime\Agent\Role\RoleRegistry}
     * (`<root>/.milpa/agents/*.md`) the `agent:role:list` operation reads. Guarded → none without the runtime.
     *
     * @return list<array{name: string, produces: string, deny: list<string>, skills: list<string>}>
     *
     * @codeCoverageIgnore reads through to the app-runtime RoleRegistry; exercised by integration on a booted
     *                     app (greenhouse evidence/0512), not by the standalone unit suite
     */
    public function roles(): array
    {
        if (!class_exists(\Milpa\AppRuntime\Agent\Role\RoleRegistry::class)) {
            return [];
        }
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return [];
        }

        $registry = new \Milpa\AppRuntime\Agent\Role\RoleRegistry();
        $registry->loadFrom($kernel->root() . '/.milpa/agents');

        $out = [];
        foreach ($registry->all() as $role) {
            $row = $role->toArray();
            $out[] = [
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'produces' => is_string($row['produces'] ?? null) ? $row['produces'] : '',
                'deny' => array_values(array_filter(is_array($row['deny'] ?? null) ? $row['deny'] : [], 'is_string')),
                'skills' => array_values(array_filter(is_array($row['skills'] ?? null) ? $row['skills'] : [], 'is_string')),
            ];
        }

        return $out;
    }

    /**
     * The live screens the agent declared (greenhouse decisions/0197): each served by the live wire at its own
     * path, previewable with no code deploy. Read from the same {@see \Milpa\AppRuntime\Web\ScreenStore} the
     * `screen:declare` operation writes. Guarded → none without the live wire.
     *
     * @return list<array{name: string, type: string, served_at: string}>
     *
     * @codeCoverageIgnore reads through to the app-runtime ScreenStore; exercised by integration on a booted
     *                     app (greenhouse evidence/0512), not by the standalone unit suite
     */
    public function declaredScreens(): array
    {
        if (!class_exists(\Milpa\AppRuntime\Web\ScreenStore::class)) {
            return [];
        }
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return [];
        }
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $live = $config instanceof Config && is_array($config->get('live')) ? $config->get('live') : [];

        $out = [];
        foreach (\Milpa\AppRuntime\Web\ScreenStore::fromConfig($live, $kernel->root())->catalogue() as $row) {
            $out[] = [
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'type' => is_string($row['type'] ?? null) ? $row['type'] : '',
                'served_at' => is_string($row['servedAt'] ?? null) ? $row['servedAt'] : '',
            ];
        }

        return $out;
    }

    /** The route the live wire is mounted on (config `live.route`, default `/live`) — the Preview iframe's base. */
    public function liveRoute(): string
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $live = $config instanceof Config ? $config->get('live') : null;
        $route = is_array($live) && is_string($live['route'] ?? null) && $live['route'] !== '' ? $live['route'] : '/live';

        return $route;
    }

    /**
     * The configured model provider and endpoint (real config, with env fallbacks).
     *
     * @return array{model: string, endpoint: string}
     */
    public function model(): array
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $model = $config instanceof Config ? $config->get('agent.model') : null;
        $endpoint = $config instanceof Config ? $config->get('agent.base_url') : null;

        return [
            'model' => is_string($model) && $model !== '' ? $model : (getenv('MILPA_AGENT_MODEL') ?: 'qwen3.8-27b'),
            'endpoint' => is_string($endpoint) && $endpoint !== '' ? $endpoint : (getenv('MILPA_AGENT_BASE_URL') ?: 'http://llama.local:11438'),
        ];
    }

    /**
     * The app's sessions, read from the on-disk session store (each `*.json` file is one session).
     *
     * @return list<array{id: string, goal: string, state: string}>
     */
    public function sessions(): array
    {
        // THE LEDGER FIRST (greenhouse evidence/0561): every session the agent ran is listed, whether or not the
        // Desktop ever wrote a record of its own for it — then the store's records the ledger does not know.
        $out = [];
        foreach ($this->ledgerSessions() as $id => $record) {
            $out[$id] = ['id' => (string) $id, 'goal' => (string) ($record['goal'] ?: '(no goal recorded)'), 'state' => (string) $record['state']];
        }
        foreach ($this->sessionFiles() as $file) {
            $s = $this->readJson($file);
            $id = $this->str($s['id'] ?? null) ?: basename($file, '.json');
            if (isset($out[$id])) {
                continue;
            }
            $out[$id] = [
                'id' => $id,
                'goal' => $this->str($s['goal'] ?? $s['objective'] ?? $s['title'] ?? null) ?: '(no goal recorded)',
                'state' => $this->str($s['state'] ?? $s['status'] ?? null) ?: 'idle',
            ];
        }

        return array_values($out);
    }

    /** The session the UI selected (a sidebar click posts `?session=<id>`), when it names a real one. */
    private ?string $selectedId = null;

    /**
     * Select the active session by id. A well-formed id is selected whether the store, the ledger, or nobody
     * knows it yet: the page binds to the session it was asked for (greenhouse evidence/0561), and what that
     * session holds is answered by {@see hasSession()} and the readers — never by showing another one's record.
     */
    public function select(string $id): void
    {
        if (preg_match('/^[0-9A-Za-z][0-9A-Za-z_:.-]{0,63}$/', $id) !== 1) {
            return;
        }
        $this->selectedId = $id;
    }

    /**
     * The current session's addressable id — the selected one when a sidebar click chose it, else the first
     * in the store, or '' when there is none. The file name is what the write side addresses
     * ({@see DesktopStore::updateWorkStatus()}), so it is the id the UI posts back, not the display id.
     */
    public function currentSessionId(): string
    {
        if ($this->selectedId !== null) {
            return $this->selectedId;
        }
        $files = $this->sessionFiles();

        return $files === [] ? '' : basename($files[0], '.json');
    }

    /**
     * The current session's counters (turns, steps, tokens, tool calls) — from the session, else derived.
     *
     * @return array{turns: int, steps: int, tokens: int, tool_calls: int, state: string}
     */
    public function counters(): array
    {
        $record = $this->session($this->currentSessionId());
        if ($record !== null) {
            return [
                'turns' => (int) $record['turns'],
                'steps' => (int) $record['steps'],
                'tokens' => (int) $record['tokens'],
                'tool_calls' => (int) $record['tool_calls'],
                'state' => (string) $record['state'],
            ];
        }
        $s = $this->currentSession();

        return [
            'turns' => $this->int($s['turns'] ?? null),
            'steps' => $this->int($s['steps'] ?? null) ?: \count($this->audit()),
            'tokens' => $this->int($s['tokens'] ?? null),
            'tool_calls' => $this->int($s['tool_calls'] ?? $s['toolCalls'] ?? null),
            'state' => $this->str($s['state'] ?? $s['status'] ?? null) ?: 'idle',
        ];
    }

    /**
     * The context window usage (wireframe 3a): tokens used, the window size, and derived percent/free.
     *
     * @return array{tokens: int, window: int, used_pct: int, free: int}
     */
    public function context(): array
    {
        // What the context holds is the LAST call's prompt — the ledger's `prompt_tokens` — not the session's
        // total spend; the store's file never knew the difference.
        $record = $this->session($this->currentSessionId());
        $tokens = $record !== null ? (int) $record['context_tokens'] : $this->counters()['tokens'];
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $configured = $config instanceof Config ? $config->get('agent.context_window') : null;
        $window = is_int($configured) && $configured > 0 ? $configured : 32768;

        return [
            'tokens' => $tokens,
            'window' => $window,
            'used_pct' => min(100, (int) round($tokens / $window * 100)),
            'free' => max(0, $window - $tokens),
        ];
    }

    /**
     * The current session's work board items.
     *
     * @return list<array{title: string, status: string, origin: string, draggable: bool}>
     */
    public function work(): array
    {
        // The agent's todos ARE the work board of its session; their status is the agent's fact, so a card
        // from the ledger is not dragged — the store's own cards still are.
        $record = $this->session($this->currentSessionId());
        if ($record !== null) {
            return array_map(static fn (array $item): array => $item + ['draggable' => false], $record['work']);
        }
        $items = $this->currentSession()['work'] ?? $this->currentSession()['todo'] ?? null;
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $out[] = [
                'title' => $this->str($item['title'] ?? $item['text'] ?? null) ?: '(untitled)',
                'status' => $this->str($item['status'] ?? null) ?: 'pending',
                'origin' => $this->str($item['origin'] ?? null) ?: 'planned',
                'draggable' => true,
            ];
        }

        return $out;
    }

    /**
     * The audit facts — the shared event log's events, in order, each with its seq.
     *
     * @return list<array{seq: int, type: string, data: string}>
     */
    public function audit(): array
    {
        // The named session's own facts, when the ledger holds it; the shell's log otherwise.
        $record = $this->session($this->currentSessionId());
        if ($record !== null) {
            return $record['activity'];
        }
        if ($this->log === null) {
            return [];
        }

        $out = [];
        foreach ($this->log->since(0) as $entry) {
            $out[] = ['seq' => $entry['id'], 'type' => $entry['event']->type, 'data' => $entry['event']->toJson()];
        }

        return $out;
    }

    /**
     * A downloadable dump of the CURRENT session — the material for a video or an autopsy of what the
     * agent did: the raw session record, its counters and context, the work board and the activity/audit.
     *
     * @return array{exported_at: string, id: string, model: array{model: string, endpoint: string}, session: array<string, mixed>, counters: array{turns: int, steps: int, tokens: int, tool_calls: int, state: string}, context: array{tokens: int, window: int, used_pct: int, free: int}, budget: list<array{class: string, messages: int, est_tokens: int}>, work: list<array{title: string, status: string, origin: string}>, audit: list<array{seq: int, type: string, data: string}>}
     */
    public function export(string $agentSessionId = ''): array
    {
        return [
            'exported_at' => date('c'),
            'id' => $this->currentSessionId(),
            'model' => $this->model(),
            'session' => $this->currentSession(),
            'counters' => $this->counters(),
            'context' => $this->context(),
            'budget' => $this->tokenBudget($agentSessionId),
            'work' => $this->work(),
            'audit' => $this->audit(),
        ];
    }

    /**
     * Where the token budget goes, by category — so a human can SEE and debug it (greenhouse decisions/0196).
     *
     * The provider reports a real TOTAL (decisions/0192), but not how it splits; this estimates the weight of
     * each part of the composed window (summary / current state / turns …) from its content length, the same
     * "≈ length/4" the TUI uses. The agent runs under a MINTED id (`run-…`), separate from the DesktopStore
     * session id, so the caller passes the agent session the browser drove (the `milpa_agent_sid` cookie).
     * Read from the agent's own store, guarded so an app without it degrades to none.
     *
     * @return list<array{class: string, messages: int, est_tokens: int}>
     *
     * @codeCoverageIgnore reads through to the agent SessionStore; exercised by integration on a booted app
     *                     (greenhouse evidence/0510), not by the standalone unit suite
     */
    public function tokenBudget(string $agentSessionId = ''): array
    {
        if ($agentSessionId === '' || !class_exists(\Milpa\Agent\SessionStore::class) || !class_exists(\Milpa\EventStore\FileEventStore::class)) {
            return [];
        }
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return [];
        }
        $file = $kernel->root() . '/var/agent-sessions.jsonl';
        $id = $agentSessionId;
        if (!is_file($file)) {
            return [];
        }
        $session = (new \Milpa\Agent\SessionStore(new \Milpa\EventStore\FileEventStore($file)))->load($id);
        if ($session === null) {
            return [];
        }

        $byClass = [];
        foreach ($session->classifiedWindow() as $message) {
            $class = is_string($message['class'] ?? null) ? $message['class'] : 'other';
            $content = is_string($message['content'] ?? null) ? $message['content'] : '';
            if (!isset($byClass[$class])) {
                $byClass[$class] = ['class' => $class, 'messages' => 0, 'est_tokens' => 0];
            }
            ++$byClass[$class]['messages'];
            $byClass[$class]['est_tokens'] += (int) ceil(mb_strlen($content) / 4);
        }

        return array_values($byClass);
    }

    /**
     * The whole snapshot the Desktop reads.
     *
     * @return array{
     *     capabilities: list<array{name: string, version: string, type: string, author: string}>,
     *     model: array{model: string, endpoint: string},
     *     settings: array<string, mixed>,
     *     sessions: list<array{id: string, goal: string, state: string}>,
     *     counters: array{turns: int, steps: int, tokens: int, tool_calls: int, state: string},
     *     context: array{tokens: int, window: int, used_pct: int, free: int},
     *     work: list<array{title: string, status: string, origin: string}>,
     *     audit: list<array{seq: int, type: string, data: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'capabilities' => $this->capabilities(),
            'model' => $this->model(),
            'settings' => $this->settings(),
            'sessions' => $this->sessions(),
            'counters' => $this->counters(),
            'context' => $this->context(),
            'work' => $this->work(),
            'audit' => $this->audit(),
        ];
    }

    /** @return list<string> */
    private function sessionFiles(): array
    {
        if ($this->sessionsPath === '' || !is_dir($this->sessionsPath)) {
            return [];
        }
        $files = glob(rtrim($this->sessionsPath, '/') . '/*.json') ?: [];
        sort($files);

        return $files;
    }

    /** @return array<string, mixed> */
    private function currentSession(): array
    {
        $id = $this->currentSessionId();
        if ($id === '') {
            return [];
        }

        return $this->session($id) ?? $this->storeRecord($id) ?? [];
    }

    /**
     * The store's own record for an id, or `null` when it wrote none.
     *
     * @return array<string, mixed>|null
     */
    private function storeRecord(string $id): ?array
    {
        foreach ($this->sessionFiles() as $file) {
            if (basename($file, '.json') === $id) {
                return $this->readJson($file);
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function readJson(string $file): array
    {
        $raw = is_file($file) ? (string) file_get_contents($file) : '';
        $decoded = $raw === '' ? null : json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    private function int(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }
}
