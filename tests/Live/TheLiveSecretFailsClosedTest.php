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

namespace Milpa\AgentWorkspace\Tests\Live;

use Milpa\AgentWorkspace\AgentWorkspacePlugin;
use Milpa\Container\DIContainer;
use Milpa\Runtime\Config;
use PHPUnit\Framework\TestCase;

/**
 * THE WORKSPACE'S SIGNING SECRET FAILS CLOSED, LIKE THE LIVE WIRE (greenhouse decisions/0569).
 *
 * The panel signs every component's state envelope and CSRF token — and `HubController` the hub ticket —
 * with one HMAC secret. When the house declared none, this used to derive one from the package's own
 * directory (`hash('sha256', __DIR__ . '|milpa-live|' . $kind)`): a value anyone with the image can
 * compute for a house at a known install path, so it could forge the panel's envelopes and tickets with
 * no leak at all — strictly worse than a baked, shared secret.
 *
 * A secret nobody chose is a secret nobody can rotate, which is exactly why `LivePlugin` mounts NO routes
 * without one. The workspace now does the same: no declared secret, no live surface. The house's
 * per-container `live.secret` (greenhouse decisions/0569, minted into the secret overlay) is what a dev
 * image supplies, so the panel opens there; a house that declares nothing gets no panel, not a panel
 * signed with a guessable key.
 */
final class TheLiveSecretFailsClosedTest extends TestCase
{
    public function testWithNoDeclaredSecretNoRoutesAreMounted(): void
    {
        $plugin = new AgentWorkspacePlugin(new DIContainer());

        self::assertSame([], $plugin->routes(), 'no secret, no live surface — not even the hub route');
    }

    public function testWithNoDeclaredSecretNoAdminSectionIsDeclared(): void
    {
        $plugin = new AgentWorkspacePlugin(new DIContainer());

        self::assertSame([], $plugin->adminSections(), 'the panel signs its envelopes; without a secret it does not render');
    }

    public function testADeclaredHouseSecretMountsTheLiveSurface(): void
    {
        $plugin = self::withConfig(['live' => ['secret' => 'a-secret-long-enough-to-sign-with']]);

        self::assertNotSame([], $plugin->routes(), 'a declared secret opens the wire');
        $paths = array_map(static fn ($r): string => $r->path, $plugin->routes());
        self::assertContains('/workspace/hub', $paths);
        self::assertCount(1, $plugin->adminSections());
    }

    public function testAWorkspaceSigningSecretAlsoOpensIt(): void
    {
        // The per-page key wins when declared (greenhouse decisions/0211); it is a real secret, so it opens too.
        $plugin = self::withConfig(['workspace' => ['live' => ['signing_secret' => 'a-workspace-signing-secret-long-enough']]]);

        self::assertNotSame([], $plugin->routes());
    }

    public function testTheEmptyStringIsNoSecret(): void
    {
        // A declared-but-empty secret is the LivePlugin «short secret is no secret» case: still closed.
        $plugin = self::withConfig(['live' => ['secret' => '']]);

        self::assertSame([], $plugin->routes(), 'an empty secret is no secret — it must not fall back to a derived one');
    }

    /** @param array<string, mixed> $config */
    private static function withConfig(array $config): AgentWorkspacePlugin
    {
        $container = new DIContainer();
        $container->registerService(Config::class, new Config($config));

        return new AgentWorkspacePlugin($container);
    }
}
