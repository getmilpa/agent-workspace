/*!
 * What the house decides reaches the panel by push, by EXECUTION (greenhouse decisions/0563).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 *
 * Measured (greenhouse evidence/1095): a window open on a session received 1,209 pushes and still needed a
 * reload — which it does not have — for the frontier card, the question's card, the grant's notice and
 * «Verified by the house». Each one was cut somewhere else, and each test below stands on one cut:
 *
 *   - the transport dropped the kinds it had no line for (`card`, `plan`, `evidence`, `run_ended`), read a
 *     refused call as any other call, and read the house's own notice as a person asking for work;
 *   - the surfaces that show what the house DERIVES had nothing to re-read themselves with.
 *
 * The SHIPPED files run here: the real bus, the real connector, the real surfaces.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { deepEqual as sameShape } from 'node:assert';
import { El, page, response, settle, stubFetch } from './support/page.mjs';
import { prototypeTags } from './support/shell.mjs';

/** The real bus and connector, and every fact the connector published, in order. */
function transport({ tree = null, elements = {}, modules = [] } = {}) {
  const p = page({ tree: tree || new El('html'), elements, modules: ['desktop-shell-bus', 'desktop-regions', 'desktop-hub', ...modules] });
  const said = [];
  p.sandbox.MilpaShell.onAny((type, fact) => said.push([type, fact]));

  return { p, said, hub: p.desktop().hub, types: () => said.map(([type]) => type) };
}

/** Replace the region reader with a recorder: which regions a surface ASKED for, in order. */
function recordRereads(p) {
  const asked = [];
  const regions = p.desktop().regions;
  regions.reread = (names) => { asked.push([...names]); return Promise.resolve({}); };

  return asked;
}

const HOUSE = '[house] passkey:rod granted this seat the scope «plugins.Blog:write». Your call #42 (make plugin=Blog) was refused for lacking it; that same call can run now. Nothing else changed.';

// ── the transport: every kind the hub delivers becomes a fact ───────────────────────────────────────────

test("the house's verdict is a fact of its own, with why and on what", () => {
  const { hub, said } = transport();

  assert.equal(hub.translate({ kind: 'closure', session: 's1', at: 310, closure: { verified: true, reasons: [], scope: 'recorded_work_and_house_observation' } }), 'session');
  assert.equal(hub.translate({ kind: 'closure', session: 's1', at: 311, closure: { verified: false, reasons: ['1 todo open'], scope: 'recorded_work' } }), 'session');
  assert.equal(hub.translate({ kind: 'closure', session: 's1', at: 312 }), 'session');

  sameShape(said, [
    ['house.closure', { verified: true, reasons: [], scope: 'recorded_work_and_house_observation' }],
    ['house.closure', { verified: false, reasons: ['1 todo open'], scope: 'recorded_work' }],
    ['house.closure', { verified: false, reasons: [], scope: '' }],
  ]);
});

test('a todo, a plan and a piece of evidence each say the work changed', () => {
  const { hub, said } = transport();

  hub.translate({ kind: 'card', card: { id: 't1', to: 'done' } });
  hub.translate({ kind: 'plan', plan: { text: 'A then B' } });
  hub.translate({ kind: 'evidence', card: { id: 'e1' } });

  sameShape(said, [['work.changed', { kind: 'card' }], ['work.changed', { kind: 'plan' }], ['work.changed', { kind: 'evidence' }]]);
});

test('the end of a run is said, and the session stops reading as working', () => {
  const { hub, said, p } = transport();
  p.signal('session.working', true);

  hub.translate({ kind: 'run_ended', run: { reason: 'house_debt' } });
  hub.translate({ kind: 'run_ended' });

  sameShape(said, [
    ['session.state', { state: 'idle' }], ['run.ended', { reason: 'house_debt' }],
    ['session.state', { state: 'idle' }], ['run.ended', { reason: '' }],
  ]);
});

test('a call that did NOT go through says so besides being a call; a call that ran does not', () => {
  const { hub, types } = transport();

  hub.translate({ kind: 'activity', activity: { state: 'tool', detail: 'make', ok: false, result: 'refused' } });
  assert.deepEqual(types(), ['tool.call', 'tool.failed']);

  hub.translate({ kind: 'activity', activity: { state: 'tool', detail: 'edit', ok: true, result: 'ok' } });
  hub.translate({ kind: 'activity', activity: { state: 'tool', detail: 'edit', result: 'ok' } });
  assert.deepEqual(types(), ['tool.call', 'tool.failed', 'tool.call', 'tool.call'], 'only a literal false is a failure');
});

