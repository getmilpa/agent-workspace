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
use Milpa\AgentWorkspace\Live\ComposerMessageComponent;
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\AgentWorkspace\Live\DeepScreens;
use Milpa\AgentWorkspace\Live\DesktopComponents;
use Milpa\AgentWorkspace\Live\SettingsScreen;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The declaration and the shell are two lists, so they are measured against each other.
 *
 * `AgentWorkspacePlugin::COMPONENTS` tells the catalogue what this plugin brings; `Surfaces`
 * paints them. Two hand-kept lists of the same fact is a lie waiting to happen — and the lie would
 * be the exact defect greenhouse decisions/0213 names: a catalogue reporting a capability that is
 * not wired. So every surface the shell declares must appear in the declaration.
 *
 * They are NOT identical, and the two exceptions are asserted by name so they stay decisions rather
 * than drift: {@see ComposerMessageComponent} enters through `DesktopComponents` instead, registered
 * under `ComposerField::COMPONENT` — the name `textarea`, which is why the catalogue reports that
 * name as declared by two hosts; and {@see AgentViewComponent} is built inside `AgentView::of()` for
 * the admin's guest section and never reaches a registry at all.
 */
#[CoversClass(AgentWorkspacePlugin::class)]
final class DeclarationMatchesTheShellTest extends TestCase
{
    public function testEverySurfaceTheShellPaintsIsDeclared(): void
    {
        $painted = $this->componentsTheShellDeclares();
        self::assertNotSame([], $painted, 'the shell declares no surfaces — this test would pass vacuously');

        $declared = [];
        foreach (AgentWorkspacePlugin::COMPONENTS as $class) {
            $declared[] = $class::contract()->name;
        }

        foreach ($painted as $name) {
            self::assertContains($name, $declared, $name . ' is painted by the shell but declared to nobody');
        }
    }

    public function testEveryDeclaredClassIsAComponentDefinition(): void
    {
        foreach (AgentWorkspacePlugin::COMPONENTS as $class) {
            self::assertTrue(class_exists($class), $class . ' is declared but does not exist');
            self::assertTrue(is_subclass_of($class, ComponentDefinitionInterface::class), $class . ' is not a component');
        }
    }

    public function testTheTwoComponentsOutsideTheShellAreTheComposerFieldAndTheGuestView(): void
    {
        $painted = $this->componentsTheShellDeclares();

        $unpainted = [];
        foreach (AgentWorkspacePlugin::COMPONENTS as $class) {
            $name = $class::contract()->name;
            if (!\in_array($name, $painted, true)) {
                $unpainted[] = $class;
            }
        }

        self::assertSame([ComposerMessageComponent::class, AgentViewComponent::class], $unpainted);
    }

    /**
     * Every component name the page's two declaration sites register.
     *
     * 🚨 THE DEEP SCREENS ARE ASKED BY EXECUTION, not by reading a file. {@see DeepScreens::declareOn()}
     * takes a registry and needs no request, so this half runs it against a real
     * {@see DesktopComponents} and reads back what it actually declared — the house's own rule, and the
     * form of it that has paid most: if you are about to classify something by reading it, run it
     * instead (greenhouse decisions/0268).
     *
     * The shell's own half is still read from the source, and that stays deliberate: booting the
     * controller needs a request, a container and a session, and what this test asks about is the LIST,
     * not the paint.
     *
     * @return list<string>
     */
    private function componentsTheShellDeclares(): array
    {
        $live = new DesktopComponents('test-signing-secret-0123456789', 'test-csrf-secret-0123456789');
        $before = $live->names();
        // With the Settings instance a real host hands it: that screen is declared BY INSTANCE, because
        // it needs a signing secret and both doors already have one built. Calling without it would
        // leave this half of the gate blind to the screen that most needed it.
        DeepScreens::declareOn($live, null, null, new Catalog(), settings: new SettingsScreen('test-settings-secret-0123456789'));
        $executed = array_values(array_diff($live->names(), $before));
        self::assertNotSame([], $executed, 'the extracted list declares nothing — this half would prove nothing');

        // 🚨 THE DECLARATION LIST IS READ FROM `Surfaces`, NOT FROM A CONTROLLER. It used to be
        // `src/Controllers/ShellController.php` — which is exactly how the coupling hid: the panel's
        // surfaces were declared by the page's controller, so a census of the page's source was also a
        // census of the panel's (greenhouse decisions/0283).
        $source = file_get_contents(\dirname(__DIR__, 2) . '/src/Live/Surfaces.php');
        self::assertIsString($source);

        // 🚨 THE PATTERN CROSSES A NEWLINE, because a census that depends on formatting is a census that
        // lies the first time somebody wraps a line. Moving one declaration onto three lines made this
        // report the component as «declared by nobody» (greenhouse decisions/0283).
        preg_match_all('/declare\(\s*new (\w+)\(/', $source, $matches);

        $names = [];
        foreach ($matches[1] as $short) {
            $class = 'Milpa\\AgentWorkspace\\Live\\' . $short;
            if (class_exists($class) && is_subclass_of($class, ComponentDefinitionInterface::class)) {
                $names[] = $class::contract()->name;
            }
        }

        return array_values(array_unique([...$executed, ...$names]));
    }
}
