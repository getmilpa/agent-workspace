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

namespace Milpa\AgentWorkspace\Tests\Config;

use Milpa\AgentWorkspace\AgentWorkspacePlugin;
use Milpa\AgentWorkspace\Config\WorkspaceKeys;
use Milpa\Container\DIContainer;
use Milpa\Eventing\EventDispatcher;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Runtime\Config;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * AN APP STILL DECLARING `desktop` DOES NOT BOOT, AND THE REFUSAL SAYS THE ONE-LINE FIX.
 *
 * 🚨 A FALLBACK WOULD HAVE BEEN THE WRONG SHAPE, and this house spent a day removing exactly it. A
 * reader that accepts `desktop.middleware` and carries on leaves the stale key in the file, teaches
 * nobody, and one day — when the compatibility window closes — a gate silently reverts to
 * loopback-only. `desktop.middleware` is what stands between the workspace and the network: an app
 * that will not start is better than a door that quietly changed (greenhouse decisions/0284).
 *
 * The refusal happens at BOOT, first thing, so the error reaches whoever wrote `config/app.php` — the
 * same reasoning as the framework's boot guard for an unjudgeable operation (decisions/0279).
 */
final class TheOldNamespaceRefusesToBootTest extends TestCase
{
    /** It refuses, and the message carries both names. */
    public function testItRefusesAndNamesBothKeys(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/desktop\.middleware/');
        $this->expectExceptionMessageMatches('/`workspace`/');

        self::boot(['desktop' => ['middleware' => []]]);
    }

    /** The new namespace boots, which is the control that makes the refusal mean something. */
    public function testTheNewNamespaceBoots(): void
    {
        self::boot(['workspace' => ['middleware' => []]]);

        self::assertSame([], WorkspaceKeys::read(new Config(['workspace' => ['middleware' => []]]), 'middleware'));
    }

    /**
     * 🚨 A SIGNAL NAMED `desktop.*` IS NOT A CONFIG KEY, and a detector that grepped the string would
     * refuse a perfectly configured app. `desktop.tab`, `desktop.nav` and `desktop.i18n` are seeded
     * per page and never read from `Config`; this asks `Config` for the bag and nothing else.
     */
    public function testASignalNamedLikeTheOldNamespaceIsNotAConfigKey(): void
    {
        self::boot(['desktop.tab' => 'chat', 'desktop.i18n' => ['a' => 'b']]);

        self::assertTrue(true, 'an app carrying signal-shaped keys boots');
    }

    /** An app that declares nothing at all boots — absence is not the old namespace. */
    public function testAnAppThatDeclaresNothingBoots(): void
    {
        self::boot([]);

        self::assertNull(WorkspaceKeys::read(null));
    }

    /** @param array<string, mixed> $config */
    private static function boot(array $config): void
    {
        $container = new DIContainer();
        $container->registerService(MilpaEventDispatcherInterface::class, new EventDispatcher(new NullLogger()));
        $container->registerService(Config::class, new Config($config));

        (new AgentWorkspacePlugin($container))->boot();
    }
}
