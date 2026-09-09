/*!
 * desktop-hub — the Mercure connector, as a client module (greenhouse decisions/0211, phase D1).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * NOT a component: a transport is not a surface. Like the guard and the bus it is a RUNTIME module the
 * page declares, emitted once by `LiveBoot::html()`. It is the ONE place the Desktop opens a stream.
 *
 * It reads the hub's URL as DATA (`#milpa-desktop-hub`, a `type="application/json"` tag the server
 * writes) — never as a URL baked into a script the page executes — opens ONE `EventSource` on it, and
 * TRANSLATES what arrives into the shell's own facts:
 *
 *   - a desktop `ShellEvent` carries `event` + `data` → published as that fact, unchanged;
 *   - a governed turn's projection carries `kind` (activity / message / reasoning / waiting,
 *     greenhouse decisions/0190) → mapped to the facts the conversation, the turn and the Activity tab
 *     already listen for, so ONE set of subscribers renders both streams.
 *
 * Nothing here touches the DOM. A parked question becomes two facts — a system notice and
 * `decision.parked` — and the surfaces that own the inbox card and the sidebar's badge consume them.
 *
 * The stream is opened on `DOMContentLoaded`, and that wait is load-bearing: every component module is a
 * DEFERRED script, so it has executed by then. Opening earlier would put a window between the first
 * message and the handlers that must see it — and a fact already queued at the hub arrives inside it.
 */
