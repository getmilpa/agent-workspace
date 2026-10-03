/*!
 * A region re-reads itself from the house, by EXECUTION (greenhouse decisions/0563).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 *
 * What the house decides is DERIVED on the server: the frontier from the refused calls, the enrollment
 * ledger and the authoring policy; the verdict from the whole stream. A pushed fact says «this changed», and
 * the region that shows it asks the house for itself again — the page a reload would get, without the
 * reload. These tests load the SHIPPED module and assert what it requested and what it left on the page.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { El, moduleFile, page, settle } from './support/page.mjs';

/** A page carrying two marked regions and the seeded signals tag. */
function surface({ modules = [] } = {}) {
  const tree = new El('html');
  const decisions = tree.appendChild(new El('div', { 'data-live-region': 'decisions.frontier' }));
  decisions.appendChild(new El('ol', { id: 'milpa-frontier-list' }));
  const work = tree.appendChild(new El('div', { 'data-live-region': 'work' }));
  work.appendChild(new El('p', { text: 'No work board yet' }));
  const p = page({ tree, modules: ['desktop-shell-bus', 'desktop-regions', ...modules] });

  return { p, tree, decisions, work };
}

/** What the house would print now: a fresh document with the regions named, and optionally its signal seeds. */
function fresh(regions, signals = null) {
  const doc = new El('html');
  for (const [name, text] of Object.entries(regions)) {
    doc.appendChild(new El('div', { 'data-live-region': name, text }));
  }
  if (signals !== null) {
    doc.appendChild(new El('script', { id: 'milpa-live-signals', text: JSON.stringify(signals) }));
  }

  return doc;
}

/** Serve the documents given, one per request, and record every request the page MADE. */
function serve(p, docs) {
  const calls = [];
  const queue = [...docs];
  let parsed = null;
  p.sandbox.fetch = (url, init) => {
    calls.push({ url, init });
    const next = queue.shift();
    if (next instanceof Error) { return Promise.reject(next); }
    // An error answer may still carry markup — an error page printed inside the panel's frame — and it is
    // not what the house says the region is now.
    if (next && next.status) { parsed = next.doc || null; return Promise.resolve({ ok: false, status: next.status, text: async () => 'an error page' }); }
    parsed = next;

    return Promise.resolve({ ok: true, status: 200, text: async () => 'the page' });
  };
  p.sandbox.DOMParser = class {
    parseFromString(html, type) {
      assert.equal(type, 'text/html');
      const doc = parsed;

      return { querySelectorAll: (s) => doc.querySelectorAll(s), getElementById: (id) => doc.descendants.find((el) => el.id === id) || null };
    }
  };

  return calls;
}

const regionText = (tree, name) => tree.querySelectorAll(`[data-live-region="${name}"]`).map((el) => el.textContent);

test('a region asked for is replaced by what the house prints now, and only that region', async () => {
  const { p, tree, work } = surface();
  const calls = serve(p, [fresh({ 'decisions.frontier': 'Grant plugins.Blog:write', work: 'changed too' })]);

  const outcome = await p.desktop().regions.reread(['decisions.frontier']);

  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, 'http://localhost/desktop', 'the page itself is asked — the same request a reload makes');
  assert.equal(calls[0].init.cache, 'no-store');
  assert.equal(calls[0].init.headers.Accept, 'text/html');
  assert.deepEqual(regionText(tree, 'decisions.frontier'), ['Grant plugins.Blog:write']);
  assert.equal(tree.querySelectorAll('[data-live-region="work"]')[0], work, 'a region nobody asked for is left alone');
  assert.equal(outcome['decisions.frontier'], 'fresh');
});

