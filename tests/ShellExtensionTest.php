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

namespace Milpa\AgentWorkspace\Tests;

use Milpa\AgentWorkspace\AgentWorkspacePlugin;
use Milpa\AgentWorkspace\Tests\Fixtures\DemoSectionPlugin;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

/**
 * The 0188 seam, proved by execution: a plugin hosts the workspace and OTHER plugins modify that same
 * UI through the `desktop.shell.compose` event, decoupled — the host never names the contributor.
 *
 * 🚨 THE SUBJECT MOVED FROM THE PAGE TO THE PANEL, AND THE SEAM NEARLY DIED WITH THE PAGE.
 * `ShellController` was the only dispatcher of that event AND the only renderer of the sections it
 * collects, so retiring `/desktop` would have taken a published extension point with it — silently,
 * because a contribution nobody paints looks exactly like a plugin that contributed nothing. Caught by
 * adversarially mapping the retirement, not by this test, which would simply have been deleted with
 * the route it asked (greenhouse decisions/0283).
 *
 * `AgentViewRenderer` dispatches it now, so the witness is the contributor's marker appearing in the
 * PANEL's Agent region — and the negative control is the same marker's absence when the contributor is
 * not installed, which is what proves the section comes from the contributor and nowhere else.
 */
final class ShellExtensionTest extends TestCase
{
    public function testASecondPluginContributesUiIntoTheShell(): void
    {
        $psr17 = new Psr17Factory();
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [AgentWorkspacePlugin::class, DemoSectionPlugin::class],
        ]);

        $body = self::region($kernel);

        self::assertStringContainsString(DemoSectionPlugin::MARKER, $body, 'the foreign plugin modified the workspace UI');
        self::assertStringContainsString('data-plugin="demo-section"', $body, 'the section is attributed to its contributor');
        // The region is still there — the contribution extends, it does not replace.
        self::assertStringContainsString('data-desktop-agent=', $body);
    }

    public function testWithoutTheContributorTheShellCarriesNoSection(): void
    {
        $psr17 = new Psr17Factory();
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [AgentWorkspacePlugin::class],
        ]);

        $body = self::region($kernel);

        self::assertStringNotContainsString(DemoSectionPlugin::MARKER, $body);
        self::assertStringNotContainsString('data-plugin=', $body);
    }

    /** The panel's Agent region, rendered from the booted kernel's own registry and dispatcher. */
    private static function region(Kernel $kernel): string
    {
        $container = $kernel->container();
        $live = $container->get(\Milpa\AgentWorkspace\Live\DesktopComponents::class);
        $events = $kernel->dispatcher();

        return (new \Milpa\AgentWorkspace\Admin\AgentViewRenderer(
            $live,
            $container->has(\Milpa\AgentWorkspace\Data\DesktopData::class) ? $container->get(\Milpa\AgentWorkspace\Data\DesktopData::class) : null,
            null,
            '',
            $events,
        ))->render(
            new \Milpa\AgentWorkspace\Admin\AgentViewComponent(),
            new \Milpa\Live\ValueObjects\RenderRequest(
                new \Milpa\Live\ValueObjects\ComponentContext('milpa-admin-section-agent', route: '/milpa/admin'),
                ['gate' => 'loopback'],
            ),
        )->output;
    }
}
