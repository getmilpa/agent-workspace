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
use Milpa\AgentWorkspace\Live\ComposerBar;
use PHPUnit\Framework\TestCase;

/**
 * NO SURFACE OF THIS PACKAGE RESOLVES THE MODEL, and none of them names a host.
 *
 * `AgentEndpoint` exists because one precedence was written twice and the copies disagreed: a human
 * who configured through the governed path opened the first screen and was told they had configured
 * nothing (greenhouse evidence/0165). Its docblock ruled on the remedy — **the surface loses the
 * right to resolve**, «because patching it would have left THREE copies of the precedence instead of
 * two».
 *
 * This package arrived after that rule and made SEVEN: `qwen3.8-27b` hardcoded in five places and
 * `http://llama.local:11438` in two, the latter a host that stopped resolving when that machine moved
 * to Tailscale. Every one of them asserted a model it had never asked (decisions/0266).
 *
 * 🚨 THIS CHECK READS CODE AND NOT COMMENTS, and that is not fussiness. Twice in one day a ban over
 * whole files went wrong in both directions: a comment naming a removed value made an assertion PASS
 * while the code did the opposite, and a comment explaining a removal made a good assertion FAIL.
 * The docblocks in these files quote the very strings this bans, because they say why they left.
 */
final class NoSurfaceResolvesTheModelTest extends TestCase
{
    /** Every file that used to carry its own copy of the answer. */
    private const SURFACES = [
        'src/Data/DesktopData.php',
        'src/Live/AuthOverlay.php',
        'src/Live/ComposerBar.php',
        'src/Live/StatusBar.php',
        'src/Live/SettingsScreen.php',
    ];

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function surfaces(): iterable
    {
        foreach (self::SURFACES as $file) {
            yield $file => [$file];
        }
    }

    /**
     * @dataProvider surfaces
     */
    public function testNoSurfaceCarriesAModelNameOrAHost(string $file): void
    {
        $code = self::withoutComments((string) file_get_contents(\dirname(__DIR__) . '/' . $file));

        self::assertStringNotContainsString('qwen3', $code, "$file names a model");
        self::assertStringNotContainsString('llama.local', $code, "$file names a host");
        self::assertStringNotContainsString('11438', $code, "$file names a port");
        // The key that never existed: `AgentKeys` declares `agent.baseUrl`, and this package read
        // `agent.base_url` — so it never once saw the value the turn uses.
        self::assertStringNotContainsString('agent.base_url', $code, "$file reads a key nobody declares");
    }

    /**
     * THE CONTROL FOR THE INSTRUMENT ITSELF: the comments DO still carry those strings, on purpose.
     *
     * If this failed, the eraser above would be reading nothing and the whole battery would pass over
     * an empty string. The reasons stay in the files — a removal that does not say what left teaches
     * nobody why it must not come back.
     */
    public function testTheReasonsAreStillWrittenInThoseFiles(): void
    {
        $prose = '';
        foreach (self::SURFACES as $file) {
            $prose .= (string) file_get_contents(\dirname(__DIR__) . '/' . $file);
        }

        self::assertStringContainsString('llama.local', $prose, 'the docblocks say what left');
        self::assertStringContainsString('agent.base_url', $prose);
        self::assertNotSame(
            $prose,
            self::withoutComments($prose),
            'and the eraser actually erases something',
        );
    }

    /**
     * The one label every surface reads, and the three sentences it can say.
     *
     * None of them is a default name. «Not answering» exists because a name alone would claim a
     * working provider, and «does not serve it» is the arm nothing was checking — a catalogue without
     * the configured model fails every turn AT the provider.
     */
    public function testTheLabelSaysAbsenceSilenceAndTheWrongCatalogue(): void
    {
        $catalog = new Catalog();

        self::assertSame('no model declared', ComposerBar::modelLabel([], $catalog));
        self::assertSame('no model declared', ComposerBar::modelLabel(['model' => ''], $catalog));
        self::assertSame('qwen-x · not answering', ComposerBar::modelLabel(['model' => 'qwen-x', 'reached' => false], $catalog));
        self::assertSame('qwen-x · this provider does not serve it', ComposerBar::modelLabel(['model' => 'qwen-x', 'reached' => true, 'serves_declared' => false], $catalog));
        // Reached and serving it: the name alone, with nothing added.
        self::assertSame('qwen-x', ComposerBar::modelLabel(['model' => 'qwen-x', 'reached' => true, 'serves_declared' => true], $catalog));
        // 🚨 UNASKED IS NOT UNREACHED. `reached: null` means the question could not be asked — no
        // endpoint, or no reader — and a surface that painted «not answering» there would accuse a
        // provider nobody ever contacted.
        self::assertSame('qwen-x', ComposerBar::modelLabel(['model' => 'qwen-x', 'reached' => null], $catalog));
    }

    /** It speaks the declared locale, like every other string of this package. */
    public function testTheLabelSpeaksTheCatalogsLanguage(): void
    {
        self::assertSame('sin modelo declarado', ComposerBar::modelLabel([], new Catalog('es')));
        self::assertSame('qwen-x · no contesta', ComposerBar::modelLabel(['model' => 'qwen-x', 'reached' => false], new Catalog('es')));
    }

    /**
     * PHP with its comments removed — what the code actually says.
     *
     * Strings are left alone: a `'qwen3'` inside a string literal is exactly what this bans, and an
     * eraser that dropped those would pass over the defect it exists to catch.
     */
    private static function withoutComments(string $php): string
    {
        $out = '';
        foreach (token_get_all($php) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= \is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
