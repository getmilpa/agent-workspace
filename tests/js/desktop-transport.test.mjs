/*!
 * The Desktop's transport, as the browser runs it (greenhouse decisions/0211, phase D1).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 *
 * Two modules that used to be two inline `<script>` tags in the shell: the BUS (`window.MilpaShell`, the
 * published extension point five modules and every plugin panel reach for) and the HUB connector (the one
 * `EventSource`, and the translation of what arrives on it into the shell's own facts).
 *
 * The tests below run the SHIPPED files — no bundler, no jsdom — so what they exercise is the file the
 * browser gets.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
// Objects a MODULE built live in the `node:vm` realm, so their prototype is not this realm's: a strict
// deep-equal would compare prototypes and fail on values that are identical. The loose one is the right
// instrument for a fact that crossed the boundary — the strict one stays for everything else.
import { deepEqual as sameShape } from 'node:assert';
import { El, page, response, settle, stubFetch } from './support/page.mjs';

/** A page with the real bus and the real connector, and the hub tag the server would have written. */
function transport({ url = '', tree = null, modules = [] } = {}) {
  const elements = { 'milpa-desktop-hub': new El('script', { id: 'milpa-desktop-hub', text: JSON.stringify(url === '' ? {} : { url }) }) };

  return page({ tree: tree || new El('html'), elements, modules: ['desktop-shell-bus', 'desktop-hub', ...modules] });
}

test('the bus publishes to its own subscribers and to the any-handlers, and one deaf consumer silences nobody', () => {
  const p = transport();
  const bus = p.sandbox.MilpaShell;
  const seen = [];
  const all = [];

  bus.on('gate.opened', () => { throw new Error('a subscriber that throws'); });
  bus.on('gate.opened', (fact) => seen.push(fact));
  bus.onAny((type, fact) => all.push([type, fact]));
  bus.emit('gate.opened', { operation: 'fs:write' });

  assert.deepEqual(seen, [{ operation: 'fs:write' }], 'the second subscriber still ran');
  assert.deepEqual(all, [['gate.opened', { operation: 'fs:write' }]], 'and so did the any-handler');
});

test('the transport state is a SIGNAL, so the status bar binds it instead of being poked', () => {
  const p = transport();
  const bus = p.sandbox.MilpaShell;
  const told = [];
  bus.onStatus((state) => told.push(state));

  bus.status('live');
  assert.equal(p.signal('conn.state'), 'live');
  assert.equal(p.signal('conn.label'), '◉ live', 'the words come from the catalog, not from the module');

  bus.status('offline');
  assert.equal(p.signal('conn.state'), 'offline');
  assert.equal(p.signal('conn.label'), '○ offline');
  assert.deepEqual(told, ['live', 'offline'], 'a plugin that tracks the connection still hears it');
});

test('a contributed panel is reachable by id — the plugin DX the bus exists for', () => {
  const html = new El('html');
  const panel = html.appendChild(new El('section', { 'data-panel': 'sessions' }));
  const body = panel.appendChild(new El('div', { 'data-panel-body': '' }));
  const p = transport({ tree: html });

  assert.equal(p.sandbox.MilpaShell.panel('sessions'), body);
  assert.equal(p.sandbox.MilpaShell.panel('nobody'), null);
});

test('a desktop ShellEvent is republished unchanged; an unknown envelope is ignored', () => {
  const p = transport();
  const seen = [];
  p.sandbox.MilpaShell.on('gate.opened', (fact) => seen.push(fact));

  assert.equal(p.desktop().hub.translate({ event: 'gate.opened', data: { operation: 'fs:write' } }), 'event');
  assert.deepEqual(seen, [{ operation: 'fs:write' }]);
  assert.equal(p.desktop().hub.translate({ nothing: true }), '', 'a shape this Desktop does not know is not a fact');
  assert.equal(p.desktop().hub.translate(null), '');
});

