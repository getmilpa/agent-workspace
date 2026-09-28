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

use Milpa\AgentWorkspace\Controllers\PanelDoorController;
use PHPUnit\Framework\TestCase;

/**
 * greenhouse decisions/0498: installing the room grows its installer by what operating the room takes — reading
 * the agent's sessions and answering what they ask — and by nothing else. The runtime reads this from the
 * manifest that ships; so does this test.
 */
final class TheRoomDeclaresWhatItsOperatorNeedsTest extends TestCase
{
    /** Reading the sessions, and the answer the panel's own door runs. */
    public function testTheOperatorScopesAreReadingAndAnswering(): void
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__) . '/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        self::assertSame(['agent:read', PanelDoorController::ANSWER], $manifest['extra']['milpa']['capability']['operator_scopes'] ?? null);
    }
}
