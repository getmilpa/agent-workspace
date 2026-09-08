<?php

/**
 * This file is part of milpa/agent-workspace — the agent's workspace, a section of the Milpa panel.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent-workspace
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Tests;

use Milpa\AgentWorkspace\Event\AgentWorkspaceEvents;
use Milpa\Interfaces\Event\DeclaresEvents;
use Milpa\Interfaces\Event\EventDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * The falsifier of greenhouse decisions/0228, second slice: the MANIFEST names the holder of the events,
 * and what it names is the holder that answers.
 *
 * An emitter declares when it is constructed, so a process that never constructs it never hears its events:
 * measured on cattle, a CLI listed seven of the family's twenty-four (evidence/0567). The manifest is how a
 * host reaches the declaration of an emitter nobody built — it reads `extra.milpa.events` from what Composer
 * actually resolved and asks the named class. That only works while the name in the manifest is real, so this
 * test reads the package's OWN `composer.json` FROM DISK and follows the string: the class it names must
 * exist, must be a {@see DeclaresEvents} holder, and its `declarations()` must carry the same set of names as
 * the holder this package's code declares to the dispatcher. A typo, a rename, or a holder that stops
 * implementing the contract goes red here instead of going silent in a host.
 *
 * The manifest is read as text, never through `composer.json`'s in-memory image or a constant, because the
 * string a host consumes is the one on disk.
 */
final class TheManifestNamesTheHolderOfTheEventsTest extends TestCase
{
    public function testTheManifestNamesExactlyThisPackagesEventHolder(): void
    {
        self::assertSame([AgentWorkspaceEvents::class], self::manifestEventHolders(), 'extra.milpa.events names this package\'s holder, and only it');
    }

    public function testEveryClassTheManifestNamesIsARealHolderThatAnswersTheSameEvents(): void
    {
        $expected = array_map(static fn (EventDeclaration $d): string => $d->name, AgentWorkspaceEvents::declarations());
        sort($expected);
        self::assertNotSame([], $expected, 'the holder declares something to compare against');

        $named = [];
        foreach (self::manifestEventHolders() as $class) {
            self::assertTrue(class_exists($class), $class . ' is named in the manifest and exists');
            self::assertTrue(is_a($class, DeclaresEvents::class, true), $class . ' is named in the manifest and is a DeclaresEvents holder');

            /** @var class-string<DeclaresEvents> $class */
            foreach ($class::declarations() as $declaration) {
                $named[] = $declaration->name;
            }
        }

        sort($named);
        self::assertSame($expected, $named, 'the class named in the manifest declares the same event names as the holder');
    }

    /**
     * Naming the holder costs the manifest nothing else: the capability declaration it already carried is
     * still there, so a host that reads one key does not lose the other (greenhouse evidence/0565).
     */
    public function testNamingTheHolderLeavesTheCapabilityDeclarationAlone(): void
    {
        $milpa = self::manifest()['extra']['milpa'] ?? null;
        self::assertIsArray($milpa);
        self::assertArrayHasKey('capability', $milpa);
        self::assertIsArray($milpa['capability']);
        self::assertSame('agent-workspace', $milpa['capability']['id'] ?? null);
    }

    /**
     * The fully-qualified class names `extra.milpa.events` lists, read from the manifest on disk.
     *
     * @return list<string>
     */
    private static function manifestEventHolders(): array
    {
        $events = self::manifest()['extra']['milpa']['events'] ?? null;
        self::assertIsArray($events, 'extra.milpa.events is a list of holder class names');

        $names = [];
        foreach ($events as $name) {
            self::assertIsString($name, 'extra.milpa.events holds class names');
            $names[] = $name;
        }

        return $names;
    }

    /**
     * This package's own manifest, decoded from the file a host would read.
     *
     * @return array<string, mixed>
     */
    private static function manifest(): array
    {
        $path = \dirname(__DIR__) . '/composer.json';
        $raw = file_get_contents($path);
        self::assertIsString($raw, 'the package manifest is readable at ' . $path);

        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
