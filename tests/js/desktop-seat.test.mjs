/**
 * Giving the resident a seat from the inbox, by EXECUTION (greenhouse decisions/0499).
 *
 * A stub page carries the form `DecisionsInboxView::seatsHtml()` prints; the shipped module is loaded on top and
 * driven by a click. The ceremony asks for a challenge bound to `identity:seat {label}` in the `identity:seat`
 * intent session, the passkey signs it, the call carries the assertion and never a scope, walks the confirm gate,
 * and the command the resident's key runs is shown — or the refusal is painted.
 *
 * Run: `node --test 'tests/js/**\/*.test.mjs'` — or `npm test` (Node ≥ 22; node:test is built in).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { El, page, response, settle, stubFetch, CATALOG } from './support/page.mjs';

/** The give-a-seat form, as the server prints it. */
function tree(label = 'resident', taken = []) {
  const root = new El('html');
  const form = root.appendChild(new El('div', { class: 'decision-card decision-card--give-seat', 'data-seat-give-form': '', 'data-seat-taken': JSON.stringify(taken) }));
  const options = form.appendChild(new El('p', { class: 'decision-card__options' }));
  const input = options.appendChild(new El('input', { 'data-seat-label': '' }));
  input.value = label;
  options.appendChild(new El('button', { 'data-seat-give': '', text: 'Give the resident a seat' }));
  form.appendChild(new El('p', { class: 'decision-card__facts', 'data-seat-give-status': '' }));
  form.appendChild(new El('pre', { 'data-seat-command': '', hidden: '' }));

  return root;
}

function click(p, el) {
  (p.listeners.click || []).forEach((fn) => fn({ target: el, preventDefault() {} }));
}

function withPasskey(p) {
  const asked = [];
  const bytes = (text) => new Uint8Array([...text].map((c) => c.charCodeAt(0))).buffer;
  p.sandbox.atob = (s) => Buffer.from(s, 'base64').toString('binary');
  p.sandbox.btoa = (s) => Buffer.from(s, 'binary').toString('base64');
  p.sandbox.Uint8Array = Uint8Array;
  p.sandbox.PublicKeyCredential = function PublicKeyCredential() {};
  p.sandbox.navigator = {
    credentials: {
      get(options) {
        asked.push(options);
        return Promise.resolve({
          rawId: bytes('cred-rod'),
          response: { clientDataJSON: bytes('{"c":1}'), authenticatorData: bytes('auth'), signature: bytes('sig') },
        });
      },
    },
  };

  return asked;
}

const OPTIONS = { rpId: 'localhost', challenge: 'Y2hhbGxlbmdl', allowCredentials: [{ type: 'public-key', id: 'Y3JlZC1yb2Q' }], operation: 'identity:seat' };
const MINTED = { ok: true, label: 'resident', command: 'php bin/coa identity:accept --invite=SECRET --sign', scopes: ['agent:run'], vouched_by: 'passkey:cred-rod', for_key: null, expires_at: '2026-09-28T20:00:00+00:00' };

test('a seat binds the touch to THIS name, carries no scope, walks the confirm gate and shows the command', async () => {
  const root = tree();
  const p = page({ tree: root, catalog: CATALOG, modules: ['desktop-decisions'] });
  const signed = withPasskey(p);
  const calls = stubFetch(p, [response(200, OPTIONS), response(428, { requires_confirmation: true, confirm_token: 'tok-1' }), response(201, MINTED)]);
  const form = root.querySelector('[data-seat-give-form]');

  click(p, form.querySelector('[data-seat-give]'));
  await settle();

  assert.equal(calls.length, 3, 'the challenge, the gate, the seat');
  assert.equal(calls[0].url, '/webauthn/intent/options');
  assert.deepEqual(JSON.parse(calls[0].init.body), { operation: 'identity:seat', arguments: { label: 'resident' }, session: 'identity:seat' });
  assert.equal(signed.length, 1);
  assert.equal(signed[0].publicKey.userVerification, 'required');

  assert.equal(calls[1].url, '/workspace/seat');
  const body = JSON.parse(calls[1].init.body);
  assert.equal(body.label, 'resident');
  assert.equal(body.assertion.credentialId, 'Y3JlZC1yb2Q');
  assert.ok(!('scope' in body) && !('scopes' in body), 'the client never names a scope');
  assert.equal(calls[2].init.headers['Confirm-Token'], 'tok-1');

  assert.equal(form.querySelector('[data-seat-give-status]').textContent, 'Run this where the resident lives, with its own key, before 2026-09-28T20:00:00+00:00:');
  const command = form.querySelector('[data-seat-command]');
  assert.equal(command.textContent, 'php bin/coa identity:accept --invite=SECRET --sign');
  assert.equal(command.getAttribute('hidden'), null, 'the command is shown');
});

