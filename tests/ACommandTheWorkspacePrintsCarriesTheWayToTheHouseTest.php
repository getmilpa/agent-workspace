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
use Milpa\AgentWorkspace\Live\ShellSignals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 A COMMAND THE WORKSPACE PRINTS CARRIES THE WAY TO THE HOUSE — and it asks the house, it does not guess.
 *
 * In the Desktop the house lives in a container: `php bin/coa …` runs only after
 * `docker exec -it <container>`. The process that serves the house declares that way in
 * (`MILPA_CLI_PREFIX`) and `milpa/app-runtime` builds every command it hands a person from
 * `Capabilities::cli()` (greenhouse decisions/0559, E5). This package kept two of its own, as catalog
 * values, and they still said `php bin/coa` (greenhouse evidence/1093).
 *
 * This class is the half that needs no declaration: with nothing declared every byte is what it was,
 * whoever answers `cli()` is believed, and no command to type is left in `src/` that does not go
 * through {@see HouseCli}. What the two surfaces paint when the house does declare a way in is
 * {@see TheWorkspaceAsksTheHouseHowItIsReachedTest}, asked of the real app-runtime.
 */
#[CoversClass(HouseCli::class)]
final class ACommandTheWorkspacePrintsCarriesTheWayToTheHouseTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('MILPA_CLI_PREFIX');
    }

    /** With nothing declared the CLI is `php bin/coa ` and both surfaces say it as they always did. */
    public function testWithNothingDeclaredNothingChanges(): void
    {
        self::assertSame('php bin/coa ', HouseCli::cli());

        foreach (Catalog::locales() as $locale) {
            $catalog = new Catalog($locale);
            foreach (['settings.write.no_policy_command', 'conn.degraded.command'] as $key) {
                self::assertSame($catalog->tr($key), HouseCli::reached($catalog->tr($key)), $locale . ' · ' . $key);
            }
        }

        $settings = (new SettingsScreen('test-settings-secret-0123456789', null, null, new Catalog(), static fn (): string => SettingsScreen::NO_POLICY, static fn (): bool => false))->render(hidden: false);
        self::assertStringContainsString('<code>php bin/coa capabilities:enable milpa/auth --sign</code>', $settings);
        self::assertStringContainsString('<code class="composer-degraded__cmd">php bin/coa stack</code>', (new ComposerBar(str_repeat('k', 32), stackUrl: ''))->render());
    }

    /**
     * The catalog itself is not rewritten: what the shell hands its client script is the same map.
     *
     * A command is the same sentence in every locale and is stored once per locale as it always was;
     * the way in is applied where a person reads it, so the `desktop.i18n` signal does not move.
     */
    public function testTheCatalogHandedToTheBrowserDoesNotMove(): void
    {
        $house = (new class () {
            public static function cli(): string
            {
                return 'docker exec -it box php bin/coa ';
            }
        })::class;

        self::assertSame('docker exec -it box php bin/coa stack', HouseCli::reached((new Catalog())->tr('conn.degraded.command'), $house));
        self::assertSame('php bin/coa stack', (new Catalog())->all()['conn.degraded.command']);
        self::assertSame('php bin/coa capabilities:enable milpa/auth --sign', (new Catalog('es'))->all()['settings.write.no_policy_command']);
        self::assertStringNotContainsString("HouseCli", (string) file_get_contents((string) (new \ReflectionClass(ShellSignals::class))->getFileName()));
    }

    /**
     * 🚨 ONE JUDGE: the workspace reads no declaration itself.
     *
     * What a declared way in may be — no line break, no `;`, no `$`, 200 characters — is app-runtime's
     * judgement. With a house that cannot say, a declared variable changes nothing here.
     */
    public function testTheWorkspaceDoesNotReadTheDeclarationItself(): void
    {
        $fromBefore = (new class () {})::class;

        putenv('MILPA_CLI_PREFIX=docker exec -it box');
        try {
            self::assertSame('php bin/coa ', HouseCli::cli($fromBefore));
            self::assertSame('php bin/coa stack', HouseCli::reached('php bin/coa stack', $fromBefore));
        } finally {
            putenv('MILPA_CLI_PREFIX');
        }
    }

    /** Whoever answers `cli()` is believed; every command in a text takes the way in, prose around it untouched. */
    public function testEveryCommandInATextTakesTheWayInTheHouseSays(): void
    {
        $house = (new class () {
            public static function cli(): string
            {
                return 'docker exec -it box php bin/coa ';
            }
        })::class;

        self::assertSame('docker exec -it box php bin/coa ', HouseCli::cli($house));
        self::assertSame(
            'run `docker exec -it box php bin/coa stack`, then `docker exec -it box php bin/coa serve`',
            HouseCli::reached('run `php bin/coa stack`, then `php bin/coa serve`', $house),
        );
        self::assertSame('Nothing to type here.', HouseCli::reached('Nothing to type here.', $house));
    }

    /** A house from before it could say, one that fails, one that answers nothing: the plain command. */
    public function testAHouseThatCannotSayIsNotAnError(): void
    {
        $fromBefore = (new class () {})::class;
        $failing = (new class () {
            public static function cli(): string
            {
                throw new \RuntimeException('the house does not boot');
            }
        })::class;
        $silent = (new class () {
            public static function cli(): string
            {
                return '';
            }
        })::class;
        $wrong = (new class () {
            /** @return list<string> */
            public static function cli(): array
            {
                return ['php bin/coa '];
            }
        })::class;

        foreach ([$fromBefore, $failing, $silent, $wrong, 'Milpa\\Nobody\\Home'] as $house) {
            self::assertSame('php bin/coa ', HouseCli::cli($house));
            self::assertSame('`php bin/coa list`', HouseCli::reached('`php bin/coa list`', $house));
        }
    }

    /**
     * 🚨 THE GUARD: no command to type is left in `src/` that does not go through {@see HouseCli}.
     *
     * A literal command in code is refused outright, and every catalog key whose value carries a
     * command must be read on a line that wraps it. A new command added the old way fails here.
     */
    public function testNoCommandToTypeIsBuiltWithoutAskingTheHouse(): void
    {
        $src = \dirname(__DIR__) . '/src';
        $catalog = (string) file_get_contents($src . '/I18n/Catalog.php');

        preg_match_all('/^\s*\'([a-z_.]+)\' => .*php bin\/coa /m', $catalog, $found);
        $keys = array_values(array_unique($found[1]));
        sort($keys);
        self::assertSame(['conn.degraded.command', 'settings.write.no_policy_command'], $keys, 'the catalog values that carry a command to type');

        $offenders = [];
        foreach (self::phpFiles($src) as $file) {
            $name = substr($file, \strlen($src) + 1);
            if ($name === 'HouseCli.php' || $name === 'I18n/Catalog.php') {
                continue;
            }
            foreach (explode("\n", (string) file_get_contents($file)) as $n => $line) {
                $trimmed = ltrim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                $at = $name . ':' . ($n + 1) . '  ' . trim($line);
                if (str_contains($line, 'php bin/coa ')) {
                    $offenders[] = 'a literal command: ' . $at;
                }
                foreach ($keys as $key) {
                    if (str_contains($line, "'" . $key . "'") && !str_contains($line, 'HouseCli::reached(')) {
                        $offenders[] = 'a catalog command, read bare: ' . $at;
                    }
                }
            }
        }

        self::assertSame([], $offenders, "every command a person is told to type goes through HouseCli:\n" . implode("\n", $offenders));
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $root): array
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }
        sort($found);

        return $found;
    }
}
