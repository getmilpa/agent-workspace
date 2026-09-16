<?php

/**
 * This file is part of milpa/agent-workspace — the agent's workspace inside a Milpa app.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link https://github.com/getmilpa/agent-workspace
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Tests\Admin;

use Milpa\Admin\AdminSettings;
use Milpa\Admin\Section\AdminSection;
use Milpa\Admin\Section\AdminSectionProvider;
use Milpa\Admin\Section\SectionCatalogue;
use Milpa\Admin\View\AdminShell;
use Milpa\AgentWorkspace\Admin\AgentView;
use Milpa\AgentWorkspace\Admin\PanelSession;
use Milpa\AgentWorkspace\Data\DesktopData;
use Milpa\AgentWorkspace\DesktopSettings;
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\AgentWorkspace\Live\DesktopComponents;
use Milpa\AgentWorkspace\Live\Surfaces;
use Milpa\Container\DIContainer;
use Milpa\Eventing\EventDispatcher;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The panel's ticket, selector and signals describe the same authenticated session (0396/0714). */
final class PanelSessionProjectionTest extends TestCase
{
    /** Reusing a view for another principal cannot retain the last render's selection or the global fallback. */
    public function testThePanelProjectsItsPrincipalsSessionIncludingAnUnstartedOne(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'panel-session-');
        self::assertIsString($file);
        $owner = PanelSession::forPrincipal('owner');
        $reader = PanelSession::forPrincipal('reader');
        $rows = [];
        foreach ([$owner => 2, $reader => 1] as $id => $turns) {
            $rows[] = ['stream_id' => 'agent-session:' . $id, 'type' => 'session.started', 'payload' => ['goal' => $id], 'seq' => count($rows) + 1];
            for ($i = 0; $i < $turns; ++$i) {
                $rows[] = ['stream_id' => 'agent-session:' . $id, 'type' => 'session.turn', 'payload' => ['role' => 'user', 'content' => $id], 'seq' => count($rows) + 1];
            }
            if ($id === $owner) {
                $rows[] = ['stream_id' => 'agent-session:' . $id, 'type' => 'session.run_terminated', 'payload' => ['reason' => 'output_truncated'], 'seq' => count($rows) + 1];
            }
        }
        file_put_contents($file, implode("\n", array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows)) . "\n");
        try {
            $data = new DesktopData(new DIContainer(), ledgerPath: $file);
            self::assertSame($reader, $data->currentSessionId(), 'the last-created session is the legacy fallback');
            $events = new EventDispatcher(new NullLogger());
            $live = new DesktopComponents('signing', 'csrf', $events);
            (new Surfaces($data, $events, new Catalog()))->declareOn($live);
            $view = AgentView::of($live, DesktopSettings::fromConfig(null), new Catalog(), $data, '/signin', 'test-secret');
            $active = AdminSection::ofView('agent', 'Agent', $view);
            $provider = new class ($active) implements AdminSectionProvider {
                public function __construct(private readonly AdminSection $section)
                {
                }

                public function adminSections(): array
                {
                    return [$this->section];
                }
            };
            $catalogue = SectionCatalogue::discover([$provider]);
            $codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('test-secret'), null);
            $shell = new AdminShell(AdminSettings::fromConfig(null), new \Milpa\Admin\I18n\Catalog(), $codec);
            foreach ([['owner', false, 2], ['reader', true, 1], ['owner', false, 2], ['unstarted', false, 0], [null, false, 0]] as [$principal, $working, $turns]) {
                $id = PanelSession::forPrincipal($principal);
                // Both consumers must select independently; a prior render may have selected somebody else.
                $data->select($principal === 'reader' ? $owner : $reader);
                $signals = $view->resolveSignals(new ComponentContext('agent', principal: $principal, meta: ['query' => ['principal' => 'reader']]));
                self::assertSame($working, $signals['session.working']);
                self::assertSame($turns, $signals['session.turns']);
                $data->select($principal === 'reader' ? $owner : $reader);
                $page = $shell->compose($catalogue, $active, ['principal' => 'reader'], $principal);
                self::assertSame($working, $page->seeds->signals['session.working']);
                self::assertSame($turns, $page->seeds->signals['session.turns']);
                self::assertStringContainsString('"agent":"' . $id . '"', $page->html);
                self::assertStringContainsString('<option value="' . ($turns === 0 ? '' : $id) . '" selected', $page->html);
            }

            $signedOut = AgentView::of($live, new DesktopSettings(middleware: [DesktopSettings::PASSKEY_GATE]), new Catalog(), $data, '/signin');
            $data->select($reader);
            $signals = $signedOut->resolveSignals(new ComponentContext('agent'));
            self::assertFalse($signals['session.working']);
            self::assertSame(0, $signals['session.turns']);
        } finally {
            unlink($file);
        }
    }
}
