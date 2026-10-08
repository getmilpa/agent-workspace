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
  /** The panel's own door to `identity:admit`, and the intent session its touch is bound to (greenhouse decisions/0597). */
  var ADMIT_ROUTE = '/workspace/admit';
  var ADMIT_INTENT_SESSION = 'identity:admit';
  /** The panel's own door to `identity:withdraw`, and the intent session its touch is bound to (greenhouse decisions/0590, rule 12). */
  var WITHDRAW_ROUTE = '/workspace/withdraw';
  var WITHDRAW_INTENT_SESSION = 'identity:withdraw';
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

  // ── the inbox asks the house again (greenhouse decisions/0563) ──────────────────────────────────
  // Every card here is DERIVED on the server: a frontier card is a refused call read against the enrollment
  // ledger and the authoring policy; a question's card carries the session it answers and its two buttons. A
  // pushed fact is narrower than either, so the inbox does not build a card from it — it re-reads its two
  // regions from the house. Measured (greenhouse evidence/1095): with 1,209 pushes received, the frontier
  // card and the question's card appeared only after a reload the window does not have.
  var PENDING_REGION = 'decisions.pending';
  var FRONTIER_REGION = 'decisions.frontier';

  function regions() { var d = desk(); return (d && d.regions) || null; }

  function reread() {
    var r = regions();

    return r ? r.reread([PENDING_REGION, FRONTIER_REGION]) : null;
  }

  /** While a ceremony is in flight its card must not be swapped away; when it ends the inbox catches up. */
  function busy(card, promise) {
    card.setAttribute('data-busy', '');
    var settle = function (value) {
      card.removeAttribute('data-busy');
      var r = regions();
      if (r) { r.resume(); }

      return value;
    };

    return promise.then(settle, settle);
  }

  /** Whether two cards are the same decision: the same refused call, or the same session's question. */
  function same(a, b) {
    if (a.hasAttribute('data-seat-session')) {
      return a.getAttribute('data-seat-session') === b.getAttribute('data-seat-session')
        && a.getAttribute('data-seat-seq') === b.getAttribute('data-seat-seq');
    }

    return a.hasAttribute('data-decision-session')
      && a.getAttribute('data-decision-session') === b.getAttribute('data-decision-session');
  }

  /**
   * WHAT THE HUMAN SETTLED ON THIS PAGE STAYS AS ITS RECEIPT. A grant's card says «granted · … — the seat can
   * continue» and an answer's says what the door answered; the house no longer prints either, and a re-read
   * that dropped them would take the only word the human got. So a settled card is carried into the fresh
   * list, first — unless the house still prints that same decision, and then the house wins: a receipt never
   * hides something that is open.
   */
  function carrySettled(old, fresh) {
    var list = fresh.querySelector('ol');
    var shown = old.querySelectorAll('.decision-card');
    var settled = [];
    for (var s = 0; s < shown.length; s++) {
      if (shown[s].hasAttribute('data-granted') || shown[s].hasAttribute('data-answered')) { settled.push(shown[s]); }
    }
    if (!list || settled.length === 0) { return; }
    var open = list.querySelectorAll('.decision-card');
    for (var i = settled.length - 1; i >= 0; i--) {
      var printed = false;
      for (var j = 0; j < open.length; j++) { if (same(settled[i], open[j])) { printed = true; break; } }
      if (!printed) { list.insertBefore(settled[i], list.firstChild); }
    }
  }

  /** Subscribe to the transport's facts, ONCE. */
  var subscribed = false;

  function subscribe() {
    var bus = window.MilpaShell;
    if (subscribed || !bus || typeof bus.on !== 'function') { return false; }
    subscribed = true;
    // The clone is the question's text at once; the re-read replaces it with the card that can be answered.
    bus.on('decision.parked', function (fact) { parked((fact && fact.question) || ''); reread(); });
    bus.on('agent.answered', reread);
    // A call that did not go through may be a refusal that opens a frontier card, the end of a run may expire one (greenhouse decisions/0510),
    // and the house's notice is a grant having closed one.
    bus.on('tool.failed', reread);
    bus.on('run.ended', reread);
    bus.on('house.notice', reread);
    var r = regions();
    if (r) {
      r.keep(PENDING_REGION, carrySettled);
      r.keep(FRONTIER_REGION, carrySettled);
    }

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
    // What comes back is READ — a run says whether it was denied or failed, an answer says why it was not taken —
    // so the house's «no» passes as the answer it is (greenhouse decisions/0583), with or without the gate first.
    var flow = d && d.answeredFlow ? d.answeredFlow : function (r) { return r; };
    var guard = d && d.answered ? d.answered : function (r) { return r; };

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
    return busy(card, confirmed(ANSWER_ROUTE, { session: session, answer: answer })
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
      }));
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
    // A VERB OF A BUILT CAPABILITY IS ADMITTED, NOT GRANTED A WORD (greenhouse decisions/0590). Its card carries
    // the digest of the contract it shows; the reader ticks the box that says they read it, and that digest
    // travels inside what the passkey approves — so what is approved is what was shown.
    var admits = card.getAttribute('data-seat-admits');
    if (admits !== null) {
      if (!acknowledged(card)) {
        status.textContent = tr('frontier.admit_ack_first');
        return Promise.resolve(null);
      }
      call.admits = admits;
    }
    if (!canTouch()) {
      status.textContent = tr('frontier.no_passkey');
      return Promise.resolve(null);
    }
    status.textContent = tr(admits !== null ? 'frontier.admitting' : 'frontier.granting');

    return busy(card, touchFor('identity:grant', call, call.session)
      .then(function (assertion) {
        var body = { session: call.session, seq: call.seq, assertion: assertion };
        if (call.existing !== undefined) { body.existing = call.existing; }
        if (call.admits !== undefined) { body.admits = call.admits; }
        return confirmed(GRANT_ROUTE, body);
      })
      .then(function (read) {
        if (!read || read.ok === false) { throw new Error((read && (read.error || read.message)) || 'refused'); }
        status.textContent = admits !== null
          ? admittedSaid(read)
          : (Array.isArray(read.suspended) && read.suspended.length > 0 && existing !== null
            // The permit of a capability persons admitted reopens its works (greenhouse decisions/0590, rule 10).
            ? tr('frontier.granted_works', String(read.granted || ''), existing)
            : tr('frontier.granted', String(read.granted || '')));
        card.setAttribute('data-granted', '');
        var button = card.querySelector('[data-seat-grant]');
        if (button) { button.setAttribute('hidden', ''); }
        return read;
      })
      .catch(function (err) {
        status.textContent = tr(admits !== null ? 'frontier.refused_admit' : 'frontier.refused_grant', why(err));
      }));
  }

  // IN WORKS OR ADMITTED, NEVER BOTH (greenhouse decisions/0590, rule 10): an admission takes the capability's
  // building permit from every seat that held it, and what the house answers says whose. Said with the admission,
  // because it is something the act did beyond what its button named.
  function admittedSaid(read) {
    var granted = String(read.granted || '');
    var capability = String(read.capability || '');
    var closed = Array.isArray(read.closed) ? read.closed.filter(function (key) { return typeof key === 'string' && key !== ''; }) : [];
    if (closed.length === 0) { return tr('frontier.admitted', granted, capability); }

    return tr('frontier.admitted_closed', granted, capability, closed.map(function (key) {
      return key.length > 12 ? key.slice(0, 12) + '…' : key;
    }).join(', '));
  }

  function acknowledged(card) {
    var ack = card.querySelector('[data-seat-ack]');
    return !!(ack && ack.checked);
  }

  // ── admitting with no refusal in front (greenhouse decisions/0597) ──────────────────────────────────────────
  // «Your seats» offers each scope of a built capability no admission covers, with the contract a refusal's card
  // shows. The touch is bound to `identity:admit {seat, admits}`: a seat and the digest of what was read — never a
  // capability, never a scope. The house finds which scope that digest is; one that moved since admits nothing.
  function admitSeat(card) {
    var status = seatStatus(card);
    var call = { seat: card.getAttribute('data-admit-seat') || '', admits: card.getAttribute('data-seat-admits') || '' };
    if (call.seat === '' || call.admits === '') { return Promise.resolve(null); }
    if (!acknowledged(card)) {
      status.textContent = tr('frontier.admit_ack_first');
      return Promise.resolve(null);
    }
    if (!canTouch()) {
      status.textContent = tr('frontier.no_passkey');
      return Promise.resolve(null);
    }
    status.textContent = tr('frontier.admitting');

    return busy(card, touchFor('identity:admit', call, ADMIT_INTENT_SESSION)
      .then(function (assertion) {
        return confirmed(ADMIT_ROUTE, { seat: call.seat, admits: call.admits, assertion: assertion });
      })
      .then(function (read) {
        if (!read || read.ok === false) { throw new Error((read && (read.error || read.message)) || 'refused'); }
        status.textContent = admittedSaid(read);
        card.setAttribute('data-granted', '');
        var button = card.querySelector('[data-seat-admit]');
        if (button) { button.setAttribute('hidden', ''); }
        return read;
      })
      .catch(function (err) {
        status.textContent = tr('frontier.refused_admit', why(err));
      }));
  }

  // ── taking one admission back (greenhouse decisions/0590, rule 12) ─────────────────────────────────────────
  // Beside each «Admitted: …» line. It only removes authority, so there is no box to tick: the passkey's touch IS
  // the act, bound to `identity:withdraw {seat, capability, scope}` — exactly what the line says, and nothing a
  // person types. The seat's next call to those verbs is refused; its scopes and its other admissions stay.
  function withdrawAdmission(line) {
    var status = seatStatus(line);
    var call = {
      seat: line.getAttribute('data-withdraw-seat') || '',
      capability: line.getAttribute('data-withdraw-capability') || '',
      scope: line.getAttribute('data-withdraw-scope') || ''
    };
    if (call.seat === '' || call.capability === '' || call.scope === '') { return Promise.resolve(null); }
    if (!canTouch()) {
      status.textContent = tr('frontier.no_passkey');
      return Promise.resolve(null);
    }
    status.textContent = tr('seats.withdrawing');

    return busy(line, touchFor('identity:withdraw', call, WITHDRAW_INTENT_SESSION)
      .then(function (assertion) {
        return confirmed(WITHDRAW_ROUTE, { seat: call.seat, capability: call.capability, scope: call.scope, assertion: assertion });
      })
      .then(function (read) {
        if (!read || read.ok === false) { throw new Error((read && (read.error || read.message)) || 'refused'); }
        status.textContent = tr('seats.withdrawn_done', String(read.withdrawn || ''), String(read.capability || ''));
        line.setAttribute('data-withdrawn', '');
        var button = line.querySelector('[data-seat-withdraw]');
        if (button) { button.setAttribute('hidden', ''); }
        return read;
      })
      .catch(function (err) {
        status.textContent = tr('seats.refused_withdraw', why(err));
      }));
  }

  // ── your seats: the human gives the resident one, and no file is edited (greenhouse decisions/0499) ──
  // The touch is bound to `identity:seat {label}`; the answer is the command the resident's OWN key runs to
  // take the seat — its signature is what proves the key. The form carries a name, never a scope: what a seat
  // may do is the house's to declare.
  function giveSeat(form) {
    var status = form.querySelector('[data-seat-give-status]') || statusOf(form);
    var command = form.querySelector('[data-seat-command]');
    var input = form.querySelector('[data-seat-label]');
    var typed = String((input && input.value) || '').trim();
    var label = typed || 'resident';
    // One seat per name (greenhouse decisions/0536): `identity:seat` refuses a name a seat already carries, so a
    // taken one is refused here before it costs a passkey touch.
    var taken = takenNames(form);
    if (taken.indexOf(label.toLowerCase()) !== -1) {
      status.textContent = typed === '' ? tr('seats.name_first') : tr('seats.taken', label);
      return Promise.resolve(null);
    }
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

  /** The names the seats already carry, lower-cased, as the server printed them on the form. */
  function takenNames(form) {
    var names;
    try { names = JSON.parse(form.getAttribute('data-seat-taken') || '[]'); } catch (e) { names = []; }
    return Array.isArray(names) ? names.map(function (n) { return String(n).toLowerCase(); }) : [];
  }

  if (live && live.desktop) {
    live.desktop.decisions = { parked: parked, subscribe: subscribe, confirmed: confirmed, run: run, answer: answerParked, grant: grantSeat, giveSeat: giveSeat, withdraw: withdrawAdmission };
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

    var admit = target.closest('[data-seat-admit]');
    if (admit) {
      var admitCard = admit.closest('[data-admit-seat]');
      if (!admitCard || admitCard.getAttribute('data-granted') !== null) { return; }
      event.preventDefault();
      admitSeat(admitCard);
      return;
    }

    var withdraw = target.closest('[data-seat-withdraw]');
    if (withdraw) {
      var held = withdraw.closest('[data-withdraw-seat]');
      if (!held || held.getAttribute('data-withdrawn') !== null || held.getAttribute('data-busy') !== null) { return; }
      event.preventDefault();
      withdrawAdmission(held);
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
