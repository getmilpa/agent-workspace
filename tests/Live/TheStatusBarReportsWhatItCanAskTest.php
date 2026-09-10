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

use Milpa\AgentWorkspace\Live\StatusBar;
use Milpa\Live\Security\HmacStateSigner;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\Transport\XhtmlStateTransferCodec;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 THE BAR'S IDENTITY LINE WAS A LITERAL, and no test noticed for as long as it existed.
 *
 * It read `m4-core local-agent · v0.1.0`: somebody's laptop, and a version belonging to nothing, painted
 * in the status bar of every installation. Its own docblock called it «the product's name, not copy» —
 * which is how it survived, because nobody translates a product name and so nobody checked whether it
 * was true.
 *
 * A lie on a first screen is worse than a wrong value: a wrong value invites the question, and a lie
 * answers it (greenhouse evidence/0165, caught adopting this bar into the panel, decisions/0270).
 */
final class TheStatusBarReportsWhatItCanAskTest extends TestCase
{
    /** The machine it actually runs on, and the version actually installed. */
    public function testTheIdentityLineIsAskedAndNotInvented(): void
    {
        $codec = new SignedXhtmlStateTransferCodec(new XhtmlStateTransferCodec(), new HmacStateSigner('test-statusbar-secret-0123456789'), null);
        $html = (new StatusBar($codec))->render();

        $machine = gethostname();
        self::assertIsString($machine);
        self::assertNotSame('', $machine);
        self::assertStringContainsString($machine, $html, 'the machine this app runs on');

        $version = \Composer\InstalledVersions::getPrettyVersion('milpa/agent-workspace');
        self::assertIsString($version, 'composer knows what is installed');
        self::assertStringContainsString($version, $html, 'the version actually installed, not a constant to remember to bump');
    }

    /**
     * THE CONTROL, AND IT IS WHAT THIS TEST EXISTS FOR: the literal is gone from the whole package.
     *
     * Asserted over the source rather than the render, deliberately. A machine name can be reintroduced
     * anywhere — a template, a fallback, a second bar — and a test that only read one render would pass
     * while the lie lived somewhere else.
     */
    public function testNobodysLaptopIsNamedAnywhereInThisPackage(): void
    {
        $found = [];
        $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src'));
        foreach ($dir as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            // Assembled rather than written, so this file does not contain the string it forbids.
            if (str_contains($source, 'm4' . '-core') || str_contains($source, 'local' . '-agent ·')) {
                $found[] = $file->getFilename();
            }
        }

        self::assertSame([], $found, 'a machine name in the source is a machine name on every installation\'s screen');
    }
}
