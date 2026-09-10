/*!
 * desktop-session-strip — the session row's own controls, as a client module.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * 🚨 THIS MODULE EXISTS BECAUSE THE STRIP'S CONTROLS WERE DEAD IN THE PANEL, and the reason is worth
 * keeping: `desktop-sidebar.js` wired them. Its docblock said why — «both surfaces call this module
 * rather than each carrying a copy» — which is sound reasoning about duplication and wrong about
 * ownership. The strip is the surface that PRINTS `[data-new-session]` and the picker; the sidebar
 * merely reached across to them because, in the page, it happened to be loaded.
 *
 * So in the admin panel, which paints the strip and has its own navigation, the sidebar's module never
 * loads and the strip shipped with a button that fired NOTHING. Measured by clicking it: no request at
 * all (greenhouse decisions/0273).
 *
 * A surface owns the controls it prints. That is the same rule that put `hidden` in the host's hands
 * and the session in the surface's (greenhouse decisions/0256, decisions/0268).
 *
 * AND «NEW SESSION» ASKS THE ROUTE, not another surface. The old handler called the AUTH OVERLAY's
 * `open()` — a component the panel does not paint either, so the button was broken twice over. Creating
 * a session is `POST /desktop/sessions`, which answers `{ok, id}`; identity is the door's business, and
 * a door that refuses answers with a status this module reports rather than guesses at.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live || typeof live.register !== 'function') {
    if (window.console && console.warn) { console.warn('[desktop-session-strip] the milpa/live runtime must load first'); }

    return;
  }
  if (live.desktop && live.desktop.sessionStrip) {
    if (window.console && console.warn) { console.warn('[desktop-session-strip] loaded twice; ignoring the second copy'); }

    return;
  }

  /**
   * Name a session in the CURRENT url and go there.
   *
   * The session lives in the URL (greenhouse evidence/0561) and this is host-agnostic on purpose: the
   * panel stays on `/milpa/admin/s/agent`, the standalone page stays on `/desktop`, and every other
   * query param a host put there survives. The old handler hardcoded `?session=<id>&embed=1`, which
   * named a mode that no longer exists and a path that is only one of the two hosts.
   */
  function open(id) {
    var url = new URL(window.location.href);
    url.searchParams.set('session', id);
    window.location.assign(url.toString());
  }

  /** Ask the route for a session, then go to it. A refusal is REPORTED, never swallowed. */
  function newSession() {
    return fetch('/desktop/sessions', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ goal: '' }),
    }).then(function (response) {
      if (!response.ok) { throw new Error('the session route answered HTTP ' + response.status); }

      return response.json();
    }).then(function (data) {
      if (!data || data.ok !== true || typeof data.id !== 'string' || data.id === '') {
        throw new Error('the session route answered without an id');
      }
      open(data.id);
    }).catch(function (failure) {
      // Said out loud: a control that fails silently is the defect this module was written to fix.
      if (window.console && console.error) { console.error('[desktop-session-strip] no session was created —', failure.message); }
      var bus = window.MilpaShell;
      if (bus && typeof bus.emit === 'function') { bus.emit('session.create_failed', { reason: failure.message }); }
    });
  }

  var buttons = document.querySelectorAll('[data-new-session]');
  for (var i = 0; i < buttons.length; i++) {
    buttons[i].addEventListener('click', function (event) {
      event.preventDefault();
      newSession();
    });
  }

  var picker = document.getElementById('milpa-embed-session');
  if (picker) {
    picker.addEventListener('change', function () {
      if (picker.value !== '') { open(picker.value); }
    });
  }

  if (live.desktop) { live.desktop.sessionStrip = { newSession: newSession, open: open }; }
})();