test("the house's own notice is a NOTICE: nobody asked for work, so the session is not «working»", () => {
  const { hub, said } = transport();

  hub.translate({ kind: 'activity', activity: { state: 'thinking', role: 'user', text: HOUSE } });

  sameShape(said, [['house.notice', { text: HOUSE }]]);
});

test('control: a person asking for work still reads as working, and is no notice', () => {
  const { hub, said } = transport();

  hub.translate({ kind: 'activity', activity: { state: 'thinking', role: 'user', text: 'continue' } });
  hub.translate({ kind: 'activity', activity: { state: 'thinking', role: 'user', text: 'tell me what [house] means' } });
  hub.translate({ kind: 'activity', activity: { state: 'thinking' } });

  sameShape(said, [['session.state', { state: 'working' }], ['session.state', { state: 'working' }], ['session.state', { state: 'working' }]]);
});

// ── the conversation: the verdict and the notice are painted where they are read ────────────────────────

function thread() {
  const tree = new El('html');
  tree.appendChild(new El('div', { id: 'milpa-chat' }));
  const t = transport({ tree, elements: prototypeTags(), modules: ['desktop-conversation', 'desktop-thinking', 'desktop-agent-message', 'desktop-tool-call', 'desktop-result-claim', 'desktop-ask-grant'] });
  t.p.mount('desktopConversation');

  return { ...t, chat: t.p.desktop().conversation.chat() };
}

test('a pushed verdict stamps the answer it judged, exactly as a reloaded thread does', () => {
  const { hub, chat } = thread();
  hub.translate({ kind: 'activity', seq: 9, activity: { state: 'ready', role: 'assistant', text: 'The blog is built, tested, and live.' } });

  hub.translate({ kind: 'closure', closure: { verified: true, reasons: [], scope: 'recorded_work_and_house_observation' } });

  assert.equal(chat.children.length, 1, 'the verdict rides the answer, it takes no line of its own');
  const slot = chat.children[0].querySelector('[data-agent-verdict]');
  assert.equal(slot.hidden, false);
  assert.equal(slot.getAttribute('data-verified'), '1');
  assert.equal(slot.querySelector('[data-verdict-label]').textContent, 'verified');
});

test('a pushed verdict that did not verify says why; with no answer to ride it is a line of its own', () => {
  const { hub, chat } = thread();

  hub.translate({ kind: 'closure', closure: { verified: false, reasons: ['1 todo open', 'artifact Blog has no current verification'], scope: 'recorded_work' } });

  assert.equal(chat.children.length, 1);
  assert.equal(chat.children[0].getAttribute('data-verified'), '0');
  assert.match(chat.children[0].querySelector('[data-result-tip]').textContent, /1 todo open; artifact Blog has no current verification/);
});

test("the grant's notice is painted in the conversation the moment the house writes it", () => {
  const { hub, chat, p } = thread();

  hub.translate({ kind: 'activity', activity: { state: 'thinking', role: 'user', text: HOUSE } });

  assert.equal(chat.children.length, 1);
  assert.equal(chat.children[0].classList.contains('msg--system'), true, 'a notice, never a bubble in the reader\'s own voice');
  assert.equal(chat.children[0].textContent, HOUSE);
  assert.equal(p.signal('session.working'), false);
});

test('a reloaded thread paints the same notice the same way', () => {
  const tree = new El('html');
  tree.appendChild(new El('div', { id: 'milpa-chat' }));
  const tag = new El('script', { id: 'milpa-desktop-transcript' });
  tag.textContent = JSON.stringify([{ kind: 'user', text: 'build the blog' }, { kind: 'notice', text: HOUSE }]);
  const p = page({ tree, elements: { ...prototypeTags(), 'milpa-desktop-transcript': tag }, bus: true, modules: ['desktop-conversation', 'desktop-thinking', 'desktop-agent-message', 'desktop-tool-call', 'desktop-result-claim', 'desktop-ask-grant'] });
  p.mount('desktopConversation');
  const chat = p.desktop().conversation.chat();

  assert.equal(chat.children.length, 2);
  assert.equal(chat.children[0].classList.contains('msg--user'), true);
  assert.equal(chat.children[1].classList.contains('msg--system'), true);
  assert.equal(chat.children[1].textContent, HOUSE);
});

// ── the inbox: every fact that can move a card makes it ask the house again ─────────────────────────────

const INBOX = ['decisions.pending', 'decisions.frontier'];

