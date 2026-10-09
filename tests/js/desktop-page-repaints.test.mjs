/*!
 * A turn that ran on a page leaves the page saying what a reload would, by EXECUTION (greenhouse decisions/0609,
 * path 1, I4).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 *
 * Measured (evidence/1175 §6), on the page where a turn ran:
 *
 *   - the session strip said «No session open» before a person's first turn — true — and went on saying it after,
 *     over the conversation that turn had just opened. It is painted when the page loads;
 *   - the thread showed the turn's answer and none of its refused calls, with them in the ledger. A call reaches a
 *     thread over the stream or in the transcript a load prints, and this page had neither for that turn.
 *
 * A page loaded afterwards had both right. So when a turn comes back the page asks the house for itself again
 * (decisions/0563) and takes two things from what it prints: the strip, and the calls it does not show yet. These
 * tests load the SHIPPED modules and assert what the page requested and what it holds afterwards.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { El, page, response, settle } from './support/page.mjs';
import { prototypeTags } from './support/shell.mjs';

const REFUSAL = "Missing required permission 'plugins.Blog:write' for plugin 'Blog'. No plugin 'Blog' exists in this house yet.";

/** The strip as `Live\SessionStrip` prints it, inside its region. */
function strip({ goal = 'No session open', options = [['', 'No session open']] } = {}) {
  const region = new El('div', { class: 'milpa-live-region', 'data-live-region': 'session.strip' });
  const row = region.appendChild(new El('div', { class: 'milpa-session-strip', id: 'milpa-session-strip' }));
  row.appendChild(new El('span', { class: 'milpa-session-strip__goal', id: 'milpa-session-strip-goal', text: goal }));
  const picker = row.appendChild(new El('select', { id: 'milpa-embed-session', value: options[0][0] }));
  options.forEach(([value, text]) => picker.appendChild(new El('option', { value, text })));
  row.appendChild(new El('button', { 'data-new-session': '' }));

  return region;
}

function transcriptTag(rows) {
  const tag = new El('script', { id: 'milpa-desktop-transcript' });
  tag.textContent = JSON.stringify(rows);

  return tag;
}

/** What the house would print now: a document with the strip and the thread's transcript, whichever are given. */
function fresh({ goal = null, rows = null } = {}) {
  const doc = new El('html');
  if (goal !== null) { doc.appendChild(strip({ goal, options: [['run-1', goal + ' · idle']] })); }
  if (rows !== null) { doc.appendChild(transcriptTag(rows)); }

  return doc;
}

/**
 * The house, to this page: a turn answers with the next of `turns`, and the page itself with the next of `pages`
 * (a document, or an Error for a page that cannot be read). Every request the page MADE is recorded.
 */
function house(p, { turns = [], pages = [] } = {}) {
  const calls = [];
  const turnQueue = [...turns];
  const pageQueue = [...pages];
  let parsed = null;
  p.sandbox.fetch = (url, init) => {
    calls.push({ url, init });
    if (url === '/workspace/turn') { return Promise.resolve(response(200, turnQueue.shift() || { ok: true })); }
    if (url === '/workspace/sessions') { return Promise.resolve(response(200, { ok: true, id: 'run-2' })); }
    const next = pageQueue.shift();
    if (next === undefined || next instanceof Error) { return Promise.reject(next || new Error('no page was expected')); }
    parsed = next;

    return Promise.resolve({ ok: true, status: 200, text: async () => 'the page' });
  };
  p.sandbox.DOMParser = class {
    parseFromString() {
      const doc = parsed;

      return { querySelectorAll: (s) => doc.querySelectorAll(s), getElementById: (id) => doc.descendants.find((el) => el.id === id) || null };
    }
  };

  return calls;
}

const pageReads = (calls) => calls.filter((call) => call.url === 'http://localhost/desktop');
/** A value the page's own realm built, as plain data — what `deepEqual` can compare. */
const plain = (value) => JSON.parse(JSON.stringify(value));

