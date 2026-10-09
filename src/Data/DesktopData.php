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

namespace Milpa\AgentWorkspace\Data;

use Milpa\AgentWorkspace\Controllers\PanelDoorController;
use Milpa\Attributes\PluginMetadata;
use Milpa\AgentWorkspace\Live\ShellEventLog;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Runtime\Config;
use Milpa\AppRuntime\Config\AgentEndpoint;
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
    /** Named as a string: milpa/app-runtime owns the run's lease, and this package does not require it. */
    public const string RUN_LEASE = 'Milpa\\AppRuntime\\Agent\\RunLease';

    /** Who answers {@see running()} — the runtime's lease, or a test's stand-in with the same static `held()`. */
    private static string $runLease = self::RUN_LEASE;

    /** Named as a string: milpa/app-runtime owns the frontier, and this package does not require it. */
    public const string FRONTIER = 'Milpa\\AppRuntime\\Agent\\SeatFrontier';

    /**
     * The class asked what a person's session was refused. Held as a string that is not a literal, so that whether the
     * runtime installed HERE reads those refusals yet is asked of the class at run time, never decided when this
     * package is analysed against whichever runtime it was installed with.
     */
    private static string $frontier = self::FRONTIER;

    /**
     * Who answers {@see refusedToAPerson()} — null, the house's own frontier; or a test's stand-in, handed the session.
     *
     * @var (\Closure(string): mixed)|null
     */
    private static ?\Closure $personFrontier = null;

    /** The permission modes a turn carries (greenhouse decisions/0202). */
    private const array MODES = ['ask', 'acknowledge', 'auto'];

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
     * the turns, the tool calls, the questions parked and answered, a sequence pausing and resuming, and the
     * point where the window compacted. The
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
        // WHAT A PERSON'S OWN SESSION WAS REFUSED AND NOBODY CAN GRANT (greenhouse decisions/0609, path 1, I2), by the
        // seq of the refused call — the house's judgement, read; never this package's reading of a sentence.
        $unseated = $this->refusedToAPerson($agentSid);

        $out = [];
        foreach ($rows as $event) {
            $p = \is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $row = match ($event['type'] ?? '') {
                // THE HOUSE'S OWN TURN IS A NOTICE (greenhouse decisions/0495, 0563): it is recorded as a turn
                // because the model must read it, and nobody typed it — painted in the reader's own voice it
                // read as something they had said.
                'session.turn' => match (true) {
                    ($p['role'] ?? '') === 'assistant' => ['kind' => 'agent', 'text' => $this->str($p['content'] ?? null)],
                    str_starts_with($this->str($p['content'] ?? null), LedgerSession::HOUSE_VOICE) => ['kind' => 'notice', 'text' => $this->str($p['content'] ?? null)],
                    default => ['kind' => 'user', 'text' => $this->str($p['content'] ?? null)],
                },
                // A CALL CARRIES ITS OWN POSITION IN THE LEDGER (greenhouse decisions/0609, I4): the page that ran a
                // turn re-reads this transcript when the turn comes back, and paints the calls it does not show.
                // Without a name of its own a call could only be painted twice, or not at all.
                'session.tool_called' => ['kind' => 'tool', 'name' => $this->str($p['tool'] ?? null) ?: 'tool', 'result' => $this->str($p['result'] ?? null), 'seq' => (int) ($event['seq'] ?? 0)],
                // The parked question carries its OPTIONS and its why (greenhouse decisions/0254): a
                // reloaded thread renders the request with the same buttons a live one has, so a question
                // raised before the reload is still answerable from where it was asked.
                'session.question_asked' => [
                    'kind' => 'question',
                    'text' => $this->str($p['question'] ?? null),
                    'id' => $this->str($p['id'] ?? null),
                    'reason' => $this->str($p['reason'] ?? null),
                    'why' => $this->str($p['why'] ?? null),
                    'options' => array_values(array_filter(
                        \is_array($p['options'] ?? null) ? $p['options'] : [],
                        static fn (mixed $option): bool => \is_string($option) && $option !== '',
                    )),
                ],
                // The window compacted here. Declared in milpa/agent since it existed and, until this
                // slice, read by nobody: a thread would lose its earlier turns to a summary and read as
                // though they had never happened.
                'session.compacted' => ['kind' => 'compacted', 'through' => (int) ($p['through'] ?? 0), 'summary' => $this->str($p['summary'] ?? null)],
                // WHICH question was answered travels too (greenhouse decisions/0258): a surface arriving
                // later has to be able to close the request it is replaying, or it paints live buttons for
                // something decided days ago.
                'session.question_answered' => ['kind' => 'answered', 'id' => $this->str($p['id'] ?? null), 'answer' => $this->str($p['answer'] ?? null), 'by' => $this->str(\is_array($p['by'] ?? null) ? ($p['by']['id'] ?? null) : null)],
                // A RUN THAT FAILED is a row, with its endpoint's cause when the ledger has one (greenhouse
                // decisions/0536). Measured (evidence/1069 §C1): the leg died on a 404 from `/v1/v1/chat/completions`
                // and the thread showed nothing — the error lived only in the terminal that ran it.
                'session.run_terminated' => ($p['reason'] ?? null) === 'failed' ? self::runFailed($p['cause'] ?? null) : null,
                'session.sequence_paused' => ['kind' => 'sequence_paused', 'sequence' => $this->str($p['sequenceId'] ?? null)],
                'session.sequence_resumed' => ['kind' => 'sequence_resumed', 'sequence' => $this->str($p['sequenceId'] ?? null)],
                // THE HOUSE'S VERDICT travels with the thread (greenhouse decisions/0509 §7): a reloaded thread
                // stamps it on the answer it judged, exactly as a live turn does. Measured (evidence/1036): the
                // final answer said «the blog is built, tested, and live» and the house's `verified: false` was
                // on no screen at all.
                'session.closure_derived' => [
                    'kind' => 'closure',
                    'verified' => ($p['verified'] ?? null) === true,
                    'reasons' => array_values(array_filter(
                        \is_array($p['reasons'] ?? null) ? $p['reasons'] : [],
                        static fn (mixed $reason): bool => \is_string($reason) && $reason !== '',
                    )),
                    'scope' => $this->str($p['scope'] ?? null),
                ],
                default => null,
            };
            if ($row !== null) {
                $out[] = $row;
            }
            // Right under the call it is about: the grant her session would have needed, as the option she cannot
            // take, and the act that works today.
            $refused = $unseated[(int) ($event['seq'] ?? 0)] ?? null;
            if ($refused !== null && ($event['type'] ?? '') === 'session.tool_called') {
                $out[] = ['kind' => 'no_frontier'] + $refused;
            }
        }

        return $out;
    }

    /**
     * What a session a PERSON opened was refused and nobody can grant, keyed by the seq of the refused call (greenhouse
     * decisions/0609, path 1, I2).
     *
     * A person's session runs with her passkey: it is nobody's seat, the frontier only knows seats, and so nothing
     * of it ever reached Decisions (`seatFrontier()` is empty for it, as decided — decisions/0493). The house now
     * reads those refusals apart, as rows shaped like a seat's refusal without a seat; this hands them to the thread
     * so it can tell her, where she is, what her session cannot do. The judgement is app-runtime's
     * ({@see \Milpa\AppRuntime\Agent\SeatFrontier}), re-made from the recorded call: nothing here parses a refusal's
     * sentence, and nothing here grants.
     *
     * Guarded so an app without the runtime, or with one that does not read them yet, shows none — and a house that
     * cannot be asked, or answers something that is not such a row, shows none either: the thread is then what it was.
     *
     * @return array<int, array{seq: int, tool: string, plugin: ?string, permission: string}>
     */
    private function refusedToAPerson(string $agentSid): array
    {
        try {
            $rows = self::$personFrontier !== null ? (self::$personFrontier)($agentSid) : $this->askTheHouseWhatAPersonWasRefused($agentSid);
        } catch (\Throwable) {
            return [];
        }
        $bySeq = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (\is_array($row) && \is_int($row['seq'] ?? null) && \is_string($row['tool'] ?? null) && $row['tool'] !== ''
                && \is_string($row['permission'] ?? null) && $row['permission'] !== '') {
                $bySeq[$row['seq']] = ['seq' => $row['seq'], 'tool' => $row['tool'], 'plugin' => \is_string($row['plugin'] ?? null) ? $row['plugin'] : null, 'permission' => $row['permission']];
            }
        }

        return $bySeq;
    }

    /**
     * The house's own answer: app-runtime's frontier, over this app's ledger.
     *
     * @return mixed what the runtime returns — rows, when it reads them
     *
     * @codeCoverageIgnore reads through to app-runtime's frontier; exercised on a booted app, not by the standalone suite
     */
    private function askTheHouseWhatAPersonWasRefused(string $agentSid): mixed
    {
        // NAMED AS A STRING, as the seats' frontier is: this package does not require the runtime. A runtime that does
        // not read these refusals yet has no such method, and then there is nothing to show.
        $class = self::$frontier;
        if (!class_exists($class) || !method_exists($class, 'refusedToAPerson')
            || !class_exists(\Milpa\Agent\SessionStore::class) || !class_exists(\Milpa\EventStore\FileEventStore::class)) {
            return [];
        }
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        $file = $this->ledgerFile();
        if (!$kernel instanceof Kernel || $file === null || !is_file($file)) {
            return [];
        }

        return $class::forRoot($kernel->root(), new \Milpa\Agent\SessionStore(new \Milpa\EventStore\FileEventStore($file)), self::built($kernel))->refusedToAPerson($agentSid);
    }

    /**
     * Name who answers what a person's own session was refused — null restores the house's frontier.
     *
     * @param (\Closure(string): mixed)|null $reader handed the session, answers the runtime's rows
     *
     * @internal a seam for tests: the judgement is the runtime's, and a suite must be able to stand in for it
     */
    public static function usePersonFrontier(?\Closure $reader): void
    {
        self::$personFrontier = $reader;
    }

    /**
     * A failed run's row: the endpoint's cause as milpa/ai-gateway records it (`provider_refused` with its status,
     * `provider_unreachable`), or an empty cause — a failure the thread still shows, without inventing why.
     *
     * @return array{kind: 'run_failed', cause: string, status: int|null, endpoint: string}
     */
    private static function runFailed(mixed $cause): array
    {
        $kind = \is_array($cause) ? ($cause['kind'] ?? null) : null;
        if (!\is_array($cause) || !\in_array($kind, ['provider_refused', 'provider_unreachable'], true)) {
            return ['kind' => 'run_failed', 'cause' => '', 'status' => null, 'endpoint' => ''];
        }

        return [
            'kind' => 'run_failed',
            'cause' => $kind,
            'status' => $kind === 'provider_refused' && \is_int($cause['status'] ?? null) ? $cause['status'] : null,
            'endpoint' => \is_string($cause['endpoint'] ?? null) ? $cause['endpoint'] : '',
        ];
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

    /*
     * NO HAY `capabilityCatalogue()`. Su ÚNICO llamador era la pantalla de capacidades de este paquete,
     * que se retiró por duplicada: la sección Plugins del panel es nativa de `milpa/admin`, pinta el
     * mismo catálogo y corre el mismo `capabilities:enable` — y lee el catálogo por su cuenta, desde
     * `Milpa\AppRuntime\Support\Capabilities::answer()`, que es la autoridad que este método envolvía
     * (greenhouse decisions/0290).
     */

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
     * ── FOR WHOEVER IS READING (greenhouse decisions/0584) ──────────────────────────────────────────────────────────
     *
     * Given the reader, each decision also says which options THAT person may take. This judges nothing: the engine
     * does, with the rule it refuses an answer by (`choices`, `viewer` — `GraphRuns::pending($viewer)`), and what the
     * door asks of whoever presses is the door's own operation's (`door`). The reader is handed to the engine the way
     * the door would hand them when they press — who the panel authenticated, with what the house's ledger says they
     * hold NOW — so what the card says and what the door does are read from the same place.
     *
     * With no reader, a reader the ledger does not know, or an orchestrator that does not say it yet, the rows carry
     * no judgement and the card paints every option as it always did.
     *
     * @return list<array<string, mixed>> each `{graph, instance, question, options, requester}`, and when judged
     *                                    `choices`, `viewer` and `door`
     *
     * @codeCoverageIgnore reads through to GraphRuns; exercised on a booted app, not by the standalone suite
     */
    public function pendingGraphDecisions(string $principal = ''): array
    {
        if (!class_exists(\Milpa\Orchestrator\Declaration\GraphRuns::class)
            || !$this->container->getContainer()->has(\Milpa\Orchestrator\Declaration\GraphRuns::class)) {
            return [];
        }

        $runs = $this->container->get(\Milpa\Orchestrator\Declaration\GraphRuns::class);

        if (!$runs instanceof \Milpa\Orchestrator\Declaration\GraphRuns) {
            return [];
        }

        $holds = $this->scopesOf($principal);
        $viewer = $holds === null ? null : $this->viewer($principal, $holds);
        $door = $viewer === null ? null : $this->doorOf(PanelDoorController::DECIDE, $principal, $holds);
        $rows = [];

        // An engine that takes no viewer ignores the argument: its rows carry no `choices`, and nothing is judged.
        foreach ($runs->pending($viewer) as $row) {
            /** @var list<string> $options */
            $options = \is_array($row['options'] ?? null) ? array_values(array_filter($row['options'], 'is_string')) : [];
            $judged = \is_array($row['viewer'] ?? null) && \is_array($row['choices'] ?? null);

            $rows[] = [
                'graph' => (string) ($row['graph'] ?? ''),
                'instance' => (string) ($row['instance_id'] ?? ''),
                'question' => (string) ($row['gate_id'] ?? ''),
                'options' => $options,
                'requester' => (string) ($row['requester'] ?? ''),
            ] + ($judged ? ['choices' => $row['choices'], 'viewer' => $row['viewer'], 'door' => $door] : []);
        }

        return $rows;
    }

    /**
     * What this principal holds NOW, as the house's ledger says it — or null when the house cannot say.
     *
     * The same reading the passkey door makes on every request (app-runtime's `PasskeySessionResolver`): the session
     * proves who is there, the ledger supplies the scopes they hold at this moment.
     *
     * @return list<string>|null
     *
     * @codeCoverageIgnore reads through to app-runtime's enrollment ledger; exercised on a booted app
     */
    private function scopesOf(string $principal): ?array
    {
        // Named as strings: milpa/app-runtime is where the ledger lives and this package does not require it.
        $ledger = 'Milpa\\AppRuntime\\Identity\\FileEnrollmentStore';
        $line = 'Milpa\\AppRuntime\\Identity\\EnrollmentLine';
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if ($principal === '' || !$kernel instanceof Kernel || !class_exists($ledger) || !class_exists($line)) {
            return null;
        }
        $key = $line::keyOf($principal);

        return $key === null ? null : (new $ledger(rtrim($kernel->root(), '/') . '/storage/identity/enrollments.json'))->scopesFor($key);
    }

    /**
     * The reader as the engine judges a caller: who the panel authenticated, and what they hold.
     *
     * @param list<string> $holds
     *
     * @codeCoverageIgnore builds milpa/orchestrator's Caller; exercised on a booted app
     */
    private function viewer(string $principal, array $holds): ?object
    {
        $caller = 'Milpa\\Orchestrator\\Declaration\\Caller';
        if (!class_exists($caller) || !class_exists(\Milpa\Command\InvocationContext::class) || !class_exists(\Milpa\ToolRuntime\Contracts\ToolContext::class)) {
            return null;
        }

        // As the HTTP door names whoever presses: the actor it attributes the call to, and the principal it judges.
        return new $caller(
            new \Milpa\Command\InvocationContext(actor: 'actor:' . $principal, verified: true, channel: 'web'),
            \Milpa\ToolRuntime\Contracts\ToolContext::web($principal, $holds),
        );
    }

    /**
     * What a panel door will ask of this reader: the scopes its operation declares, and whether they hold one.
     *
     * Null when the app does not offer the operation — the door says that itself when pressed.
     *
     * @param list<string> $holds
     *
     * @return array{may: bool, needs: list<string>}|null
     *
     * @codeCoverageIgnore reads the app's own catalogue; exercised on a booted app
     */
    private function doorOf(string $operation, string $principal, array $holds): ?array
    {
        $offered = PanelDoorController::offered($this->container, $operation);
        if ($offered === null) {
            return null;
        }

        return [
            'may' => $offered->scopes === [] || \Milpa\ToolRuntime\Contracts\ToolContext::web($principal, $holds)->hasAnyScope($offered->scopes),
            'needs' => $offered->scopes,
        ];
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
     * The sessions of the seats this principal answers for, each with its open refusals (greenhouse decisions/0493).
     *
     * A seat's missing scope stays a refusal (decisions/0317); what reaches the panel is the human who enrolled
     * the seat seeing it, so the decision the resident needs is in front of the one who can make it. The
     * relation and the judgement are app-runtime's ({@see \Milpa\AppRuntime\Agent\SeatFrontier}): this reads
     * them, it does not decide them. Guarded so an app without the runtime or the agent store degrades to none.
     *
     * @return list<array{session: string, goal: string, seat: string, refusals: list<array{seq: int, tool: string, plugin: ?string, permission: string}>}>
     */
    public function seatFrontier(string $principal): array
    {
        // NAMED AS A STRING, like the admin's installer: milpa/app-runtime is where the relation lives and this
        // package does not require it, so a class reference would be a type this package cannot promise.
        $class = 'Milpa\\AppRuntime\\Agent\\SeatFrontier';
        if ($principal === '' || !class_exists($class) || !class_exists(\Milpa\Agent\SessionStore::class) || !class_exists(\Milpa\EventStore\FileEventStore::class)) {
            return [];
        }
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        $file = $this->ledgerFile();
        if (!$kernel instanceof Kernel || $file === null || !is_file($file)) {
            return [];
        }
        // WHAT THE HOUSE BUILT is handed to the frontier (greenhouse decisions/0590): with it, a seat's call to a verb
        // of a capability built here that no person admitted is listed apart, under `admissions`, with the contract
        // to admit. A runtime that does not know built capabilities takes no third argument and lists none.
        return $class::forRoot($kernel->root(), new \Milpa\Agent\SessionStore(new \Milpa\EventStore\FileEventStore($file)), self::built($kernel))->sessionsFor($principal);
    }

    /**
     * The capabilities built in this house, as app-runtime reads them — or null when the runtime does not know them.
     * Named as a string for the same reason the frontier is: this package does not require the runtime.
     */
    private static function built(Kernel $kernel): ?object
    {
        $class = 'Milpa\\AppRuntime\\Agent\\BuiltCapabilities';

        return class_exists($class) ? $class::of($kernel) : null;
    }

    /**
     * The seats this principal answers for — their key, name, scopes and who enrolled them (greenhouse
     * decisions/0499) — so the person who gave a seat can compare its key with the resident's own.
     *
     * The relation is app-runtime's (`ResidentSeat::seatsFor`, the line of decisions/0493); an app without the
     * runtime shows none rather than guessing.
     *
     * @return list<array<string, mixed>> each `fingerprint`, `label`, `scopes`, `authorized_by` — and, from a runtime
     *                                    that knows built capabilities, `admitted` and `unadmitted`
     */
    public function seats(string $principal): array
    {
        // Named as a string: milpa/app-runtime is where the relation lives and this package does not require it.
        $class = 'Milpa\\AppRuntime\\Identity\\ResidentSeat';
        if ($principal === '' || !class_exists($class)) {
            return [];
        }
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        if (!$kernel instanceof Kernel) {
            return [];
        }
        // WITH WHAT EACH HOLDS, when the runtime can say it (greenhouse decisions/0590, 0597): what persons admitted
        // to the seat of the capabilities built here, and each scope no admission covers, with its contract.
        $built = self::built($kernel);
        // The runtime this package is analysed against may be older — or newer — than the one a house runs, so the
        // method is asked for at run time, in a way the analyser does not decide from the version it happens to
        // have installed: an ignore written for one of them broke the gate the day the other was published.
        if ($built !== null && (new \ReflectionClass($class))->hasMethod('holdings')) {
            return $class::holdings($kernel->root(), $principal, $built);
        }

        return $class::seatsFor($kernel->root(), $principal);
    }

    /**
     * The ids of the sessions whose seat this principal answers for — the tasks a panel may open for it.
     *
     * @return list<string>
     */
    public function seatSessionIds(string $principal): array
    {
        return array_column($this->seatFrontier($principal), 'session');
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
            // Same as above: `ScreenStore::catalogue()` declares these three as strings, so casting
            // them «just in case» was distrust of a contract this file consumes on purpose.
            $out[] = ['name' => $row['name'], 'type' => $row['type'], 'served_at' => $row['servedAt']];
        }

        return $out;
    }

    /** A booted draft service owns review; older hosts keep their active-screen preview. */
    public function screenReviewRoute(): ?string
    {
        return $this->container->has('Milpa\\AppRuntime\\Web\\ScreenDrafts') ? $this->liveRoute() . '/review' : null;
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
     * WHICH MODEL THIS APP TALKS TO — asked of the authority, and asked of the PROVIDER.
     *
     * ── THIS METHOD USED TO RESOLVE, AND IT RESOLVED WRONG ───────────────────────────────────────
     *
     * It read `agent.base_url` — a key `AgentKeys` does not declare; the authority reads
     * `agent.baseUrl` — so it never once saw the value the TURN uses. It fell through to the
     * environment, and from there to `'http://llama.local:11438'`, a host that stopped resolving
     * when that machine moved to Tailscale. And it hardcoded `'qwen3.8-27b'` as the model, so every
     * surface reading it printed a model name whether or not anything was listening.
     *
     * `AgentEndpoint` exists for exactly this defect and its docblock had already ruled on it:
     * a precedence written twice disagreed, a human who configured through the governed path was
     * told they had configured nothing, and the remedy was that **the surface loses the right to
     * resolve** — «because patching it would have left THREE copies of the precedence instead of
     * two» (greenhouse evidence/0165). This package arrived after that rule and made seven
     * (decisions/0266).
     *
     * ── SO IT ASKS, AND IT REPORTS WHAT IT CANNOT SAY ────────────────────────────────────────────
     *
     * `model` and `endpoint` are `null` when nobody declared one. NOT a default: a surface that
     * names a host the reader never chose sends them to fix a machine that was never theirs, which
     * is how `llama.local` survived in this file long after it stopped existing.
     *
     * IT TOUCHES NOTHING ON THE WIRE. Whether the provider answers is the `agent:model` operation's
     * question, not this method's — asking costs a round trip that a paint must not pay, measured at
     * five seconds against a provider that was down, and the operation is what declares that egress.
     *
     * WITHOUT THE AUTHORITY INSTALLED it says everything is undeclared rather than inventing. A
     * Desktop shipped without `milpa/app-runtime` has no governed configuration to read, and «I do
     * not know» is the only true answer it can give.
     *
     * @return array{model: null|string, endpoint: null|string, model_from: string, endpoint_from: string}
     */
    public function model(): array
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $config = $config instanceof Config ? $config : null;

        if (!class_exists(AgentEndpoint::class)) {
            return ['model' => null, 'endpoint' => null, 'model_from' => 'none', 'endpoint_from' => 'none'];
        }

        return [
            'model' => AgentEndpoint::model($config),
            'endpoint' => AgentEndpoint::baseUrl($config),
            'model_from' => AgentEndpoint::modelSource($config),
            'endpoint_from' => AgentEndpoint::baseUrlSource($config),
        ];
    }

    /*
     * NO HAY `modelReach()` AQUÍ, Y ES UNA DECISIÓN.
     *
     * Existió unas horas: separaba «lo declarado» de «lo que contesta», que era correcto y sigue
     * siéndolo. Lo que no era correcto es que viviera aquí. `agent:model` —la operación gobernada—
     * le pregunta directo a `AgentEndpoint::providerReach()`, así que este método quedó siendo una
     * SEGUNDA forma de hacer una pregunta, que es exactamente lo que esta rebanada retiró de las
     * cinco superficies de este paquete (greenhouse decisions/0266).
     *
     * Quien quiera saber si el proveedor contesta —el wizard que abre cuando no hay modelo, el chip
     * del composer— pide la OPERACIÓN. Que además es lo que debe: pasa por la compuerta, declara su
     * `externality: third_party`, y deja rastro. Un lector propio en este paquete se saltaría las
     * tres cosas.
     *
     * Lo cachó el censo de piezas sin cablear, no yo (decisions/0213).
     */


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

        return array_values($this->panelSessionIds === null ? $out : array_intersect_key($out, array_flip($this->panelSessionIds)));
    }

    /** The session the UI selected (a sidebar click posts `?session=<id>`), when it names a real one. */
    private ?string $selectedId = null;

    /** @var list<string>|null A panel render offers only the authority's recognized tasks. */
    private ?array $panelSessionIds = null;

    /** Select from authenticated server context and share that resolution with composed surfaces. */
    public function selectForPanel(\Milpa\Live\ValueObjects\ComponentContext $context): \Milpa\AgentWorkspace\Admin\PanelSession
    {
        $selection = \Milpa\AgentWorkspace\Admin\PanelSession::fromContext($context, $this->store, $this->seatSessionIds($context->principal ?? ''));
        $this->panelSessionIds = $selection->allowed;
        $this->selectedId = $selection->id ?? '';

        return $selection;
    }

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
     * in the store, else the last the LEDGER started, or '' when there is none. The file name is what the
     * write side addresses ({@see DesktopStore::updateWorkStatus()}), so it is the id the UI posts back,
     * not the display id.
     *
     * ONE SESSION, TWO STORES (greenhouse decisions/0258, third time in this family). `session()` reads the
     * AGENT's ledger, and `hasSession()` already accepts an id that only the ledger knows — but naming the
     * current one asked the SHELL's file store alone. A host that never writes that store (the admin's Agent
     * section is one: it has no sidebar to select from) got `''` back, so the counters seeded zero while the
     * ledger held nine turns and a hundred and fifty thousand tokens. This is not a new source: it is the
     * source the class already reads, asked the question it could always answer.
     *
     * The write side fails closed on an id with no file ({@see DesktopStore::updateWorkStatus()} returns
     * false), so naming a ledger-only session costs nothing it was not already costing.
     */
    public function currentSessionId(): string
    {
        if ($this->selectedId !== null) {
            return $this->selectedId;
        }
        $files = $this->sessionFiles();
        if ($files !== []) {
            return basename($files[0], '.json');
        }
        // The ledger folds each stream in the order the file first names it, so the LAST key is the session
        // most recently started — which is the one a person opening the page means by «the session».
        $ledger = $this->ledgerSessions();

        return $ledger === [] ? '' : (string) array_key_last($ledger);
    }

    /**
     * The current session's counters (turns, steps, tokens, tool calls) — from the session, else derived.
     *
     * The ledger's `working` is split by asking the process (greenhouse decisions/0513 §3): `working` while a run
     * holds the session's lease, `interrupted` when nobody does. A house whose runtime keeps no lease stays `working`.
     *
     * @return array{turns: int, steps: int, tokens: int, tool_calls: int, state: string}
     */
    public function counters(): array
    {
        $id = $this->currentSessionId();
        $record = $this->session($id);
        if ($record !== null) {
            $state = (string) $record['state'];

            return [
                'turns' => (int) $record['turns'],
                'steps' => (int) $record['steps'],
                'tokens' => (int) $record['tokens'],
                'tool_calls' => (int) $record['tool_calls'],
                'state' => $state === 'working' && $this->running($id) === false ? 'interrupted' : $state,
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
     * Whether a process is running session `$id` right now: its lease, asked of `milpa/app-runtime`'s `RunLease`
     * (greenhouse decisions/0513 §3) — or null when this house's runtime keeps no lease and nothing can be said.
     */
    public function running(string $id): ?bool
    {
        $kernel = $this->container->has(Kernel::class) ? $this->container->get(Kernel::class) : null;
        $held = [self::$runLease, 'held'];
        if ($id === '' || !$kernel instanceof Kernel || !\is_callable($held)) {
            return null;
        }

        return (bool) $held($kernel->root(), $id);
    }

    /**
     * Name the class that answers whether a session's run is alive — null restores the runtime's.
     *
     * @internal a seam for tests: the lease is the runtime's, and a suite must be able to hold one without it
     */
    public static function useRunLease(?string $class): void
    {
        self::$runLease = $class ?? self::RUN_LEASE;
    }

    /**
     * The mode the composer's next turn carries (greenhouse decisions/0513 §4): the one the open session recorded —
     * `session.started`, then `session.mode_changed` — else the saved setting, else the mode that asks.
     */
    public function mode(): string
    {
        $record = $this->session($this->currentSessionId());
        $recorded = $record !== null ? (string) $record['mode'] : '';
        if (\in_array($recorded, self::MODES, true)) {
            return $recorded;
        }
        $saved = $this->settings()['mode'] ?? null;

        return \is_string($saved) && \in_array($saved, self::MODES, true) ? $saved : 'ask';
    }

    /**
     * The context window usage (wireframe 3a): tokens used, the window size, and derived percent/free.
     *
     * The window is the one the run OBEYED — the last `session.window_composed` its legs recorded (greenhouse
     * decisions/0513 §5) — else what a human declared, by the key the runtime reads (`agent.contextTokens`, then
     * `MILPA_AGENT_CONTEXT_TOKENS`). Nothing else: `0` means unknown, and no percentage is computed over a number
     * nobody said. Painting never asks the provider (decisions/0266).
     *
     * @return array{tokens: int, window: int, used_pct: int, free: int}
     */
    public function context(): array
    {
        // What the context holds is the LAST call's prompt — the ledger's `prompt_tokens` — not the session's
        // total spend; the store's file never knew the difference.
        $record = $this->session($this->currentSessionId());
        $tokens = $record !== null ? (int) $record['context_tokens'] : $this->counters()['tokens'];
        $recorded = $record !== null && \is_int($record['window'] ?? null) ? $record['window'] : 0;
        $window = $recorded > 0 ? $recorded : $this->declaredWindow();

        return [
            'tokens' => $tokens,
            'window' => $window,
            'used_pct' => $window > 0 ? min(100, (int) round($tokens / $window * 100)) : 0,
            'free' => max(0, $window - $tokens),
        ];
    }

    /** The window a human declared, as the runtime reads it — or 0 when nobody did. */
    private function declaredWindow(): int
    {
        $config = $this->container->has(Config::class) ? $this->container->get(Config::class) : null;
        $declared = $config instanceof Config ? $config->get('agent.contextTokens') : null;
        if (\is_string($declared) && ctype_digit($declared)) {
            $declared = (int) $declared;
        }
        if (\is_int($declared) && $declared > 0) {
            return $declared;
        }
        $environment = getenv('MILPA_AGENT_CONTEXT_TOKENS');

        return \is_string($environment) && ctype_digit($environment) ? (int) $environment : 0;
    }

    /**
     * The house's closure verdict on the current session's last leg, or `null` when it recorded none — or a
     * later request reopened the work (greenhouse decisions/0509 §7).
     *
     * @return array{verified: bool, reasons: list<string>, scope: string, seq: int}|null
     */
    public function closure(): ?array
    {
        $record = $this->session($this->currentSessionId());
        $closure = $record['closure'] ?? null;

        return \is_array($closure) ? $closure : null;
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

    /*
     * NO HAY `export()`, Y SU ÚNICO LLAMADOR ERA UNA RUTA. Servía `GET /desktop/export` — el material de
     * autopsia de una sesión — y esa ruta se fue con la página. El censo lo reportó «no caller outside
     * its own file» en el mismo commit en que borré la ruta (greenhouse decisions/0213, decisions/0283).
     *
     * Lo que exportaba sigue siendo legible: el ledger de la sesión, que es de donde lo leía.
     */
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
            $class = $message['class'];
            $content = $message['content'];
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
