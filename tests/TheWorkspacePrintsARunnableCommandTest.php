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

use Milpa\AgentWorkspace\I18n\Catalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 A COMMAND THIS PACKAGE PRINTS HAS TO RUN AS PRINTED.
 *
 * `coa` is not on PATH after `composer create-project`: Composer does not link the ROOT package's
 * `bin`, so `vendor/bin/` holds php-cs-fixer, phpstan and phpunit and no `coa`. Measured in a clean
 * shell at an app root: `coa capabilities:refresh` answers «command not found», exit 127, while
 * `php bin/coa list` exits 0 (greenhouse decisions/0305).
 *
 * The audit was of `house:start` in `milpa/app-runtime`; the count came back at sixteen sites across
 * three packages, because each keeps its own copies. This one had four — two catalog values, in two
 * locales each: the way out of a write with no policy, and the probe for a degraded stack.
 *
 * 🚨 AND THE TESTS THAT COVERED THEM COULD NOT HAVE CAUGHT IT. Three assertions read
 * `assertStringContainsString('coa stack', …)`, which passes on `php bin/coa stack` AND on a bare
 * `coa stack` — a substring of the command cannot tell the runnable form from the broken one. They
 * stayed green through the whole defect and through its fix. They assert the full invocation now
 * (greenhouse decisions/0306).
 */
#[CoversClass(Catalog::class)]
final class TheWorkspacePrintsARunnableCommandTest extends TestCase
{
    /** Both locales live in one file, so one scan reads them both. */
    public function testNoStringInTheSourceOffersABareCoa(): void
    {
        $offenders = [];

        foreach (self::phpFiles(\dirname(__DIR__) . '/src') as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $n => $line) {
                $trimmed = ltrim($line);
                // Comments and docblocks are the code-language ratchet's subject, not this guard's:
                // prose naming an operation is not an instruction.
                if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                if (preg_match('/[\'"`]coa\s+[a-z]/', $line) === 1) {
                    $offenders[] = basename($file) . ':' . ($n + 1) . '  ' . trim($line);
                }
            }
        }

        self::assertSame([], $offenders, "a command a person is told to type must start with `php bin/coa`:\n" . implode("\n", $offenders));
    }

    /** And the commands the catalog carries are the runnable form in every locale it ships. */
    public function testEveryLocaleCarriesTheRunnableForm(): void
    {
        foreach (Catalog::locales() as $locale) {
            $catalog = new Catalog($locale);

            foreach (['settings.write.no_policy_command', 'conn.degraded.command'] as $key) {
                self::assertStringStartsWith('php bin/coa ', $catalog->tr($key), $locale . ' · ' . $key);
            }
        }
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

        return $found;
    }
}