/** The panel's Agent region, as much of it as a turn touches: the strip, the thread, the modules in LiveBoot's order. */
function surface({ rows = null, withStrip = true, withThread = true, hub = false } = {}) {
  const html = new El('html');
  if (withStrip) { html.appendChild(strip()); }
  const chat = html.appendChild(new El('section', { id: 'milpa-chat', class: 'tabpane milpa-chat' }));
  const elements = { ...prototypeTags(), 'milpa-desktop-session': new El('script', { id: 'milpa-desktop-session', text: JSON.stringify({ agent: 'run-1' }) }) };
  if (rows !== null) { elements['milpa-desktop-transcript'] = transcriptTag(rows); }
  const p = page({
    tree: html,
    elements,
    modules: [
      'desktop-shell-bus', 'desktop-regions', ...(hub ? ['desktop-hub'] : []), 'desktop-turn',
      ...(withStrip ? ['desktop-session-strip'] : []),
      ...(withThread ? ['desktop-conversation', 'desktop-thinking', 'desktop-agent-message', 'desktop-tool-call', 'desktop-result-claim', 'desktop-ask-grant'] : []),
    ],
  });
  if (withThread) { p.mount('desktopConversation', undefined, chat); }

  return { p, html, chat };
}

const kinds = (chat) => chat.children.map((m) => ['user', 'tool', 'agent', 'no-frontier', 'system', 'result'].find((k) => m.classList.contains('msg--' + k)));
const tools = (chat) => chat.children.filter((m) => m.classList.contains('msg--tool')).map((m) => m.querySelector('[data-tool-name]').textContent);
const goalOf = (html) => html.querySelector('#milpa-session-strip-goal').textContent;

/** A person's first turn on a page that had no session: what she typed, the call the house refused, the answer. */
const FIRST_TURN = [
  { kind: 'user', text: 'Create a plugin named Blog' },
  { kind: 'tool', name: 'make', result: REFUSAL, seq: 4 },
  // Right under the call it is about, and carrying that call's position (decisions/0609, I2): it is not a call.
  { kind: 'no_frontier', seq: 4, tool: 'make', plugin: 'Blog', permission: 'plugins.Blog:write' },
  { kind: 'agent', text: 'I could not create it.' },
];

// ── a region that is data ─────────────────────────────────────────────────────────────────────────
test('a region that is DATA is read by whoever registered for it, in the same request as the regions that are markup', async () => {
  const { p, html } = surface({ withThread: false });
  const calls = house(p, { pages: [fresh({ goal: 'Create a plugin named Blog', rows: FIRST_TURN })] });
  const seen = [];
  p.desktop().regions.reader('thread', (doc) => { seen.push(JSON.parse(doc.getElementById('milpa-desktop-transcript').textContent).length); return 'fresh'; });

  const outcome = await p.desktop().regions.reread(['thread', 'session.strip']);

  assert.equal(pageReads(calls).length, 1, 'one page, read once, for both');
  assert.deepEqual(seen, [4], 'the reader was handed the page the house printed');
  assert.equal(outcome.thread, 'fresh');
  assert.equal(outcome['session.strip'], 'fresh');
  assert.equal(goalOf(html), 'Create a plugin named Blog');
});

test('a reader nobody registered is absent and costs no request; a page that cannot be read leaves a reader stale, uncalled', async () => {
  const { p } = surface({ withStrip: false, withThread: false });
  const calls = house(p, { pages: [new Error('offline')] });

  assert.deepEqual(plain(await p.desktop().regions.reread(['thread'])), { thread: 'absent' });
  assert.equal(calls.length, 0);

  let called = 0;
  p.desktop().regions.reader('thread', () => { called += 1; return 'fresh'; });
  assert.deepEqual(plain(await p.desktop().regions.reread(['thread'])), { thread: 'stale' });
  assert.equal(called, 0);
});

