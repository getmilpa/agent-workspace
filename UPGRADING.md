# Upgrading

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
