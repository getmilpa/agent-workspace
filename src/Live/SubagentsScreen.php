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

namespace Milpa\AgentWorkspace\Live;

use Milpa\AgentWorkspace\Data\DesktopData;
use Milpa\AgentWorkspace\Event\RenderEvents;
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Live\Security\SignedXhtmlStateTransferCodec;
use Milpa\Live\ValueObjects\ComponentContext;
use Milpa\Live\ValueObjects\StateSnapshot;

/**
 * The Subagents screen: the specialist agents this app declares.
 *
 * Split out of the Skills screen, which named two subjects under one title — what the agent CARRIES
 * and WHO ELSE it can hand work to. {@see SubagentsScreenComponent} carries the reasoning; the roles
 * list itself is {@see RolesView}, unchanged and now with a screen of its own
 * (greenhouse decisions/0268).
 */
final class SubagentsScreen
{
    /** The dom id of the screen's root and of its state envelope. */
    public const string COMPONENT_ID = 'subagents';

    /** Dispatched with the props before the screen renders — a subscriber may change them. */
    public const string BEFORE_RENDER = 'desktop.subagents.before_render';

    /** Dispatched with the HTML after the screen renders — a subscriber may change it. */
    public const string AFTER_RENDER = 'desktop.subagents.after_render';

    /** The key the mutable subject rides under in both events. */
    public const string SUBJECT_KEY = 'subagents';

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
        return RenderEvents::of(self::class, self::BEFORE_RENDER, self::AFTER_RENDER, self::SUBJECT_KEY, 'the Subagents screen');
    }

    /**
     * The screen, with its signed envelope, after the render events a plugin may extend it through.
     *
     * `$hidden` is the host's call, not this screen's — see {@see ScreenVisibility}.
     */
    public function render(bool $hidden = true): string
    {
        $subject = new ComposerRender([
            'roles' => $this->data?->roles() ?? [],
            'hidden' => $hidden,
        ]);
        $this->events?->dispatch(self::BEFORE_RENDER, [self::SUBJECT_KEY => $subject]);

        $state = (new SubagentsScreenComponent())->mount($subject->props, new ComponentContext(componentId: self::COMPONENT_ID));
        $subject->html = $this->markup($subject->props) . $this->envelope($state);

        $this->events?->dispatch(self::AFTER_RENDER, [self::SUBJECT_KEY => $subject]);

        return $subject->html;
    }

    /** @param array<string, mixed> $props */
    private function markup(array $props): string
    {
        /** @var list<array{name: string, produces: string, deny: list<string>, skills: list<string>}> $roles */
        $roles = \is_array($props['roles'] ?? null) ? $props['roles'] : [];

        return '<div class="view milpa-skills" data-view="subagents"'
            . ' data-milpa-component="desktop-subagents" data-milpa-component-id="' . self::COMPONENT_ID . '"'
            . ScreenVisibility::attr($props) . '>'
            . '<p class="milpa-skills__intro">' . $this->tr('subagents.intro') . '</p>'
            . '<div id="milpa-roles">' . (new RolesView())->html($roles) . '</div>'
            . '</div>';
    }

    private function envelope(StateSnapshot $state): string
    {
        return '<script type="application/milpa+xhtml" data-milpa-state="' . self::COMPONENT_ID . '">' . $this->codec->encodeState($state) . '</script>';
    }

    /** One catalog message, escaped for the markup. */
    private function tr(string $key): string
    {
        return htmlspecialchars(($this->catalog ?? new Catalog())->tr($key), ENT_QUOTES);
    }
}