test('a reader that throws, or that says anything but «fresh», is stale — and costs the regions read with it nothing', async () => {
  const { p, html } = surface({ withThread: false });
  house(p, { pages: [fresh({ goal: 'first read', rows: [] }), fresh({ goal: 'second read', rows: [] })] });
  const regions = p.desktop().regions;

  regions.reader('thread', () => { throw new Error('a reader with a bug'); });
  assert.deepEqual(plain(await regions.reread(['thread', 'session.strip'])), { thread: 'stale', 'session.strip': 'fresh' });
  assert.equal(goalOf(html), 'first read');

  regions.reader('thread', () => 'whatever');
  assert.deepEqual(plain(await regions.reread(['thread', 'session.strip'])), { thread: 'stale', 'session.strip': 'fresh' });
  assert.equal(goalOf(html), 'second read');
});

test('the slow poll re-reads what is marked on the page, and never a reader: a thread is caught up by the turn that ran on it', () => {
  const { p } = surface();

  assert.deepEqual([...p.desktop().regions.all()].sort(), ['session.strip', 'signals']);
});

// ── the strip ─────────────────────────────────────────────────────────────────────────────────────
test('the strip says the session a turn opened as soon as the turn comes back, on the page it ran on', async () => {
  const { p, html } = surface();
  const calls = house(p, { turns: [{ ok: true, answer: 'I could not create it.' }], pages: [fresh({ goal: 'Create a plugin named Blog', rows: FIRST_TURN })] });
  assert.equal(goalOf(html), 'No session open', 'true before the first turn: there is none');

  await p.desktop().turn.run('Create a plugin named Blog');
  await settle();

  assert.equal(goalOf(html), 'Create a plugin named Blog', 'and no longer true after it');
  assert.deepEqual(html.querySelectorAll('#milpa-embed-session option').map((o) => o.textContent), ['Create a plugin named Blog · idle']);
  assert.equal(pageReads(calls).length, 1, 'the strip and the thread are read from ONE page');
});

test('a turn the house answered «no» came back too, and the strip is re-read after it', async () => {
  const { p, html } = surface();
  house(p, { turns: [{ ok: false, error: 'the model endpoint answered 404' }], pages: [fresh({ goal: 'Create a plugin named Blog', rows: [] })] });

  await p.desktop().turn.run('Create a plugin named Blog');
  await settle();

  assert.equal(goalOf(html), 'Create a plugin named Blog', 'the session exists whether or not the turn ended well');
});

test('the controls of a strip that was re-read are alive: «New session» asks the route and the picker goes to the session', async () => {
  const { p, html } = surface();
  const calls = house(p, { turns: [{ ok: true, answer: 'done' }], pages: [fresh({ goal: 'Create a plugin named Blog', rows: [] })] });
  const before = html.querySelector('[data-new-session]');
  await p.desktop().turn.run('Create a plugin named Blog');
  await settle();
  const button = html.querySelector('[data-new-session]');
  assert.notEqual(button, before, 'the strip on the page is the one the house printed now');

  button.fire('click');
  await settle();
  assert.equal(calls.filter((call) => call.url === '/workspace/sessions').length, 1, 'a swapped button that fired nothing is the defect of decisions/0273 again');
  assert.deepEqual(p.assigned, ['http://localhost/desktop?session=run-2']);

  const picker = html.querySelector('#milpa-embed-session');
  picker.value = 'run-1';
  picker.fire('change');
  assert.equal(p.assigned[1], 'http://localhost/desktop?session=run-1');
});

test('a strip whose picker is in somebody\'s hand is not swapped under it, and is read when the hand leaves', async () => {
  const { p, html } = surface();
  const calls = house(p, { pages: [fresh({ goal: 'first read' }), fresh({ goal: 'second read' })] });
  const picker = html.querySelector('#milpa-embed-session');

  picker.fire('focus');
  const outcome = await p.desktop().regions.reread(['session.strip']);
  assert.equal(outcome['session.strip'], 'deferred');
  assert.equal(html.querySelector('#milpa-embed-session'), picker, 'the picker she is using is still the one on the page');
  assert.equal(goalOf(html), 'No session open');

  picker.fire('blur');
  await settle();
  assert.equal(pageReads(calls).length, 2, 'what was put off is read when she is done');
  assert.equal(goalOf(html), 'second read');
});

