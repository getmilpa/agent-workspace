<?php

/**
 * This file is part of milpa/agent-workspace.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent-workspace
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Tests\Admin;

use Milpa\AgentWorkspace\Admin\AgentViewComponent;
use Milpa\AgentWorkspace\Admin\AgentViewRenderer;
use Milpa\AgentWorkspace\Data\DesktopData;
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\AgentWorkspace\Live\DeepScreens;
use Milpa\AgentWorkspace\Live\DesktopComponents;
use Milpa\AgentWorkspace\Live\Surfaces;
use Milpa\Container\DIContainer;
use Milpa\Eventing\EventDispatcher;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * THE PANEL PAINTS EVERY SURFACE IT MOUNTS WITHOUT THE PAGE'S CONTROLLER EXISTING.
 *
 * 🚨 THIS GUARD DID NOT EXIST, AND ITS ABSENCE IS WHY THE COUPLING SURVIVED. The 21 surface
 * declarations the panel needs came from `ShellController`'s CONSTRUCTOR — a side effect of the page's
 * controller being built during `boot()`. Nothing asserted the panel could stand without it, and
 * nothing would have noticed: `AgentViewRenderer::paint()` wraps every surface in `catch (\Throwable)`
 * and returns a small warning div per failure, so a panel missing every behaviour still answers HTTP
 * 200 and renders pixel-perfect server-side.
 *
 * Measured while mapping the retirement: rendering the Agent view against a registry populated only
 * the way `adminSections()` populates it returned 10 170 bytes, no exception, and SEVENTEEN
 * `data-failed-component` markers (greenhouse decisions/0283).
 *
 * So the property is a COUNT OF ZERO, and it is the count that matters: an assertion that the view
 * contains some component would have passed the whole time.
 */
final class ThePanelPaintsEverySurfaceWithoutThePageTest extends TestCase
{
    /** Not one surface fails to paint, and the failure marker is the thing being counted. */
    public function testNotOneSurfaceFailsToPaint(): void
    {
        $html = self::panelView();

        self::assertSame(
            0,
            preg_match_all('/data-failed-component/', $html),
            'every surface the panel mounts must paint from declarations the panel itself made',
        );
    }

    /**
     * THE POSITIVE CONTROL: the marker is real and this instrument can see it.
     *
     * A registry with NOTHING declared must produce the markers — otherwise the assertion above would
     * pass on a page that never tried, which is the shape of a guard that measures its own absence.
     */
    public function testAnEmptyRegistryDoesProduceTheMarkerSoTheCountMeansSomething(): void
    {
        $bare = new DesktopComponents('s', 'c', new EventDispatcher(new NullLogger()));
        $html = (new AgentViewRenderer($bare, null))->render(
            new AgentViewComponent(),
            new RenderRequest(new ComponentContext('milpa-admin-section-agent', route: '/milpa/admin'), ['gate' => 'loopback']),
        )->output;

        self::assertGreaterThan(0, preg_match_all('/data-failed-component/', $html), 'the marker exists and is countable');
    }

    /** The registry exactly as `AgentWorkspacePlugin::adminSections()` builds it — no page, no controller. */
    private static function panelView(): string
    {
        $events = new EventDispatcher(new NullLogger());
        $data = new DesktopData(new DIContainer(), null, sys_get_temp_dir());
        $catalog = new Catalog();
        $live = new DesktopComponents('signing', 'csrf', $events);

        (new Surfaces($data, $events, $catalog))->declareOn($live);
        DeepScreens::declareOn($live, $data, null, $catalog, hidden: false);

        return (new AgentViewRenderer($live, $data))->render(
            new AgentViewComponent(),
            new RenderRequest(new ComponentContext('milpa-admin-section-agent', route: '/milpa/admin'), ['gate' => 'loopback']),
        )->output;
    }
}
