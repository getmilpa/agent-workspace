<?php

/**
 * This file is part of Milpa Desktop App — the local agent workspace of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/agent-workspace
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Tests\Live;

use Milpa\AgentWorkspace\Admin\AgentViewComponent;
use Milpa\AgentWorkspace\AgentWorkspacePlugin;
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\AgentWorkspace\Live\ComposerMessageComponent;
use Milpa\AgentWorkspace\Live\DeepScreens;
use Milpa\AgentWorkspace\Live\DesktopComponents;
use Milpa\AgentWorkspace\Live\SettingsScreen;
use Milpa\AgentWorkspace\Live\Surfaces;
use Milpa\Container\DIContainer;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The declaration IS the shell: what the plugin tells the catalogue is read from the sites that declare.
 *
 * It used to be two lists — `AgentWorkspacePlugin::COMPONENTS` for the catalogue, `Surfaces` for the
 * paint — measured against each other here, one half by a regex over the source. Two hand-kept lists of
 * the same fact is a lie waiting to happen, and a test that compares them is a defence, not a design
 * (greenhouse decisions/0388). Now {@see Surfaces::components()} and {@see DeepScreens::components()}
 * are the keys of the very maps `declareOn()` walks, and the plugin composes them.
 *
 * So this asks BY EXECUTION — the house's rule, and the form of it that has paid most: if you are about
 * to classify something by reading it, run it instead (greenhouse decisions/0268) — that the list each
 * class publishes is exactly what it registers on a real registry, and that the plugin's whole
 * declaration adds only the two components that enter no registry: {@see ComposerMessageComponent},
 * registered by `DesktopComponents` itself under the name `textarea`, and {@see AgentViewComponent},
 * built inside `AgentView::of()` for the admin's guest section.
 */
#[CoversClass(AgentWorkspacePlugin::class)]
#[CoversClass(Surfaces::class)]
#[CoversClass(DeepScreens::class)]
final class DeclarationMatchesTheShellTest extends TestCase
{
    public function testTheSurfacesPublishExactlyWhatTheyRegister(): void
    {
        $live = self::registry();
        $before = $live->names();
        (new Surfaces())->declareOn($live);

        self::assertSame(
            self::namesOf(Surfaces::components()),
            array_values(array_diff($live->names(), $before)),
            'the list Surfaces publishes and the names it registered are the same map, in the same order',
        );
    }

    public function testTheDeepScreensPublishExactlyWhatTheyRegisterWhenTheHostHasEveryInstance(): void
    {
        $live = self::registry();
        $before = $live->names();
        // With the Settings instance a real host hands it: that screen is declared BY INSTANCE, because it
        // needs a signing secret and both doors already have one built.
        DeepScreens::declareOn($live, null, null, new Catalog(), settings: new SettingsScreen('test-settings-secret-0123456789'));

        self::assertSame(self::namesOf(DeepScreens::components()), array_values(array_diff($live->names(), $before)));
    }

    public function testWithoutASettingsInstanceTheDeepScreensStillPublishSettingsButDoNotRegisterIt(): void
    {
        $live = self::registry();
        $before = $live->names();
        DeepScreens::declareOn($live, null, null, new Catalog());

        $registered = array_values(array_diff($live->names(), $before));
        self::assertNotContains('desktop-settings', $registered);
        self::assertSame(array_values(array_diff(self::namesOf(DeepScreens::components()), ['desktop-settings'])), $registered);
    }

    public function testEveryDeclaredClassIsAComponentDefinition(): void
    {
        $declared = self::plugin()->declaredComponents();
        self::assertNotSame([], $declared, 'this would prove nothing with nothing declared');

        foreach ($declared as $class) {
            self::assertTrue(class_exists($class), $class . ' is declared but does not exist');
            self::assertTrue(is_subclass_of($class, ComponentDefinitionInterface::class), $class . ' is not a component');
        }
        self::assertSame($declared, array_values(array_unique($declared)), 'a definition declared twice is two rows for one fact');
    }

    public function testTheTwoComponentsOutsideTheShellAreTheComposerFieldAndTheGuestView(): void
    {
        $live = self::registry();
        $before = $live->names();
        (new Surfaces())->declareOn($live);
        DeepScreens::declareOn($live, null, null, new Catalog(), settings: new SettingsScreen('test-settings-secret-0123456789'));
        $painted = array_values(array_diff($live->names(), $before));

        $unpainted = [];
        foreach (self::plugin()->declaredComponents() as $class) {
            if (!\in_array($class::contract()->name, $painted, true)) {
                $unpainted[] = $class;
            }
        }

        self::assertSame([ComposerMessageComponent::class, AgentViewComponent::class], $unpainted);
    }

    private static function plugin(): AgentWorkspacePlugin
    {
        return new AgentWorkspacePlugin(new DIContainer());
    }

    private static function registry(): DesktopComponents
    {
        return new DesktopComponents('test-signing-secret-0123456789', 'test-csrf-secret-0123456789');
    }

    /**
     * @param list<class-string<ComponentDefinitionInterface>> $classes
     *
     * @return list<string>
     */
    private static function namesOf(array $classes): array
    {
        return array_map(static fn (string $class): string => $class::contract()->name, $classes);
    }
}
