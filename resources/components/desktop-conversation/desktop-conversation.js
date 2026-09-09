/*!
 * desktop-conversation — the Desktop's conversation thread, as a client module (greenhouse decisions/0211, phase C1).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by the conversation's renderer through DeclaresClientAssets and emitted, once, by
 * `LiveBoot::html()`. It registers ONE Alpine factory, `desktopConversation`, which the `#milpa-chat`
 * section binds with `x-data="desktopConversation()" @click="onClick($event)"`.
 *
 * The thread knows TWO things and no more (greenhouse decisions/0191):
 *
 *   - HOW a message lands: clone the prototype the server rendered for that kind, let the kind's own
 *     component fill the clone's data regions, append it. No `createElement`, no markup written here.
 *   - WHERE the clicks go: one delegated handler asks each message component whether the click was its
 *     own, so a message TYPE added later needs no wiring in the container.
 *
 * WHAT each kind renders is its own component's, registered into `MilpaLive.desktop.messages` by that
 * component's module (`desktop-thinking`, `desktop-agent-message`, `desktop-tool-call`,
 * `desktop-result-claim`). The three plain kinds — the user's message, a task row, a system notice — are
 * registered HERE: their whole fill is one `textContent` into one region and they have no interaction of
 * their own, so a module apiece would be a file with a line in it.
 *
 * The closure VERDICT's words live here too (`tip()` / `label()`): two message shapes show the same
 * judgement — the agent answer's tool row and the standalone result claim — so one owner says it once,
 * from the catalog, and they cannot disagree.
 *
 * The thread is also what CONSUMES `desktop.notice`: the guard says what a door answered and the
 * conversation renders it as a system message. Nothing else couples the guard to the chat.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live || typeof live.register !== 'function') {
    if (window.console && console.warn) { console.warn('[desktop-conversation] the milpa/live runtime must load first'); }

    return;
  }
  if (live.registered('desktopConversation')) {
    if (window.console && console.warn) { console.warn('[desktop-conversation] loaded twice; ignoring the second copy'); }

    return;
  }

  /** The thread's element id — the ONE place this module resolves it. */
  var CHAT_ID = 'milpa-chat';

  function desk() { return live.desktop || null; }
  function tr(key) { var d = desk(); return d ? d.tr.apply(null, arguments) : key; }

  /**
   * The registry each message COMPONENT registers its kind into: `{ proto, fill, click, append }`.
   *
   * It is created by whichever module gets there first — the modules are emitted in the order their
   * surfaces were painted, and none of them may assume it is that one.
   */
  function messages() {
    var d = desk();
    if (!d) { return null; }
    d.messages = d.messages || {};

    return d.messages;
  }

  /** The thread element, or null on a page that renders no conversation. */
  function chat() { return document.getElementById(CHAT_ID); }

  /**
   * One data region of a clone: the region is a DESCENDANT of the clone, or the clone's own root.
   *
   * `querySelector` never returns the node it was called on, and the system notice carries
   * `data-system-body` ON the `.msg` root — so a plain descendant lookup found nothing and every guard
   * notice painted an EMPTY bubble. Asking both ways makes a fill independent of where a component (or a
   * plugin that re-rendered it) put its region.
   */
  function region(root, selector) { return root.matches(selector) ? root : root.querySelector(selector); }

  /** The words a verdict is said in — one owner, two message shapes (the answer's row, the result claim). */
  function tip(ok, reasons) {
    return ok !== false
      ? tr('verdict.backed')
      : tr('verdict.disputed.why', (reasons ? String(reasons) : tr('verdict.disputed.default')));
  }

  /** What the verdict's badge says. */
  function label(ok) { return ok !== false ? tr('verdict.verified') : tr('verdict.disputed'); }

  /** The same judgement for a screen reader: the badge, then the reason. */
  function aria(ok, text) { return tr(ok !== false ? 'verdict.aria.verified' : 'verdict.aria.disputed', text); }

  /**
   * Append one message of `kind`: the component that owns the kind fills the clone of its prototype.
   *
   * A kind whose component declares its own `append` (the thinking block streams across a whole turn
   * instead of landing once) takes that path instead. An unknown kind lands as a system notice rather
   * than silently not landing.
   */
  function append(kind, opts) {
    var thread = chat();
    var registry = messages() || {};
    var spec = registry[kind] || registry.system;
    if (!thread || !spec) { return null; }
    opts = opts || {};
    if (typeof spec.append === 'function') { return spec.append(thread, opts); }
    var proto = document.getElementById(spec.proto);
    if (!proto || !('content' in proto)) { return null; }
    var frag = proto.content.cloneNode(true);
    var root = frag.querySelector('.msg');
    if (root && typeof spec.fill === 'function') { spec.fill(root, opts, region); }
    // A STEP of the current turn hangs inside the turn's own block (greenhouse decisions/0254). With no
    // block open it lands in the thread like any other message: a step with no turn to belong to is still
    // a fact, and a fact nobody can nest is shown, never dropped.
    if (!(NESTED[kind] === true && nest(frag))) { thread.appendChild(frag); }
    if (root && typeof root.scrollIntoView === 'function') { root.scrollIntoView({ block: 'end' }); }

    return root;
  }

  /**
   * The kinds that belong to a TURN rather than to the thread: what the agent did while reasoning, and
   * what it stopped to ask. The answer, the user's message and a compaction boundary are not steps —
   * they are the thread's, and nesting them would bury the answer inside the reasoning that produced it.
   */
  var NESTED = { tool: true, 'ask-grant': true };

  /** Hand a built node to the open turn's block. False when no turn is open. */
  function nest(node) {
    var spec = (messages() || {}).thinking;

    return !!(spec && typeof spec.step === 'function' && spec.step(node) === true);
  }

  /**
   * One reading of a parked question, whether it came from the stream or from the replayed transcript.
   *
   * Two readers would be two chances to disagree about the same fact — and the two sources genuinely do
   * carry different key names for it, which is exactly why the normalising belongs in one place.
   */
  function parked(row) {
    row = row || {};

    return {
      id: row.id || '',
      text: row.text || row.question || '',
      why: row.why || row.reason_text || '',
      reason: row.reason || '',
      options: (row.options && row.options.length) ? row.options : [],
    };
  }

  /**
   * Is this agent text the SAME FACT as that parked question?
   *
   * The house answers a parked turn with the question as its `answer`, sometimes with the arguments
   * appended — so the ledger holds the assistant's turn AND the question, and a thread that painted
   * both said one thing twice: once as the request with its buttons, once as an agent bubble repeating
   * it word for word. What makes them the same fact is that the answer OPENS WITH the question.
   *
   * The rule lives HERE and the turn module asks for it, because two copies of one predicate are two
   * chances to disagree about whether a thing was already said.
   */
  function echoesQuestion(text, question) {
    if (!text || !question) { return false; }

    return String(text).trim().indexOf(String(question).trim()) === 0;
  }

  /** Close a replayed request whose decision the ledger already holds. */
  function settled(row) {
    var spec = (messages() || {})['ask-grant'];

    return (spec && typeof spec.decided === 'function') ? spec.decided(row) : null;
  }

  /** Stream reasoning into the live thinking block — the thinking component's own lifecycle. */
  function reasoning(text) {
    var spec = (messages() || {}).thinking;

    return (spec && typeof spec.delta === 'function') ? spec.delta(chat(), text) : null;
  }

  /** Close the live thinking block, if one is open. */
  function endReasoning() {
    var spec = (messages() || {}).thinking;

    return (spec && typeof spec.end === 'function') ? spec.end() : null;
  }

  /**
   * The turn's closure verdict, RIDING the last agent answer (Rod's ask — it saves a whole line).
   *
   * False when there is no answer to ride, so the caller falls back to the standalone result claim.
   */
  function verdict(ok, reasons) {
    var spec = (messages() || {}).agent;
    var thread = chat();

    return !!(thread && spec && typeof spec.verdict === 'function' && spec.verdict(thread, ok, reasons) === true);
  }

  /** The delegated click: the first message component that owns the target handles it. */
  function dispatch(event) {
    if (!event || !event.target || typeof event.target.closest !== 'function') { return false; }
    var registry = messages() || {};
    for (var kind in registry) {
      if (Object.prototype.hasOwnProperty.call(registry, kind)
        && typeof registry[kind].click === 'function'
        && registry[kind].click(event) === true) {
        return true;
      }
    }

    return false;
  }

  // The plain kinds: one region, one text, no interaction (see the file header).
  var registry = messages();
  if (registry) {
    registry.user = {
      proto: 'milpa-user-msg-proto',
      fill: function (root, opts, at) { var body = at(root, '[data-user-body]'); if (body) { body.textContent = opts.text || ''; } },
    };
    registry.task = {
      proto: 'milpa-task-msg-proto',
      fill: function (root, opts, at) {
        var title = at(root, '[data-task-title]');
        if (title) { title.textContent = opts.title || ''; }
        var status = at(root, '[data-task-status]');
        if (status) { status.textContent = opts.status || 'todo'; }
      },
    };
    registry.system = {
      proto: 'milpa-system-msg-proto',
      fill: function (root, opts, at) { var body = at(root, '[data-system-body]'); if (body) { body.textContent = opts.text || ''; } },
    };
    // The compaction boundary (greenhouse decisions/0254): one region, one text, no interaction — a
    // separator does nothing, so like the other plain kinds it needs no module of its own.
    registry.compacted = {
      proto: 'milpa-compacted-proto',
      fill: function (root, opts, at) {
        var body = at(root, '[data-compacted-text]');
        if (!body) { return; }
        // How far the summary reaches is the only figure that means anything to a reader; absent, the
        // boundary still says that one happened, which is the whole point of painting it.
        body.textContent = opts.through ? tr('conversation.compacted.through', String(opts.through)) : tr('conversation.compacted');
      },
    };
  }

  /**
   * Subscribe to the stream, ONCE.
   *
   * At module load when the bus is already there (it is: the shell's runtime tag is inline at the top of
   * `<body>` and every module is deferred), and again from the factory's `init()` — because the hub is
   * connected on `DOMContentLoaded`, which is AFTER every deferred module has run: subscribing at load is
   * what keeps a fact already queued at the hub from arriving before there is anyone to render it.
   */
  var subscribed = false;

  function subscribe() {
    var shell = window.MilpaShell;
    if (subscribed || !shell || typeof shell.on !== 'function') { return false; }
    subscribed = true;
    shell.on('agent.reasoning', function (fact) { reasoning((fact && fact.text) || ''); });
    shell.on('agent.message', function (fact) { endReasoning(); append('agent', { text: (fact && fact.text) || '' }); });
    shell.on('agent.thinking', function (fact) { append('thinking', { text: (fact && fact.text) || '' }); });
    shell.on('tool.call', function (fact) { append('tool', { name: (fact && fact.name) || 'tool', result: (fact && fact.result) || '' }); });
    shell.on('task.added', function (fact) { append('task', { title: (fact && fact.title) || '', status: (fact && fact.status) || 'todo' }); });
    shell.on('system.notice', function (fact) { append('system', { text: (fact && fact.text) || '' }); });
    // The turn stopped to ask (greenhouse decisions/0254). It lands INSIDE the turn's block, under the
    // reasoning that led to it, with the agent's own options as buttons.
    shell.on('agent.parked', function (fact) { append('ask-grant', parked(fact)); });
    // The window compacted. `session.compacted` has been declared in milpa/agent all along and painted
    // by nobody; it reaches the bus through the transport's generic `event` envelope.
    shell.on('session.compacted', function (fact) {
      append('compacted', { through: (fact && fact.through) || 0, summary: (fact && fact.summary) || '' });
    });

    return true;
  }

  /**
   * REPLAY the thread the server printed (greenhouse evidence/0561), ONCE.
   *
   * The ledger is the truth of a session, and a reload used to lose the thread: the page carried nothing
   * of it, so a parked question could not be answered from the conversation it was raised in. The server
   * now prints the session's transcript as data; each row is painted with the SAME prototype a live turn
   * uses, so a replayed thread and a live one are one markup.
   */
  var TRANSCRIPT_TAG = 'milpa-desktop-transcript';
  var replayed = false;

  function replay() {
    if (replayed) { return 0; }
    var tag = document.getElementById(TRANSCRIPT_TAG);
    if (!tag) { return 0; }
    replayed = true;
    var rows = [];
    try { rows = JSON.parse(tag.textContent || '[]'); } catch (e) { rows = []; }
    if (!Array.isArray(rows)) { return 0; }
    var painted = 0;
    for (var i = 0; i < rows.length; i++) {
      var row = rows[i] || {};
      switch (row.kind) {
        case 'user': append('user', { text: row.text || '' }); break;
        case 'agent':
          // The echo of a question the row before already painted as a request is not a second thing
          // the agent said.
          if (!echoesQuestion(row.text, (rows[i - 1] || {}).kind === 'question' ? (rows[i - 1] || {}).text : '')) {
            append('agent', { text: row.text || '' });
          }
          break;
        case 'tool': append('tool', { name: row.name || 'tool', result: row.result || '' }); break;
        case 'question': append('ask-grant', parked(row)); break;
        case 'compacted': append('compacted', { through: row.through || 0, summary: row.summary || '' }); break;
        case 'answered':
          // THE DECISION IS SAID ONCE, and the request it settled is the best place to say it: it holds
          // the question, what was being authorized, and now the answer and who gave it. A separate
          // «ANSWERED …» line under it repeated all of that (Rod: «toda esa info ya está en la primera
          // burbuja»).
          //
          // The standalone line survives for the case that needs it: a decision whose request is not on
          // this page — an older thread, a pruned question. Then it is the only record there is.
          if (settled(row) === null) {
            append('system', { text: tr('conversation.answered', row.answer || '', row.by || '') });
          }
          break;
        case 'sequence_paused': append('system', { text: tr('conversation.sequence_paused', row.sequence || '') }); break;
        case 'sequence_resumed': append('system', { text: tr('conversation.sequence_resumed', row.sequence || '') }); break;
        default: continue;
      }
      painted += 1;
    }
    return painted;
  }

  /** Consume `desktop.notice`, ONCE: the guard says what happened, the thread renders it. */
  var listening = false;

  function listen() {
    var d = desk();
    if (listening || !d || typeof d.onNotice !== 'function') { return false; }
    listening = true;
    d.onNotice(function (notice) { append('system', { text: (notice && notice.text) || '' }); });

    return true;
  }

  live.register('desktopConversation', function () {
    return {
      /** Late subscriptions, for a page whose bus or guard arrived after this module did. */
      init: function () {
        subscribe();
        listen();
        replay();
      },
      /** The thread's ONE click handler — every message component's actions ride it. */
      onClick: function (event) {
        dispatch(event);
      },
    };
  });

  if (live.desktop) {
    live.desktop.conversation = {
      append: append,
      chat: chat,
      region: region,
      reasoning: reasoning,
      endReasoning: endReasoning,
      verdict: verdict,
      click: dispatch,
      echoesQuestion: echoesQuestion,
      tip: tip,
      label: label,
      aria: aria,
      replay: replay,
    };
  }

  subscribe();
  // The replay waits for `init()`: every message module must have registered its prototype fill first,
  // and the factory's init runs after all deferred modules did — a replay at load would paint the first
  // row and lose the rest to fills that were not there yet (measured in the node harness).
  listen();
})();
