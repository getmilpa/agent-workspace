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

use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\AgentWorkspace\Live\SettingsScreen;
use PHPUnit\Framework\TestCase;

/**
 * THE MODEL IS SAVED BY AN ACT A PERSON CAN PERFORM (greenhouse decisions/0542).
 *
 * The select saved only on `change`. When the provider serves ONE model, «Find models» leaves it as the only
 * option, already selected: there is nothing to change to, so a person could not save it. Rod's house kept no
 * `agent.model` after station 7b (evidence/1071); the rehearsal had saved it with a `selectOption` that fires
 * `change` — a thing no person can do with one option.
 */
final class SettingsSavesTheModelByAnActTest extends TestCase
{
    public function testTheModelFieldOffersAnExplicitSaveBesideTheSelect(): void
    {
        $html = $this->screen(new Catalog());

        self::assertMatchesRegularExpression(
            '~<select id="set-model"[^>]*>.*?</select></span>.*?<button type="button"[^>]*data-declare-model[^>]*@click="declareModel\(\)"[^>]*>Use this model</button>~s',
            $html,
        );
        // Changing the model still saves it; the button is the path that exists when there is nothing to change.
        self::assertStringContainsString('<select id="set-model" class="mui-select" @change="declareModel()">', $html);
    }

    public function testTheActSpeaksTheDeclaredLocale(): void
    {
        self::assertStringContainsString('>Usar este modelo</button>', $this->screen(new Catalog('es')));
    }

    private function screen(Catalog $catalog): string
    {
        return (new SettingsScreen(
            'test-settings-secret-0123456789',
            null,
            null,
            $catalog,
            static fn (): string => '',
            static fn (): bool => false,
        ))->render(hidden: false);
    }
}
