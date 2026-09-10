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

namespace Milpa\AgentWorkspace\Tests;

use Milpa\AgentWorkspace\AgentWorkspacePlugin;
use Milpa\AgentWorkspace\Live\DesktopAssets;
use Milpa\Runtime\Http\RequestHandler;
use Milpa\Runtime\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Execution, not text (greenhouse D³): the unit tests call {@see AgentWorkspacePlugin::routes()} directly,
 * which proves the shape but NOT that the real runtime can boot the plugin and serve it. A Milpa app
 * boots plugins by class-string through {@see Kernel::boot()}, which requires `#[PluginMetadata]` and
 * mounts every booted `RouteProviderInterface`'s routes into the router the front controller dispatches
 * through. This test boots that real path end to end — the only witness that installing the plugin
 * actually serves `/desktop`, and, since the Desktop stands behind the same door as the admin
 * (greenhouse decisions/0209), the only witness that the door actually closes on the LAN.
 */
final class RuntimeBootTest extends TestCase
{
    public function testWithoutThePluginTheShellRouteIsNotServed(): void
    {
        // Positive control's negative: the same runtime, the same request, but the plugin NOT installed.
        // If /desktop answered here, the route would be coming from somewhere other than this plugin.
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [],
        ]);

        $response = self::dispatch($kernel, 'GET', '/desktop/hub', '127.0.0.1');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testTheDefaultDoorRefusesTheLanAndTheAssetsStayPublic(): void
    {
        // The door, through the real pipeline (greenhouse decisions/0209): the router resolves the gate the
        // plugin registered, and a LAN address is refused — a page for a browser, JSON for the shell's calls.
        $kernel = Kernel::boot(['root' => sys_get_temp_dir(), 'plugins' => [AgentWorkspacePlugin::class]]);

        $page = self::dispatch($kernel, 'GET', '/desktop/hub', '203.0.113.9', ['Accept' => 'text/html']);
        self::assertSame(403, $page->getStatusCode());
        self::assertStringContainsString('desktop.middleware', (string) $page->getBody());

        $call = self::dispatch($kernel, 'POST', '/desktop/settings', '203.0.113.9', ['Content-Type' => 'application/json']);
        self::assertSame(403, $call->getStatusCode());
        self::assertSame(['ok' => false, 'error' => 'loopback_only'], json_decode((string) $call->getBody(), true));


        // The assets are public package files: a JSON 401/403 to a <link> or <script> would break the page
        // silently. Since the declared views (greenhouse decisions/0211) that includes every per-component
        // file the page's own renderers declared — served by the `{file}` route family, gate-free.
        // THE ONLY PUBLIC ASSETS LEFT ARE THE COMPONENTS'. The page's own five went with it, and the
        // panel serves its runtimes from its own routes - measured: zero references to
        // /desktop/assets/alpine.min.js in a rendered panel. What stays public is
        // /desktop/assets/c/{file}, where the PANEL loads every workspace file from
        // (greenhouse decisions/0283).
        foreach (DesktopAssets::declared() as $component) {
            $declared = DesktopAssets::of($component);
            foreach ([...$declared->styles, ...$declared->scripts] as $url) {
                self::assertSame(200, self::dispatch($kernel, 'GET', $url, '203.0.113.9')->getStatusCode(), $url . ' is served to anyone');
            }
        }
        self::assertSame(404, self::dispatch($kernel, 'GET', '/desktop/assets/c/nope.css', '127.0.0.1')->getStatusCode(), 'a name no renderer declared is a 404');

        // No address at all fails closed.
        self::assertSame(403, self::dispatch($kernel, 'GET', '/desktop/hub', '')->getStatusCode());
    }

    public function testADeclaredEmptyListOpensTheDoorOnPurpose(): void
    {
        // The positive control of the refusal above: the same LAN address, the door declared open.
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [AgentWorkspacePlugin::class],
            'config' => ['desktop' => ['middleware' => []]],
        ]);

        $response = self::dispatch($kernel, 'GET', '/desktop/hub', '203.0.113.9', ['Accept' => 'text/html']);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAMisdeclaredGateFallsToLoopbackOnlyInsteadOfDying(): void
    {
        $kernel = Kernel::boot([
            'root' => sys_get_temp_dir(),
            'plugins' => [AgentWorkspacePlugin::class],
            'config' => ['desktop' => ['middleware' => ['Acme\\Nope']]],
        ]);

        self::assertSame(403, self::dispatch($kernel, 'GET', '/desktop/hub', '203.0.113.9')->getStatusCode(), 'the LAN is refused, not served by a half-loaded gate');
        $local = self::dispatch($kernel, 'GET', '/desktop/hub', '127.0.0.1');
        self::assertSame(200, $local->getStatusCode(), 'loopback still works — no 500 hiding the cause');
    }

    /**
     * @param array<string, string> $headers
     */
    private static function dispatch(Kernel $kernel, string $method, string $path, string $address, array $headers = [], ?string $body = null): ResponseInterface
    {
        $request = new ServerRequest($method, $path, $headers, $body, '1.1', $address === '' ? [] : ['REMOTE_ADDR' => $address]);

        return (new RequestHandler($kernel, new Psr17Factory()))->handle($request);
    }
}
