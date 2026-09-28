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

namespace Milpa\AgentWorkspace\Tests\Admin;

use Milpa\Agent\Principal;
use Milpa\Agent\SessionStore;
use Milpa\AgentWorkspace\Admin\PanelSession;
use Milpa\AgentWorkspace\Data\DesktopData;
use Milpa\AgentWorkspace\Data\DesktopStore;
use Milpa\AppRuntime\Identity\FileEnrollmentStore;
use Milpa\AppRuntime\Identity\IdentityEnrolled;
use Milpa\Container\DIContainer;
use Milpa\EventStore\FileEventStore;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Runtime\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * The panel shows the human the sessions of the seats they enrolled, with the refusal and the action to
 * grant it — and shows nothing of a seat to a human outside its enrollment line (greenhouse decisions/0493).
 */
final class SeatFrontierPanelTest extends TestCase
{
    private const HUMAN = 'C1FEA43BAC5F22E7A5F21152B46AB0F97CAFB831';
    private const STRANGER = 'D00D000011112222333344445555666677778888';
    private const SEAT = '95A3AC7B96F8BC6AA7044F2C09082971DEBAAA50';
    private const PASSKEY = 'passkey:QM1LEWEfsoWiMm';
    private const STRANGER_PASSKEY = 'passkey:ZZ9otherCredential';
    private const SESSION = 'camino-blog';

    private string $root = '';

    protected function setUp(): void
    {
        if (!class_exists(\Milpa\AppRuntime\Agent\SeatFrontier::class)) {
            self::markTestSkipped('needs milpa/app-runtime with SeatFrontier (greenhouse decisions/0493)');
        }
        $this->root = sys_get_temp_dir() . '/milpa-seat-panel-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/storage/identity', 0o777, true);
        mkdir($this->root . '/var', 0o777, true);
        $ledger = new FileEnrollmentStore($this->root . '/storage/identity/enrollments.json');
        $ledger->record(new IdentityEnrolled(self::SEAT, ['agent:run', 'agent:read', 'plugins:read'], 'key:' . self::HUMAN));
        $ledger->record(new IdentityEnrolled(substr(self::PASSKEY, 8), ['milpa.admin', 'agent:read', 'identity:enroll'], 'key:' . self::HUMAN));
        $ledger->record(new IdentityEnrolled(substr(self::STRANGER_PASSKEY, 8), ['milpa.admin', 'agent:read', 'identity:enroll'], 'key:' . self::STRANGER));

        $sessions = new SessionStore(new FileEventStore($this->root . '/var/agent-sessions.jsonl'));
        $sessions->start(self::SESSION, 'Build the blog', by: new Principal('key:' . self::SEAT, true));
        $sessions->recordToolCall(self::SESSION, 'make', ['what' => 'plugin', 'plugin' => 'Blog'], "Missing required permission 'plugins.Blog:write' for plugin 'Blog'.", false, true);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testTheHumanWhoEnrolledTheSeatSeesItsRefusalAndOpensItsSession(): void
    {
        $data = $this->data();

        $frontier = $data->seatFrontier(self::PASSKEY);
        self::assertCount(1, $frontier);
        self::assertSame(self::SESSION, $frontier[0]['session']);
        self::assertSame('plugins.Blog:write', $frontier[0]['refusals'][0]['permission']);

        self::assertSame(self::SESSION, $data->selectForPanel($this->asking(self::PASSKEY))->id, 'the seat session opens instead of «unavailable»');
    }

    public function testAHumanAnotherKeyEnrolledNeitherSeesNorOpensTheSeat(): void
    {
        $data = $this->data();

        self::assertSame([], $data->seatFrontier(self::STRANGER_PASSKEY));
        self::assertNull($data->selectForPanel($this->asking(self::STRANGER_PASSKEY))->id, 'still «unavailable for this identity»');
    }

    public function testWithoutTheSeatListThePanelStillRefusesTheSession(): void
    {
        // The control for the admission: the same request, resolved without the seats, is the rehearsal's refusal.
        self::assertNull(PanelSession::fromContext($this->asking(self::PASSKEY))->id);
        self::assertSame(self::SESSION, PanelSession::fromContext($this->asking(self::PASSKEY), null, [self::SESSION])->id);
    }

    /** «Your seats» (greenhouse decisions/0499): the seats a viewer answers for, read from the runtime's own relation. */
    public function testYourSeatsListsTheSeatForItsLineAndNothingForAnother(): void
    {
        $data = $this->data();

        self::assertSame([[
            'fingerprint' => self::SEAT,
            'label' => null,
            'scopes' => ['agent:run', 'agent:read', 'plugins:read'],
            'authorized_by' => 'key:' . self::HUMAN,
        ]], $data->seats(self::PASSKEY), 'the passkey the seat\'s key enrolled answers for it');
        self::assertSame([], $data->seats(self::STRANGER_PASSKEY), 'another line sees no seat');
        self::assertSame([], $data->seats(''), 'nobody signed in, no seat');
    }

    private function data(): DesktopData
    {
        $container = new DIContainer();
        $container->registerService(Kernel::class, $this->kernel());

        return new DesktopData($container, null, $this->root . '/sessions', new DesktopStore($this->root . '/sessions', $this->root . '/settings.json'));
    }

    private function asking(string $principal): ComponentContext
    {
        return new ComponentContext('agent', $principal, 'en', '/milpa/admin/s/agent', ['query' => ['session' => self::SESSION]]);
    }

    private function kernel(): Kernel
    {
        $kernel = (new \ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
        foreach (['root' => $this->root, 'commands' => []] as $name => $value) {
            $property = new \ReflectionProperty(Kernel::class, $name);
            $property->setValue($kernel, $value);
        }

        return $kernel;
    }
}
