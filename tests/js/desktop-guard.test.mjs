/**
 * The Desktop's shared runtime module, measured by EXECUTION (greenhouse decisions/0211, phase A4).
 *
 * A stub page loads the shipped `milpa-live.js` verbatim and then `desktop-guard.js` on top of it — no build,
 * no bundler — and each claim of the guard is asserted by running it: the copy comes from the page's catalog,
 * a 401 carrying `signin` leaves for the door with `next` and never settles, a 403 is told once as a
 * `desktop.notice` and rejected, 428 is the capabilities FLOW and not a refusal, `failed()` never tells a
 * refusal twice, the ONE document click listener bumps `ui.dismiss` and calls every consumer, and a second
 * copy of the module is ignored.
 *
 * Run: `node --test 'tests/js/**\/*.test.mjs'` — or `npm test` (Node ≥ 22; node:test is built in).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import vm from 'node:vm';
// ONE copy of the shipped copy, shared with the component harness and checked against `Catalog.php`
// by `ClientCopyTest` — a second hand-typed catalog here is a second place the words can drift.
import { CATALOG } from './support/page.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const LOCAL = path.join(root, 'vendor/milpa/live-web/resources/milpa-live.js');
const GUARD = path.join(root, 'resources/components/desktop-guard/desktop-guard.js');

/**
 * A page carrying the Desktop's catalog script, the live runtime and (unless asked otherwise) the guard.
 * `document` knows only what the two files ask of it at load time.
 */
function page({ catalog = CATALOG, withRuntime = true, withGuard = true } = {}) {
  const listeners = {};
  const warnings = [];
  const assigned = [];
  // The catalog rides in the signals seed, under `desktop.i18n` — one source, seeded the way both
  // hosts seed it (greenhouse decisions/0277).
  const elements = {
    'milpa-live-signals': {
      textContent: JSON.stringify({ 'desktop.i18n': catalog, 'ui.dismiss': 0, 'desktop.notice': null }),
    },
  };
  const sandbox = {
    console: { warn: (m) => warnings.push(m), log() {}, error() {} },
    location: { pathname: '/desktop', search: '?embed=1', assign: (url) => assigned.push(url) },
    document: {
      addEventListener(type, fn) { (listeners[type] ||= []).push(fn); },
      getElementById(id) { return elements[id] || null; },
      querySelector() { return null; },
    },
  };
  sandbox.window = sandbox;
  vm.createContext(sandbox);
  const load = (file) => vm.runInContext(readFileSync(file, 'utf8'), sandbox, { filename: file });
  if (withRuntime) { load(LOCAL); }
  if (withGuard) { load(GUARD); }

  return { sandbox, listeners, warnings, assigned, load, desktop: () => sandbox.MilpaLive && sandbox.MilpaLive.desktop };
}

/** A fetch Response as the guard reads one: `ok`, `status`, and a body it reads with `text()`. */
function response(status, body = {}) {
  return { ok: status >= 200 && status < 300, status, text: () => Promise.resolve(JSON.stringify(body)) };
}

test('it hangs off the framework runtime and speaks the page catalog', () => {
  const { desktop } = page();

  assert.ok(desktop(), 'MilpaLive.desktop is the module');
  assert.equal(typeof desktop().guarded, 'function');
  assert.equal(desktop().tr('guard.forbidden'), 'Not allowed here');
  assert.equal(desktop().tr('guard.forbidden.reason', 'loopback_only'), 'Not allowed here (loopback_only)');
  assert.equal(desktop().tr('nobody.wrote.this'), 'nobody.wrote.this', 'a key nobody wrote answers as itself');
});

test('without the runtime it refuses to install itself', () => {
  const p = page({ withRuntime: false, withGuard: false });
  p.load(GUARD);

  assert.equal(p.sandbox.MilpaLive, undefined);
  assert.match(p.warnings.join(' '), /must load first/);
});

test('a second copy is ignored and the first stands', () => {
  const p = page();
  const first = p.desktop();
  p.load(GUARD);

  assert.equal(p.desktop(), first);
  assert.match(p.warnings.join(' '), /loaded twice/);
});

