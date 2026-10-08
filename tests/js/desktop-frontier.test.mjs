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

/** The HelloPlugin card of evidence/1036, as the server prints it after decisions/0510. */
function informedTree() {
  const root = new El('html');
  const list = root.appendChild(new El('ol', { id: 'milpa-frontier-list' }));
  const card = list.appendChild(new El('li', { class: 'decision-card decision-card--frontier decision-card--informed', 'data-seat-session': 'camino-1036-blog', 'data-seat-seq': '49', 'data-seat-permission': 'plugins.HelloPlugin:write', 'data-seat-existing': 'HelloPlugin' }));
  const label = card.appendChild(new El('label'));
  label.appendChild(new El('input', { type: 'checkbox', 'data-seat-ack': '' }));
  card.appendChild(new El('p', { class: 'decision-card__facts', 'data-seat-status': '' }));
  const options = card.appendChild(new El('p', { class: 'decision-card__options' }));
  options.appendChild(new El('button', { 'data-seat-grant': '', text: 'Grant write over existing HelloPlugin' }));

  return root;
}

test('a grant over existing work is not one touch: without the box ticked nothing is asked or posted', async () => {
  const root = informedTree();
  const p = page({ tree: root, catalog: CATALOG, modules: ['desktop-decisions'] });
  const signed = withPasskey(p);
  const calls = stubFetch(p, []);
  const card = root.querySelector('[data-seat-session]');

  click(p, card.querySelector('[data-seat-grant]'));
  await settle();

  assert.equal(calls.length, 0, 'no challenge, no grant');
  assert.equal(signed.length, 0, 'the passkey was never asked');
  assert.equal(card.querySelector('[data-seat-status]').textContent, 'tick the box first: this grant opens write over existing work');
  assert.equal(card.getAttribute('data-granted'), null);
});

test('ticked, the touch is bound to the grant WITH the plugin named, and the grant carries it', async () => {
  const root = informedTree();
  const p = page({ tree: root, catalog: CATALOG, modules: ['desktop-decisions'] });
  const signed = withPasskey(p);
  const calls = stubFetch(p, [response(200, OPTIONS), response(201, { ok: true, granted: 'plugins.HelloPlugin:write' })]);
  const card = root.querySelector('[data-seat-session]');
  card.querySelector('[data-seat-ack]').checked = true;

  click(p, card.querySelector('[data-seat-grant]'));
  await settle();

  assert.equal(signed.length, 1);
  assert.deepEqual(JSON.parse(calls[0].init.body), { operation: 'identity:grant', arguments: { session: 'camino-1036-blog', seq: 49, existing: 'HelloPlugin' }, session: 'camino-1036-blog' });
  const body = JSON.parse(calls[1].init.body);
  assert.equal(body.existing, 'HelloPlugin');
  assert.equal(body.seq, 49);
  assert.ok(!('permission' in body) && !('scope' in body), 'still no scope from the client');
  assert.equal(card.getAttribute('data-granted'), '');
});

// ── the admission of a built verb (greenhouse decisions/0590, 0597) ─────────────────────────────────────────────────

const DIGEST = 'sha256:12b7c568802279593b0f78fc2462543fab5b37a0f108c494305e70bea67bbee7';
const SEAT = 'EEEE5555FFFF6666AAAA7777BBBB8888CCCC9999';
const ADMITTED = { ok: true, fingerprint: SEAT, granted: 'herramientas:write', capability: 'Prestamos', admitted: ['herramientas.agregar'], contract: DIGEST };

/** An admission card as the server prints it: bound to a refused call, or — with no refusal — to a seat. */
function admissionTree(bound, hook) {
  const root = new El('html');
  const list = root.appendChild(new El('ol', { id: 'milpa-frontier-list' }));
  const card = list.appendChild(new El('li', { class: 'decision-card decision-card--frontier decision-card--admission', 'data-seat-admits': DIGEST, 'data-seat-permission': 'herramientas:write', ...bound }));
  const label = card.appendChild(new El('label'));
  label.appendChild(new El('input', { type: 'checkbox', 'data-seat-ack': '' }));
  card.appendChild(new El('p', { class: 'decision-card__facts', 'data-seat-status': '' }));
  const options = card.appendChild(new El('p', { class: 'decision-card__options' }));
  options.appendChild(new El('button', { [hook]: '', text: 'Admit herramientas:write of Prestamos' }));

  return root;
}

