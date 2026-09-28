/**
 * A seat's frontier, decided from the inbox, by EXECUTION (greenhouse decisions/0493).
 *
 * A stub page carries one frontier card as `DecisionsInboxView::frontierHtml()` prints it; the shipped
 * module is loaded on top and driven by a click. Each claim is asserted by what it POSTed and what it
 * painted: the ceremony asks for a challenge bound to `identity:grant {session, seq}`, the passkey signs
 * it, the grant carries the assertion and never a scope, walks the confirm gate, and a refusal is painted.
 *
 * Run: `node --test 'tests/js/**\/*.test.mjs'` — or `npm test` (Node ≥ 22; node:test is built in).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { El, page, response, settle, stubFetch, CATALOG } from './support/page.mjs';

/** One frontier card, as the server prints it. */
function tree() {
  const root = new El('html');
  const list = root.appendChild(new El('ol', { id: 'milpa-frontier-list' }));
  const card = list.appendChild(new El('li', { class: 'decision-card decision-card--frontier', 'data-seat-session': 'camino-blog', 'data-seat-seq': '42', 'data-seat-permission': 'plugins.Blog:write' }));
  card.appendChild(new El('p', { class: 'decision-card__facts', 'data-seat-status': '' }));
  const options = card.appendChild(new El('p', { class: 'decision-card__options' }));
  options.appendChild(new El('button', { 'data-seat-grant': '', text: 'Grant plugins.Blog:write' }));

  return root;
}

function click(p, el) {
  (p.listeners.click || []).forEach((fn) => fn({ target: el, preventDefault() {} }));
}

/** A browser with a passkey: records what it was asked to sign and answers a fixed assertion. */
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

const OPTIONS = { rpId: 'localhost', challenge: 'Y2hhbGxlbmdl', allowCredentials: [{ type: 'public-key', id: 'Y3JlZC1yb2Q' }], operation: 'identity:grant' };
const GRANTED = { ok: true, fingerprint: '95A3', granted: 'plugins.Blog:write', authorized_by: 'passkey:cred-rod' };

test('a grant binds the touch to THIS refusal, carries no scope, and walks the confirm gate', async () => {
  const root = tree();
  const p = page({ tree: root, catalog: CATALOG, modules: ['desktop-decisions'] });
  const signed = withPasskey(p);
  const calls = stubFetch(p, [response(200, OPTIONS), response(428, { requires_confirmation: true, confirm_token: 'tok-1' }), response(201, GRANTED)]);
  const card = root.querySelector('[data-seat-session]');

  click(p, card.querySelector('[data-seat-grant]'));
  await settle();

  assert.equal(calls.length, 3, 'the challenge, the gate, the grant');
  assert.equal(calls[0].url, '/webauthn/intent/options');
  assert.deepEqual(JSON.parse(calls[0].init.body), { operation: 'identity:grant', arguments: { session: 'camino-blog', seq: 42 }, session: 'camino-blog' });
  assert.equal(signed.length, 1, 'the passkey was asked once');
  assert.equal(signed[0].publicKey.rpId, 'localhost');
  assert.equal(signed[0].publicKey.userVerification, 'required');

  assert.equal(calls[1].url, '/workspace/grant');
  const body = JSON.parse(calls[1].init.body);
  assert.equal(body.session, 'camino-blog');
  assert.equal(body.seq, 42);
  assert.equal(body.assertion.credentialId, 'Y3JlZC1yb2Q', 'the credential travels base64url, as the ceremony reads it');
  assert.ok(!('scope' in body) && !('permission' in body) && !('scopes' in body), 'the client never names a scope');
  assert.equal(calls[1].init.headers['Confirm-Token'], undefined);
  assert.equal(calls[2].init.headers['Confirm-Token'], 'tok-1');

  assert.equal(card.querySelector('[data-seat-status]').textContent, 'granted · plugins.Blog:write — the seat can continue');
  assert.equal(card.getAttribute('data-granted'), '');
  assert.equal(card.querySelector('[data-seat-grant]').getAttribute('hidden'), '', 'the button steps aside once granted');
});

test('a refused grant is painted, not swallowed, and the card stays open', async () => {
  const root = tree();
  const p = page({ tree: root, catalog: CATALOG, modules: ['desktop-decisions'] });
  withPasskey(p);
  stubFetch(p, [response(200, OPTIONS), response(201, { ok: false, error: 'you do not answer for this session\'s seat' })]);
  const card = root.querySelector('[data-seat-session]');

  click(p, card.querySelector('[data-seat-grant]'));
  await settle();

  assert.equal(card.querySelector('[data-seat-status]').textContent, 'the grant was refused: you do not answer for this session\'s seat');
  assert.equal(card.getAttribute('data-granted'), null);
});

test('a browser without passkeys says so and posts nothing', async () => {
  const root = tree();
  const p = page({ tree: root, catalog: CATALOG, modules: ['desktop-decisions'] });
  const calls = stubFetch(p, []);
  const card = root.querySelector('[data-seat-session]');

  click(p, card.querySelector('[data-seat-grant]'));
  await settle();

  assert.equal(calls.length, 0);
  assert.equal(card.querySelector('[data-seat-status]').textContent, 'this browser cannot run the passkey ceremony');
});
