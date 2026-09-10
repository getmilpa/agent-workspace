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

use Milpa\AgentWorkspace\Data\DesktopData;
use Milpa\AgentWorkspace\Event\RenderEvents;
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * Renders the {@see StatusBarComponent} — the shell's bottom bar (greenhouse decisions/0211, phase D4).
 *
 * Three bindings and one fact. The connection binds `conn.label` and colours itself off `conn.state` —
 * the signals `MilpaShell.status()` writes, so the transport reports its state ONCE and every surface
 * that shows it binds instead of being poked by id. The counters bind the computed `session.status`
 * signal, the same truth the composer chips and the panels project. The model comes from the app's real
 * configuration ({@see DesktopData::model()}), not from a string typed into the template.
 *
 * Every span carries a SERVER-RENDERED seed, so the bar reads correctly before Alpine hydrates it.
 */
final class StatusBar
{
    public const string COMPONENT_ID = 'statusbar';

    /** Dispatched with a mutable {@see ComposerRender} BEFORE the render — a subscriber may change its props. */
    public const string BEFORE_RENDER = 'desktop.statusbar.before_render';

    /** Dispatched with a mutable {@see ComposerRender} AFTER the render — a subscriber may change its html. */
    public const string AFTER_RENDER = 'desktop.statusbar.after_render';

    /** The payload key both render events carry their mutable {@see ComposerRender} under. */
    public const string SUBJECT_KEY = 'statusbar';

    /** The package whose version this bar reports as its own. */
    private const string PACKAGE = 'milpa/agent-workspace';

    public function __construct(
        private readonly SignedXhtmlStateTransferCodec $codec,
        private readonly ?DesktopData $data = null,
        private readonly ?MilpaEventDispatcherInterface $events = null,
        private readonly ?Catalog $catalog = null,
    ) {
    }

    /**
     * The events `render()` dispatches, declared from the same constants it dispatches with (greenhouse decisions/0228).
     *
     * @return list<\Milpa\Interfaces\Event\EventDeclaration>
     */
    public static function events(): array
    {
        return RenderEvents::of(self::class, self::BEFORE_RENDER, self::AFTER_RENDER, self::SUBJECT_KEY, 'the status bar');
    }

    /** The bar, with its signed envelope, after the render events a plugin may extend it through. */
    public function render(): string
    {
        // ONE LABEL, SAID ONCE — this file carried the hardcoded name twice, in two methods
        // (greenhouse decisions/0266).
        $subject = new ComposerRender(['model' => ComposerBar::modelLabel($this->data?->model() ?? [], $this->catalog)]);
        $this->events?->dispatch(self::BEFORE_RENDER, [self::SUBJECT_KEY => $subject]);

        $state = (new StatusBarComponent())->mount($subject->props, new ComponentContext(componentId: self::COMPONENT_ID));
        $subject->html = $this->markup($subject->props) . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, [self::SUBJECT_KEY => $subject]);

        return $subject->html;
    }

    /** @param array<string, mixed> $props */
    private function markup(array $props): string
    {
        $catalog = $this->catalog ?? new Catalog();
        $model = \is_string($props['model'] ?? null) && $props['model'] !== '' ? (string) $props['model'] : $catalog->tr('model.undeclared');
        $counters = $this->data?->counters() ?? ['turns' => 0, 'steps' => 0, 'tokens' => 0, 'tool_calls' => 0, 'state' => 'idle'];
        $seed = \sprintf('%d turns · %d steps · %d tokens · %d tool calls', $counters['turns'], $counters['steps'], $counters['tokens'], $counters['tool_calls']);

        return '<div class="statusbar" data-milpa-component="desktop-statusbar" data-milpa-component-id="' . self::COMPONENT_ID . '">'
            . '<span class="statusbar__conn" x-data :class="{ \'statusbar__conn--live\': $store.milpa[\'conn.state\'] === \'live\' }" x-text="$store.milpa[\'conn.label\']">'
            . htmlspecialchars($catalog->tr('conn.connecting'), ENT_QUOTES) . '</span>'
            . '<span class="statusbar__model">' . htmlspecialchars($catalog->tr('statusbar.model', $model), ENT_QUOTES) . '</span>'
            . '<span class="statusbar__counters" x-data x-text="$store.milpa[\'session.status\']">' . htmlspecialchars($seed, ENT_QUOTES) . '</span>'
            . '<span class="statusbar__host">' . htmlspecialchars(self::host(), ENT_QUOTES) . '</span>'
            . '</div>';
    }

    /**
     * 🚨 THE MACHINE AND THE VERSION, ASKED — this line used to be a LITERAL.
     *
     * It was a constant naming somebody's laptop and a version belonging to nothing, painted in the
     * status bar of every installation. Its own docblock called it «the product's name, not copy»,
     * which is how it survived — nobody translates a product name, so nobody checked whether it was
     * true. The string is not quoted here on purpose: a test forbids it package-wide, and a test with
     * a carve-out for documentation is where the next real occurrence hides. A lie on a first screen is worse than a wrong value, because a wrong value invites
     * the question and a lie answers it (greenhouse evidence/0165, caught adopting this bar into the
     * panel in decisions/0270).
     *
     * `gethostname()` is the machine the app runs on, which is what a status bar means by host — not the
     * Host header, which is the name the BROWSER used to arrive. The version is composer's own runtime
     * answer, so it is the version actually installed rather than a constant somebody must remember to
     * bump. Either can be unavailable and the line simply drops that half.
     */
    private static function host(): string
    {
        $machine = gethostname();
        $version = class_exists(\Composer\InstalledVersions::class)
            ? \Composer\InstalledVersions::getPrettyVersion(self::PACKAGE)
            : null;

        $parts = [];
        if (\is_string($machine) && $machine !== '') {
            $parts[] = $machine;
        }
        if (\is_string($version) && $version !== '') {
            $parts[] = $version;
        }

        return implode(' · ', $parts);
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }
}