const fromARefusal = () => admissionTree({ 'data-seat-session': 'taller', 'data-seat-seq': '57' }, 'data-seat-grant');
const withNoRefusal = () => admissionTree({ 'data-admit-seat': SEAT }, 'data-seat-admit');

test('an admission is never one touch: without the box ticked nothing is asked or posted', async () => {
  for (const [tree, hook] of [[fromARefusal(), 'data-seat-grant'], [withNoRefusal(), 'data-seat-admit']]) {
    const p = page({ tree, catalog: CATALOG, modules: ['desktop-decisions'] });
    const signed = withPasskey(p);
    const calls = stubFetch(p, []);
    const card = tree.querySelector('[data-seat-admits]');

    click(p, card.querySelector('[' + hook + ']'));
    await settle();

    assert.equal(calls.length, 0, 'no challenge, no admission');
    assert.equal(signed.length, 0, 'the passkey was never asked');
    assert.equal(card.querySelector('[data-seat-status]').textContent, 'tick the box first: an admission is approved knowingly, with its contract read');
    assert.equal(card.getAttribute('data-granted'), null);
  }
});

test('from a refusal, the touch is bound to the grant WITH the digest of what the card showed, and the grant carries it', async () => {
  const root = fromARefusal();
  const p = page({ tree: root, catalog: CATALOG, modules: ['desktop-decisions'] });
  const signed = withPasskey(p);
  const calls = stubFetch(p, [response(200, OPTIONS), response(201, ADMITTED)]);
  const card = root.querySelector('[data-seat-session]');
  card.querySelector('[data-seat-ack]').checked = true;

  click(p, card.querySelector('[data-seat-grant]'));
  await settle();

  assert.equal(signed.length, 1);
  assert.deepEqual(JSON.parse(calls[0].init.body), { operation: 'identity:grant', arguments: { session: 'taller', seq: 57, admits: DIGEST }, session: 'taller' });
  assert.equal(calls[1].url, '/workspace/grant');
  const body = JSON.parse(calls[1].init.body);
  assert.equal(body.admits, DIGEST);
  assert.equal(body.seq, 57);
  assert.ok(!('existing' in body), 'this is not write over a plugin');
  assert.ok(!('permission' in body) && !('scope' in body) && !('capability' in body), 'the client names no scope and no capability');
  assert.equal(card.querySelector('[data-seat-status]').textContent, 'admitted · herramientas:write of Prestamos — the seat can call it now');
  assert.equal(card.getAttribute('data-granted'), '');
});

test('with no refusal, the touch is bound to identity:admit for THIS seat and THIS digest, and nothing else is sent', async () => {
  const root = withNoRefusal();
  const p = page({ tree: root, catalog: CATALOG, modules: ['desktop-decisions'] });
  const signed = withPasskey(p);
  const calls = stubFetch(p, [response(200, OPTIONS), response(428, { requires_confirmation: true, confirm_token: 'tok-9' }), response(201, ADMITTED)]);
  const card = root.querySelector('[data-admit-seat]');
  card.querySelector('[data-seat-ack]').checked = true;

  click(p, card.querySelector('[data-seat-admit]'));
  await settle();

  assert.equal(signed.length, 1, 'the passkey was asked once');
  assert.deepEqual(JSON.parse(calls[0].init.body), { operation: 'identity:admit', arguments: { seat: SEAT, admits: DIGEST }, session: 'identity:admit' });
  assert.equal(calls[1].url, '/workspace/admit');
  const body = JSON.parse(calls[1].init.body);
  assert.deepEqual(Object.keys(body).sort(), ['admits', 'assertion', 'seat'], 'a seat, a digest and the touch: no capability, no scope');
  assert.equal(body.seat, SEAT);
  assert.equal(body.admits, DIGEST);
  assert.equal(calls[2].init.headers['Confirm-Token'], 'tok-9', 'it walks the confirm gate like every act a person decides');
  assert.equal(card.querySelector('[data-seat-status]').textContent, 'admitted · herramientas:write of Prestamos — the seat can call it now');
  assert.equal(card.getAttribute('data-granted'), '');
  assert.equal(card.querySelector('[data-seat-admit]').getAttribute('hidden'), '', 'the button steps aside once admitted');
});

