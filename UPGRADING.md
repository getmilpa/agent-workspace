# Upgrading

## To 0.76

Three retirements, each measured byte-identical on a fresh app before it shipped (greenhouse `decisions/0388`,
`evidence/0705`, `evidence/0706`):

- **`AgentWorkspacePlugin::COMPONENTS` is gone.** It was a second list of what `Surfaces` and `DeepScreens`
  declare. Ask the plugin — `declaredComponents()` — or the declaring sites: `Surfaces::components()` and
  `DeepScreens::components()`.
- **The surfaces and the store are no longer registered in the container.** `Tabs`, `WorkBoard`, `Activity`,
  `Context`, `Gate`, `Thinking`, `AgentMessage`, `MessagePrototypes`, `Conversation`, `SessionStrip`, `ComposerBar`,
  `ComposerField` and `DesktopStore` were registered by `boot()` and resolved by nobody — no package in the family, no test, no
  documented door (greenhouse `evidence/0705`). `$container->get(Tabs::class)` now throws instead of handing back
  the shared instance, and **there is no replacement for holding a surface instance**: the registry
  (`DesktopComponents`) returns definitions and renderers, never the surface. A plugin that wants to extend a
  surface subscribes to its render events — `desktop.<surface>.before_render` / `after_render`, documented in the
  README — which is the door that always existed. The controllers (`MutationController`, `AssetsController`,
  `HubController`) stay registered: the router resolves them on every request.
- **`routes()` declares by verb** (`Route::get`/`Route::post` behind one door, `Route::behind`) and therefore
  **requires `milpa/http >= 0.5`**. The route table is the same; only the declaration ORDER changed (the four
  gated routes first, the public component assets last).

The metadata `version` now tracks the package version (`// x-release-please-version`); it read `0.1.0` before.

## From `milpa/desktop-app`

This package was renamed. Replace the requirement and the namespace:

```diff
-  "milpa/desktop-app": ">=0.54 <1.0"
+  "milpa/agent-workspace": ">=0.55 <1.0"
```

```diff
-use Milpa\DesktopApp\DesktopAppPlugin;
+use Milpa\AgentWorkspace\AgentWorkspacePlugin;
```

Every `Milpa\DesktopApp\…` class is now `Milpa\AgentWorkspace\…`, and `DesktopAppPlugin` is
`AgentWorkspacePlugin`. **Nothing else changed**: the routes, the components, the events and the behaviour are
byte-identical, and the suite that proves it is the same 297 tests.

**The capability id changed too**, because it was the loudest form of the lie:

```diff
-  id: desktop-app        provides: [desktop-shell, http-shell]
+  id: agent-workspace    provides: [agent-workspace]
```

## Why

The name said *desktop* and delivered *agent*. Measured when the decision was taken: this package declared twelve
routes and all of them were agent workspace, while `milpa/admin` — six weeks older — held the section contract,
the native sections, the gate, the settings and the i18n. And greenhouse `decisions/0210` was already titled
*"the Desktop as a guest of the admin"*: the inversion had been decided and built, and only the package names
still said otherwise.

So: **the panel is `milpa/admin`. This is a tenant of it, and it is opt-in** — an app has a panel without it.

**The routes stay `/desktop/*`** on purpose. `Desktop` is the product a human opens; `agent-workspace` is the
package that provides one of its sections. Both names are true now, which is the whole point of the rename.

See greenhouse `decisions/0220`.
