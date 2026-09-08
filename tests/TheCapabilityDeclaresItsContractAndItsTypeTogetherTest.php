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

use PHPUnit\Framework\TestCase;

/**
 * The falsifier of greenhouse evidence/0568: a capability is discoverable by WHAT IT IS, so the contract and
 * the type that publishes it are one declaration, never two.
 *
 * Every Milpa app finds its capabilities through one registry listing —
 * `https://packagist.org/packages/list.json?type=milpa-capability` — which filters by Composer's `type` and
 * by nothing else. A package that carries a full `extra.milpa.capability` contract but publishes the default
 * `library` type is INVISIBLE to that listing: the contract is on Packagist, complete and unreachable.
 * Measured on the live registry, the index built from the listing saw 9 of 13 capability packages, and this
 * package — published `type: library` up to v0.60.0 — was one of the four it could not see, while being one
 * of the surfaces the marketplace is shown in.
 *
 * The two halves fail differently and silently, so this test asserts them TOGETHER: dropping `type` leaves a
 * contract nobody lists, and dropping `extra.milpa.capability` leaves a listing entry with nothing to say.
 * Either edit goes red here.
 *
 * The manifest is read as TEXT from disk, never through Composer's in-memory image or a constant, because
 * the bytes a registry publishes are the bytes on disk.
 */
final class TheCapabilityDeclaresItsContractAndItsTypeTogetherTest extends TestCase
{
    /** The only value the registry listing every Milpa app queries will match. */
    private const DISCOVERABLE_TYPE = 'milpa-capability';

    public function testTheManifestDeclaresTheCapabilityContractAndTheTypeThatPublishesIt(): void
    {
        $manifest = self::manifest();

        self::assertSame(
            self::DISCOVERABLE_TYPE,
            $manifest['type'] ?? null,
            'without `type: milpa-capability` the registry listing cannot see this package at all',
        );

        $capability = $manifest['extra']['milpa']['capability'] ?? null;
        self::assertIsArray(
            $capability,
            'without `extra.milpa.capability` the listing entry carries no contract to read',
        );
        self::assertSame('agent-workspace', $capability['id'] ?? null);
        self::assertIsString($capability['title'] ?? null);
        self::assertIsString($capability['briefing'] ?? null);
        self::assertContains('agent-workspace', $capability['provides'] ?? []);
    }

    /**
     * This package's own manifest, decoded from the file a registry would publish.
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
