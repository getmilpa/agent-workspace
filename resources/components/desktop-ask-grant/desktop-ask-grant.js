/*!
 * desktop-ask-grant — the agent's parked question, answerable in place (greenhouse decisions/0254).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by the ask-grant prototype's renderer through DeclaresClientAssets and emitted, once, by
 * `LiveBoot::html()`. It registers NO Alpine factory: like every message kind, the block is CLONED from a
 * server-rendered `<template>` and a per-instance `x-data` double-initialises here (greenhouse
 * decisions/0191) — so the behaviour is registered against the KIND, in
 * `MilpaLive.desktop.messages['ask-grant']`, and the conversation's one delegated handler routes clicks.
 *
 * ── WHAT THIS REPLACED, AND WHY IT MATTERED ─────────────────────────────────────────────────────
 *
 * A parked turn used to reach the thread as `result.hint` — the CLI's own line, verbatim:
 *
 *     contesta con: coa agent:answer --session=… --answer=<sí|no>
 *
 * `AgentOperations` says what that string is for: *«el `hint` es de la CLI: dice cómo contestar donde no
 * hay dónde teclear la respuesta»*. This surface HAS somewhere. It was handing a terminal instruction to
 * someone looking at a screen with buttons on it.
 *
 * ── THE OPTIONS ARE THE AGENT'S ─────────────────────────────────────────────────────────────────
 *
 * One button per option the question actually carried. `PendingQuestion` has them because the agent is
 * who knows which forks it is facing; a hardcoded Authorize/Deny would be this surface inventing one.
 * A question that arrived with NO options still renders — its question, its reason, and a line saying
 * where it can be answered — because a question nobody can see is worse than one nobody can click.
 *
 * Answering is `POST /agent/answer`: the operation's own door, with the passkey session as principal,
 * the same door the decisions inbox and a terminal take. This module adjudicates nothing.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live) {
    if (window.console && console.warn) { console.warn('[desktop-ask-grant] the milpa/live runtime must load first'); }

    return;
  }

  /** The prototype the conversation clones per parked question. */
  var PROTO_ID = 'milpa-ask-grant-proto';
  /** The `agent:answer` operation's own HTTP projection. */
  var ANSWER_ROUTE = '/agent/answer';

  function desk() { return live.desktop || null; }
  function tr(key) { var d = desk(); return d ? d.tr.apply(null, arguments) : key; }

  function messages() {
    var d = desk();
    if (!d) { return null; }
    d.messages = d.messages || {};

    return d.messages;
  }

  var registry = messages();
  if (!registry) {
    if (window.console && console.warn) { console.warn('[desktop-ask-grant] the shared desktop runtime must load first'); }

    return;
  }
  if (registry['ask-grant']) {
    if (window.console && console.warn) { console.warn('[desktop-ask-grant] loaded twice; ignoring the second copy'); }

    return;
  }

  /**
   * What the question's STABLE reason code is called in the reader's language.
   *
   * The code — `permission`, `signature`, `target_not_named` — is the contract; the words are the
   * catalog's. A code this surface does not know yet falls back to the generic label instead of printing
   * a raw identifier at someone: an unknown reason is still a question worth answering.
   */
  function kindOf(reason) {
    var known = { permission: 'grant.kind.permission', signature: 'grant.kind.signature', target_not_named: 'grant.kind.target' };
    var key = known[String(reason || '')];

    return key ? tr(key) : tr('grant.kind.default');
  }

  /** The session the answer belongs to — the turn module owns it; this one never mints or guesses it. */
  function session() {
    var d = desk();

    return (d && d.turn && typeof d.turn.session === 'function') ? d.turn.session() : '';
  }

  /** Every option button of one bubble. */
  function options(root) { return root.querySelectorAll('[data-grant-option]'); }

  function setStatus(root, text) {
    var line = root.querySelector('[data-grant-status]');
    if (line) { line.textContent = text || ''; }
  }

  /** Lock the bubble: an answered question offers no second answer, and says which one it got. */
  function settle(root, chosen) {
    var buttons = options(root);
    for (var i = 0; i < buttons.length; i++) {
      buttons[i].disabled = true;
      // A CLASS, not a data hook: the house's DOM gate holds that a `data-*` a module reaches for is one
      // the server prints, and this marker is written here and read only by the stylesheet.
      if (buttons[i].textContent === chosen) { buttons[i].classList.add('msg__grant-option--chosen'); }
    }
    root.setAttribute('data-grant-state', 'answered');
  }

  /**
   * POST the answer through the operation's door.
   *
   * The house's confirm gate is a FLOW, not a refusal (greenhouse decisions/0193): a door that wants
   * consent answers 428 with a one-use token, and the second call carries it in `Confirm-Token`. A 401
   * or a 403 is still the guard's, and `guarded` takes it to the door.
   */
  function answer(root, chosen) {
    var d = desk();
    var sid = session();
    if (sid === '') { setStatus(root, tr('grant.no_session')); return null; }
    var send = function (token) {
      var headers = { 'Content-Type': 'application/json' };
      if (token) { headers['Confirm-Token'] = token; }

      return fetch(ANSWER_ROUTE, { method: 'POST', headers: headers, body: JSON.stringify({ session: sid, answer: chosen }) });
    };
    var flow = (d && d.guardedFlow) ? d.guardedFlow : function (r) { return r; };
    var guard = (d && d.guarded) ? d.guarded : function (r) { return r; };
    setStatus(root, tr('grant.sending'));

    return send('').then(flow).then(function (response) {
      if (response.status !== 428) { return response.json(); }

      return response.json().then(function (gate) {
        if (!gate || !gate.confirm_token) { throw new Error(tr('grant.no_token')); }

        return send(gate.confirm_token).then(guard).then(function (again) { return again.json(); });
      });
    }).then(function (read) {
      if (read && read.ok === false) { throw new Error(read.error || tr('grant.refused')); }
      settle(root, chosen);
      setStatus(root, tr('grant.answered', chosen));

      return read;
    }).catch(function (err) {
      // The bubble keeps its buttons: a send that failed is a question STILL waiting, and locking it
      // would strand the turn with no way to answer it from the place it was raised.
      setStatus(root, tr('grant.failed', (err && err.message) || ''));
    });
  }

  registry['ask-grant'] = {
    proto: PROTO_ID,
    /** Fill a clone: the kind, the question, its why, and one button per option the agent proposed. */
    fill: function (root, opts, at) {
      var kind = at(root, '[data-grant-kind]');
      if (kind) { kind.textContent = kindOf(opts.reason); }
      var asked = at(root, '[data-grant-question]');
      if (asked) { asked.textContent = opts.text || tr('grant.unnamed'); }
      var why = at(root, '[data-grant-why]');
      if (why) {
        why.textContent = opts.why || '';
        // An empty why would otherwise hold a paragraph's worth of blank space under the question.
        why.hidden = !opts.why;
      }
      var list = at(root, '[data-grant-options]');
      var template = list ? list.querySelector('[data-grant-option]') : null;
      if (!list || !template) { return; }
      var given = (opts.options && opts.options.length) ? opts.options : [];
      for (var i = 0; i < given.length; i++) {
        var button = template.cloneNode(true);
        button.textContent = String(given[i]);
        button.hidden = false;
        // The FIRST option carries the emphasis: it is the one the agent proposed first, and a row of
        // identical buttons makes the reader do work the agent already did.
        if (i === 0) { button.classList.add('mui-btn--primary'); }
        list.appendChild(button);
      }
      if (given.length === 0) { setStatus(root, tr('grant.no_options')); }
      if (opts.id) { root.setAttribute('data-grant-id', String(opts.id)); }
    },
    /** The delegated click: an option of THIS kind's bubble, and nothing else. */
    click: function (event) {
      var button = event.target.closest('[data-grant-option]');
      if (!button || button.disabled) { return false; }
      var root = button.closest('.msg--grant');
      if (!root || root.getAttribute('data-grant-state') === 'answered') { return false; }
      answer(root, button.textContent || '');

      return true;
    },
  };
})();