function inbox() {
  const tree = new El('html');
  const pending = tree.appendChild(new El('div', { 'data-live-region': 'decisions.pending' }));
  pending.appendChild(new El('ol', { id: 'milpa-decisions-list' }));
  const frontier = tree.appendChild(new El('div', { 'data-live-region': 'decisions.frontier' }));
  const list = frontier.appendChild(new El('ol', { id: 'milpa-frontier-list' }));
  const t = transport({ tree, modules: ['desktop-decisions'] });

  return { ...t, tree, pending, frontier, list };
}

test('a parked question, an answer, a refused call, the end of a run and a grant each re-read the inbox', () => {
  for (const envelope of [
    { kind: 'waiting', ended: { id: 'q1', question: 'Confirm edit on «BlogPluginTest»?', options: ['yes', 'no'] } },
    { kind: 'answered', answered: { id: 'q1', answer: 'yes' } },
    { kind: 'activity', activity: { state: 'tool', detail: 'make', ok: false } },
    { kind: 'run_ended', run: { reason: 'house_debt' } },
    { kind: 'activity', activity: { state: 'thinking', role: 'user', text: HOUSE } },
  ]) {
    const { p, hub } = inbox();
    const asked = recordRereads(p);

    hub.translate(envelope);

    sameShape(asked, [INBOX], `«${envelope.kind}» must re-read the inbox`);
  }
});

test('control: a call that ran, a thought and an answer in flight re-read nothing', () => {
  const { p, hub } = inbox();
  const asked = recordRereads(p);

  hub.translate({ kind: 'activity', activity: { state: 'tool', detail: 'edit', ok: true } });
  hub.translate({ kind: 'reasoning', reasoning: { delta: 'hm' } });
  hub.translate({ kind: 'activity', activity: { state: 'thinking', role: 'user', text: 'continue' } });
  hub.translate({ kind: 'activity', activity: { state: 'ready', role: 'assistant', text: 'done' } });

  sameShape(asked, []);
});

/** A frontier card as the server prints it. */
function frontierCard(session, seq, extra = {}) {
  const card = new El('li', { class: 'decision-card decision-card--frontier', 'data-seat-session': session, 'data-seat-seq': String(seq), ...extra });
  card.appendChild(new El('p', { class: 'decision-card__facts', 'data-seat-status': '' }));
  card.appendChild(new El('button', { 'data-seat-grant': '', text: 'Grant' }));

  return card;
}

/** What the house prints now for one inbox region: a fresh list with the cards given. */
function freshInbox(name, listId, cards) {
  const doc = new El('html');
  const region = doc.appendChild(new El('div', { 'data-live-region': name }));
  const list = region.appendChild(new El('ol', { id: listId }));
  cards.forEach((card) => list.appendChild(card));

  return doc;
}

function servePage(p, doc) {
  p.sandbox.fetch = () => Promise.resolve({ ok: true, status: 200, text: async () => 'page' });
  p.sandbox.DOMParser = class { parseFromString() { return { querySelectorAll: (s) => doc.querySelectorAll(s), getElementById: () => null }; } };
}

test('the card the human just granted stays as its receipt when the frontier is re-read', async () => {
  const { p, tree, list } = inbox();
  const granted = list.appendChild(frontierCard('camino-blog', 42, { 'data-granted': '' }));
  granted.querySelector('[data-seat-status]').textContent = 'granted · plugins.Blog:write — the seat can continue';
  list.appendChild(frontierCard('camino-blog', 77));
  servePage(p, freshInbox('decisions.frontier', 'milpa-frontier-list', [frontierCard('camino-blog', 90)]));

  await p.desktop().regions.reread(['decisions.frontier']);

  const cards = tree.querySelectorAll('#milpa-frontier-list .decision-card');
  assert.deepEqual(cards.map((c) => c.getAttribute('data-seat-seq')), ['42', '90'], 'the receipt first, then what the house says is open now');
  assert.equal(cards[0], granted);
  assert.equal(cards[0].querySelector('[data-seat-status]').textContent, 'granted · plugins.Blog:write — the seat can continue');
});

test('when the house still prints the card, the house wins: a receipt never hides an open refusal', async () => {
  const { p, tree, list } = inbox();
  list.appendChild(frontierCard('camino-blog', 42, { 'data-granted': '' }));
  const open = frontierCard('camino-blog', 42);
  servePage(p, freshInbox('decisions.frontier', 'milpa-frontier-list', [open]));

  await p.desktop().regions.reread(['decisions.frontier']);

  const cards = tree.querySelectorAll('#milpa-frontier-list .decision-card');
  assert.equal(cards.length, 1);
  assert.equal(cards[0], open);
});

