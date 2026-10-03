/*!
 * desktop-regions — a region of the page re-reads itself from the house (greenhouse decisions/0563).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * NOT a component: like the guard, the bus and the hub it is a RUNTIME module the page declares.
 *
 * What the house decides is DERIVED on the server. A seat's frontier is the refused calls read against the
 * enrollment ledger and the authoring policy; the verdict is read off the whole stream; a parked question's
 * card carries the session it answers. A pushed fact is narrower than any of them — it says «a call was
 * refused», never «this is the card» — so a surface that built its card from the push would be a second
 * derivation, and the first one to drift. Measured (greenhouse evidence/1095): a window that received 1,209
 * pushes showed the frontier, the question's card and «Verified by the house» only after a reload.
 *
 * So the push says THAT something changed and the region asks the house for itself again: it requests the
 * page it is on — the request a reload makes, behind the same door — and swaps in the regions the server
 * marked `data-live-region="<name>"`. Nothing here builds markup.
 *
 *   - asks made in the same tick are ONE request; an ask that lands while the page is being read is read
 *     once more after, never dropped;
 *   - a region a human is acting in (`[data-busy]` inside it) is not swapped under them: it is deferred,
 *     and `resume()` reads it when they finish;
 *   - a page that does not carry the region — the sign-in lapsed, the house answered an error — leaves what
 *     is shown and marks it `data-live-stale`; it never blanks what somebody was reading;
 *   - `signals` is a region too: the session's own figures (turns, tools, context) are re-seeded from the
 *     fresh page. Where the human is standing — the tab, the open panel, the draft — is never re-read.
 *
 * With no hub the transport says `offline`, and the regions are re-read on a slow poll: the notice the
 * shell shows promises one. When the hub comes back the poll stops and the page catches up once.
 */
