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

use PHPUnit\Framework\TestCase;

/**
 * `.mui-empty` IS A COLUMN CONTAINER, SO NOTHING MAY USE IT AS A PARAGRAPH.
 *
 * Rod caught the Context tab printing one sentence across three lines: the text, a code chip, and a
 * lone «.». The cause was not the copy — it was `<p class="mui-empty">` with inline children. The
 * primitive is `display:flex; flex-direction:column; align-items:center`, so EVERY child of it becomes
 * its own centered line. Measured in the browser before the fix: `display:flex`, `flexDirection:column`,
 * children `[TEXT, CODE, TEXT(".")]` (greenhouse decisions/0287, evidence/0614).
 *
 * Four of the eight sites had an inline `<code>` and therefore broke; Rod only opened one of them. The
 * other four looked fine because a single text node in a column is indistinguishable from a paragraph —
 * which is exactly how the misuse spread to eight places without anyone seeing it.
 *
 * 🚨 AND THE MISUSE PAID FOR ITSELF IN PATCHES. Six of those sites carried
 * `style="color:var(--text-muted)"` inline. `.mui-empty__desc` already declares that color AND a `40ch`
 * measure — the patch existed only because using the container as a paragraph loses the part that has
 * them. A primitive patched per instance is a primitive being used wrong (greenhouse decisions/0284).
 */
final class AnEmptyStateIsAContainerNotAParagraphTest extends TestCase
{
    /** Every source file of this package that writes markup. */
    private static function sources(): \Generator
    {
        $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src'));
        foreach ($dir as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                yield $file->getPathname() => [$file->getPathname()];
            }
        }
    }

    /**
     * No `<p class="mui-empty">` anywhere: the container's children are `__title` / `__desc` blocks.
     *
     * The check is on the OPENING TAG rather than on the rendered HTML, because the defect is a
     * structural choice made in source and a renderer test would have to guess which state is empty.
     */
    public function testNoParagraphWearsTheContainerClass(): void
    {
        $offenders = [];
        foreach (self::sources() as [$path]) {
            $source = file_get_contents($path);
            self::assertIsString($source, 'a source file that cannot be read makes this whole check pass on nothing');
            if (preg_match_all('/<p\s+class="[^"]*\bmui-empty\b/', $source, $hits) > 0) {
                $offenders[] = basename($path) . ' × ' . \count($hits[0]);
            }
        }

        self::assertSame([], $offenders, '`.mui-empty` is a flex COLUMN: as a <p> every inline child becomes its own line. Wrap the copy in <div class="mui-empty"> with __title / __desc children instead: ' . implode(', ', $offenders));
    }

    /** And nobody re-declares by hand what `__desc` already ships. */
    public function testNobodyPatchesTheColourTheDescriptionAlreadyHas(): void
    {
        $offenders = [];
        foreach (self::sources() as [$path]) {
            $source = (string) file_get_contents($path);
            if (preg_match('/class="[^"]*\bmui-empty\b[^"]*"\s+style=/', $source) === 1) {
                $offenders[] = basename($path);
            }
        }

        self::assertSame([], $offenders, '`.mui-empty__desc` declares the muted colour and a 40ch measure; an inline style on the container means the wrong part is carrying the copy: ' . implode(', ', $offenders));
    }

    /** Positive control: the check FIRES on the markup that shipped. */
    public function testTheCheckCatchesTheMarkupThatShipped(): void
    {
        $shipped = '$body = \'<p class="mui-empty">No plugin has contributed a panel yet. A plugin adds one with \'';

        self::assertSame(1, preg_match('/<p\s+class="[^"]*\bmui-empty\b/', $shipped), 'a check that does not fire on the known defect proves nothing about the sites it passes');
    }
}