test('asks made together cost ONE request, and an ask made while reading is read again after', async () => {
  const { p, tree } = surface();
  const calls = serve(p, [
    fresh({ 'decisions.frontier': 'first', work: 'first' }),
    fresh({ 'decisions.frontier': 'second', work: 'second' }),
    fresh({ 'decisions.frontier': 'third', work: 'third' }),
  ]);
  const regions = p.desktop().regions;

  const a = regions.reread(['decisions.frontier']);
  const b = regions.reread(['work']);
  assert.equal(calls.length, 0, 'nothing is requested until the asks of this tick are gathered');
  await settle();
  assert.equal(calls.length, 1, 'two asks in one tick are one request');
  await Promise.all([a, b]);
  assert.deepEqual(regionText(tree, 'work'), ['first']);

  // A fact that lands while the page is being read is not lost: the page is read once more.
  let release;
  let made = 0;
  p.sandbox.fetch = ((inner) => (url, init) => new Promise((resolve) => { made += 1; release = () => resolve(inner(url, init)); }))(p.sandbox.fetch);
  const c = regions.reread(['work']);
  await settle();
  const d = regions.reread(['work']);
  const e = regions.reread(['work']);
  await settle();
  assert.equal(made, 1, 'while the page is being read, asking requests nothing more');
  release();
  await settle();
  release();
  await Promise.all([c, d, e]);
  assert.equal(calls.length, 3, 'ONE trailing read for the two asks that landed meanwhile, not one per ask');
  assert.deepEqual(regionText(tree, 'work'), ['third'], 'and the page shows what the house printed last');
});

test('a page that does not carry the region leaves what is shown and marks it stale', async () => {
  for (const answer of [fresh({ somethingElse: 'signed out' }), { status: 500 }, { status: 500, doc: fresh({ 'decisions.frontier': 'an error page that prints the region' }) }, new Error('unreachable')]) {
    const { p, tree, decisions } = surface();
    serve(p, [answer]);

    const outcome = await p.desktop().regions.reread(['decisions.frontier']);

    assert.equal(tree.querySelectorAll('[data-live-region="decisions.frontier"]')[0], decisions, 'what the human was reading stays');
    assert.equal(decisions.getAttribute('data-live-stale'), '', 'and it says it could not be re-read');
    assert.equal(outcome['decisions.frontier'], 'stale');
  }
});

test('a region this page does not carry is not requested at all', async () => {
  const { p } = surface();
  const calls = serve(p, []);

  const outcome = await p.desktop().regions.reread(['nobody.here']);

  assert.equal(calls.length, 0);
  assert.equal(outcome['nobody.here'], 'absent');
});

test('a region a human is acting in is not swapped under them; it is re-read when they finish', async () => {
  const { p, tree, decisions } = surface();
  const card = decisions.appendChild(new El('li', { 'data-busy': '' }));
  const calls = serve(p, [fresh({ 'decisions.frontier': 'while busy' }), fresh({ 'decisions.frontier': 'after' })]);
  const regions = p.desktop().regions;

  const outcome = await regions.reread(['decisions.frontier']);
  assert.equal(outcome['decisions.frontier'], 'deferred');
  assert.equal(tree.querySelectorAll('[data-live-region="decisions.frontier"]')[0], decisions);

  card.removeAttribute('data-busy');
  await regions.resume();
  assert.equal(calls.length, 2);
  assert.deepEqual(regionText(tree, 'decisions.frontier'), ['after']);

  await regions.resume();
  assert.equal(calls.length, 2, 'nothing was deferred the second time, so nothing is read');
});

test('a surface may carry something of the old region into the fresh one', async () => {
  const { p, tree, decisions } = surface();
  decisions.appendChild(new El('li', { 'data-granted': '', text: 'granted · plugins.Blog:write' }));
  serve(p, [fresh({ 'decisions.frontier': '' })]);
  const regions = p.desktop().regions;
  const seen = [];
  regions.keep('decisions.frontier', (old, now) => {
    seen.push([old, now]);
    old.querySelectorAll('[data-granted]').forEach((kept) => now.appendChild(kept));
  });

  await regions.reread(['decisions.frontier']);

  const shown = tree.querySelectorAll('[data-live-region="decisions.frontier"]')[0];
  assert.equal(seen.length, 1);
  assert.equal(seen[0][0], decisions);
  assert.notEqual(shown, decisions);
  assert.equal(shown.querySelectorAll('[data-granted]').length, 1, 'the receipt of what the human did survives the re-read');
});