test('a 2xx passes straight through', async () => {
  const { desktop } = page();
  const ok = response(200, { ok: true });

  assert.equal(await desktop().guarded(ok), ok);
});

test('a 401 with signin leaves for the door with next, and never settles', async () => {
  const p = page();
  let settled = false;

  p.desktop().guarded(response(401, { signin: '/webauthn/signin' })).then(() => { settled = true; }, () => { settled = true; });
  await new Promise((r) => setTimeout(r, 5));

  assert.deepEqual(p.assigned, ['/webauthn/signin?next=%2Fdesktop%3Fembed%3D1'], 'next carries the path AND the query');
  assert.equal(settled, false, 'a page that is leaving settles nothing');
});

test('a 403 is told once as a desktop.notice and rejected as told', async () => {
  const p = page();
  const seen = [];
  p.desktop().onNotice((n) => seen.push(n));

  const err = await p.desktop().guarded(response(403, { error: 'loopback_only' })).then(() => null, (e) => e);

  assert.equal(err.status, 403);
  assert.equal(err.told, true);
  assert.deepEqual(seen.map((n) => [n.kind, n.text]), [['error', 'Not allowed here (loopback_only)']]);
  assert.equal(p.sandbox.MilpaLive.signal('desktop.notice').text, 'Not allowed here (loopback_only)', 'the signal carries it too');

  // Told once: failed() on the same error says nothing more.
  p.desktop().failed(err, 'unreachable');
  assert.equal(seen.length, 1);
});

test('any other non-2xx rejects with its status and is told by failed()', async () => {
  const p = page();
  const seen = [];
  p.desktop().onNotice((n) => seen.push(n));

  const err = await p.desktop().guarded(response(500, {})).then(() => null, (e) => e);
  assert.equal(err.status, 500);
  assert.equal(err.told, false);

  p.desktop().failed(err);
  assert.deepEqual(seen.map((n) => n.text), ['The request failed (HTTP 500)']);

  // A network failure has no status: the caller's own copy is what is said.
  p.desktop().failed(new Error('boom'), p.desktop().tr('guard.unreachable'));
  assert.deepEqual(seen.map((n) => n.text), ['The request failed (HTTP 500)', 'The app could not be reached']);
});

test('428 is the capabilities flow, not a refusal — but only through guardedFlow', async () => {
  const p = page();
  const gate = response(428, { confirm_token: 'tok' });

  assert.equal(await p.desktop().guardedFlow(gate), gate, 'the confirm gate passes');
  const refused = await p.desktop().guarded(gate).then(() => null, (e) => e);
  assert.equal(refused.status, 428, 'the strict guard still refuses it');

  // A door still answers a door through the flow guard.
  const forbidden = await p.desktop().guardedFlow(response(403, { error: 'nope' })).then(() => null, (e) => e);
  assert.equal(forbidden.status, 403);
});

test('one document listener bumps ui.dismiss and calls every consumer', () => {
  const p = page();
  const seen = [];
  p.desktop().onDismiss((e) => seen.push(['menu', e.target]));
  p.desktop().onDismiss(() => { throw new Error('one deaf consumer'); });
  p.desktop().onDismiss((e) => seen.push(['popup', e.target]));

  const clicks = p.listeners.click || [];
  assert.equal(clicks.length, 1, 'exactly ONE document-level click listener');

  clicks[0]({ target: 'the-field' });

  assert.deepEqual(seen, [['menu', 'the-field'], ['popup', 'the-field']], 'a throwing consumer never silences the rest');
  assert.equal(p.sandbox.MilpaLive.signal('ui.dismiss'), 1);
  clicks[0]({ target: 'elsewhere' });
  assert.equal(p.sandbox.MilpaLive.signal('ui.dismiss'), 2, 'the signal is a counter, so every click is a new dismissal');
});

// ── the house's «no» is an answer (greenhouse decisions/0583) ───────────────────────────────────────
const REFUSAL = { ok: false, error: 'The node \'publish\' (essay:publish) did not run: it needs the scope essay:publish, which actor:clerk does not hold.' };