test('the name the human typed is the name bound to the touch', async () => {
  const root = tree('builder');
  const p = page({ tree: root, catalog: CATALOG, modules: ['desktop-decisions'] });
  withPasskey(p);
  const calls = stubFetch(p, [response(200, OPTIONS), response(201, { ...MINTED, label: 'builder' })]);

  click(p, root.querySelector('[data-seat-give]'));
  await settle();

  assert.deepEqual(JSON.parse(calls[0].init.body).arguments, { label: 'builder' });
  assert.equal(JSON.parse(calls[1].init.body).label, 'builder');
});

test('a refused seat is painted and no command appears', async () => {
  const root = tree();
  const p = page({ tree: root, catalog: CATALOG, modules: ['desktop-decisions'] });
  withPasskey(p);
  stubFetch(p, [response(200, OPTIONS), response(201, { ok: false, error: 'the passkey did not approve THIS identity:seat' })]);
  const form = root.querySelector('[data-seat-give-form]');

  click(p, form.querySelector('[data-seat-give]'));
  await settle();

  assert.equal(form.querySelector('[data-seat-give-status]').textContent, 'no seat was given: the passkey did not approve THIS identity:seat');
  assert.equal(form.querySelector('[data-seat-command]').getAttribute('hidden'), '');
});

test('a browser without passkeys says so and posts nothing', async () => {
  const root = tree();
  const p = page({ tree: root, catalog: CATALOG, modules: ['desktop-decisions'] });
  const calls = stubFetch(p, []);

  click(p, root.querySelector('[data-seat-give]'));
  await settle();

  assert.equal(calls.length, 0);
  assert.equal(root.querySelector('[data-seat-give-status]').textContent, 'this browser cannot run the passkey ceremony');
});

// ── one seat per name (greenhouse decisions/0536, evidence/1069 §C3) ─────────────────────────────────────────
test('a name a seat already carries costs no touch and no request: the module says so', async () => {
  for (const [typed, said] of [['Resident', '«Resident» already has a seat — give the new one another name.'], ['', 'name the new seat first']]) {
    const root = tree(typed, ['resident']);
    const p = page({ tree: root, catalog: CATALOG, modules: ['desktop-decisions'] });
    const signed = withPasskey(p);
    const calls = stubFetch(p, []);

    click(p, root.querySelector('[data-seat-give]'));
    await settle();

    assert.equal(signed.length, 0, 'no passkey touch for a name the judge would refuse');
    assert.equal(calls.length, 0, 'no request either');
    assert.equal(root.querySelector('[data-seat-give-status]').textContent, said);
  }
});

test('another name, with the resident seated, still gives a seat', async () => {
  const root = tree('reviewer', ['resident']);
  const p = page({ tree: root, catalog: CATALOG, modules: ['desktop-decisions'] });
  withPasskey(p);
  const calls = stubFetch(p, [response(200, OPTIONS), response(428, { requires_confirmation: true, confirm_token: 'tok-1' }), response(201, { ...MINTED, label: 'reviewer' })]);

  click(p, root.querySelector('[data-seat-give]'));
  await settle();

  assert.equal(calls.length, 3);
  assert.equal(JSON.parse(calls[2].init.body).label, 'reviewer');
});
