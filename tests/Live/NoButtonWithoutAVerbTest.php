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

namespace Milpa\AgentWorkspace\Tests\Live;

use Milpa\AgentWorkspace\Admin\AgentViewComponent;
use Milpa\AgentWorkspace\Admin\AgentViewRenderer;
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\AgentWorkspace\Live\DeepScreens;
use Milpa\AgentWorkspace\Live\DesktopComponents;
use Milpa\AgentWorkspace\Live\Surfaces;
use Milpa\Eventing\EventDispatcher;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\RenderRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * NOT ONE BUTTON THIS PACKAGE PRINTS IS PRESSABLE AND INERT — across every surface, not one screen.
 *
 * 🚨 THE RULE WAS ALREADY WRITTEN AND SCOPED TOO NARROWLY. `NoControlWithoutAReaderTest` counts
 * verbless buttons on the Settings screen, and it found three there — the interface-scale row, which
 * left with greenhouse decisions/0280. The composer's attach `＋` survived that pass for one reason:
 * it was on a different surface, and the guard could not see it.
 *
 * It carried an `aria-label` and NOTHING else: no `id`, no `@click`, no `data-*`, so no module could
 * bind it even by delegation. Measured by counting the rendered page's buttons, not by reading
 * (decisions/0283), and removed when Rod asked for the residues to be cleaned (decisions/0286).
 *
 * A CONTROL IS BOUND ONE OF THREE WAYS in this package, and all three are visible in the markup: an
 * Alpine `@click`, a `data-*` hook a module delegates from, or an `id` a module resolves. A button
 * with none of them is inert, and the person pressing it learns nothing.
 */
final class NoButtonWithoutAVerbTest extends TestCase
{
    /** Every surface the panel paints, and every deep screen, in one census. */
    public function testEverySurfaceOffersOnlyButtonsThatDoSomething(): void
    {
        $html = self::everything();

        preg_match_all('/<button(?![^>]*(?:@click|data-[a-z-]+|\bid="))[^>]*>/', $html, $inert);

        self::assertSame([], $inert[0], 'a button with no @click, no data-* hook and no id is pressable and inert');
    }

    /**
     * THE POSITIVE CONTROL: the pattern can see an inert button when there is one.
     *
     * Without it the assertion above would pass on a census that found no buttons at all — which is
     * how a guard ends up measuring its own silence.
     */
    public function testThePatternSeesAnInertButtonWhenThereIsOne(): void
    {
        self::assertSame(
            1,
            preg_match_all('/<button(?![^>]*(?:@click|data-[a-z-]+|\bid="))[^>]*>/', '<button type="button" aria-label="attach">＋</button>'),
            'the exact shape the composer carried',
        );
    }

    /** And it counts a real one: the census must have found buttons to have proven anything. */
    public function testTheCensusFoundButtonsToJudge(): void
    {
        self::assertGreaterThanOrEqual(10, preg_match_all('/<button/', self::everything()), 'the instrument read real surfaces');
    }

    private static function everything(): string
    {
        $events = new EventDispatcher(new NullLogger());
        $catalog = new Catalog();
        $live = new DesktopComponents('signing', 'csrf', $events);
        (new Surfaces(null, $events, $catalog))->declareOn($live);
        DeepScreens::declareOn($live, null, $events, $catalog, hidden: false);

        $region = (new AgentViewRenderer($live, null, $catalog, '', $events))->render(
            new AgentViewComponent(),
            new RenderRequest(new ComponentContext('milpa-admin-section-agent', route: '/milpa/admin'), ['gate' => 'loopback']),
        )->output;

        $screens = '';
        foreach (['desktop-settings', 'desktop-skills', 'desktop-subagents', 'desktop-screens', 'desktop-capabilities'] as $screen) {
            $screens .= $live->has($screen) ? $live->compiler()->compileFragment('<milpa-' . $screen . '/>', new ComponentContext('s-' . $screen))->output : '';
        }

        return $region . $screens;
    }
}