test("the session's figures are re-read as SIGNALS, and only the session's", async () => {
  const { p } = surface();
  p.sandbox.MilpaLive.signal('context.used', '10.00K');
  p.sandbox.MilpaLive.signal('desktop.tab', 'decisions');
  // A run that ended while the stream was being renewed never said so (greenhouse evidence/1097): the page kept
  // «Working». Whether the session is working is the house's to say, so it is re-read with the other figures.
  p.sandbox.MilpaLive.signal('session.working', true);
  p.sandbox.MilpaLive.signal('session.state.label', 'Working');
  const calls = serve(p, [fresh({}, { 'context.used': '35.86K', 'context.window': '49.15K', 'session.turns': 3, 'session.working': false, 'session.state.label': 'Idle', 'desktop.tab': 'chat', 'composer.panel': 'context' })]);

  const outcome = await p.desktop().regions.reread(['signals']);

  assert.equal(calls.length, 1);
  assert.equal(outcome.signals, 'fresh');
  assert.equal(p.signal('context.used'), '35.86K');
  assert.equal(p.signal('context.window'), '49.15K');
  assert.equal(p.signal('session.turns'), 3);
  assert.equal(p.signal('session.working'), false, 'a run whose end was never pushed stops reading as working');
  assert.equal(p.signal('session.state.label'), 'Idle');
  assert.equal(p.signal('desktop.tab'), 'decisions', 'where the human is standing is theirs, never re-read');
  assert.equal(p.signal('composer.panel'), '', 'nor what they have open');
});

test('with no hub the regions are re-read on a poll, and the poll stops when the hub is live', async () => {
  const { p, tree } = surface();
  const timers = [];
  const cleared = [];
  p.sandbox.setInterval = (fn, ms) => { timers.push({ fn, ms }); return timers.length; };
  p.sandbox.clearInterval = (id) => { cleared.push(id); };
  const calls = serve(p, [
    fresh({ 'decisions.frontier': 'polled', work: 'polled' }, {}),
    fresh({ 'decisions.frontier': 'caught up', work: 'caught up' }, {}),
  ]);
  const bus = p.sandbox.MilpaShell;

  bus.status('connecting');
  assert.equal(timers.length, 0, 'a stream still opening is not «no hub»');
  bus.status('offline');
  bus.status('offline');
  assert.equal(timers.length, 1, 'one poll, however many times the transport says offline');
  assert.ok(timers[0].ms >= 5000, 'a poll is a whole page render: it is slow on purpose');
  assert.equal(calls.length, 0, 'saying offline requests nothing by itself');

  timers[0].fn();
  await settle();
  assert.equal(calls.length, 1);
  assert.deepEqual(regionText(tree, 'work'), ['polled']);

  // A hidden window polls nothing: nobody is reading it.
  p.document.hidden = true;
  timers[0].fn();
  await settle();
  assert.equal(calls.length, 1);
  p.document.hidden = false;

  // The hub came back: the poll stops, and what was pushed while it was away is caught up ONCE.
  bus.status('live');
  await settle();
  assert.deepEqual(cleared, [1]);
  assert.equal(calls.length, 2);
  assert.deepEqual(regionText(tree, 'decisions.frontier'), ['caught up']);

  bus.status('live');
  await settle();
  assert.equal(calls.length, 2, 'live after live is not a return: nothing is re-read');
});

test('a page whose stream opens at once never polls and re-reads nothing', async () => {
  const { p } = surface();
  const timers = [];
  p.sandbox.setInterval = (fn, ms) => { timers.push({ fn, ms }); return timers.length; };
  const calls = serve(p, []);

  p.sandbox.MilpaShell.status('connecting');
  p.sandbox.MilpaShell.status('live');
  await settle();

  assert.equal(timers.length, 0);
  assert.equal(calls.length, 0);
});

test('loading the module twice keeps the first', () => {
  const { p } = surface();
  const first = p.desktop().regions;
  p.load(moduleFile('desktop-regions'));

  assert.equal(p.desktop().regions, first);
});
