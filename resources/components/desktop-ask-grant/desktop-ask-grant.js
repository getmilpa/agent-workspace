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
   * The request for this question that is still WAITING, if any.
   *
   * 🚨 A question id is NOT unique on a page. It is `perm:<operation>` and stable by design, because the
   * gate matches a standing consent by it — so a thread that asked the same permission across several
   * turns holds several bubbles with the same id. Taking the first match found an old, already-answered
   * one and left the live request open, which is the exact failure this function exists to fix.
   */
  function openRequest(id) {
    var all = document.querySelectorAll('.msg--grant[data-grant-id="' + String(id).replace(/["\\]/g, '') + '"]');
    for (var i = all.length - 1; i >= 0; i--) {
      if (all[i].getAttribute('data-grant-state') !== 'answered') { return all[i]; }
    }

    return null;
  }

  /**
   * A DECISION TAKEN ANYWHERE CLOSES THIS REQUEST (greenhouse decisions/0258).
   *
   * Somebody answered — on another device, from a terminal, or here — and a request that keeps offering
   * its buttons for something already decided is a room lying about what is still open. Rod, looking at
   * a panel whose gate was still live after the answer: «debe aparecer disabled o desaparecer cuando la
   * acción ya se tomó».
   *
   * The bubble STAYS: it is the record of the question and of the answer it got, and deleting it would
   * erase the decision from the story. What goes is the offer.
   */
  function decided(fact) {
    var id = fact && fact.id ? String(fact.id) : '';
    if (id === '') { return null; }
    var root = openRequest(id);
    if (!root) { return null; }
    settle(root, fact.answer || '');
    // WHO decided is said, not derived: the fact carries the actor and the process that materialised it,
    // and a surface must not have to re-derive an attribution somebody already recorded.
    var who = fact.by || fact.executor || '';
    setStatus(root, who === '' ? tr('grant.answered', fact.answer || '') : tr('grant.answered.by', fact.answer || '', who));

    return root;
  }

  /** Subscribe to the decision, ONCE — the same bus every message kind reads. */
  var listening = false;

  function listen() {
    var bus = window.MilpaShell;
    if (listening || !bus || typeof bus.on !== 'function') { return false; }
    listening = true;
    bus.on('agent.answered', decided);

    return true;
  }

  listen();

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
      // The hub will say it too, to every surface including this one. `settle()` is idempotent and
      // `decided()` steps aside on a bubble already answered, so the two roads meet without a race.

      return read;
    }).catch(function (err) {
      // The bubble keeps its buttons: a send that failed is a question STILL waiting, and locking it
      // would strand the turn with no way to answer it from the place it was raised.
      setStatus(root, tr('grant.failed', (err && err.message) || ''));
    });
  }

  /**
   * THE FIVE AXES, in the order the TUI paints them — most-commonly-tightened first.
   *
   * Two surfaces of one fact must not teach two vocabularies, so this list is the TUI's
   * (`AgentScreen::ejesDelTecho`) and not a new opinion.
   */
  var AXES = ['authority', 'reversibility', 'mutation', 'externality', 'subject'];

  /** A stable code in the reader's words, or the code itself when this surface does not know it yet. */
  function word(kind, code) {
    var key = 'claim.' + kind + '.' + String(code);
    var said = tr(key);

    return said === key ? String(code) : said;
  }

  /** One row cloned from its template, filled and shown. */
  function row(list, template, name, value) {
    var node = template.cloneNode(true);
    node.hidden = false;
    var n = node.querySelector('[data-claim-' + name + '-name]');
    var v = node.querySelector('[data-claim-' + name + '-value]');
    if (n) { n.textContent = value[0]; }
    if (v) { v.textContent = value[1]; }
    list.appendChild(node);
  }

  /**
   * Paint WHAT IS BEING AUTHORIZED.
   *
   * The gate keeps this as machine data on purpose — it re-reads it to hold a consent to these exact
   * arguments — so painting it is this surface's job, not the emitter's. Prose stays prose; a claim
   * this surface cannot parse is shown RAW, because inventing a sentence about something nobody
   * understood would be worse than the JSON, which is at least true.
   */
  function claim(root, at, why) {
    var prose = at(root, '[data-grant-why]');
    var box = at(root, '[data-grant-claim]');
    if (prose) { prose.textContent = ''; prose.hidden = true; }
    if (box) { box.hidden = true; }
    if (!why) { return; }

    var read = null;
    try { read = JSON.parse(why); } catch (e) { read = null; }
    // No claim region — an older prototype, or one a plugin re-rendered — falls back to saying it as
    // text. Losing the why entirely because its NICER rendering is missing would be worse than plain.
    if (read === null || typeof read !== 'object' || !box) {
      // Not a claim: it is something somebody wrote for a human, and it reads as one.
      if (prose) { prose.textContent = String(why); prose.hidden = false; }

      return;
    }
    box.hidden = false;

    var op = box.querySelector('[data-claim-operation]');
    if (op) { op.textContent = read.operation ? String(read.operation) : ''; }

    // WHAT IT IS OVER goes first and in clear — the same rule the TUI states: a map buried behind the
    // effect profiles is not understood at a glance, and this is the moment somebody has to decide.
    var args = box.querySelector('[data-claim-args]');
    var argRow = args ? args.querySelector('[data-claim-arg]') : null;
    var over = box.querySelector('[data-claim-over]');
    var given = (read.arguments && typeof read.arguments === 'object') ? read.arguments : {};
    var names = Object.keys(given);
    if (over) { over.textContent = names.length ? ' ' + tr('claim.over') : ''; }
    if (args && argRow) {
      for (var i = 0; i < names.length; i++) {
        var raw = given[names[i]];
        row(args, argRow, 'arg', [names[i], typeof raw === 'string' ? raw : JSON.stringify(raw)]);
      }
    }

    // Only `base` — the DECLARED ceiling, which is the trusted one. `composed` is what the run worked
    // out, and showing both would ask a human to adjudicate between two numbers at the worst moment.
    var axes = box.querySelector('[data-claim-axes]');
    var axisRow = axes ? axes.querySelector('[data-claim-axis]') : null;
    var base = (read.base && typeof read.base === 'object') ? read.base : {};
    var painted = 0;
    if (axes && axisRow) {
      for (var a = 0; a < AXES.length; a++) {
        if (typeof base[AXES[a]] !== 'string' || base[AXES[a]] === '') { continue; }
        row(axes, axisRow, 'axis', [word('axis', AXES[a]), word('value', base[AXES[a]])]);
        painted += 1;
      }
    }

    // A claim whose shape this surface does not know: show it whole rather than show nothing.
    if (painted === 0 && !read.operation) {
      var pre = box.querySelector('[data-claim-raw]');
      if (pre) { pre.textContent = why; pre.hidden = false; }
    }
  }

  registry['ask-grant'] = {
    proto: PROTO_ID,
    /** Fill a clone: the kind, the question, its why, and one button per option the agent proposed. */
    fill: function (root, opts, at) {
      var kind = at(root, '[data-grant-kind]');
      if (kind) { kind.textContent = kindOf(opts.reason); }
      var asked = at(root, '[data-grant-question]');
      if (asked) { asked.textContent = opts.text || tr('grant.unnamed'); }
      claim(root, at, opts.why);
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
    /**
     * Close the request a decision settled — used live by the bus, and by the REPLAY for a question
     * that was answered before this surface ever opened.
     */
    decided: decided,
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
