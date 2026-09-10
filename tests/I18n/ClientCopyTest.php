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
use Milpa\Eventing\EventDispatcher;
use Milpa\AgentWorkspace\Admin\AgentView;
use Milpa\AgentWorkspace\Live\Surfaces;
use Milpa\AgentWorkspace\Live\DesktopComponents;
use Milpa\AgentWorkspace\DesktopSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The copy the CLIENT says, against the copy the SERVER has (greenhouse decisions/0211, phase A4).
 *
 * A declared view keeps its behaviour in its own file, so the Desktop's human copy now leaves the server
 * twice: once as HTML a renderer wrote, and once as `tr('<key>')` inside eight separate `.js` files, fed
 * by the catalog the shell serializes into `#milpa-desktop-i18n`. Nothing in the PHP suite reads those
 * files and nothing in the node suite can read the catalog, so a key renamed on one side would print the
 * RAW KEY to a user with every gate green. That gap is what this closes.
 *
 * Two claims, both by reading the shipped artifacts and checking them against the running catalog:
 *
 *   1. every DOTTED LITERAL a shipped module carries is either a key the catalog answers, a signal the
 *      page seeds or computes, or one of the few declared names that are neither;
 *   2. the node harness's hand-typed `CATALOG` — the copy those tests assert sentences against — says
 *      exactly what the English catalog says. It had already drifted («The call failed (%s)» against the
 *      shipped «The request failed (HTTP %s)»), which is precisely a test asserting a sentence no user
 *      ever sees.
 *
 * (1) IS WIDER THAN IT WAS, and the widening is the point. The first parser here matched `\btr\('…'` and
 * therefore saw only a key written as the literal FIRST argument — so nine live keys were invisible to it:
 * `conn.live` / `conn.offline` / `conn.connecting` (the bus picks one into a variable and calls `tr(key)`),
 * `verdict.aria.verified` / `verdict.aria.disputed`, `command.mode.set` / `command.mode.set.auto`,
 * `command.goal.set` / `command.goal.unchanged` (each reached through a ternary INSIDE the call). Renaming
 * any of them in the catalog printed the raw key at a user with every gate green — exactly the failure this
 * test exists to stop. Reading every dotted literal instead means the parser cannot be walked around by
 * writing the call differently; the price is that the signals and the bus's fact types look the same, so
 * those are named — the signals READ OFF THE PAGE the shell serves, not hand-typed.
 */
final class ClientCopyTest extends TestCase
{
    /** The package's own root — the shipped files, not a fixture of them. */
    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * The dotted names a module carries that are NOT copy, each with the reason it is not.
     *
     * The signals are not here: they are read off the page the shell actually serves (`#milpa-live-signals`
     * and `#milpa-live-computed`), so a signal renamed on the server is a signal renamed here too. What is
     * left is what no server tag declares — the bus's fact TYPES, the two connection signals the bus alone
     * writes, and the one browser storage key.
     *
     * @var array<string, string> name => why it is not a catalog key
     */
    private const array NOT_COPY = [
        'agent.message' => 'a bus fact type (the hub republishes it, the thread renders it)',
        'agent.reasoning' => 'a bus fact type',
        'agent.thinking' => 'a bus fact type',
        'decision.parked' => 'a bus fact type (the sidebar ticks its badge, the inbox adds its card)',
        'gate.opened' => 'a bus fact type',
        'agent.answered' => 'a bus fact type (a decision taken anywhere closes the request on every surface)',
        'agent.parked' => 'a bus fact type (the turn stopped to ask; the thread renders the request)',
        'session.compacted' => 'a bus fact type (milpa/agent declares it; the thread draws its boundary)',
        'session.create_failed' => 'a bus fact type (the strip emits it when the session route refuses; a surface that shows it must translate its own copy)',
        'session.state' => 'a bus fact type',
        'system.notice' => 'a bus fact type',
        'task.added' => 'a bus fact type',
        'tool.call' => 'a bus fact type',
        'conn.state' => 'a signal the bus alone writes and the status bar binds — the page seeds no value',
        'conn.label' => 'idem: the connection has no state until the transport says one',
        'milpa.theme' => "the localStorage key the viewer's own theme preference is remembered under",
        // CONFIGURATION KEYS, not copy: the settings screen's one governed writer names them in the
        // body it posts to `config:set` (greenhouse decisions/0281). Its sibling `agent.baseUrl` is
        // here for the same reason and NOT because the parser demanded it — that name escapes the
        // pattern below on a capital letter alone, which is worth writing down: a parser that cannot
        // see a name will never complain about it.
        'agent.model' => 'the config key the model select writes through config:set',
        'agent.baseUrl' => 'the config key the endpoint field writes through config:set',
    ];

