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
 * THE ROW IS RE-READ FROM THE HOUSE (greenhouse decisions/0609, I4; decisions/0563). It is painted from the
 * sessions the ledger holds when the page loads, and a person's first turn is what OPENS hers — so the page that
 * ran it said «No session open» over the conversation (evidence/1175 §6). The turn asks for the region when it
 * comes back; what this module owes it is that the strip which arrives is wired like the one that left, and that
 * a picker somebody has in hand is not replaced under it.
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
    return fetch('/workspace/sessions', {
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

  /** The region the server prints the row in, and how a surface says a human is acting inside one. */
  var STRIP_REGION = 'session.strip';
  var BUSY = 'data-busy';

  function regions() { return (live.desktop && live.desktop.regions) || null; }

  /**
   * Wire the controls of ONE printed strip — the one the page loaded with, and each one a re-read brings: a
   * swapped button that fires nothing is the defect this module was written to fix, a second time.
   */
  function wire(root) {
    var buttons = root.querySelectorAll('[data-new-session]');
    for (var i = 0; i < buttons.length; i++) {
      buttons[i].addEventListener('click', function (event) {
        event.preventDefault();
        newSession();
      });
    }

    var picker = root.querySelector('#milpa-embed-session');
    if (!picker) { return; }
    picker.addEventListener('change', function () {
      if (picker.value !== '') { open(picker.value); }
    });
    // IN SOMEBODY'S HAND: an open picker replaced under it closes on what they were choosing. The regions'
    // reader puts a region with a busy element off, and reads it when the surface says they are done.
    picker.addEventListener('focus', function () { picker.setAttribute(BUSY, ''); });
    picker.addEventListener('blur', function () {
      picker.removeAttribute(BUSY);
      var r = regions();
      if (r && typeof r.resume === 'function') { r.resume(); }
    });
  }

  wire(document);
  var reader = regions();
  if (reader && typeof reader.keep === 'function') { reader.keep(STRIP_REGION, function (shown, printed) { wire(printed); }); }

  if (live.desktop) { live.desktop.sessionStrip = { newSession: newSession, open: open }; }
})();