(function () {
  'use strict';

  var live = window.MilpaLive || null;

  function desk() { return (live && live.desktop) || null; }

  if (!desk()) { return; }
  if (desk().regions) {
    if (window.console && console.warn) { console.warn('[desktop-regions] loaded twice; ignoring the second copy'); }

    return;
  }

  /** How the server marks a region that can be re-read, and how this module marks one it could not. */
  var MARK = 'data-live-region';
  var STALE = 'data-live-stale';
  /** The one region that is not markup: the session's figures, seeded as signals. */
  var SIGNALS = 'signals';
  var SEED_TAG = 'milpa-live-signals';
  /** The seeds that describe the SESSION. Every other signal is the human's own state on this page. */
  var SESSION_SIGNALS = [
    'session.turns', 'session.steps', 'session.tokens', 'session.tool_calls', 'context.used', 'context.window',
    // Whether it is working is the house's to say too: a run that ended while the stream was being renewed never
    // pushed its end, and the page kept «Working» (greenhouse evidence/1097).
    'session.working', 'session.state.label',
  ];
  /** A poll is a whole page render, so it is slow on purpose. */
  var POLL_MS = 15000;

  var wanted = {};
  var reading = null;
  var deferred = {};
  var keepers = {};

  function marked(root, name) { return root.querySelectorAll('[' + MARK + '="' + name + '"]'); }

  /** Every region name this page carries, plus the signals — what a poll or a catch-up re-reads. */
  function all() {
    var names = [SIGNALS];
    var here = document.querySelectorAll('[' + MARK + ']');
    for (var i = 0; i < here.length; i++) {
      var name = here[i].getAttribute(MARK);
      if (name && names.indexOf(name) === -1) { names.push(name); }
    }

    return names;
  }

  function stale(name) {
    var here = marked(document, name);
    for (var i = 0; i < here.length; i++) { here[i].setAttribute(STALE, ''); }

    return 'stale';
  }

  /** Re-seed the session's figures from the fresh page; 'stale' when it carries no seeds to read. */
  function reseed(page) {
    var tag = page.getElementById(SEED_TAG);
    var seeds = null;
    try { seeds = tag ? JSON.parse(tag.textContent || '') : null; } catch (e) { seeds = null; }
    if (!seeds || typeof seeds !== 'object' || typeof live.signal !== 'function') { return 'stale'; }
    for (var i = 0; i < SESSION_SIGNALS.length; i++) {
      if (Object.prototype.hasOwnProperty.call(seeds, SESSION_SIGNALS[i])) { live.signal(SESSION_SIGNALS[i], seeds[SESSION_SIGNALS[i]]); }
    }

    return 'fresh';
  }

  /** Put the fresh page's copy of one region where the shown one is. */
  function swap(name, page) {
    if (name === SIGNALS) { return reseed(page); }
    var here = marked(document, name);
    var there = marked(page, name);
    if (here.length === 0) { return 'absent'; }
    if (there.length !== here.length) { return stale(name); }
    for (var i = 0; i < here.length; i++) {
      if (here[i].querySelector('[data-busy]')) {
        deferred[name] = true;

        return 'deferred';
      }
    }
    var kept = keepers[name] || [];
    for (var j = 0; j < here.length; j++) {
      for (var k = 0; k < kept.length; k++) {
        try { kept[k](here[j], there[j]); } catch (e) { /* one keeper that throws does not cost the region its re-read */ }
      }
      here[j].replaceWith(there[j]);
    }

    return 'fresh';
  }

  /** One request for everything asked so far. */
  function read() {
    var names = Object.keys(wanted);
    var out = {};
    var asked = [];
    wanted = {};
    for (var i = 0; i < names.length; i++) {
      if (names[i] !== SIGNALS && marked(document, names[i]).length === 0) { out[names[i]] = 'absent'; } else { asked.push(names[i]); }
    }
    if (asked.length === 0) { return Promise.resolve(out); }

    return fetch(window.location.href, { headers: { Accept: 'text/html' }, cache: 'no-store', credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) { throw new Error('HTTP ' + response.status); }

        return response.text();
      })
      .then(function (html) {
        var page = new DOMParser().parseFromString(html, 'text/html');
        for (var j = 0; j < asked.length; j++) { out[asked[j]] = swap(asked[j], page); }

        return out;
      })
      .catch(function () {
        for (var j = 0; j < asked.length; j++) { out[asked[j]] = asked[j] === SIGNALS ? 'stale' : stale(asked[j]); }

        return out;
      });
  }

  /**
   * Ask for regions by name. Resolves with what happened to each: 'fresh', 'stale' (the house did not print
   * it), 'deferred' (a human is acting in it) or 'absent' (this page does not carry it).
   */
  function reread(names) {
    for (var i = 0; i < (names || []).length; i++) { wanted[String(names[i])] = true; }
    if (reading) { return reading; }
    reading = Promise.resolve().then(read).then(function (out) {
      reading = null;
      if (Object.keys(wanted).length === 0) { return out; }

      return reread([]).then(function (again) {
        for (var name in again) { if (Object.prototype.hasOwnProperty.call(again, name)) { out[name] = again[name]; } }

        return out;
      });
    });

    return reading;
  }

  /** Re-read what was deferred while a human was acting — called by the surface when they finish. */
  function resume() {
    var names = Object.keys(deferred);
    deferred = {};

    return names.length === 0 ? Promise.resolve({}) : reread(names);
  }

  /** Let a surface carry something of the shown region into the fresh one, just before the swap. */
  function keep(name, fn) {
    if (typeof fn === 'function') { (keepers[name] = keepers[name] || []).push(fn); }
  }

  // ── with no hub, a poll; when the hub returns, one catch-up ─────────────────────────────────────
  var timer = null;
  var away = false;

  function connection(state) {
    if (state === 'live') {
      if (timer !== null) { window.clearInterval(timer); timer = null; }
      if (away) { away = false; reread(all()); }

      return;
    }
    if (state !== 'offline') { return; }
    away = true;
    if (timer !== null || typeof window.setInterval !== 'function') { return; }
    timer = window.setInterval(function () {
      if (document.hidden) { return; }
      reread(all());
    }, POLL_MS);
  }

  desk().regions = { reread: reread, resume: resume, keep: keep, all: all };

  var bus = window.MilpaShell;
  if (bus && typeof bus.onStatus === 'function') { bus.onStatus(connection); }
})();
