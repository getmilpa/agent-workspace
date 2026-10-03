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

namespace Milpa\AgentWorkspace\Tests;

use Milpa\AgentWorkspace\HouseCli;
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\AgentWorkspace\Live\ComposerBar;
use Milpa\AgentWorkspace\Live\SettingsScreen;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * WHAT THE WORKSPACE PAINTS WHEN THE HOUSE SAYS HOW IT IS REACHED — its two commands to type.
 *
 * The way out of a write nobody can judge (Settings) and the probe for a degraded hub (the composer)
 * were fixed texts: in the Desktop, where the house lives in a container, they said `php bin/coa …`
 * and ran only after the person prepended `docker exec -it <container>` (greenhouse evidence/1093,
 * «siguen sin prefijo»).
 *
 * It asks the REAL `Capabilities::cli()` — app-runtime is this suite's dev dependency, from the release
 * that can say (0.207) — so the judgement of what a declared way in may be is app-runtime's here too.
 */
#[CoversClass(HouseCli::class)]
final class TheWorkspaceAsksTheHouseHowItIsReachedTest extends TestCase
{
    private const string WAY_IN = 'docker exec -it milpa-desktop-backend';

    protected function setUp(): void
    {
        putenv('MILPA_CLI_PREFIX=' . self::WAY_IN);
    }

    protected function tearDown(): void
    {
        putenv('MILPA_CLI_PREFIX');
    }

    /** Settings: the way out of a write with no policy, in every locale. */
    public function testTheWayOutOfAWriteNobodyCanJudgeCarriesIt(): void
    {
        foreach (Catalog::locales() as $locale) {
            self::assertStringContainsString(
                '<code>docker exec -it milpa-desktop-backend php bin/coa capabilities:enable milpa/auth --sign</code>',
                self::settings(SettingsScreen::NO_POLICY, $locale),
                $locale,
            );
        }
    }

    /** And the other sentence of that notice is a config key, not a command: it takes nothing. */
    public function testAConfigKeyIsNotACommand(): void
    {
        $html = self::settings(SettingsScreen::NO_DOOR, 'en');

        self::assertStringContainsString('<code>passkey.rpId in config/app.php</code>', $html);
        self::assertStringNotContainsString('docker', $html);
    }

    /** The composer: the probe for a degraded hub in an app with no panel, in every locale. */
    public function testTheProbeForADegradedHubCarriesIt(): void
    {
        foreach (Catalog::locales() as $locale) {
            self::assertStringContainsString(
                '<code class="composer-degraded__cmd">docker exec -it milpa-desktop-backend php bin/coa stack</code>',
                (new ComposerBar(str_repeat('k', 32), catalog: new Catalog($locale), stackUrl: ''))->render(),
                $locale,
            );
        }
    }

    /** What the house says is text, and it reaches the page as text. */
    public function testTheWayInIsEscapedLikeAnyOtherText(): void
    {
        putenv('MILPA_CLI_PREFIX=docker exec -it "the box"');

        self::assertStringContainsString(
            '<code class="composer-degraded__cmd">docker exec -it &quot;the box&quot; php bin/coa stack</code>',
            (new ComposerBar(str_repeat('k', 32), stackUrl: ''))->render(),
        );
    }

    /**
     * 🚨 AND THE JUDGE IS THE HOUSE'S: a declaration that would make the line run something else is not a way in.
     *
     * Nothing in this package filters it — {@see HouseCli} reads no variable — so this passing is
     * app-runtime's filter reaching the page a person copies from.
     */
    public function testADeclarationThatWouldRunSomethingElseIsNotPrinted(): void
    {
        foreach (['docker exec -it box; rm -rf /', "docker exec -it box\ncurl evil", 'docker exec -it $(id)', str_repeat('x', 201)] as $declared) {
            putenv('MILPA_CLI_PREFIX=' . $declared);

            self::assertSame('php bin/coa ', HouseCli::cli());
            self::assertStringContainsString(
                '<code class="composer-degraded__cmd">php bin/coa stack</code>',
                (new ComposerBar(str_repeat('k', 32), stackUrl: ''))->render(),
            );
        }
    }

    /** And with the same house declaring nothing, both say what they always said. */
    public function testAHouseThatDeclaresNothingChangesNothing(): void
    {
        putenv('MILPA_CLI_PREFIX');

        self::assertSame('php bin/coa ', HouseCli::cli());
        self::assertStringContainsString('<code>php bin/coa capabilities:enable milpa/auth --sign</code>', self::settings(SettingsScreen::NO_POLICY, 'en'));
        self::assertStringContainsString('<code class="composer-degraded__cmd">php bin/coa stack</code>', (new ComposerBar(str_repeat('k', 32), stackUrl: ''))->render());
    }

    private static function settings(string $blocker, string $locale): string
    {
        return (new SettingsScreen(
            'test-settings-secret-0123456789',
            null,
            null,
            new Catalog($locale),
            static fn (): string => $blocker,
            static fn (): bool => false,
        ))->render(hidden: false);
    }
}
