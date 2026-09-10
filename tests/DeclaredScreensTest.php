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

use Milpa\Container\DIContainer;
use Milpa\AgentWorkspace\Data\DesktopData;
use Milpa\AgentWorkspace\Data\DesktopStore;
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\AgentWorkspace\Live\SettingsControls;
use Milpa\AgentWorkspace\Live\SettingsScreen;
use Milpa\AgentWorkspace\Live\SettingsScreenComponent;
use Milpa\Eventing\EventDispatcher;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\InteractionRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The two screens phase B took out of the shell's template (greenhouse decisions/0211): the Settings
 * screen and the entry overlay.
 *
 * They were the last raw HTML the shell hand-wrote — a `<div class="view">` block each, with their
 * behaviour in the page's inline script. As declared views they are components like every other surface:
 * a contract, a signed envelope, lifecycle events a plugin can extend them through, a stylesheet and a
 * client module. What is asserted here is that shape, and the two facts the doctrine actually cares
 * about: the save NEVER says «Saved» on its own (the door does), and the overlay authenticates nobody.
 */
final class DeclaredScreensTest extends TestCase
{
    public function testTheSettingsScreenIsAComponentWithItsEnvelopeAndItsBindings(): void
    {
        $html = (new SettingsScreen('secret', null, null, null, static fn (): bool => true))->render();

        self::assertStringContainsString('data-milpa-component="desktop-settings"', $html);
        self::assertStringContainsString('data-milpa-state="settings"', $html);
        self::assertStringContainsString('security="signed"', $html);
        self::assertStringContainsString('x-data="desktopSettings()"', $html);
        // The four cards the screen has always shown, by their headings.
        foreach (['Model and provider', 'Default autonomy', 'Context and storage'] as $card) {
            self::assertStringContainsString($card, $html);
        }
        // 🚨 THE IDS COME FROM THE DECLARED CORRESPONDENCE, NOT FROM A LIST KEPT BY HAND. This assertion
        // used to name `set-prov`, `set-stream` and `set-comp` and justify them as «the ids any plugin has
        // always looked up» — and that argument is exactly what kept three controls alive that NOTHING
        // read, on a screen whose Save button reported success for them (greenhouse decisions/0280).
        // Sourced from {@see SettingsControls::READERS}, this test cannot outlive the contract.
        foreach (array_keys(SettingsControls::READERS) as $control) {
            $q = preg_quote($control, '/');
            self::assertMatchesRegularExpression('/(?:id|name)="' . $q . '"|data-' . $q . '="/', $html, $control);
        }
        foreach (['milpa-save-settings', 'milpa-discard', 'milpa-settings-saved'] as $id) {
            self::assertStringContainsString('id="' . $id . '"', $html, $id);
        }
        // Save and Discard are the component's verbs; the badge BINDS to the shared `settings.saved` signal.
        self::assertStringContainsString('@click="save()"', $html);
        self::assertStringContainsString('@click="discard()"', $html);
        self::assertStringContainsString('x-text="savedText" :hidden="!saved"', $html);
        self::assertStringContainsString(":class=\"{ 'mui-badge--success': savedOk, 'mui-badge--warning': !savedOk }\"", $html);
        // 🚨 THE THREE THEME BUTTONS ARE GONE, AND THIS ASSERTION IS WHY THEY LASTED. It checked that
        // they bind to `setTheme()`/`isTheme()`, which they did — and the object those call,
        // `MilpaLive.desktop.theme`, is created ONLY by `desktop-topbar.js`, a module the panel never
        // emits. So the buttons were correctly bound to a reader that is not on the surface, and in the
        // panel they did nothing, silently. Measured while mapping the page's retirement.
        //
        // They did not come back wired: `milpa/admin` has its own theme over the same `data-theme` root
        // attribute, so this was two doors for one fact. A guest does not own the document's theme
        // (greenhouse decisions/0283).
        self::assertStringNotContainsString('data-theme-set', $html, 'the host owns the document theme');
        self::assertStringNotContainsString('setTheme(', $html);
        // Its look is a declared file: not one inline style attribute is left.
        self::assertStringNotContainsString('style=', $html);
    }

