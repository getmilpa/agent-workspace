/*!
 * desktop-settings — the Desktop's Settings screen, as a client module (greenhouse decisions/0211, phase B7).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by the screen's renderer through DeclaresClientAssets and emitted, once, by `LiveBoot::html()`.
 * It registers ONE Alpine factory, `desktopSettings`, bound by the screen with
 * `x-data="desktopSettings()"`.
 *
 *   - `save()` posts the form to `POST /desktop/settings` THROUGH THE GUARD and reports what the door
 *     answered: green «Saved» ONLY on a 2xx, a warning naming the status on anything else, and a 401
 *     leaves for sign-in and comes back rather than painting a badge over a page that is going away
 *     (greenhouse decisions/0209). The report is the shared `settings.saved` signal, so the badge is a
 *     BINDING and nothing pokes its text, its class or its hidden.
 *   - `discard()` reloads, which is what "discard" means when the server holds the values.
 *   - `setTheme()` / `isTheme()` are the SHARED `ui.theme` signal the topbar's module owns — these three
 *     buttons and the window chrome's toggle set one value, so they can never disagree.
 *
 * The fields are read from the component's own root, never from the document: this screen owns them.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live || typeof live.register !== 'function') {
    if (window.console && console.warn) { console.warn('[desktop-settings] the milpa/live runtime must load first'); }

    return;
  }
  if (live.registered('desktopSettings')) {
    if (window.console && console.warn) { console.warn('[desktop-settings] loaded twice; ignoring the second copy'); }

    return;
  }

  /** The shared signal the save badge is: `{ok, text}` while a save is being reported, else null. */
  var SAVED_SIGNAL = 'settings.saved';
  /** The shared signal the theme is — the topbar's module owns the rule, this screen only sets it. */
  var THEME_SIGNAL = 'ui.theme';
  /** How long a report stands: long enough to read, short enough not to linger. */
  var HOLD_OK_MS = 2000;
  var HOLD_FAILED_MS = 4000;

  function desk() { return live.desktop || null; }
  function tr(key, arg) { var d = desk(); return d ? d.tr(key, arg) : key; }

  live.register('desktopSettings', function () {
    return {
      /** The save report, or null when there is nothing to report. */
      get saved() {
        return this.$store.milpa[SAVED_SIGNAL] || null;
      },
      /** Whether the report is a success — the badge's colour is a binding on it. */
      get savedOk() {
        var report = this.saved;

        return !report || report.ok !== false;
      },
      /** What the badge says: the door's own answer, in the declared locale. */
      get savedText() {
        var report = this.saved;

        return report ? String(report.text || '') : '';
      },
      /** Report a save (or its failure) and clear the report after it has been read. */
      report: function (ok, text) {
        var self = this;
        this.$store.milpa[SAVED_SIGNAL] = { ok: !!ok, text: String(text || '') };
        setTimeout(function () { self.$store.milpa[SAVED_SIGNAL] = null; }, ok ? HOLD_OK_MS : HOLD_FAILED_MS);
      },
      /**
       * The form as the writer takes it — and it is ONE field now.
       *
       * 🚨 IT USED TO POST FOUR, AND THREE OF THEM WERE READ BY NOBODY. `endpoint` went into the
       * settings blob while the agent read `agent.baseUrl` from the governed configuration; `stream`
       * and `compact` had no reader anywhere in the package. The Save button reported success for all
       * of them (greenhouse decisions/0280).
       *
       * What is left is `mode`, which the composer's chip, the topbar and the seeded signals all read.
       * The endpoint has its own verb, because writing it is a governed act.
       */
      values: function () {
        var root = this.$root || document;
        var mode = root.querySelector('input[name="set-mode"]:checked');

        return { mode: mode ? mode.value : 'ask' };
      },
      /** Persist. «Saved» is the DOOR's answer, never this screen's assumption. */
      save: function () {
        var d = desk();
        if (!d) { return Promise.reject(new Error('desktop-guard not loaded')); }
        var self = this;

        return fetch('/desktop/settings', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(this.values()),
        }).then(d.guarded).then(function () {
          self.report(true, tr('settings.saved'));
        }).catch(function (err) {
          self.report(false, tr('settings.save_failed', (err && err.status) || 0));
        });
      },
      /**
       * 🚨 THE KEY GOES THROUGH ITS OWN OPERATION, NEVER THROUGH `save()`.
       *
       * `POST /desktop/settings` writes `.milpa/desktop-settings.json`, and on a real app's own
       * `.gitignore` — checked on a fresh repository with the template's rules — THAT FILE IS
       * COMMITTED. A key riding along with the endpoint and the theme would be a key in somebody's git
       * history (greenhouse decisions/0276).
       *
       * `provider:declare` writes it where the code reads it and git does not, and demands identity to
       * do so — two same-origin POSTs once wrote a credential with no session at all until that was
       * closed (greenhouse decisions/0274). The confirm gate's 428 is part of that flow, which is why
       * this uses `guardedFlow` and carries the token back.
       *
       * THE INPUT IS CLEARED WHETHER IT WORKED OR NOT: a key left sitting in a field is a key in the
       * next screenshot, and a key that failed to save is not a key worth keeping around to retry
       * blind.
       */
      declareKey: function () {
        var d = desk();
        if (!d) { return Promise.reject(new Error('desktop-guard not loaded')); }
        var self = this;
        var input = document.getElementById('set-key');
        var value = input ? String(input.value || '') : '';
        if (value === '') { return Promise.resolve(); }

        var send = function (token) {
          var headers = { 'Content-Type': 'application/json' };
          if (token) { headers['Confirm-Token'] = token; }

          return fetch('/provider/declare', {
            method: 'POST',
            headers: headers,
            body: JSON.stringify({ key: 'agent.apiKey', value: value }),
          });
        };

        return send(null).then(d.guardedFlow).then(function (r) {
          if (r.status !== 428) { return r; }

          return r.json().then(function (body) { return send(body && body.confirm_token).then(d.guarded); });
        }).then(function () {
          if (input) { input.value = ''; }
          self.report(true, tr('settings.model.key.saved'));
        }).catch(function (err) {
          if (input) { input.value = ''; }
          self.report(false, tr('settings.model.key.refused', (err && err.status) || 0));
        });
      },
      /**
       * 🚨 THE AGENT'S ADDRESS GOES THROUGH `config:set`, NEVER THROUGH `save()`.
       *
       * `agent.baseUrl` is where every prompt and every piece of context this app sends will go. Two
       * same-origin POSTs once moved it with no session at all, WITH a policy registered, because
       * `config:set` declared no scope for the policy to match; it declares `config:write` now and the
       * framework refuses at boot to publish it unjudged (greenhouse decisions/0278, decisions/0279).
       *
       * Which is exactly why it cannot ride in `POST /desktop/settings`: that door writes a file, and
       * the agent does not read that file. The screen was posting to a place nothing read.
       *
       * The confirm gate's 428 is part of a consent-demanding operation's flow, so this carries the
       * token back the way the key does. The field is NOT cleared: unlike a key, an endpoint is
       * something you want to still see after saving it.
       */
      declareEndpoint: function () {
        var d = desk();
        if (!d) { return Promise.reject(new Error('desktop-guard not loaded')); }
        var self = this;
        var input = document.getElementById('set-end');
        var value = input ? String(input.value || '') : '';
        if (value === '') { return Promise.resolve(); }

        var send = function (token) {
          var headers = { 'Content-Type': 'application/json' };
          if (token) { headers['Confirm-Token'] = token; }

          return fetch('/config/set', {
            method: 'POST',
            headers: headers,
            body: JSON.stringify({ key: 'agent.baseUrl', value: value }),
          });
        };

        return send(null).then(d.guardedFlow).then(function (r) {
          if (r.status !== 428) { return r; }

          return r.json().then(function (body) { return send(body && body.confirm_token).then(d.guarded); });
        }).then(function () {
          self.report(true, tr('settings.model.endpoint.saved'));
        }).catch(function (err) {
          self.report(false, tr('settings.model.endpoint.refused', (err && err.status) || 0));
        });
      },
      /** Discard: the persisted values are the server's, so reloading IS the discard. */
      discard: function () {
        location.reload();
      },
      /** Choose the shell's theme — the same value the window chrome's toggle sets. */
      setTheme: function (choice) {
        var d = desk();
        if (d && d.theme && typeof d.theme.set === 'function') { d.theme.set(choice); }
      },
      /** Whether `choice` is the theme in effect — read INSIDE the effect, so aria-pressed stays reactive. */
      isTheme: function (choice) {
        return this.$store.milpa[THEME_SIGNAL] === choice;
      },
    };
  });
})();