(function () {
  'use strict';

  var live = window.MilpaLive || null;

  /** Where the server tells the page which hub to open, if any. */
  var HUB_TAG = 'milpa-desktop-hub';
  /** Where a surface that could not write the payload asks for it (greenhouse decisions/0253). */
  var ASK = '/desktop/hub';
  /** Where the server sealed WHICH SESSION this surface inhabits (greenhouse decisions/0256). */
  var TICKET_TAG = 'milpa-desktop-ticket';

  /**
   * The house's sealed decision, read as DATA and handed back untouched.
   *
   * This module never names a session. It could not if it wanted to: the id lives inside a signature it
   * cannot produce, and the route it asks has no session parameter of any kind. Before this, the hub
   * route rebuilt identity from a cookie — and the panel, whose page cannot set that cookie, ended up
   * subscribed to a session nobody was driving while reporting itself live.
   */
  function ticket() {
    var tag = document.getElementById(TICKET_TAG);
    try {
      var read = tag ? JSON.parse(tag.textContent || '{}') : {};

      return (read && typeof read.ticket === 'string') ? read.ticket : '';
    } catch (e) { return ''; }
  }

  function desk() { return (live && live.desktop) || null; }
  function tr(key) { var d = desk(); return d ? d.tr.apply(null, arguments) : key; }
  function bus() { return window.MilpaShell || null; }

  function signal(key, value) {
    if (!live || typeof live.signal !== 'function') { return null; }

    return arguments.length > 1 ? live.signal(key, value) : live.signal(key);
  }

  if (desk() && desk().hub) {
    if (window.console && console.warn) { console.warn('[desktop-hub] loaded twice; ignoring the second copy'); }

    return;
  }

  /** The hub's public URL with its exact topics, or '' when this Desktop has no hub wired. */
  var URL = (function () {
    var tag = document.getElementById(HUB_TAG);
    try {
      var read = tag ? JSON.parse(tag.textContent || '{}') : {};

      return (read && typeof read.url === 'string') ? read.url : '';
    } catch (e) { return ''; }
  })();

  /** Publish one fact on the shell's bus. */
  function say(type, data) {
    var b = bus();
    if (b && typeof b.emit === 'function') { b.emit(type, data); }
  }

  /** A governed turn's `activity` projection (greenhouse decisions/0190). */
  function activity(env) {
    var detail = env.activity || {};
    var state = detail.state || '';
    if (state === 'thinking') { say('session.state', { state: 'working' }); return; }
    if (state === 'ready') {
      say('session.state', { state: 'idle' });
      // THE ANSWER REACHES EVERY SURFACE, not only the one that asked (greenhouse decisions/0258).
      // The turn used to project its STATE and nothing else, so the agent's answer travelled only on
      // the `POST /agent` response — fine while whoever asked and whoever watches are the same person,
      // and wrong the moment a session is open on two devices.
      if (detail.role === 'assistant' && detail.text) { say('agent.message', { text: detail.text, turn: env.seq || null }); }

      return;
    }
    if (state === 'tool') {
      // A tool ran: show it in the conversation and count it into the shared tool_calls signal.
      say('tool.call', { name: detail.detail || 'tool', result: detail.result || '' });
      signal('session.tool_calls', (parseInt(signal('session.tool_calls'), 10) || 0) + 1);
    }
  }

  /**
   * One hub envelope, translated into the shell's facts. Returns what it recognised, so a test can read
   * the translation without a stream: 'event', 'session', or '' for a shape this Desktop does not know.
   */
  function translate(env) {
    if (!env || typeof env !== 'object') { return ''; }
    if (typeof env.event === 'string') { say(env.event, env.data); return 'event'; }
    if (typeof env.kind !== 'string') { return ''; }
    if (env.kind === 'activity') { activity(env); return 'session'; }
    if (env.kind === 'message') { say('agent.message', { text: (env.message && env.message.content) || '' }); return 'session'; }
    if (env.kind === 'reasoning') { say('agent.reasoning', { text: (env.reasoning && (env.reasoning.delta || env.reasoning.text)) || '' }); return 'session'; }
    // A DECISION TAKEN ANYWHERE REACHES EVERY SURFACE (greenhouse decisions/0258). Somebody answered
    // — here, on another device, or from a terminal — and every open request for that question must
    // stop offering buttons. Without this the room showed a live gate for something already decided.
    if (env.kind === 'answered') {
      var decided = env.answered || {};
      say('agent.answered', {
        id: decided.id || '',
        answer: decided.answer || '',
        // Two identities, both already in the fact: who authorized, and what process materialised it.
        by: (decided.by && decided.by.id) || '',
        executor: decided.executor || '',
      });

      return 'session';
    }
    if (env.kind === 'waiting') {
      var ended = env.ended || {};
      var question = ended.question || '';
      // The parked question, with everything the envelope carried (greenhouse decisions/0254): the
      // conversation renders it as a REQUEST — its options as buttons — instead of a grey line of prose.
      // It used to also `say('system.notice', …)` here, and that was the same fact told twice: once as a
      // notice and once to the inbox, which is exactly what `AgentOperations` already warns about.
      say('agent.parked', {
        id: ended.id || '',
        text: question,
        why: ended.why || ended.reason_text || '',
        reason: ended.reason || '',
        options: (ended.options && ended.options.length) ? ended.options : [],
      });
      // The inbox and the sidebar badge each consume this (greenhouse decisions/0196): a parked question
      // shows up without a reload, and the transport touches neither of their elements.
      say('decision.parked', { question: question });

      return 'session';
    }

    return 'session';
  }

  /**
   * Open the stream on a URL — or, with no hub wired, say so once so the status bar settles.
   *
   * `url` is the inline payload's when the page carried one, and the asked-for one otherwise.
   */
  function openOn(url) {
    var b = bus();
    if (url === '' || typeof window.EventSource !== 'function') {
      if (b && typeof b.status === 'function') { b.status('offline'); }

      return null;
    }
    var stream = new EventSource(url, { withCredentials: true });
    stream.onopen = function () { if (b && typeof b.status === 'function') { b.status('live'); } };
    stream.onerror = function () { if (b && typeof b.status === 'function') { b.status('offline'); } };
    stream.onmessage = function (message) {
      var env;
      try { env = JSON.parse(message.data); } catch (e) { return; }
      translate(env);
    };

    return stream;
  }

  /**
   * A SURFACE THAT COULD NOT WRITE THE PAYLOAD ASKS FOR IT.
   *
   * The Desktop page inlines it — it owns its response, and a request would buy nothing. The workspace
   * inside the admin panel is a declared view: it contributes markup to somebody else's response and
   * cannot set the cookie the hub reads, so it found no payload and reported itself offline forever,
   * with the hub running and the Stack green. Asking `/desktop/hub` is how that surface gets both the
   * URL and the cookie (greenhouse decisions/0253).
   *
   * `{}` is an ANSWER, not a failure: this app wired no hub, the workspace runs on the polled log, and
   * saying «offline» once is exactly right.
   */
  function open() {
    if (URL !== '') { return openOn(URL); }
    if (typeof window.fetch !== 'function') { return openOn(''); }

    fetch(ASK, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Milpa-Session-Ticket': ticket() } })
      .then(function (r) { return r.ok ? r.json() : {}; })
      .then(function (payload) {
        URL = (payload && typeof payload.url === 'string') ? payload.url : '';
        openOn(URL);
      })
      .catch(function () { openOn(''); });

    return null;
  }

  if (live && live.desktop) {
    live.desktop.hub = { url: function () { return URL; }, translate: translate, open: open };
  }

  document.addEventListener('DOMContentLoaded', function () { open(); });
})();