    /**
     * The signals the workspace DECLARES — read from the declared view, not scraped off a document.
     *
     * 🚨 IT USED TO RENDER THE PAGE AND REGEX ITS TWO SCRIPT TAGS. The page is retired, and the
     * substitution is better than the original: a `DeclaredView` carries `signals` and `computed` as
     * ARRAYS, which is what the host seeds those tags from — so this reads the source instead of the
     * host's rendering of it, and the two script tags become the host's business
     * (greenhouse decisions/0211, decisions/0283).
     */
    private static function signalsOfThePage(): array
    {
        $events = new EventDispatcher(new NullLogger());
        $catalog = new Catalog();
        $live = new DesktopComponents('signing', 'csrf', $events);
        (new Surfaces(null, $events, $catalog))->declareOn($live);

        $view = AgentView::of($live, new DesktopSettings(), $catalog, null, '', '');

        $names = [];
        foreach (['milpa-live-signals' => $view->signals, 'milpa-live-computed' => $view->computed] as $id => $declared) {
            self::assertIsArray($declared, $id . ' is an array of names');
            foreach (array_keys($declared) as $name) {
                $names[(string) $name] = $id;
            }
        }
        self::assertArrayHasKey('session.working', $names, 'the instrument read real signals');

        return $names;
    }

    public function testEveryDottedNameAModuleCarriesIsCopyTheCatalogAnswersOrADeclaredNonKey(): void
    {
        $catalog = new Catalog();
        $signals = self::signalsOfThePage();
        $modules = glob(self::root() . '/resources/components/*/*.js') ?: [];
        self::assertNotEmpty($modules, 'the instrument found no modules to read — it would pass on an empty package');

        $carried = [];
        foreach ($modules as $module) {
            $source = (string) file_get_contents($module);
            preg_match_all("/'([a-z][a-z0-9_]*(?:\\.[a-z0-9_]+)+)'/", $source, $matches);
            foreach ($matches[1] as $name) {
                $carried[$name][] = basename($module);
            }
        }

        // The positive control for the PARSER: if it stopped matching, every assertion below would pass
        // vacuously. It must find the keys the guard is built on AND the nine the old parser could not see.
        foreach ([
            'guard.forbidden', 'settings.saved',
            'conn.live', 'conn.offline', 'conn.connecting',
            'verdict.aria.verified', 'verdict.aria.disputed',
            'command.mode.set', 'command.mode.set.auto', 'command.goal.set', 'command.goal.unchanged',
        ] as $reached) {
            self::assertArrayHasKey($reached, $carried, '«' . $reached . '» is reached by a shipped module and the parser must see it');
        }
        self::assertGreaterThanOrEqual(40, \count($carried));

        foreach ($carried as $name => $files) {
            $where = implode(', ', array_unique($files));
            if (isset(self::NOT_COPY[$name]) || isset($signals[$name])) {
                self::assertFalse(
                    $catalog->has($name) && isset(self::NOT_COPY[$name]),
                    \sprintf('«%s» is declared a non-key but the catalog answers it — say which it is', $name),
                );
                continue;
            }
            self::assertTrue(
                $catalog->has($name),
                \sprintf('«%s» is carried by %s and is neither copy the catalog answers nor a declared non-key — a user would read it raw', $name, $where),
            );
        }

        // And the control for the CLAIM: a key nobody wrote is not answered, so `has()` discriminates.
        self::assertFalse($catalog->has('nobody.wrote.this'));
    }

