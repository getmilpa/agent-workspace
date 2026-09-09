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

use Milpa\Admin\AdminSettings;
use Milpa\AgentWorkspace\Live\PanelLink;
use Milpa\Runtime\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Where the panel's sections are, when there IS a panel (greenhouse decisions/0255).
 *
 * The Desktop does not depend on milpa/admin on purpose, so a link into the panel has two ways to be
 * wrong and they are the same mistake: assuming a panel exists, and assuming where it is mounted.
 */
#[CoversClass(PanelLink::class)]
final class PanelLinkTest extends TestCase
{
    /** 1 · a declared `admin.route` wins, and the trailing slash never doubles. */
    public function testItHonoursTheRouteTheAppDeclared(): void
    {
        self::assertSame('/panel/s/stack', PanelLink::fromConfig($this->config(['route' => '/panel']))->stack());
        self::assertSame('/panel/s/stack', PanelLink::fromConfig($this->config(['route' => '/panel/']))->stack());
    }

    /**
     * 2 · THE MIRROR, pinned against its owner.
     *
     * `<route>/s/<id>` is the panel's own `sectionUrl()`, and it is not exposed as a constant, so this is
     * the one shape mirrored rather than read. Comparing against the method itself is what keeps the copy
     * from drifting in silence: if the panel ever changes how it addresses a section, this goes red here
     * instead of shipping a link that 404s in somebody's browser.
     */
    public function testTheShapeIsTheOneThePanelItselfBuilds(): void
    {
        $panel = new AdminSettings(route: '/panel');

        self::assertSame(
            $panel->sectionUrl(PanelLink::STACK),
            PanelLink::fromConfig($this->config(['route' => '/panel']))->stack(),
            'the address of a section belongs to the panel; this only mirrors it',
        );
    }

    /**
     * 3 · with nothing declared, the DEFAULT is the panel's own — read, never retyped.
     *
     * Copying `/milpa/admin` into this package would put one value in two places, which is exactly where
     * two truths come apart.
     */
    public function testTheDefaultComesFromThePanelNotFromACopy(): void
    {
        self::assertSame(
            AdminSettings::DEFAULT_ROUTE . '/s/stack',
            PanelLink::fromConfig($this->config([]))->stack(),
        );
        self::assertSame(AdminSettings::DEFAULT_ROUTE . '/s/stack', PanelLink::fromConfig(null)->stack());
    }

    /** 4 · a route that is not an absolute local path is not a route: the panel's default stands. */
    public function testAMalformedRouteIsNotObeyed(): void
    {
        foreach (['', 'panel', 'https://elsewhere.example/panel', 42, null] as $bad) {
            self::assertSame(
                AdminSettings::DEFAULT_ROUTE . '/s/stack',
                PanelLink::fromConfig($this->config(['route' => $bad]))->stack(),
                'a declaration that is not an absolute local path is rejected, not obeyed',
            );
        }
    }

    /**
     * 5 · the control this class exists for: with NO panel installed there is no link at all.
     *
     * It cannot be measured here — milpa/admin is installed in this package's own vendor — so what is
     * asserted is the branch that decides it: an empty route yields an empty URL, and the renderer reads
     * exactly that to say the state without offering a way out that does not exist. The end-to-end half
     * lives in the composer's own test, which renders with `stackUrl: ''`.
     */
    public function testWithNoRouteThereIsNoLink(): void
    {
        $reflected = new \ReflectionClass(PanelLink::class);
        /** @var PanelLink $none */
        $none = $reflected->newInstanceWithoutConstructor();
        $route = $reflected->getProperty('route');
        $route->setValue($none, '');

        self::assertSame('', $none->stack(), 'no panel, no link — a dead link is worse than no offer');
    }

    private function config(mixed $admin): Config
    {
        return new Config(['admin' => $admin]);
    }
}