test("a governed turn's projection is translated into the facts the shell already renders", () => {
  const p = transport();
  const facts = [];
  p.sandbox.MilpaShell.onAny((type, data) => facts.push([type, data]));
  p.signal('session.tool_calls', 2);

  const hub = p.desktop().hub;
  hub.translate({ kind: 'activity', activity: { state: 'thinking' } });
  hub.translate({ kind: 'reasoning', reasoning: { delta: 'weighing…' } });
  hub.translate({ kind: 'message', message: { content: 'done' } });
  hub.translate({ kind: 'activity', activity: { state: 'tool', detail: 'fs:read', result: '{}' } });
  hub.translate({ kind: 'activity', activity: { state: 'ready' } });

  assert.deepEqual(facts.map(([type]) => type), [
    'session.state', 'agent.reasoning', 'agent.message', 'tool.call', 'session.state',
  ]);
  sameShape(facts[0][1], { state: 'working' });
  sameShape(facts[1][1], { text: 'weighing…' });
  sameShape(facts[2][1], { text: 'done' });
  sameShape(facts[3][1], { name: 'fs:read', result: '{}' });
  sameShape(facts[4][1], { state: 'idle' });
  assert.equal(p.signal('session.tool_calls'), 3, 'a tool that ran is counted into the shared signal');
});

test('a parked question becomes TWO facts — the request and decision.parked — and touches no DOM', () => {
  // It used to say `system.notice` here as well, which was ONE fact told twice: a grey line in the thread
  // and a card in the inbox. The thread now renders the REQUEST, with everything the envelope carried, so
  // the notice was the duplicate and it is gone (greenhouse decisions/0254).
  const p = transport();
  const facts = [];
  p.sandbox.MilpaShell.onAny((type, data) => facts.push([type, data]));

  assert.equal(p.desktop().hub.translate({
    kind: 'waiting',
    ended: { id: 'q-1', question: 'May I write?', reason: 'permission', why: 'it writes outside the project', options: ['yes', 'no'] },
  }), 'session');

  sameShape(facts, [
    ['agent.parked', { id: 'q-1', text: 'May I write?', why: 'it writes outside the project', reason: 'permission', options: ['yes', 'no'] }],
    ['decision.parked', { question: 'May I write?' }],
  ]);
});

test('a waiting envelope that carries only the question still parks it — the options are the agent\'s to give', () => {
  // An older runtime, or a question with nothing to choose between: the request still lands, because a
  // question nobody can see is worse than one nobody can click.
  const p = transport();
  const facts = [];
  p.sandbox.MilpaShell.onAny((type, data) => facts.push([type, data]));

  p.desktop().hub.translate({ kind: 'waiting', ended: { question: 'May I write?' } });

  sameShape(facts, [
    ['agent.parked', { id: '', text: 'May I write?', why: '', reason: '', options: [] }],
    ['decision.parked', { question: 'May I write?' }],
  ]);
});

test('with a hub wired the module opens ONE stream, and reports live / offline on it', () => {
  const opened = [];
  const p = transport({ url: 'https://hub.example/.well-known/mercure?topic=desktop%2Fshell' });
  p.sandbox.EventSource = function (url, options) { opened.push([url, options]); this.url = url; };

  const stream = p.desktop().hub.open();

  assert.equal(opened.length, 1, 'one connection, not one per topic');
  assert.equal(opened[0][0], 'https://hub.example/.well-known/mercure?topic=desktop%2Fshell');
  assert.equal(opened[0][1].withCredentials, true, 'the hub reads the subscriber JWT from the cookie');

  const seen = [];
  p.sandbox.MilpaShell.on('agent.message', (fact) => seen.push(fact));
  stream.onopen();
  assert.equal(p.signal('conn.state'), 'live');
  stream.onmessage({ data: JSON.stringify({ kind: 'message', message: { content: 'hello' } }) });
  sameShape(seen, [{ text: 'hello' }]);
  stream.onmessage({ data: 'not json' });
  sameShape(seen, [{ text: 'hello' }], 'a malformed frame is dropped, not thrown');
  stream.onerror();
  assert.equal(p.signal('conn.state'), 'offline');
});

test('with no hub wired nothing is opened and the bar settles on offline', () => {
  const p = transport();
  p.sandbox.EventSource = function () { throw new Error('nothing must be opened'); };

  assert.equal(p.desktop().hub.url(), '');
  assert.equal(p.desktop().hub.open(), null);
  assert.equal(p.signal('conn.state'), 'offline');
  assert.equal(p.signal('conn.label'), '○ offline');
});

