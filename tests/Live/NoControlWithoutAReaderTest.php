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

use Milpa\AgentWorkspace\Data\DesktopData;
use Milpa\AgentWorkspace\Data\DesktopStore;
use Milpa\AgentWorkspace\Live\SettingsControls;
use Milpa\AgentWorkspace\Live\SettingsScreen;
use Milpa\Container\DIContainer;
use PHPUnit\Framework\TestCase;

/**
 * NO CONTROL IS OFFERED THAT NOTHING READS — the falsifier {@see SettingsControls} exists for.
 *
 * 🚨 THIS TEST IS THE REORDERING. The task was «the Endpoint field writes where the agent reads». The
 * measurement said the defect was not one field: SIX of the screen's nine controls were connected to
 * nothing, and the Save button reported success for all of them. So the first move is not a fix — it
 * is the assertion that no control can be printed without a named reader, which is what makes the six
 * fail at once and keeps the seventh from being added (greenhouse decisions/0280).
 *
 * The round-trip cases go through the ACTUAL reader rather than checking that a key was stored: the
 * Endpoint field looked correct for weeks precisely because it read back its own saved value.
 */
final class NoControlWithoutAReaderTest extends TestCase
{
    /**
     * Every control the screen prints is in the map, and every entry in the map is printed.
     *
     * Both directions on purpose: the first refuses a control nothing reads, the second refuses an
     * entry that outlived the control it described — a map that documents a field somebody deleted is
     * how a correspondence starts lying.
     */
    public function testEveryControlPrintedHasADeclaredReader(): void
    {
        $printed = self::controlsIn((new SettingsScreen('s', null, null, null, static fn (): bool => true))->render(false));

        self::assertSame(
            array_values(array_keys(SettingsControls::READERS)),
            $printed,
            'A control the screen offers must name who reads it in SettingsControls::READERS.',
        );
    }

    /** A person can press it, so it carries a verb — three scale buttons carried none. */
    public function testEveryButtonCarriesAVerb(): void
    {
        $html = (new SettingsScreen('s', null, null, null, static fn (): bool => true))->render(false);

        self::assertSame(
            0,
            preg_match_all('/<button(?![^>]*@click)[^>]*>/', $html),
            'A button with no verb is a control that does nothing when pressed.',
        );
    }

    /**
     * THE ENDPOINT SHOWN IS WHAT THE AGENT READS, and nothing else can put a value in that field.
     *
     * The positive control is the second assertion: a value saved under the store's own `endpoint` key
     * — exactly what the screen used to post and read back — must NOT appear. That self-read is what
     * made a field that never reached the agent look like it worked.
     */
    public function testTheEndpointFieldShowsWhatTheAgentReadsAndNeverItsOwnSavedValue(): void
    {
        $dir = sys_get_temp_dir() . '/milpa-settings-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o775, true);
        $store = new DesktopStore($dir . '/sessions', $dir . '/desktop-settings.json');
        $store->saveSettings(['endpoint' => 'http://written-by-the-screen:1234']);
        $data = new DesktopData(new DIContainer(), null, $dir, $store);

        $html = (new SettingsScreen('s', $data, null, null, static fn (): bool => true))->render(false);

        self::assertStringNotContainsString('http://written-by-the-screen:1234', $html);
        self::assertMatchesRegularExpression('/id="set-end"[^>]*value=""/', $html);
    }

    /** The autonomy radios reflect what is stored — the screen printed `ask` checked no matter what. */
    public function testTheAutonomyRadiosReflectTheStoredMode(): void
    {
        $dir = sys_get_temp_dir() . '/milpa-settings-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o775, true);
        $store = new DesktopStore($dir . '/sessions', $dir . '/desktop-settings.json');
        $store->saveSettings(['mode' => 'auto']);
        $data = new DesktopData(new DIContainer(), null, $dir, $store);

        $html = (new SettingsScreen('s', $data, null, null, static fn (): bool => true))->render(false);

        self::assertStringContainsString('value="auto" checked="checked"', $html);
        self::assertStringNotContainsString('value="ask" checked="checked"', $html);
    }

    /**
     * The controls a rendered screen offers: input ids and the `data-*-set` verbs that stand for a group.
     *
     * @return list<string>
     */
    private static function controlsIn(string $html): array
    {
        preg_match_all('/(?:id|name)="(set-[a-z]+)"/', $html, $ids);
        preg_match_all('/data-([a-z]+-set)="/', $html, $verbs);

        return array_values(array_unique([...$ids[1], ...$verbs[1]]));
    }
}
