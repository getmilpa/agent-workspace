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
        $html = $this->screen(blocker: '', held: true);

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
        $html = $this->screen(blocker: SettingsScreen::NO_POLICY, held: false);

        self::assertStringContainsString('data-key-state="unjudgeable"', $html);
        self::assertStringNotContainsString('id="set-key"', $html, 'a control that cannot work is not offered');
        self::assertStringContainsString('nothing here can say who may reconfigure the agent', $html);
        self::assertStringContainsString('php bin/coa capabilities:enable milpa/auth --sign', $html, 'and the way out is a command, not advice');
    }

    /**
     * 🚨 A JUDGE AND NO DOOR: the field is STILL not offered, and the sentence is a different one.
     *
     * This is the state the whole slice exists for. Measured on cattle with `milpa/auth` installed and
     * `passkey.rpId` absent: the `OperationHttpPolicy` IS in the container — so the old question, «is
     * there a policy», said yes and the field was offered — while `AuthContextFactory` is NOT, the
     * passkey door mounts ZERO routes, nobody can be signed in, and pressing save answered 500.
     *
     * A judge with nobody it can judge is not an answer. And the sentence matters as much as the
     * refusal: telling somebody to install `milpa/auth` when they already have it is the failure this
     * replaced (greenhouse decisions/0285).
     */
    public function testWithAJudgeButNoDoorTheFieldIsStillNotOfferedAndSaysSo(): void
    {
        $html = $this->screen(blocker: SettingsScreen::NO_DOOR, held: false);

        self::assertStringContainsString('data-blocked-by="no-door"', $html);
        self::assertStringNotContainsString('id="set-key"', $html, 'a write that cannot be authorized is not offered');
        self::assertStringContainsString('no door is mounted', $html);
        self::assertStringContainsString('passkey.rpId in config/app.php', $html, 'the way out is the key to declare, not a package to install');
        self::assertStringNotContainsString('capabilities:enable milpa/auth', $html, 'it already has it — saying otherwise is the old lie');
    }

    /** With a judge and no key, the field is there and empty. */
    public function testWithAJudgeAndNoKeyTheFieldIsOfferedEmpty(): void
    {
        $html = $this->screen(blocker: '', held: false);

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
     *
     * 🚨 AND THE WIDENED QUESTION MADE THIS LOAD-BEARING RATHER THAN CAUTIOUS. `AuthContextFactory` is
     * registered by `PasskeyPlugin`, a DIFFERENT plugin — measured on cattle, booting only this
     * package's plugin and rendering reports `no-door`, and booting the whole kernel reports nothing
     * blocking. If the answer were frozen at construction the screen would refuse forever on any app
     * that lists this plugin first (greenhouse decisions/0285).
     */
    public function testTheAnswersAreAskedEveryRenderRatherThanRememberedOnce(): void
    {
        $held = false;
        $screen = new SettingsScreen(
            'test-settings-secret-0123456789',
            null,
            null,
            new Catalog(),
            static fn (): string => '',
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
            static fn (): string => SettingsScreen::NO_POLICY,
        );

        self::assertStringContainsString('nada aquí puede decir quién puede reconfigurar al agente', $screen->render(hidden: false));
    }

    /**
     * `$blocker` is what stops a write: `''` offers the fields, {@see SettingsScreen::NO_POLICY} and
     * {@see SettingsScreen::NO_DOOR} each say their own sentence (greenhouse decisions/0285).
     */
    private function screen(string $blocker, bool $held): string
    {
        return (new SettingsScreen(
            'test-settings-secret-0123456789',
            null,
            null,
            new Catalog(),
            static fn (): string => $blocker,
            static fn (): bool => $held,
        ))->render(hidden: false);
    }
}