    public function testTheNodeHarnessCopyOfTheCatalogSaysWhatTheCatalogSays(): void
    {
        $harness = self::root() . '/tests/js/support/page.mjs';
        self::assertFileExists($harness);
        $source = (string) file_get_contents($harness);

        self::assertSame(1, preg_match('/export const CATALOG = \{(.*?)\n\};/s', $source, $block), 'the harness still declares one CATALOG');
        preg_match_all("/^\s*'([^']+)':\s*'(.*)',$/m", $block[1], $entries, \PREG_SET_ORDER);
        self::assertNotEmpty($entries, 'the instrument read no entries — it would pass on an empty catalog');

        $catalog = new Catalog();
        foreach ($entries as [, $key, $value]) {
            self::assertTrue($catalog->has($key), \sprintf('the harness carries «%s», which the catalog does not', $key));
            self::assertSame(
                $catalog->tr($key),
                str_replace("\\'", "'", $value),
                \sprintf('the harness says something else for «%s» — a node test would assert a sentence no user reads', $key),
            );
        }
        self::assertGreaterThanOrEqual(7, \count($entries), 'the harness still carries the guard and settings copy');
    }

    /**
     * 🚨 THE OTHER DIRECTION, AND ITS ABSENCE WAS A CLAIM THIS FILE'S DOCBLOCK ALREADY MADE.
     *
     * The assertion above checks that every sentence the harness carries is the sentence the catalog
     * ships. It never checked that a key a module ASKS FOR is in the harness at all — so a module could
     * call `tr('settings.model.endpoint.refused')` and the node test asserting its message would be
     * comparing against the raw key, silently, forever. Which is exactly what happened while writing
     * `declareEndpoint`: the PHP suite stayed green and the node test failed with the key as its text
     * (greenhouse decisions/0280).
     *
     * A guard whose docblock promises a property it does not assert is worse than no guard: the promise
     * is what stops the next person from writing the check. This is the second time that pattern has
     * been caught here after the glyph guard, so the property is now the test's NAME.
     */
    public function testEveryKeyAModuleAsksForIsInTheHarnessCopyToo(): void
    {
        $catalog = new Catalog();
        $modules = glob(self::root() . '/resources/components/*/*.js') ?: [];
        self::assertNotEmpty($modules, 'the instrument found no modules to read');

        $harness = (string) file_get_contents(self::root() . '/tests/js/support/page.mjs');
        self::assertSame(1, preg_match('/export const CATALOG = \{(.*?)\n\};/s', $harness, $block));
        preg_match_all("/^\s*'([^']+)':/m", $block[1], $carried);
        $seeded = array_flip($carried[1]);
        self::assertNotEmpty($seeded);

        // 🚨 IT COLLECTS EVERY DOTTED NAME, NOT EVERY `tr('…')` CALL, AND THAT IS A CORRECTION.
        //
        // The first shape of this test read `tr\('([^']+)'` — the call sites. One refactor later, the
        // settings screen grew ONE governed writer taking its message keys as ARGUMENTS
        // (`declareConfig(key, value, okKey, failKey)`), and four keys went invisible to it the same
        // hour it was written: they are still literals in the module, just not inside a `tr(`.
        //
        // A parser that cannot see a name will never complain about it — which is the sentence this
        // file already carries about `agent.baseUrl` escaping on a capital letter. Twice in one slice.
        //
        // So the collection is the same as the sibling test's: every dotted name the module carries.
        // Filtering to the ones the CATALOG answers is what separates a message key from a bus fact
        // type or a config key, and it means an indirect key is covered exactly like a direct one.
        $asked = [];
        foreach ($modules as $module) {
            preg_match_all("/'([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)'/", (string) file_get_contents($module), $names);
            foreach ($names[1] as $name) {
                if ($catalog->has($name)) {
                    $asked[$name] = basename($module);
                }
            }
        }
        // The positive control for the PARSER: two keys it is known to have to see — one written at its
        // call site, one handed to the writer as an argument.
        self::assertArrayHasKey('guard.unreachable', $asked, 'a key at its call site');
        self::assertArrayHasKey('settings.model.endpoint.saved', $asked, 'a key passed to the governed writer as an argument');
        self::assertGreaterThanOrEqual(20, \count($asked));

        $missing = [];
        foreach ($asked as $key => $file) {
            if (!isset($seeded[$key])) {
                $missing[] = $key . ' (' . $file . ')';
            }
        }

        self::assertSame([], $missing, 'a key a module carries must be in the harness copy, or its node test asserts the key instead of the sentence');
    }
}
