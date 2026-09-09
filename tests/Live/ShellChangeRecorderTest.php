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

use Milpa\AgentWorkspace\Live\ShellChangeRecorder;
use Milpa\AgentWorkspace\Live\ShellEvent;
use Milpa\AgentWorkspace\Live\ShellEventLog;
use Milpa\AgentWorkspace\Live\ShellPublisher;
use PHPUnit\Framework\TestCase;

/**
 * Recording a shell change writes to both transports (greenhouse decisions/0475): always the shared log, and
 * — when a live publisher is wired — the hub too. With no publisher it is exactly the log behavior of 0473.
 */
final class ShellChangeRecorderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/milpa-desktop-rec-' . uniqid('', true) . '.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testItAppendsToTheLogAndPublishesToTheHub(): void
    {
        $log = new ShellEventLog($this->path);
        $published = [];
        $publisher = new class ($published) implements ShellPublisher {
            /** @param list<ShellEvent> $published */
            public function __construct(private array &$published)
            {
            }

            public function publish(ShellEvent $event): void
            {
                $this->published[] = $event;
            }
        };
        $event = new ShellEvent('badge.updated', ['text' => 'hi']);

        (new ShellChangeRecorder($log, $publisher))->record($event);

        self::assertSame('badge.updated', $log->since(0)[0]['event']->type, 'appended to the log');
        self::assertCount(1, $published, 'published to the hub');
        self::assertSame($event, $published[0]);
    }

    public function testWithoutAPublisherItOnlyAppendsToTheLog(): void
    {
        $log = new ShellEventLog($this->path);

        (new ShellChangeRecorder($log))->record(new ShellEvent('panel.closed'));

        self::assertSame('panel.closed', $log->since(0)[0]['event']->type);
    }
}
