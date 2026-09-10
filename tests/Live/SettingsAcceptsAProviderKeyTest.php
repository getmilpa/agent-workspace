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

use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\AgentWorkspace\Live\SettingsScreen;
use PHPUnit\Framework\TestCase;

/**
 * WHERE A PROVIDER CREDENTIAL IS ACCEPTED, and the three states are facts rather than moods.
 *
 * Rod chose the shape: Settings grows and the house sends you there, rather than a wizard beside it —
 * one door, because what you configure and what you fix should be the same screen. The field is the
 * first slice of that (greenhouse decisions/0276).
 *
 * 🚨 IT DOES NOT RIDE IN THE SETTINGS BLOB, measured rather than preferred: `POST /desktop/settings`
 * writes `.milpa/desktop-settings.json`, and on a real app's own `.gitignore` — checked on a fresh
 * repository with the template's rules — THAT FILE IS COMMITTED, while `.milpa/secrets.json` is not. A
 * key riding along with the endpoint and the theme would be a key in somebody's git history.
 */
final class SettingsAcceptsAProviderKeyTest extends TestCase
{
    /**
     * 🚨 THE PROPERTY THIS SCREEN EXISTS TO NOT VIOLATE: it never renders a key.
     *
     * `SecretOverlay` has no reader that returns a value, so this screen cannot echo one even by
     * mistake — but the assertion is here anyway, over the WHOLE rendered screen, because the day
     * somebody adds an accessor «for the settings screen» this is what fails.
     */
    public function testTheScreenNeverRendersAKeyEvenWhenOneIsHeld(): void
    {
        $html = $this->screen(judge: true, held: true);

        self::assertStringNotContainsString('sk-', $html, 'no provider key shape anywhere');
        self::assertStringContainsString('data-key-state="held"', $html);
        self::assertStringContainsString('There is a key', $html, 'said, never shown');
        // The input is a password field with autocomplete off: a key typed here is not offered back by
        // the browser to the next person at the machine.
        self::assertStringContainsString('id="set-key" class="mui-input milpa-settings__mono" type="password" autocomplete="off"', $html);
    }

    /** With nobody to judge who may write one, the field is NOT offered — and the screen says why. */
    public function testWithNoPolicyTheFieldIsNotOfferedAndTheReasonIsNamed(): void
    {
        $html = $this->screen(judge: false, held: false);

        self::assertStringContainsString('data-key-state="unjudgeable"', $html);
        self::assertStringNotContainsString('id="set-key"', $html, 'a control that cannot work is not offered');
        self::assertStringContainsString('nothing here can say who may write one', $html);
        self::assertStringContainsString('coa capabilities:enable milpa/auth --sign', $html, 'and the way out is a command, not advice');
    }

    /** With a judge and no key, the field is there and empty. */
    public function testWithAJudgeAndNoKeyTheFieldIsOfferedEmpty(): void
    {
        $html = $this->screen(judge: true, held: false);

        self::assertStringContainsString('data-key-state="absent"', $html);
        self::assertStringContainsString('id="set-key"', $html);
        self::assertStringContainsString('Paste the provider key', $html);
        self::assertStringNotContainsString('There is a key', $html);
    }

    /**
     * 🚨 THE QUESTIONS ARE ASKED AT RENDER, NOT FROZEN AT BOOT.
     *
     * Both facts depend on when you ask: the policy is registered by whichever plugin brings it, so
     * whether it is there depends on BOOT ORDER, and a key declared while the app runs must show as
     * declared on the next render rather than the next restart. Measured in the same session: `Kernel`
     * is not registered while a plugin boots either (greenhouse decisions/0269, decisions/0276).
     */
    public function testTheAnswersAreAskedEveryRenderRatherThanRememberedOnce(): void
    {
        $held = false;
        $screen = new SettingsScreen(
            'test-settings-secret-0123456789',
            null,
            null,
            new Catalog(),
            static fn (): bool => true,
            static function () use (&$held): bool {
                return $held;
            },
        );

        self::assertStringContainsString('data-key-state="absent"', $screen->render(hidden: false));

        $held = true;
        self::assertStringContainsString('data-key-state="held"', $screen->render(hidden: false), 'the same instance, asked again');
    }

    /** The copy is the catalog's, in the locale the page answers in. */
    public function testTheFieldSpeaksTheRequestsLocale(): void
    {
        $screen = new SettingsScreen(
            'test-settings-secret-0123456789',
            null,
            null,
            new Catalog('es'),
            static fn (): bool => false,
        );

        self::assertStringContainsString('nada aquí puede decir quién tiene permiso', $screen->render(hidden: false));
    }

    private function screen(bool $judge, bool $held): string
    {
        return (new SettingsScreen(
            'test-settings-secret-0123456789',
            null,
            null,
            new Catalog(),
            static fn (): bool => $judge,
            static fn (): bool => $held,
        ))->render(hidden: false);
    }
}
