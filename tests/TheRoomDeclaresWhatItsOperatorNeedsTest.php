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
 * the agent's sessions, answering what they ask, driving a turn (composer, goal, skills, mode: `agent:run`) and
 * declaring the model it runs on (Settings, the model chip: `config:write`), as greenhouse evidence/1030 measured
 * the panel's doors to need — and by nothing else. Saving a provider key (`provider:declare`) is NOT here: a
 * secret is a separate decision. The runtime reads this from the manifest that ships; so does this test.
 */
final class TheRoomDeclaresWhatItsOperatorNeedsTest extends TestCase
{
    /** Reading, answering, driving and declaring the model — never a provider's secret. */
    public function testTheOperatorScopesAreWhatThePanelDoorsNeed(): void
    {
        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__) . '/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        $scopes = $manifest['extra']['milpa']['capability']['operator_scopes'] ?? null;
        self::assertSame(['agent:read', PanelDoorController::ANSWER, 'agent:run', 'config:write'], $scopes);
        self::assertNotContains('provider:declare', $scopes, 'a provider key is its own decision (greenhouse 0498)');
    }
}