test('an operation that ran and answered «no» comes back as 409, and `answered` hands its body on', async () => {
  const { desktop, sandbox } = page();
  const told = [];
  desktop().onNotice((notice) => told.push(notice.text));

  const passed = await desktop().answered(response(409, REFUSAL));

  assert.equal(passed.status, 409, 'the status is still the one the house gave');
  assert.equal(passed.ok, false);
  // The body crosses from the module's context: compared as what it says, not as the same object.
  assert.equal(JSON.stringify(await passed.json()), JSON.stringify(REFUSAL), 'the operation\'s own body is what the caller reads');
  assert.deepEqual(JSON.parse(await passed.text()), REFUSAL);
  assert.deepEqual(told, [], 'an answer is not a failure: nothing is told on the caller\'s behalf');
  assert.equal(sandbox.MilpaLive.signal('desktop.notice'), null);
});

test('`answered` passes a 2xx untouched, and judges everything else as `guarded` does', async () => {
  const { desktop, assigned } = page();
  const told = [];
  desktop().onNotice((notice) => told.push(notice.text));

  const fine = response(201, { ok: true });
  assert.equal(await desktop().answered(fine), fine);

  // A 409 that is NOT the operation's own answer — a listener stopped it — is a call that failed.
  await assert.rejects(desktop().answered(response(409, { error: 'stopped by a listener', code: 'MILPA_OPERATION_STOPPED' })), (err) => {
    assert.equal(err.status, 409);
    assert.equal(err.body.code, 'MILPA_OPERATION_STOPPED');

    return true;
  });
  // A body that says `ok: false` under another status is the door or the server speaking, not the operation.
  await assert.rejects(desktop().answered(response(500, { ok: false, error: 'internal_error', reference: 'abc' })), (err) => err.status === 500);
  await assert.rejects(desktop().answered(response(501, { ok: false, error: 'This app offers no graph:decide' })), (err) => err.status === 501);
  await assert.rejects(desktop().answered(response(422, { errors: { decision: 'required' } })), (err) => err.status === 422);
  // A 409 whose body is not JSON at all.
  await assert.rejects(desktop().answered({ ok: false, status: 409, text: () => Promise.resolve('<html>conflict</html>') }), (err) => err.status === 409);

  await assert.rejects(desktop().answered(response(403, { error: 'This operation requires scope graph:decide' })), (err) => err.told === true);
  assert.deepEqual(told, ['Not allowed here (This operation requires scope graph:decide)'], 'a 403 is still told once, by the guard');

  desktop().answered(response(401, { signin: '/webauthn/signin' }));
  await new Promise((resolve) => setTimeout(resolve, 5));
  assert.equal(assigned.length, 1, 'and a 401 still leaves for the door');
});

test('`answeredFlow` is the confirm flow that also reads an answer: 428 and the house\'s «no» both pass', async () => {
  const { desktop } = page();

  const gate = response(428, { requires_confirmation: true, confirm_token: 'tok-1' });
  assert.equal(await desktop().answeredFlow(gate), gate);
  assert.equal(JSON.stringify(await (await desktop().answeredFlow(response(409, REFUSAL))).json()), JSON.stringify(REFUSAL));
  await assert.rejects(desktop().answeredFlow(response(409, { error: 'stopped by a listener' })), (err) => err.status === 409);
  await assert.rejects(desktop().answeredFlow(response(404, {})), (err) => err.status === 404);
});

test('`guarded` is unchanged: a caller that never reads the body is still told the house said «no»', async () => {
  const { desktop } = page();
  const told = [];
  desktop().onNotice((notice) => told.push(notice.text));

  const err = await desktop().guarded(response(409, REFUSAL)).then(() => null, (e) => e);

  assert.ok(err, 'a 409 does not pass for a success');
  assert.equal(err.status, 409);
  desktop().failed(err, 'unreachable');
  assert.deepEqual(told, [REFUSAL.error], 'and what is told is the operation\'s sentence, not a status');
});