test('an admission the house refuses is painted with its sentence, and the card stays open', async () => {
  const root = withNoRefusal();
  const p = page({ tree: root, catalog: CATALOG, modules: ['desktop-decisions'] });
  withPasskey(p);
  stubFetch(p, [response(200, OPTIONS), response(409, { ok: false, error: 'its contract moved since it was read; read it again' })]);
  const card = root.querySelector('[data-admit-seat]');
  card.querySelector('[data-seat-ack]').checked = true;

  click(p, card.querySelector('[data-seat-admit]'));
  await settle();

  assert.equal(card.querySelector('[data-seat-status]').textContent, 'nothing was admitted: its contract moved since it was read; read it again');
  assert.equal(card.getAttribute('data-granted'), null);
});

// ── taking one admission back (greenhouse decisions/0590, rule 12) ──────────────────────────────────────────────────

/** One admitted line of «Your seats», as the server prints it. */
function admittedTree() {
  const root = new El('html');
  const list = root.appendChild(new El('ol', { id: 'milpa-seats-list' }));
  const seat = list.appendChild(new El('li', { class: 'decision-card decision-card--seat', 'data-seat-key': SEAT }));
  const line = seat.appendChild(new El('p', { class: 'decision-card__facts', 'data-seat-admitted': '', 'data-withdraw-seat': SEAT, 'data-withdraw-capability': 'Prestamos', 'data-withdraw-scope': 'herramientas:write' }));
  line.appendChild(new El('button', { 'data-seat-withdraw': '', text: 'Withdraw' }));
  line.appendChild(new El('span', { 'data-seat-status': '' }));

  return root;
}

const WITHDRAWN = { ok: true, fingerprint: SEAT, capability: 'Prestamos', withdrawn: 'herramientas:write', verbs: ['herramientas.prestar'] };

test('withdrawing binds the touch to THIS seat, capability and scope, asks for no box, and says what it did', async () => {
  const root = admittedTree();
  const p = page({ tree: root, catalog: CATALOG, modules: ['desktop-decisions'] });
  const signed = withPasskey(p);
  const calls = stubFetch(p, [response(200, OPTIONS), response(428, { requires_confirmation: true, confirm_token: 'tok-w' }), response(201, WITHDRAWN)]);
  const line = root.querySelector('[data-withdraw-seat]');

  click(p, line.querySelector('[data-seat-withdraw]'));
  await settle();

  assert.equal(signed.length, 1, 'the passkey was asked once: the touch is the act');
  assert.deepEqual(JSON.parse(calls[0].init.body), { operation: 'identity:withdraw', arguments: { seat: SEAT, capability: 'Prestamos', scope: 'herramientas:write' }, session: 'identity:withdraw' });
  assert.equal(calls[1].url, '/workspace/withdraw');
  const body = JSON.parse(calls[1].init.body);
  assert.deepEqual(Object.keys(body).sort(), ['assertion', 'capability', 'scope', 'seat']);
  assert.equal(calls[2].init.headers['Confirm-Token'], 'tok-w');
  assert.equal(line.querySelector('[data-seat-status]').textContent, 'withdrawn · herramientas:write of Prestamos — the seat\'s next call to it is refused');
  assert.equal(line.getAttribute('data-withdrawn'), '');
  assert.equal(line.querySelector('[data-seat-withdraw]').getAttribute('hidden'), '', 'the button steps aside once withdrawn');

  click(p, line.querySelector('[data-seat-withdraw]'));
  await settle();
  assert.equal(calls.length, 3, 'what was withdrawn is not withdrawn twice');
});

test('a withdrawal the house refuses is painted with its sentence, and the line stays', async () => {
  const root = admittedTree();
  const p = page({ tree: root, catalog: CATALOG, modules: ['desktop-decisions'] });
  withPasskey(p);
  stubFetch(p, [response(200, OPTIONS), response(409, { ok: false, error: 'you do not answer for that seat' })]);
  const line = root.querySelector('[data-withdraw-seat]');

  click(p, line.querySelector('[data-seat-withdraw]'));
  await settle();

  assert.equal(line.querySelector('[data-seat-status]').textContent, 'nothing was withdrawn: you do not answer for that seat');
  assert.equal(line.getAttribute('data-withdrawn'), null);
});

test('without a passkey a withdrawal says so and posts nothing', async () => {
  const root = admittedTree();
  const p = page({ tree: root, catalog: CATALOG, modules: ['desktop-decisions'] });
  const calls = stubFetch(p, []);
  const line = root.querySelector('[data-withdraw-seat]');

  click(p, line.querySelector('[data-seat-withdraw]'));
  await settle();

  assert.equal(calls.length, 0);
  assert.equal(line.querySelector('[data-seat-status]').textContent, 'this browser cannot run the passkey ceremony');
});