// ── the thread ────────────────────────────────────────────────────────────────────────────────────
test('a call the house recorded in the turn that just ran is shown on the page it ran on, above that turn\'s answer', async () => {
  const { p, chat } = surface();
  house(p, {
    turns: [{ ok: true, answer: 'I could not create it.', no_frontier: { opened_by: 'person', refused: [{ seq: 4, tool: 'make', plugin: 'Blog', permission: 'plugins.Blog:write' }] } }],
    pages: [fresh({ goal: 'Create a plugin named Blog', rows: FIRST_TURN })],
  });
  // What the composer does before it asks for the turn: what she typed is in the thread.
  p.desktop().conversation.append('user', { text: 'Create a plugin named Blog' });

  await p.desktop().turn.run('Create a plugin named Blog');
  await settle();

  assert.deepEqual(kinds(chat), ['user', 'tool', 'agent', 'no-frontier'], 'the call sits where it happened: under what she asked, above what was answered');
  assert.equal(chat.children[1].scrolledIntoView, 0, 'and the page is not dragged up to it: she is reading the answer');
  assert.equal(chat.children[2].scrolledIntoView, 1, 'as it was to the answer when that landed');
  assert.equal(chat.children[1].querySelector('[data-tool-name]').textContent, 'make');
  assert.match(chat.children[1].querySelector('[data-tool-body]').textContent, /Missing required permission 'plugins\.Blog:write'/, 'with the house\'s own words for the refusal');
});

test('a call this page already shows is not painted again: neither one it replayed nor one the stream brought', async () => {
  const earlier = [
    { kind: 'user', text: 'What routes are there?' },
    { kind: 'tool', name: 'routes_list', result: '[]', seq: 2 },
    { kind: 'agent', text: 'None of yours yet.' },
  ];
  const { p, chat } = surface({ rows: earlier });
  house(p, {
    turns: [{ ok: true, answer: 'I could not create it.' }],
    pages: [fresh({ rows: [...earlier, { kind: 'user', text: 'Create a plugin named Blog' }, { kind: 'tool', name: 'house_context', result: '{}', seq: 6 }, { kind: 'tool', name: 'make', result: REFUSAL, seq: 7 }, { kind: 'agent', text: 'I could not create it.' }] })],
  });
  p.desktop().conversation.append('user', { text: 'Create a plugin named Blog' });
  // The stream brought the first call of this turn and not the second.
  p.bus().emit('tool.call', { name: 'house_context', result: '{}', seq: 6 });

  await p.desktop().turn.run('Create a plugin named Blog');
  await settle();

  assert.deepEqual(tools(chat), ['routes_list', 'house_context', 'make'], 'three calls in the ledger, three on the page, each once');
  assert.deepEqual(kinds(chat), ['user', 'tool', 'agent', 'user', 'tool', 'tool', 'agent']);
});

test('a call the stream brings after the page caught up on it is not painted a second time', async () => {
  const { p, chat } = surface();
  house(p, { turns: [{ ok: true, answer: 'I could not create it.' }], pages: [fresh({ rows: FIRST_TURN })] });
  p.desktop().conversation.append('user', { text: 'Create a plugin named Blog' });
  await p.desktop().turn.run('Create a plugin named Blog');
  await settle();
  assert.deepEqual(tools(chat), ['make']);

  p.bus().emit('tool.call', { name: 'make', result: REFUSAL, seq: 4 });

  assert.deepEqual(tools(chat), ['make'], 'the stream is late, not new');
});