test('an answered question stays as its receipt until the same session parks another', async () => {
  const answered = () => {
    const card = new El('li', { class: 'decision-card', 'data-decision-session': 'camino-blog', 'data-answered': '' });
    card.appendChild(new El('p', { 'data-decision-status': '', text: 'answered · the session reads it on its next turn — nothing resumed yet' }));

    return card;
  };

  const first = inbox();
  const kept = first.pending.querySelector('#milpa-decisions-list').appendChild(answered());
  servePage(first.p, freshInbox('decisions.pending', 'milpa-decisions-list', []));
  await first.p.desktop().regions.reread(['decisions.pending']);
  assert.deepEqual(first.tree.querySelectorAll('#milpa-decisions-list .decision-card'), [kept], 'what the door answered is still readable');

  const second = inbox();
  second.pending.querySelector('#milpa-decisions-list').appendChild(answered());
  const next = new El('li', { class: 'decision-card', 'data-decision-session': 'camino-blog' });
  servePage(second.p, freshInbox('decisions.pending', 'milpa-decisions-list', [next]));
  await second.p.desktop().regions.reread(['decisions.pending']);
  assert.deepEqual(second.tree.querySelectorAll('#milpa-decisions-list .decision-card'), [next], 'a new question from that session replaces the old receipt');
});

test('a card whose ceremony is in flight is busy, and the inbox catches up when it ends', async () => {
  const { p, list } = inbox();
  const card = list.appendChild(frontierCard('camino-blog', 42));
  const resumed = [];
  p.desktop().regions.resume = () => { resumed.push(card.hasAttribute('data-busy')); return Promise.resolve({}); };
  // A browser with a passkey whose touch we hold open.
  let touch;
  p.sandbox.atob = (s) => Buffer.from(s, 'base64').toString('binary');
  p.sandbox.btoa = (s) => Buffer.from(s, 'binary').toString('base64');
  p.sandbox.Uint8Array = Uint8Array;
  p.sandbox.PublicKeyCredential = function PublicKeyCredential() {};
  const bytes = (text) => new Uint8Array([...text].map((c) => c.charCodeAt(0))).buffer;
  p.sandbox.navigator = { credentials: { get: () => new Promise((resolve) => { touch = () => resolve({ rawId: bytes('cred'), response: { clientDataJSON: bytes('c'), authenticatorData: bytes('a'), signature: bytes('s') } }); }) } };
  stubFetch(p, [response(200, { rpId: 'localhost', challenge: 'Y2hhbGxlbmdl', allowCredentials: [] }), response(201, { ok: true, granted: 'plugins.Blog:write' })]);

  const done = p.desktop().decisions.grant(card);
  await settle();
  assert.equal(card.hasAttribute('data-busy'), true, 'while the key is being touched the card must not be swapped away');

  touch();
  await done;
  assert.equal(card.hasAttribute('data-busy'), false);
  assert.equal(card.hasAttribute('data-granted'), true);
  assert.deepEqual(resumed, [false], 'the inbox is re-read once the card is no longer busy');
});

// ── the work board and the session's figures ────────────────────────────────────────────────────────────

test('work that changed, a verdict and the end of a run each re-read the work board', () => {
  for (const envelope of [
    { kind: 'card', card: { id: 't1', to: 'done' } },
    { kind: 'plan', plan: { text: 'A' } },
    { kind: 'evidence', card: { id: 'e1' } },
    { kind: 'closure', closure: { verified: true, reasons: [], scope: 'house_observation' } },
    { kind: 'run_ended', run: { reason: 'final_answer' } },
  ]) {
    const { p, hub } = transport({ modules: ['desktop-work-board'] });
    const asked = recordRereads(p);

    hub.translate(envelope);

    sameShape(asked, [['work']], `«${envelope.kind}» must re-read the work board`);
  }
});

test("the end of a run re-reads the session's figures; nothing else does", () => {
  const { p, hub } = transport({ modules: ['desktop-turn'] });
  const asked = recordRereads(p);

  hub.translate({ kind: 'activity', activity: { state: 'tool', detail: 'edit', ok: true } });
  hub.translate({ kind: 'closure', closure: { verified: true } });
  sameShape(asked, []);

  hub.translate({ kind: 'run_ended', run: { reason: 'final_answer' } });
  sameShape(asked, [['signals']]);
});
