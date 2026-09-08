/*!
 * desktop-decisions — the cross-session inbox, live (greenhouse decisions/0211, phase D4).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by the inbox's renderer ({@see \Milpa\DesktopApp\Live\DecisionsInbox}) through
 * DeclaresClientAssets and emitted, once, by `LiveBoot::html()`.
 *
 * It registers no Alpine factory: every interaction of the inbox is a POST to an operation's own HTTP
 * door, with the passkey session as principal — `graph:decide`, `agent:answer`, `sequence:run` — the same
 * doors a terminal takes (greenhouse decisions/0223, F4). What it also has is one live behaviour
 * (greenhouse decisions/0196): when an agent parks a question while this page is open, a card appears
 * without a reload. The transport says `decision.parked`; this consumes it and CLONES the server-rendered
 * card prototype. The full card (goal, operation, reason) lands on the next load, from the server.
 *
 * It ticks no badge: the decisions count belongs to the sidebar item that shows it, and the sidebar's own
 * module consumes the same fact.
 */
(function () {
  'use strict';

  var live = window.MilpaLive || null;

  /** The operations' own HTTP projections — the same doors a terminal takes. */
  var DECIDE_ROUTE = '/graph/decide';
  var ANSWER_ROUTE = '/agent/answer';
  var SEQUENCE_ROUTE = '/sequence/run';

  /** The list the cards live in, and the prototype the server rendered for one. */
  var LIST_ID = 'milpa-decisions-list';
  var PROTO = 'milpa-decision-proto';

  function desk() { return (live && live.desktop) || null; }
  function tr(key) { var d = desk(); return d ? d.tr.apply(null, arguments) : key; }

  if (desk() && desk().decisions) {
    if (window.console && console.warn) { console.warn('[desktop-decisions] loaded twice; ignoring the second copy'); }

    return;
  }

  /**
   * Put one parked question at the top of the inbox, cloned from the prototype.
   *
   * Returns the card, or null when this page renders no inbox (embed mode does not) — a fact nobody can
   * show is a fact ignored, never an error.
   */
  function parked(question) {
    var list = document.getElementById(LIST_ID);
    var proto = document.getElementById(PROTO);
    if (!list || !proto || !('content' in proto)) { return null; }
    var frag = proto.content.cloneNode(true);
    var card = frag.querySelector('.decision-card');
    if (!card) { return null; }
    var asked = card.querySelector('[data-decision-question]');
    if (asked) { asked.textContent = question ? String(question) : tr('decisions.unnamed'); }
    var facts = card.querySelector('[data-decision-facts]');
    if (facts) { facts.textContent = tr('decisions.just_now'); }
    list.insertBefore(card, list.firstChild);

    return card;
  }

  /** Subscribe to the transport's fact, ONCE. */
  var subscribed = false;

  function subscribe() {
    var bus = window.MilpaShell;
    if (subscribed || !bus || typeof bus.on !== 'function') { return false; }
    subscribed = true;
    bus.on('decision.parked', function (fact) { parked((fact && fact.question) || ''); });

    return true;
  }

  // ── the confirm gate, as a flow ─────────────────────────────────────────────────────────────────
  // A mutating operation whose ceiling demands consent answers the FIRST call with the house's confirm
  // gate (428 + a one-use token) and runs on the SECOND, which repeats the call with the token in the
  // `Confirm-Token` header — the same two-step the capabilities screen walks (greenhouse decisions/0193).
  // It is a flow, not a refusal; a door's 401/403 still is.
  function confirmed(path, body) {
    var d = desk();
    var send = function (token) {
      var headers = { 'Content-Type': 'application/json' };
      if (token) { headers['Confirm-Token'] = token; }
      return fetch(path, { method: 'POST', headers: headers, body: JSON.stringify(body) });
    };
    var flow = d && d.guardedFlow ? d.guardedFlow : function (r) { return r; };
    var guard = d && d.guarded ? d.guarded : function (r) { return r; };

    return send('').then(flow).then(function (r) {
      if (r.status !== 428) { return r.json(); }
      return r.json().then(function (gate) {
        if (!gate || !gate.confirm_token) { throw new Error(tr('cap.no_token')); }
        return send(gate.confirm_token).then(guard).then(function (again) { return again.json(); });
      });
    });
  }

  // ── a sequence card: run, pause, answer, resume ─────────────────────────────────────────────────
  function statusOf(card) {
    // Two selectors, asked one at a time: a parked question's card prints one, a sequence's the other.
    var line = card.querySelector('[data-sequence-status]') || card.querySelector('[data-decision-status]');
    if (!line) {
      line = card.appendChild(document.createElement('p'));
      line.className = 'decision-card__facts';
      line.setAttribute('data-decision-status', '');
    }
    return line;
  }

  function showAnswers(card, shown) {
    var buttons = card.querySelectorAll('[data-agent-answer]');
    for (var i = 0; i < buttons.length; i++) {
      if (shown) { buttons[i].removeAttribute('hidden'); } else { buttons[i].setAttribute('hidden', ''); }
    }
  }

  /**
   * What a run of `sequence:run` came back saying, painted onto its card: parked at a step (the answers
   * appear), applied whole, denied at a step nobody could judge, or stopped by a failure.
   */
  function outcome(card, read) {
    var status = statusOf(card);
    read = read || {};
    if (read.paused) {
      status.textContent = tr('decisions.paused_on', read.pending_operation || '?') + (read.pending_reason ? ' — ' + String(read.pending_reason) : '');
      card.setAttribute('data-sequence-paused', '');
      showAnswers(card, true);
      return;
    }
    card.removeAttribute('data-sequence-paused');
    showAnswers(card, false);
    if (read.applied) {
      status.textContent = tr('decisions.applied', String(read.executed_count), String(read.steps_total));
      return;
    }
    if (read.denied) {
      status.textContent = tr('decisions.denied', read.reason || read.denied_operation || '');
      return;
    }
    status.textContent = tr('decisions.failed', read.reason || read.error || 'unknown');
  }

  /** Run (or resume) the sequence a card names, through the confirm gate, and paint the outcome. */
  function run(card) {
    var body = { sequence: card.getAttribute('data-sequence') || '' };
    var session = card.getAttribute('data-sequence-session') || card.getAttribute('data-decision-session') || '';
    if (session) { body.session = session; }
    statusOf(card).textContent = tr('decisions.running');

    return confirmed(SEQUENCE_ROUTE, body)
      .then(function (read) { outcome(card, read); return read; })
      .catch(function (err) { statusOf(card).textContent = tr('decisions.failed', (err && err.message) || 'unknown'); });
  }

  /**
   * Answer the question a session parked — `sí` or `no`, what `agent:answer` reads — and, when the session
   * is parked on a SEQUENCE and the answer was yes, resume the run right here: the grant the answer minted
   * is what lets the step through on the second `sequence:run`.
   */
  function answerParked(card, answer) {
    var d = desk();
    var session = card.getAttribute('data-decision-session') || card.getAttribute('data-sequence-session') || '';
    var sequence = card.getAttribute('data-decision-sequence') || card.getAttribute('data-sequence') || '';
    var status = statusOf(card);
    status.textContent = tr('decisions.answering');

    var send = fetch(ANSWER_ROUTE, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ session: session, answer: answer }) });

    return (d && d.guarded ? send.then(d.guarded) : send)
      .then(function (response) { return response.json(); })
      .then(function (read) {
        if (read && read.ok === false) { throw new Error(read.error || 'refused'); }
        if (answer !== 'sí') {
          status.textContent = tr('decisions.stays_paused');
          showAnswers(card, false);
          return read;
        }
        if (sequence === '') {
          status.textContent = tr('decisions.answered');
          card.setAttribute('data-answered', '');
          showAnswers(card, false);
          return read;
        }
        status.textContent = tr('decisions.resuming');
        showAnswers(card, false);
        return confirmed(SEQUENCE_ROUTE, { sequence: sequence, session: session })
          .then(function (resumed) { outcome(card, resumed); return resumed; });
      })
      .catch(function (err) {
        status.textContent = tr('decisions.refused', (err && err.message) || 'unknown');
      });
  }

  if (live && live.desktop) {
    live.desktop.decisions = { parked: parked, subscribe: subscribe, confirmed: confirmed, run: run, answer: answerParked };
  }

  subscribe();
  document.addEventListener('DOMContentLoaded', subscribe);

  /**
   * Answering a GRAPH decision, right here.
   *
   * An agent's parked question is answered in the conversation of its own session, so its card is a link.
   * A graph's is answered from the inbox, because the run is parked in a log and not in a process — so the
   * options the server rendered are posted straight to `graph:decide`, the same operation a terminal calls.
   *
   * The options are the cases of the enum the routes were declared with, so this handler never has to know
   * what they mean: it sends the one the human pressed and lets the engine refuse anything it should.
   */
  function answer(card, decision) {
    var d = desk();
    var status = card.querySelector('[data-decision-status]') || card.appendChild(document.createElement('p'));
    status.className = 'decision-card__facts';
    status.setAttribute('data-decision-status', '');
    status.textContent = tr('decisions.answering');

    var body = JSON.stringify({
      graph: card.getAttribute('data-graph') || '',
      instance: card.getAttribute('data-graph-instance') || '',
      decision: decision,
      principal: card.getAttribute('data-graph-principal') || '',
    });

    var send = fetch(DECIDE_ROUTE, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body });

    return (d && d.guardedFlow ? send.then(d.guardedFlow) : send)
      .then(function (response) { return response.json(); })
      .then(function (read) {
        if (read && read.ok === false) { throw new Error(read.error || 'refused'); }
        status.textContent = tr('decisions.answered');
        card.setAttribute('data-answered', '');
      })
      .catch(function (err) {
        status.textContent = tr('decisions.refused', (err && err.message) || 'unknown');
      });
  }

  // Delegated on the list, not bound per card: a decision can arrive live, and a handler bound at load
  // would never see it (greenhouse decisions/0191 — the same lesson the conversation paid for).
  document.addEventListener('click', function (event) {
    var target = event.target && event.target.closest ? event.target : null;
    if (!target) { return; }

    var decide = target.closest('[data-graph-decide]');
    if (decide) {
      var graphCard = decide.closest('.decision-card--graph');
      if (!graphCard || graphCard.getAttribute('data-answered') !== null) { return; }
      event.preventDefault();
      answer(graphCard, decide.getAttribute('data-graph-decide') || '');
      return;
    }

    var runButton = target.closest('[data-sequence-run]');
    if (runButton) {
      var sequenceCard = runButton.closest('[data-sequence]');
      if (!sequenceCard) { return; }
      event.preventDefault();
      run(sequenceCard);
      return;
    }

    var reply = target.closest('[data-agent-answer]');
    if (reply) {
      var parkedCard = reply.closest('[data-decision-session]') || reply.closest('[data-sequence-session]');
      if (!parkedCard || parkedCard.getAttribute('data-answered') !== null) { return; }
      event.preventDefault();
      answerParked(parkedCard, reply.getAttribute('data-agent-answer') || 'no');
    }
  });
})();