    /**
     * 🚨 THIS TEST WAS GUARDING THE DEFECT, and its old name said so: «shows the persisted endpoint».
     *
     * It asserted that a value saved into `.milpa/desktop-settings.json` won the field — «the persisted
     * endpoint wins» was the message. That IS what the screen did, and it is why the field looked
     * correct for weeks: the form posted the address into a file, this method read it back, and the
     * agent — which resolves `agent.baseUrl` from the governed configuration — never saw it. Measured
     * on cattle, the door answered `{"ok":true}` and `coa agent:model` kept saying `endpoint_from:
     * none` (greenhouse decisions/0280).
     *
     * A test that pins a self-read is a test that makes the lie a requirement.
     */
    public function testTheEndpointFieldShowsWhatTheAgentReadsAndSpeaksTheCatalog(): void
    {
        $dir = sys_get_temp_dir() . '/milpa-settings-screen-' . uniqid('', true);
        mkdir($dir);
        $store = new DesktopStore($dir . '/sessions', $dir . '/settings.json');
        $store->saveSettings(['endpoint' => 'http://persisted.test/v1']);
        $data = new DesktopData(new DIContainer(), null, '', $store);

        $html = (new SettingsScreen('secret', $data, null, new Catalog('es'), static fn (): bool => true))->render();

        self::assertStringNotContainsString('persisted.test', $html, 'the settings blob cannot put a value in this field');
        self::assertMatchesRegularExpression('/id="set-end"[^>]*value=""/', $html, 'nothing is configured, so the field asks');
        self::assertStringContainsString('>Guardado</span>', $html, 'the badge seed speaks the declared locale');

        // With nothing configured THE FIELD IS EMPTY. It used to be pre-filled with
        // `http://llama.local:11438` — a host that had stopped resolving — and a form pre-filled with a
        // dead address is worse than an empty one: it reads as «this is what you are talking to», and
        // saving without touching it would DECLARE it (greenhouse decisions/0266).
        self::assertStringNotContainsString('llama.local', (new SettingsScreen('secret'))->render(), 'no surface names a host the reader never chose');

        unlink($dir . '/settings.json');
        rmdir($dir);
    }

    public function testTheSettingsScreenEmitsRenderEventsSoPluginsCanExtendIt(): void
    {
        $events = new EventDispatcher(new NullLogger());
        $events->subscribe(SettingsScreen::BEFORE_RENDER, static function (string $n, array $p): void {
            $p['settings']->props['endpoint'] = 'http://changed.test';
        });
        $events->subscribe(SettingsScreen::AFTER_RENDER, static function (string $n, array $p): void {
            $p['settings']->html .= '<!-- settings extended -->';
        });

        $html = (new SettingsScreen('secret', null, $events, null, static fn (): bool => true))->render();

        self::assertStringContainsString('value="http://changed.test"', $html, 'before_render changed the props');
        self::assertStringContainsString('settings extended', $html, 'after_render changed the html');
    }

    public function testTheSettingsComponentOnlyEverProjectsWhatTheDoorAnswered(): void
    {
        $contract = SettingsScreenComponent::contract();
        self::assertSame('desktop-settings', $contract->name);
        self::assertArrayHasKey('save', $contract->actions);

        $component = new SettingsScreenComponent();
        $state = $component->mount(['endpoint' => 'http://x', 'savedLabel' => 'Guardado'], new ComponentContext('settings'));
        self::assertFalse($state->data['saved'], 'nothing is saved on mount');
        self::assertSame('http://x', $state->meta['endpoint']);

        $result = $component->handle(new InteractionRequest('settings', 'desktop-settings', 'save', $state, []));

        self::assertTrue($result->state->data['saved']);
        self::assertSame(
            [['type' => 'state', 'key' => 'settings.saved', 'value' => ['ok' => true, 'text' => 'Guardado']]],
            $result->effects,
            'the badge is a SIGNAL, and its copy comes from the catalog the server mounted with',
        );
    }

}
