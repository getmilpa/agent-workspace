/** Prior criteria and pending candidate selection through the shipped turn.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { El, page, response, stubFetch } from './support/page.mjs';

function surface() {
  const tree = new El('html');
  const session = tree.appendChild(new El('script', { id: 'milpa-desktop-session' }));
  session.textContent = JSON.stringify({ agent: 'trusted-session' });
  const region = tree.appendChild(new El('section', { class: 'desktop-delivery-evidence' }));
  const enabled = region.appendChild(new El('input', { 'data-expectation-enable': '' }));
  const fields = region.appendChild(new El('fieldset', { 'data-expectation-fields': '', disabled: true }));
  const inputs = {};
  for (const [name, value] of Object.entries({ test_path: 'tests/Owned', test_filter: '', screen_name: 'focus', screen_type: 'focus-counter', screen_definition: '{"props":{"goal":6},"type":"focus-counter"}' })) {
    inputs[name] = fields.appendChild(new El('input', { 'data-expectation-field': name, value }));
  }
  const p = page({ tree, modules: ['desktop-turn', 'desktop-delivery-evidence'] });
  const fresh = new El('section', { class: 'desktop-delivery-evidence', 'data-native-evidence': 'awaiting_candidate' });
  p.sandbox.DOMParser = class { parseFromString(html, type) { assert.equal(type, 'text/html'); assert.equal(html, 'server-render'); return { querySelector: () => fresh }; } };
  const html = () => ({ status: 200, ok: true, text: async () => 'server-render' });
  return { p, tree, region, enabled, fields, inputs, fresh, html };
}

test('criteria are opt-in, exact inputs travel as a string, and session/mode remain owned by the turn', async () => {
  const { p, enabled, html, fresh } = surface();
  assert.deepEqual(JSON.parse(JSON.stringify(p.desktop().delivery.prepare())), {});
  enabled.checked = true;
  const calls = stubFetch(p, [response(201, { ok: true }), html()]);
  await p.desktop().turn.run('Build this screen');
  const fields = JSON.parse(calls[0].init.body);
  assert.deepEqual(JSON.parse(fields.expectation), { test: { path: 'tests/Owned', filter: '' }, screen: { name: 'focus', type: 'focus-counter', definition: { props: { goal: 6 }, type: 'focus-counter' } } });
  assert.equal(fields.session, 'trusted-session');
  assert.equal(fields.mode, 'ask');
  assert.equal(fields.prompt, 'Build this screen');
  assert.equal(calls[1].url, 'http://localhost/desktop');
  assert.equal(calls[1].init.cache, 'no-store');
  assert.equal(p.document.querySelector('.desktop-delivery-evidence'), fresh);
  assert.deepEqual(JSON.parse(JSON.stringify(p.desktop().delivery.prepare())), {}, 'recorded inputs are no longer offered by the server');
});

test('missing or malformed criteria fail before any request or working signal', async () => {
  for (const value of ['', 'null', '[]', '{']) {
    const { p, enabled, inputs } = surface();
    enabled.checked = true;
    if (value === '') { inputs.test_path.value = ''; } else { inputs.screen_definition.value = value; }
    const calls = stubFetch(p, []);
    await p.desktop().turn.run('Build');
    assert.equal(calls.length, 0);
    assert.equal(p.signal('session.working'), false);
  }
});

test('unchecked candidates are omitted; selecting sends only the native workspace', async () => {
  const { p, region, html } = surface();
  const selected = region.appendChild(new El('input', { 'data-delivery-candidate': '', value: 'w0123456789ab' }));
  assert.deepEqual(JSON.parse(JSON.stringify(p.desktop().delivery.prepare())), {});
  selected.checked = true;
  const calls = stubFetch(p, [response(201, { ok: false, error: 'Candidate is stale' }), html()]);
  await p.desktop().turn.run('Review');
  assert.deepEqual(JSON.parse(calls[0].init.body), { prompt: 'Review', session: 'trusted-session', mode: 'ask', deliveryCandidate: 'w0123456789ab' });
  assert.equal(calls.length, 2, 'a domain refusal refreshes the server state too');
});

test('a failed refresh removes old evidence and prevents another turn until reload', async () => {
  const { p, region } = surface();
  region.textContent = 'Ready for human review';
  const calls = stubFetch(p, [response(201, { ok: true }), response(500, {})]);
  await p.desktop().turn.run('Continue');
  assert.equal(region.getAttribute('data-native-evidence'), 'read_failed');
  assert.equal(region.getAttribute('role'), 'alert');
  assert.doesNotMatch(region.textContent, /Ready for human review/);
  await p.desktop().turn.run('Do not repeat');
  assert.equal(calls.length, 2);
});

test('missing and failed server regions cannot become fresh evidence', async () => {
  for (const bad of [null, new El('section', { 'data-native-evidence': 'read_failed' })]) {
    const { p, html, region } = surface();
    p.sandbox.DOMParser = class { parseFromString() { return { querySelector: () => bad }; } };
    stubFetch(p, [html()]);
    await p.desktop().delivery.refresh();
    assert.equal(region.getAttribute('data-native-evidence'), 'read_failed');
    assert.throws(() => p.desktop().delivery.prepare(), /Reload/);
  }
});

test('lost invocation responses still recover server state, and no surface leaves legacy requests alone', async () => {
  const { p, html, fresh } = surface();
  stubFetch(p, [Promise.reject(new Error('Connection lost')), html()]);
  await p.desktop().turn.run('Continue');
  assert.equal(p.document.querySelector('.desktop-delivery-evidence'), fresh);
  fresh.parent.removeChild(fresh);
  const calls = stubFetch(p, [response(201, { ok: true })]);
  await p.desktop().turn.run('Legacy');
  assert.equal(calls.length, 1);
});

test('the opt-in toggles field availability without claiming a recorded expectation', () => {
  const { p, enabled, fields } = surface();
  enabled.checked = true;
  p.listeners.change.forEach((fn) => fn({ target: enabled }));
  assert.equal(fields.disabled, false);
  enabled.checked = false;
  p.listeners.change.forEach((fn) => fn({ target: enabled }));
  assert.equal(fields.disabled, true);
  p.listeners.change.forEach((fn) => fn({ target: new El('input') }));
});