test('a stream that does not number its calls does not double them: what it brought stands for the earliest the page lacked', async () => {
  const { p, chat } = surface();
  house(p, {
    turns: [{ ok: true, answer: 'I could not create it.' }],
    pages: [fresh({ rows: [{ kind: 'user', text: 'Create a plugin named Blog' }, { kind: 'tool', name: 'house_context', result: '{}', seq: 3 }, { kind: 'tool', name: 'make', result: REFUSAL, seq: 4 }, { kind: 'agent', text: 'I could not create it.' }] })],
  });
  p.desktop().conversation.append('user', { text: 'Create a plugin named Blog' });
  p.bus().emit('tool.call', { name: 'house_context', result: '{}' });

  await p.desktop().turn.run('Create a plugin named Blog');
  await settle();

  assert.deepEqual(tools(chat), ['house_context', 'make']);
});

test('a transcript that numbers nothing, or a page that cannot be read, leaves the thread as it is', async () => {
  const { p, chat } = surface();
  house(p, {
    turns: [{ ok: true, answer: 'first' }, { ok: true, answer: 'second' }],
    pages: [fresh({ rows: [{ kind: 'user', text: 'a' }, { kind: 'tool', name: 'make', result: REFUSAL }, { kind: 'agent', text: 'first' }] }), new Error('offline')],
  });

  p.desktop().conversation.append('user', { text: 'a' });
  await p.desktop().turn.run('a');
  await settle();
  assert.deepEqual(kinds(chat), ['user', 'agent'], 'a call with no name of its own cannot be told from one already shown: it waits for a load');

  p.desktop().conversation.append('user', { text: 'b' });
  await p.desktop().turn.run('b');
  await settle();
  assert.deepEqual(kinds(chat), ['user', 'agent', 'user', 'agent']);
});

test('the thread says «fresh» when it read the house\'s transcript, and «stale» when that page has none or its calls have no position', async () => {
  const { p, chat } = surface();
  house(p, { pages: [
    fresh({ goal: 'g' }),
    fresh({ rows: [{ kind: 'user', text: 'a' }, { kind: 'tool', name: 'make', result: REFUSAL }] }),
    fresh({ rows: [{ kind: 'user', text: 'a' }, { kind: 'agent', text: 'no calls at all' }] }),
    fresh({ rows: FIRST_TURN }),
  ] });
  const read = async () => plain(await p.desktop().regions.reread(['thread'])).thread;

  assert.equal(await read(), 'stale', 'no transcript on the page the house printed');
  assert.equal(await read(), 'stale', 'calls that do not say where they are in the ledger');
  assert.equal(await read(), 'fresh', 'a transcript with no calls is read: there is nothing to catch up on');
  assert.deepEqual(tools(chat), []);
  assert.equal(await read(), 'fresh');
  assert.deepEqual(tools(chat), ['make']);
});

test('a page with no strip and no thread asks the house for nothing when a turn comes back', async () => {
  const { p } = surface({ withStrip: false, withThread: false });
  const calls = house(p, { turns: [{ ok: true, answer: 'done' }] });

  await p.desktop().turn.run('hello');
  await settle();

  assert.deepEqual(calls.map((call) => call.url), ['/workspace/turn']);
});

// ── the transport ─────────────────────────────────────────────────────────────────────────────────
test('a pushed call carries the position the house pushed it with — «at», as its projector writes it — so the thread can tell it from the same call re-read', () => {
  const { p } = surface({ hub: true });
  const said = [];
  p.bus().on('tool.call', (fact) => said.push(fact));

  p.desktop().hub.translate({ session: 'run-1', kind: 'activity', at: 41, activity: { state: 'tool', detail: 'make', result: REFUSAL, ok: false } });
  p.desktop().hub.translate({ kind: 'activity', activity: { state: 'tool', detail: 'routes_list', result: '[]' } });

  assert.deepEqual(plain(said), [{ name: 'make', result: REFUSAL, seq: 41 }, { name: 'routes_list', result: '[]', seq: null }]);
});
