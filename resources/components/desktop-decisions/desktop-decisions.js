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

  /**
   * The panel's own doors to `graph:decide`, `agent:answer` and `sequence:run` — the same operations a terminal
   * runs. Never the operations' global paths: a fresh app mounts none of them (greenhouse decisions/0495).
   */
  var DECIDE_ROUTE = '/workspace/decide';
  var ANSWER_ROUTE = '/workspace/answer';
  var SEQUENCE_ROUTE = '/workspace/sequence';
  /** The panel's own door to `identity:grant`, and the ceremony that binds a passkey touch to one call. */
  var GRANT_ROUTE = '/workspace/grant';
  /** The panel's own door to `identity:seat`: the human gives the resident a seat (greenhouse decisions/0499). */
  var SEAT_ROUTE = '/workspace/seat';
  /** The intent session a seat's touch is bound to — there is no agent session yet (app-runtime ResidentSeat). */
  var SEAT_INTENT_SESSION = 'identity:seat';
  var INTENT_ROUTE = '/webauthn/intent/options';

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

  /**
   * What a failed call says, for the card: the door's own sentence when it wrote one (a 501 names what the app
   * lacks, a refusal names why), else the status the guard saw.
   */
  function why(err) {
    if (err && err.body && typeof err.body.error === 'string' && err.body.error !== '') { return err.body.error; }
    return (err && err.message) || 'unknown';
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
      .catch(function (err) { statusOf(card).textContent = tr('decisions.failed', why(err)); });
  }

  /**
   * Answer the question a session parked — `yes` or `no`, what `agent:answer` reads — and, when the session
   * is parked on a SEQUENCE and the answer was yes, resume the run right here: the grant the answer minted
   * is what lets the step through on the second `sequence:run`.
   */
  /**
   * Whether an answer means yes — the house's reader (`AffirmativeAnswer`) in this surface. The panel posts `yes`
   * (greenhouse decisions/0518); a card an older panel rendered still carries `sí`, and it must still resume.
   */
  function affirmative(answer) {
    return ['yes', 'y', 'sí', 'si', 's'].indexOf(String(answer).trim().toLowerCase()) !== -1;
  }

  function answerParked(card, answer) {
    var session = card.getAttribute('data-decision-session') || card.getAttribute('data-sequence-session') || '';
    var sequence = card.getAttribute('data-decision-sequence') || card.getAttribute('data-sequence') || '';
    var status = statusOf(card);
    status.textContent = tr('decisions.answering');

    // Through the confirm gate: answering is irreversible, and the house asks before it records it.
    return confirmed(ANSWER_ROUTE, { session: session, answer: answer })
      .then(function (read) {
        if (read && read.ok === false) { throw new Error(read.error || 'refused'); }
        if (!affirmative(answer)) {
          status.textContent = tr('decisions.stays_paused');
          showAnswers(card, false);
          return read;
        }
        if (sequence === '') {
          // RECORDED, NOT RESUMED (greenhouse decisions/0513 §6): `agent:answer` writes the answer and runs nothing —
          // the session reads it on its next turn, and the door says how to take one when it can.
          status.textContent = tr('decisions.answered_parked') + (read && typeof read.hint === 'string' && read.hint !== '' ? ' — ' + read.hint : '');
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
        status.textContent = tr('decisions.refused', why(err));
      });
  }

  // ── a seat's frontier: the human who enrolled it grants the scope a refusal names ────────────────
  // A refusal asks nothing (greenhouse decisions/0317); this is the human deciding it (decisions/0493).
  // The touch is bound to THIS grant: the house mints a challenge for `identity:grant {session, seq}`,
  // the passkey signs it, and the assertion travels inside the call so the operation knows who decided.
  // The card carries the refused call, never a scope: the house re-derives what is missing.
  function b64uToBuf(value) {
    var b64 = String(value || '').replace(/-/g, '+').replace(/_/g, '/');
    while (b64.length % 4) { b64 += '='; }
    var raw = atob(b64);
    var bytes = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) { bytes[i] = raw.charCodeAt(i); }
    return bytes.buffer;
  }

  function bufToB64u(buffer) {
    var bytes = new Uint8Array(buffer);
    var raw = '';
    for (var i = 0; i < bytes.length; i++) { raw += String.fromCharCode(bytes[i]); }
    return btoa(raw).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function seatStatus(card) {
    return card.querySelector('[data-seat-status]') || statusOf(card);
  }

  function canTouch() {
    return !!(window.PublicKeyCredential && navigator.credentials && typeof navigator.credentials.get === 'function');
  }

  /**
   * The passkey's touch for ONE call: the house mints a challenge bound to `operation` with exactly these
   * arguments and this intent session, the key signs it, and the assertion comes back ready to travel inside the
   * call — so the operation knows who decided, and a touch for another call proves nothing.
   */
  function touchFor(operation, call, session) {
    var d = desk();
    var ask = fetch(INTENT_ROUTE, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ operation: operation, arguments: call, session: session }),
    });

    return (d && d.guarded ? ask.then(d.guarded) : ask)
      .then(function (response) { return response.json(); })
      .then(function (options) {
        return navigator.credentials.get({ publicKey: {
          challenge: b64uToBuf(options.challenge),
          rpId: options.rpId,
          allowCredentials: (options.allowCredentials || []).map(function (c) { return { type: c.type, id: b64uToBuf(c.id) }; }),
          userVerification: 'required',
          timeout: 60000,
        } });
      })
      .then(function (touch) {
        var r = touch.response;
        return {
          credentialId: bufToB64u(touch.rawId),
          clientDataJSON: bufToB64u(r.clientDataJSON),
          authenticatorData: bufToB64u(r.authenticatorData),
          signature: bufToB64u(r.signature),
        };
      });
  }

  function grantSeat(card) {
    var status = seatStatus(card);
    var call = { session: card.getAttribute('data-seat-session') || '', seq: parseInt(card.getAttribute('data-seat-seq') || '', 10) };
    // Write over existing work is an informed act, never one touch (greenhouse decisions/0510): the reader
    // ticks the box that names the plugin, and that name travels inside what the passkey approves.
    var existing = card.getAttribute('data-seat-existing');
    if (existing !== null) {
      var ack = card.querySelector('[data-seat-ack]');
      if (!ack || !ack.checked) {
        status.textContent = tr('frontier.ack_first');
        return Promise.resolve(null);
      }
      call.existing = existing;
    }
    if (!canTouch()) {
      status.textContent = tr('frontier.no_passkey');
      return Promise.resolve(null);
    }
    status.textContent = tr('frontier.granting');

    return touchFor('identity:grant', call, call.session)
      .then(function (assertion) {
        var body = { session: call.session, seq: call.seq, assertion: assertion };
        if (call.existing !== undefined) { body.existing = call.existing; }
        return confirmed(GRANT_ROUTE, body);
      })
      .then(function (read) {
        if (!read || read.ok === false) { throw new Error((read && (read.error || read.message)) || 'refused'); }
        status.textContent = tr('frontier.granted', String(read.granted || ''));
        card.setAttribute('data-granted', '');
        var button = card.querySelector('[data-seat-grant]');
        if (button) { button.setAttribute('hidden', ''); }
        return read;
      })
      .catch(function (err) {
        status.textContent = tr('frontier.refused_grant', why(err));
      });
  }

  // ── your seats: the human gives the resident one, and no file is edited (greenhouse decisions/0499) ──
  // The touch is bound to `identity:seat {label}`; the answer is the command the resident's OWN key runs to
  // take the seat — its signature is what proves the key. The form carries a name, never a scope: what a seat
  // may do is the house's to declare.
  function giveSeat(form) {
    var status = form.querySelector('[data-seat-give-status]') || statusOf(form);
    var command = form.querySelector('[data-seat-command]');
    var input = form.querySelector('[data-seat-label]');
    var label = String((input && input.value) || '').trim() || 'resident';
    if (!canTouch()) {
      status.textContent = tr('frontier.no_passkey');
      return Promise.resolve(null);
    }
    status.textContent = tr('seats.giving');
    var call = { label: label };

    return touchFor('identity:seat', call, SEAT_INTENT_SESSION)
      .then(function (assertion) { return confirmed(SEAT_ROUTE, { label: label, assertion: assertion }); })
      .then(function (read) {
        if (!read || read.ok === false) { throw new Error((read && (read.error || read.message)) || 'refused'); }
        status.textContent = tr('seats.given', String(read.expires_at || ''));
        if (command) {
          command.textContent = String(read.command || '');
          command.removeAttribute('hidden');
        }
        return read;
      })
      .catch(function (err) {
        status.textContent = tr('seats.refused', why(err));
      });
  }

  if (live && live.desktop) {
    live.desktop.decisions = { parked: parked, subscribe: subscribe, confirmed: confirmed, run: run, answer: answerParked, grant: grantSeat, giveSeat: giveSeat };
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
   *
   * It never sends WHO pressed it. The approver is the passkey session the door carries, read by `graph:decide`
   * from the request itself; a principal posted from the page was an approver anyone able to post could name
   * (greenhouse decisions/0528).
   */
  function answer(card, decision) {
    var status = card.querySelector('[data-decision-status]') || card.appendChild(document.createElement('p'));
    status.className = 'decision-card__facts';
    status.setAttribute('data-decision-status', '');
    status.textContent = tr('decisions.answering');

    return confirmed(DECIDE_ROUTE, {
      graph: card.getAttribute('data-graph') || '',
      instance: card.getAttribute('data-graph-instance') || '',
      decision: decision,
    })
      .then(function (read) {
        if (read && read.ok === false) { throw new Error(read.error || 'refused'); }
        status.textContent = tr('decisions.answered');
        card.setAttribute('data-answered', '');
      })
      .catch(function (err) {
        status.textContent = tr('decisions.refused', why(err));
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

    var grant = target.closest('[data-seat-grant]');
    if (grant) {
      var seatCard = grant.closest('[data-seat-session]');
      if (!seatCard || seatCard.getAttribute('data-granted') !== null) { return; }
      event.preventDefault();
      grantSeat(seatCard);
      return;
    }

    var give = target.closest('[data-seat-give]');
    if (give) {
      var form = give.closest('[data-seat-give-form]');
      if (!form) { return; }
      event.preventDefault();
      giveSeat(form);
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
