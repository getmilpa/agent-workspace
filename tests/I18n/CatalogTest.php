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

namespace Milpa\AgentWorkspace\Tests\I18n;

use Milpa\AgentWorkspace\I18n\Catalog;
use PHPUnit\Framework\TestCase;

/** The Desktop's copy: English by default, Spanish on request, the key itself when nobody wrote it. */
final class CatalogTest extends TestCase
{
    public function testEnglishIsTheDefaultAndSpanishIsAnOption(): void
    {
        self::assertSame(['en', 'es'], Catalog::locales());

        $en = new Catalog();
        self::assertSame('en', $en->locale());
        self::assertSame('Saved', $en->tr('settings.saved'));
        self::assertSame('gate: passkey', $en->tr('chip.gate', $en->tr('gate.kind.passkey')));
        self::assertSame('signed in as passkey:rod', $en->tr('topbar.signed_in', 'passkey:rod'));

        $es = new Catalog('es');
        self::assertSame('es', $es->locale());
        self::assertSame('Guardado', $es->tr('settings.saved'));
        self::assertSame('puerta: respaldo', $es->tr('chip.gate', $es->tr('gate.kind.fallback')));
        self::assertSame('sesión iniciada como passkey:rod', $es->tr('topbar.signed_in', 'passkey:rod'));

        self::assertSame('en', (new Catalog('fr'))->locale(), 'a locale the catalog lacks falls back to English');
    }

    public function testAnUnknownKeyAnswersAsItselfAndEveryEnglishKeyHasItsSpanish(): void
    {
        $en = new Catalog();
        self::assertSame('nobody.wrote.this', $en->tr('nobody.wrote.this'));
        self::assertFalse($en->has('nobody.wrote.this'));
        self::assertTrue($en->has('guard.forbidden'));

        $es = new Catalog('es');
        self::assertSame(array_keys($en->all()), array_keys($es->all()), 'the same keys in both, the client gets one complete map');
        // The words that are the same word: two gate kinds that are their own names, and «Endpoint», which
        // Mexican Spanish uses as-is. Anything else differing by accident is caught below.
        // «tokens» is the unit Mexican Spanish uses as-is, and the last two are pure ASSEMBLY — «%s → HTTP
        // %s — %s» and «%s (%s)» carry no words of their own; everything they say is in their arguments.
        $sameInBoth = [
            'gate.kind.loopback', 'gate.kind.passkey', 'settings.model.endpoint',
            'composer.tokens', 'op.failed', 'op.detail',
            // «API key» is the word this house uses in Spanish too, like «endpoint» below: the field
            // names a thing every provider's own docs call an API key, and «llave de API» would name
            // something the person is not looking for on their provider's dashboard.
            'settings.model.key',
            // A COMMAND IS NOT COPY. `coa capabilities:enable milpa/auth --sign` is what a person types,
            // and translating a command is telling them to type something that does not run. Both
            // ungovernable fields name the same capability, because both are gated by the same thing:
            // nothing in the app can say who may configure the agent (greenhouse decisions/0280).
            'settings.model.key.unjudgeable_command', 'settings.model.endpoint.unjudgeable_command',
            // «Skills» is the word this house uses in Spanish too — the same reason «endpoint» is
            // here. Translating it to «habilidades» would name a thing nobody in the project calls
            // that, and the screen it titles is the same screen in both languages.
            'nav.skills',
        ];
        foreach (array_keys($en->all()) as $key) {
            if (!\in_array($key, $sameInBoth, true)) {
                self::assertNotSame($en->tr($key), $es->tr($key), 'Spanish carries its own ' . $key);
            }
        }
        self::assertSame('Guardado', $es->all()['settings.saved']);
    }
}