test('the stream is opened on DOMContentLoaded — after every deferred module has subscribed', () => {
  const opened = [];
  const p = transport({ url: 'https://hub.example/x' });
  p.sandbox.EventSource = function (url) { opened.push(url); };

  assert.deepEqual(opened, [], 'loading the module opens nothing');
  p.listeners['DOMContentLoaded'].forEach((fn) => fn());
  assert.deepEqual(opened, ['https://hub.example/x']);
});

/**
 * A HUB THAT CLOSES THE STREAM IS ASKED AGAIN (greenhouse evidence/1091, E4). The subscriber JWT the door sets lives
 * five minutes (milpa/mercure), and a resident's turn lasts longer: in the real Desktop the hub dropped the stream after
 * ~4.5 minutes, the browser reconnected with the same cookie until it expired, got 401 and closed for good — and «live
 * hub not connected» came back for the rest of the turn. A CLOSED stream (readyState 2) is the browser giving up; the
 * page asks the door again, with its ticket, exactly as it did the first time. A stream the browser is still
 * reconnecting (readyState 0) is left to the browser.
 */
test('a panel stream the hub closed is renewed through the door, with the ticket, a bounded number of times', async () => {
  const p = transport();
  const tag = new El('script', { id: 'milpa-desktop-ticket', text: JSON.stringify({ ticket: 'sealed.ticket' }) });
  p.sandbox.document.getElementById = ((get) => (id) => (id === 'milpa-desktop-ticket' ? tag : get(id)))(p.sandbox.document.getElementById.bind(p.sandbox.document));
  const timers = [];
  p.sandbox.setTimeout = (fn, ms) => { timers.push(ms); fn(); };
  const opened = [];
  p.sandbox.EventSource = function (url) { this.url = url; this.readyState = 1; opened.push(this); };
  const asks = stubFetch(p, Array.from({ length: 8 }, (_, i) => response(200, { url: 'https://hub.example/sub?n=' + i })));

  p.desktop().hub.open();
  await settle();
  assert.equal(opened.length, 1);
  assert.equal(asks[0].init.headers['X-Milpa-Session-Ticket'], 'sealed.ticket');

  // the browser is still reconnecting: nothing to ask
  opened[0].readyState = 0; opened[0].onerror();
  await settle();
  assert.equal(asks.length, 1, 'a reconnecting stream is the browser\'s to retry');
  assert.equal(p.signal('conn.state'), 'offline');

  // the hub refused the cookie and the browser gave up: ask the door again and open on what it answers
  opened[0].readyState = 2; opened[0].onerror();
  await settle();
  assert.equal(asks.length, 2, 'the door is asked again');
  assert.equal(asks[1].url, '/workspace/hub');
  assert.equal(asks[1].init.headers['X-Milpa-Session-Ticket'], 'sealed.ticket', 'with the same sealed ticket');
  assert.equal(opened.length, 2);
  assert.equal(opened[1].url, 'https://hub.example/sub?n=1');
  opened[1].onopen();
  assert.equal(p.signal('conn.state'), 'live', 'and the bar is live again');

  // a hub that keeps refusing is not asked forever
  for (let i = 1; i < 8; i++) { const s = opened[opened.length - 1]; s.readyState = 2; s.onerror(); await settle(); }
  assert.ok(asks.length <= 2 + 5, 'at most five renewals in a row without a stream that opens: ' + asks.length);
  assert.equal(p.signal('conn.state'), 'offline');
});

test('a stream whose URL the page carried inline is not renewed through the door — it has no ticket to ask with', async () => {
  const p = transport({ url: 'https://hub.example/x' });
  p.sandbox.setTimeout = (fn) => fn();
  const opened = [];
  p.sandbox.EventSource = function (url) { this.url = url; this.readyState = 1; opened.push(this); };
  const asks = stubFetch(p, [response(200, { url: 'https://hub.example/y' })]);

  p.desktop().hub.open();
  opened[0].readyState = 2; opened[0].onerror();
  await settle();
  assert.equal(asks.length, 0);
  assert.equal(opened.length, 1);
});
