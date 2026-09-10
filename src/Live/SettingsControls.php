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

namespace Milpa\AgentWorkspace\Live;

/**
 * EVERY CONTROL THE SETTINGS SCREEN OFFERS, AND WHO READS IT — the correspondence, declared.
 *
 * 🚨 THIS CLASS EXISTS BECAUSE SIX OF NINE CONTROLS ON THAT SCREEN WERE NOT CONNECTED TO ANYTHING,
 * AND THE SCREEN SAID «Saved» FOR ALL OF THEM. Measured by execution on fresh cattle, not read
 * (greenhouse decisions/0280):
 *
 *   - the Endpoint field saved to `.milpa/desktop-settings.json`, then READ ITS OWN SAVED VALUE BACK,
 *     so the form looked like it had worked — while the agent kept answering `endpoint_from: none`,
 *     because the agent reads `agent.baseUrl` from the governed configuration;
 *   - the Provider select was read by NOBODY, not even by the writer that posts the form;
 *   - «Show streaming tokens» and «Compact context automatically» were written and read by nobody in
 *     the package, and `agent.compaction` is not a switch at all — it is a policy of three numbers;
 *   - the autonomy radios were saved and DO reach the composer's chip, but the screen printed `ask`
 *     checked no matter what was stored, so it forgot your choice on the next render;
 *   - the three Interface-scale buttons carried no verb whatsoever.
 *
 * A control that reports success and changes nothing is worse than a missing feature: a missing
 * feature is visible. This is the user-facing form of the unwired-piece debt the census names
 * (greenhouse decisions/0213) — and the most expensive form of it, because a person acts on it.
 *
 * SO THE RULE IS: a control is offered only when this map says who reads it, and a test renders the
 * screen and refuses any control that is not here. The map is the contract; the test is what keeps
 * the contract from becoming prose.
 */
final class SettingsControls
{
    /**
     * Control (its `id`, or the `data-*` verb that stands for a group) => the reader that answers for it.
     *
     * The reader side is a SENTENCE ABOUT WHERE THE VALUE GOES, and the three kinds are different
     * promises:
     *   - `config:<key>` — a governed operation writes it and the framework reads it. The screen never
     *     writes the file: `config:set` demands consent and declares `config:write`, so it goes through
     *     the same guarded flow as the key (greenhouse decisions/0278).
     *   - `store:<key>` — the Desktop's own settings file, for what only this UI reads.
     *   - `op:<name>` — its own governed operation, because the value must not ride in the settings blob.
     *   - `signal:<name>` — a shared client signal some other module applies.
     *   - `readonly` — printed to be read by a person, never written.
     *
     * @var array<string, string>
     */
    public const array READERS = [
        // Where the agent talks. `AgentEndpoint::baseUrl()` is what every turn resolves, so this is the
        // one address that matters and the only one the screen shows.
        'set-end' => 'config:agent.baseUrl',
        // The provider credential — never in the settings blob, which a real app COMMITS.
        'set-key' => 'op:provider:declare',
        // The default autonomy the composer's chip, the topbar and the seeded signals all read.
        'set-mode' => 'store:mode',
        // Where sessions are written. A person needs to know it; nothing here changes it.
        'set-path' => 'readonly',
        // The shell's theme, owned by the topbar's module and applied to the document root.
        'theme-set' => 'signal:ui.theme',
    ];

    /**
     * Whether this control may be printed at all — asked by {@see SettingsScreen} BEFORE it renders one.
     *
     * 🚨 THIS IS WHY THIS CLASS IS NOT A DOCUMENT. It shipped for an hour as a map that only a test
     * read, and the house's own unwired-piece gate named it within that hour: «classes +1 … graduate it
     * or retire it» (greenhouse decisions/0213). A correspondence that only a test consults is prose
     * with a checker, and prose drifts — which is precisely the failure this whole slice is about.
     *
     * Asked at RENDER time, the map is load-bearing: delete an entry and the control LEAVES THE PAGE,
     * rather than a test turning red while the screen keeps offering it. That is the difference between
     * a contract and a comment.
     */
    public static function offered(string $control): bool
    {
        return isset(self::READERS[$control]);
    }

    /*
     * NO HAY `reader(string $control)` AQUÍ, Y ES UNA DECISIÓN.
     *
     * La escribí junto con `offered()` y nadie la llamaba más que su propio test — que sería el CUARTO
     * accesorio-que-nadie-pidió de esta familia, después de `InstalledPackages::version()`,
     * `ProviderReach::declared()` y `ProviderReach::endpoint()`. Es un hábito, no un accidente
     * (greenhouse decisions/0213).
     *
     * La constante es pública: quien necesite la ORACIÓN la lee de `READERS`. Lo que el render necesita
     * es una pregunta de sí o no, y eso es lo único que este objeto contesta.
     */
}
