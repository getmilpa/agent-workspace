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
 * A run that died on its model endpoint says so in the thread (greenhouse decisions/0536).
 *
 * Measured (evidence/1069 §C1): with the endpoint saved as `…/v1`, leg 0 asked `/v1/v1/chat/completions`, got a
 * 404, and ended `failed` in 0.16 s. The 404 lived in the terminal that ran the leg; the panel showed nothing but
 * the failed leg. The ledger now carries the cause (milpa/ai-gateway's `RunTermination::causeOf()`), and the
 * transcript hands it to the thread.
 *
 * @guards a failed run is a row in the thread, with the endpoint's status and address when the ledger has them
 *
 * @refuses a row for a run that ended any other way; a cause of an unknown kind presented as the endpoint's
 */
final class ARunThatDiedOnItsEndpointSaysSoTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            rmdir($this->dir);
        }
    }

    public function testTheEndpointsRefusalIsARowOfTheThread(): void
    {
        $rows = $this->data([
            ['session.turn', ['role' => 'user', 'content' => 'Build the blog']],
            ['session.run_terminated', ['reason' => 'failed', 'receipt' => null,
                'cause' => ['kind' => 'provider_refused', 'status' => 404, 'endpoint' => 'http://llama.test:11438/v1/v1/chat/completions']]],
        ])->transcript('s');

        self::assertSame(['user', 'run_failed'], array_column($rows, 'kind'));
        self::assertSame(['kind' => 'run_failed', 'cause' => 'provider_refused', 'status' => 404, 'endpoint' => 'http://llama.test:11438/v1/v1/chat/completions'], $rows[1]);
    }

    public function testAnUnreachableEndpointAndAFailureWithoutACauseAreRowsToo(): void
    {
        $rows = $this->data([
            ['session.run_terminated', ['reason' => 'failed', 'receipt' => null, 'cause' => ['kind' => 'provider_unreachable', 'endpoint' => 'http://down.test:1/v1/chat/completions']]],
            // What a gateway before milpa/ai-gateway's cause wrote: still a failure the thread must show.
            ['session.run_terminated', ['reason' => 'failed', 'receipt' => null]],
            ['session.run_terminated', ['reason' => 'failed', 'receipt' => null, 'cause' => ['kind' => 'something_else', 'endpoint' => 'x']]],
        ])->transcript('s');

        self::assertSame([
            ['kind' => 'run_failed', 'cause' => 'provider_unreachable', 'status' => null, 'endpoint' => 'http://down.test:1/v1/chat/completions'],
            ['kind' => 'run_failed', 'cause' => '', 'status' => null, 'endpoint' => ''],
            ['kind' => 'run_failed', 'cause' => '', 'status' => null, 'endpoint' => ''],
        ], $rows);
    }

    public function testARunThatEndedAnyOtherWayIsNotARow(): void
    {
        $rows = $this->data([
            ['session.run_terminated', ['reason' => 'final_answer', 'receipt' => null]],
            ['session.run_terminated', ['reason' => 'progress_stalled', 'receipt' => null]],
            ['session.run_terminated', ['reason' => 'interrupted', 'receipt' => null]],
        ])->transcript('s');

        self::assertSame([], $rows);
    }

    /** @param list<array{0: string, 1: array<string, mixed>}> $events */
    private function data(array $events): DesktopData
    {
        $this->dir = sys_get_temp_dir() . '/milpa-run-failed-' . uniqid('', true);
        mkdir($this->dir);
        $ledger = $this->dir . '/agent-sessions.jsonl';
        $lines = [];
        foreach ($events as $i => [$type, $payload]) {
            $lines[] = json_encode(['stream_id' => 'agent-session:s', 'type' => $type, 'payload' => $payload, 'seq' => $i + 1], \JSON_THROW_ON_ERROR);
        }
        file_put_contents($ledger, implode("\n", $lines) . "\n");

        return new DesktopData(new DIContainer(), null, '', null, $ledger);
    }
}
