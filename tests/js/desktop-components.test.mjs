/**
 * The Desktop's declared client modules, measured by EXECUTION (greenhouse decisions/0211, phase B).
 *
 * The old contract for these behaviours was a set of literal JS fragments pinned in the rendered page: a
 * test could only ever say the string had not changed. These load the SHIPPED files into a stub page —
 * the framework runtime verbatim, the shared guard, then the module — hand each factory the `$store` and
 * `$root` Alpine would, and RUN it. What is asserted is what the browser will do.
 *
 * Run: `node --test 'tests/js/**\/*.test.mjs'` — or `npm test` (Node ≥ 22; node:test is built in).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { El, page, response, settle, stubFetch } from './support/page.mjs';

/** A `MilpaShell` bus, as the page's inline runtime provides it to the modules. */
function shellBus(p) {
  const byType = {};
  const any = [];
  p.sandbox.MilpaShell = {
    on: (type, cb) => { (byType[type] ||= []).push(cb); },
    onAny: (cb) => { any.push(cb); },
    emit(type, data) { (byType[type] || []).forEach((cb) => cb(data)); any.forEach((cb) => cb(type, data)); },
  };

  return p.sandbox.MilpaShell;
}

// ── desktop-tabs (B1) ───────────────────────────────────────────────────────────────────────────────
test('the tab strip switches by signal, and its highlight follows the signal', () => {
  const p = page({ modules: ['desktop-tabs'] });
  assert.equal(p.sandbox.MilpaLive.registered('desktopTabs'), true);

  const tabs = p.mount('desktopTabs', { signal: 'desktop.tab', active: 'chat' });

  // Before anything is set, the server's painted tab is what reads as current.
  assert.equal(tabs.isActive('chat'), true);
  assert.equal(tabs.isActive('work'), false);

  tabs.select('work');

  assert.equal(p.signal('desktop.tab'), 'work', 'switching a tab IS setting the signal');
  assert.equal(tabs.isActive('work'), true);
  assert.equal(tabs.isActive('chat'), false);

  // Any surface can switch the tab through the strip's published contract.
  p.desktop().showTab('activity');
  assert.equal(p.signal('desktop.tab'), 'activity');
});

test('a second copy of a module is ignored and the first factory stands', () => {
  const p = page({ modules: ['desktop-tabs'] });
  p.load(new URL('../../resources/components/desktop-tabs/desktop-tabs.js', import.meta.url).pathname);

  assert.match(p.warnings.join(' '), /\[desktop-tabs] loaded twice/);
});

// ── desktop-gate (B3) ───────────────────────────────────────────────────────────────────────────────
test('a parked question fills the gate, opens it and shows the conversation — without a badge nothing renders', () => {
  const p = page({ modules: ['desktop-tabs', 'desktop-gate'] });
  const bus = shellBus(p);
  const gate = p.mount('desktopGate');

  assert.equal(gate.open, false, 'the gate starts closed');

  // The falsifier: the page's old handler ended on `#milpa-decisions-badge`, which NOTHING renders — so
  // this very emit threw a TypeError after filling the card. The stub page renders no such element.
  assert.equal(p.document.getElementById('milpa-decisions-badge'), null);
  bus.emit('gate.opened', { operation: 'capabilities:enable', arguments: { capability: 'milpa/data' }, session: 's-42' });

  assert.equal(gate.operation, 'capabilities:enable');
  assert.equal(gate.args, '{"capability":"milpa/data"}');
  assert.equal(gate.action, 'An agent is asking to run capabilities:enable.');
  assert.equal(
    gate.href,
    '/webauthn/intent?operation=capabilities%3Aenable&arguments=%7B%22capability%22%3A%22milpa%2Fdata%22%7D&session=s-42',
    'the same-origin ceremony carries the operation, its arguments and the session',
  );
  assert.equal(gate.open, true);
  assert.equal(p.signal('desktop.gate.open'), true);
  assert.equal(p.signal('desktop.tab'), 'chat', 'the question is in the conversation, so it is shown there');

  gate.dismiss();
  assert.equal(gate.open, false);
  assert.equal(p.signal('desktop.gate.open'), false);
});

// ── desktop-settings (B7) ───────────────────────────────────────────────────────────────────────────
/**
 * The Settings screen's own root — and `save()` reads ONE field from it now.
 *
 * It used to carry four, and three of them went nowhere: the endpoint into a file the agent does not
 * read, `stream` and `compact` into a file nothing reads at all (greenhouse decisions/0280). The mode
 * is what stayed, because the composer's chip and the topbar read it.
 */
function settingsRoot() {
  const root = new El('div', { class: 'view milpa-settings' });
  root.appendChild(new El('input', { name: 'set-mode', value: 'ask' }));
  root.appendChild(new El('input', { name: 'set-mode', value: 'auto', checked: true }));

  return root;
}

test('Save posts the form through the guard and says Saved only on a 2xx', async () => {
  const p = page({ modules: ['desktop-topbar', 'desktop-settings'] });
  const root = settingsRoot();
  const settings = p.mount('desktopSettings', undefined, root);
  const calls = stubFetch(p, [response(200, { ok: true })]);

  await settings.save();

  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, '/desktop/settings');
  assert.equal(calls[0].init.method, 'POST');
  assert.deepEqual(JSON.parse(calls[0].init.body), { mode: 'auto' },
    'the form is read from the component\'s own root, checked radio and all');
  assert.equal(p.signal('settings.saved').ok, true);
  assert.equal(p.signal('settings.saved').text, 'Saved');
  assert.equal(settings.savedOk, true);
  assert.equal(settings.savedText, 'Saved');
});

test('the endpoint is written where the agent reads it, through the confirm gate', async () => {
  const p = page({ modules: ['desktop-topbar', 'desktop-settings'] });
  const input = new El('input', { id: 'set-end', value: 'http://llama.tailf880b7.ts.net:11438' });
  p.byId['set-end'] = input;
  const settings = p.mount('desktopSettings', undefined, settingsRoot());
  const calls = stubFetch(p, [response(428, { requires_confirmation: true, confirm_token: 't-42' }), response(200, { ok: true })]);

  await settings.declareEndpoint();

  assert.equal(calls.length, 2, 'the 428 is a step of the flow, not a failure');
  assert.equal(calls[0].url, '/config/set', 'agent.baseUrl is governed configuration, not the settings file');
  assert.deepEqual(JSON.parse(calls[0].init.body), { key: 'agent.baseUrl', value: 'http://llama.tailf880b7.ts.net:11438' });
  assert.equal(calls[1].init.headers['Confirm-Token'], 't-42', 'the second call carries the token back');
  assert.equal(p.signal('settings.saved').ok, true);
  // NOT cleared: unlike a key, an address is something you want to still see after saving it.
  assert.equal(input.value, 'http://llama.tailf880b7.ts.net:11438');
});

test('a refused endpoint says so with its status and never says saved', async () => {
  const p = page({ modules: ['desktop-topbar', 'desktop-settings'] });
  p.byId['set-end'] = new El('input', { id: 'set-end', value: 'http://x' });
  const settings = p.mount('desktopSettings', undefined, settingsRoot());
  stubFetch(p, [response(404, {})]);

  await settings.declareEndpoint();

  assert.equal(p.signal('settings.saved').ok, false);
  assert.match(p.signal('settings.saved').text, /404/, 'the door\'s own answer, not an assumption');
});

test('an empty endpoint asks nothing — a blank field is not a declaration', async () => {
  const p = page({ modules: ['desktop-topbar', 'desktop-settings'] });
  p.byId['set-end'] = new El('input', { id: 'set-end', value: '' });
  const settings = p.mount('desktopSettings', undefined, settingsRoot());
  const calls = stubFetch(p, []);

  await settings.declareEndpoint();

  assert.equal(calls.length, 0);
});

test('the provider key goes to its own operation and the input is cleared either way', async () => {
  const p = page({ modules: ['desktop-topbar', 'desktop-settings'] });
  const input = new El('input', { id: 'set-key', value: 'sk-secret' });
  p.byId['set-key'] = input;
  const settings = p.mount('desktopSettings', undefined, settingsRoot());
  const calls = stubFetch(p, [response(428, { confirm_token: 't-7' }), response(200, { ok: true })]);

  await settings.declareKey();

  assert.equal(calls[0].url, '/provider/declare');
  assert.deepEqual(JSON.parse(calls[0].init.body), { key: 'agent.apiKey', value: 'sk-secret' });
  assert.equal(calls[1].init.headers['Confirm-Token'], 't-7');
  // A key left in a field is a key in the next screenshot (greenhouse decisions/0276).
  assert.equal(input.value, '');
});

test('a refused save is reported with its status, and never says Saved', async () => {
  const p = page({ modules: ['desktop-topbar', 'desktop-settings'] });
  const settings = p.mount('desktopSettings', undefined, settingsRoot());
  stubFetch(p, [response(500, {})]);

  await settings.save();

  assert.equal(p.signal('settings.saved').ok, false);
  assert.equal(p.signal('settings.saved').text, 'Not saved (HTTP 500)');
  assert.equal(settings.savedOk, false);
  assert.equal(settings.savedText, 'Not saved (HTTP 500)');
});

test('a door with no session takes the browser to sign in instead of painting a badge', async () => {
  const p = page({ modules: ['desktop-topbar', 'desktop-settings'] });
  const settings = p.mount('desktopSettings', undefined, settingsRoot());
  stubFetch(p, [response(401, { signin: '/webauthn/signin' })]);

  settings.save();
  await settle();

  assert.deepEqual(p.assigned, ['/webauthn/signin?next=%2Fdesktop']);
  assert.equal(p.signal('settings.saved'), null, 'a page that is leaving reports nothing');
});

test('the Settings theme buttons set the SAME shared theme the chrome toggle does', () => {
  const html = new El('html');
  const toggle = new El('button', { id: 'milpa-theme' });
  html.appendChild(toggle);
  const p = page({ tree: html, modules: ['desktop-topbar', 'desktop-settings'] });
  const settings = p.mount('desktopSettings', undefined, settingsRoot());

  settings.setTheme('light');

  assert.equal(html.getAttribute('data-theme'), 'light');
  assert.equal(p.signal('ui.theme'), 'light');
  assert.equal(settings.isTheme('light'), true);
  assert.equal(settings.isTheme('dark'), false);
  assert.equal(p.storage['milpa.theme'], 'light');

  // The window chrome's toggle moves the one value the buttons bind to — they cannot disagree.
  toggle.fire('click');
  assert.equal(p.signal('ui.theme'), 'dark');
  assert.equal(settings.isTheme('dark'), true);
});

// ── desktop-topbar (B2) ─────────────────────────────────────────────────────────────────────────────
test('the theme is restored before Alpine, and system drops the attribute', () => {
  const html = new El('html');
  html.setAttribute('data-theme', 'dark');
  const p = page({ tree: html, modules: [] });
  p.storage['milpa.theme'] = 'light';
  p.load(new URL('../../resources/components/desktop-topbar/desktop-topbar.js', import.meta.url).pathname);

  assert.equal(html.getAttribute('data-theme'), 'light', 'the remembered choice is applied at module load');

  p.desktop().theme.set('system');
  assert.equal(html.getAttribute('data-theme'), null, "'system' hands the choice back to prefers-color-scheme");
  assert.equal('milpa.theme' in p.storage, false, 'nothing is remembered for system');
});

test('the topbar badge reads the working signal through its own component', () => {
  const p = page({ modules: ['desktop-topbar'] });
  const topbar = p.mount('desktopTopbar');

  assert.equal(topbar.working, false);
  p.signal('session.working', true);
  assert.equal(topbar.working, true, 'the getter reads the store, so the binding stays reactive');
});

// ── desktop-sidebar (B5) + desktop-auth (B5, B8) ────────────────────────────────────────────────────
/** The shell as the sidebar reaches it: the views it swaps, the search, the session rows, the strip. */
function shellTree() {
  const html = new El('html');
  html.appendChild(new El('input', { id: 'milpa-search', value: '' }));
  for (const view of ['session', 'settings', 'auth']) {
    html.appendChild(new El('div', { class: 'view', 'data-view': view, hidden: view !== 'session' }));
  }
  for (const goal of ['Audit the plugins', 'Publish the site']) {
    const row = new El('a', { class: 'mui-sidebar__item milpa-session-item' });
    row.appendChild(new El('span', { class: 'milpa-session-goal', text: goal }));
    html.appendChild(row);
  }
  html.appendChild(new El('button', { 'data-new-session': '' }));
  html.appendChild(new El('select', { id: 'milpa-embed-session', value: '' }));
  html.appendChild(new El('input', { id: 'auth-app', value: 'getmilpa/framework' }));
  html.appendChild(new El('button', { id: 'milpa-auth-open' }));

  return html;
}

test('the sidebar navigates by signal and swaps the view — never the auth overlay', () => {
  const html = shellTree();
  const p = page({ tree: html, modules: ['desktop-sidebar', 'desktop-auth'] });
  const sidebar = p.mount('desktopSidebar', { active: 'sessions' });

  assert.equal(sidebar.isCurrent('sessions'), true);
  sidebar.go('settings');

  assert.equal(p.signal('desktop.nav'), 'settings');
  assert.equal(sidebar.isCurrent('settings'), true);
  assert.equal(html.querySelector('[data-view="settings"]').hidden, false);
  assert.equal(html.querySelector('[data-view="session"]').hidden, true);
  assert.equal(html.querySelector('[data-view="auth"]').hidden, true, 'the overlay opens on demand, never by navigation');
});

test('the chrome search filters the sidebar session list', () => {
  const html = shellTree();
  const p = page({ tree: html, modules: ['desktop-sidebar'] });
  const search = html.querySelector('#milpa-search');

  search.value = 'publish';
  search.fire('input');

  const rows = html.querySelectorAll('.milpa-session-item');
  assert.equal(rows[0].classList.contains('milpa-search-miss'), true, 'filtered out');
  assert.equal(rows[1].classList.contains('milpa-search-miss'), false, 'the match stays');

  search.value = '';
  search.fire('input');
  assert.equal(rows.every((r) => !r.classList.contains('milpa-search-miss')), true, 'an empty search hides nothing');
});

test('the sidebar keeps its OWN ceremony: its button asks the auth component', () => {
  const html = shellTree();
  const p = page({ tree: html, modules: ['desktop-sidebar', 'desktop-auth'] });
  const sidebar = p.mount('desktopSidebar', { active: 'sessions' });

  sidebar.newSession();
  assert.equal(p.signal('desktop.auth.open'), true, 'the sidebar asks the auth component');
});

// 🚨 THIS PAIR ASSERTED THE OPPOSITE AND WAS GREEN. The old tests held that the sidebar's module
// wired the STRIP's controls — «one ceremony, both surfaces» — which is sound about duplication and
// wrong about ownership. It cost a dead button: in the admin panel, which paints the strip and has a
// navigation of its own, the sidebar's module never loads, so the strip's controls fired NOTHING.
// Measured by clicking it in a real browser (greenhouse decisions/0273).
test('the strip binds its own button and asks the ROUTE, not another surface', async () => {
  const html = shellTree();
  const p = page({ tree: html, modules: ['desktop-session-strip', 'desktop-auth'] });
  const calls = stubFetch(p, [response(200, { ok: true, id: 'ccc33333' })]);

  html.querySelector('[data-new-session]').fire('click');
  await settle();

  assert.equal(calls.length, 1, 'the click asked the route');
  assert.equal(calls[0].url, '/desktop/sessions');
  assert.equal(calls[0].init.method, 'POST');
  assert.deepEqual(p.assigned, ['http://localhost/desktop?session=ccc33333'], 'and went to the session it made');
  // `false`, the value the auth module seeds — the point is that the strip did not OPEN it.
  assert.equal(p.signal('desktop.auth.open'), false, 'it does NOT reach for the auth overlay a host may not paint');
});

test('a refused session is REPORTED, never swallowed', async () => {
  const html = shellTree();
  const p = page({ tree: html, modules: ['desktop-session-strip'], bus: true });
  stubFetch(p, [response(403, { ok: false })]);
  // Subscribed, not scraped: being TOLD is the contract, and the bus stub dispatches rather than
  // recording — so a listener is the faithful way to ask.
  const told = [];
  p.bus().on('session.create_failed', (data) => told.push(data));

  html.querySelector('[data-new-session]').fire('click');
  await settle();

  assert.deepEqual(p.assigned, [], 'nowhere to go: no session was made');
  assert.equal(told.length, 1, 'and the surfaces are told');
  assert.match(told[0].reason, /403/, 'with the status the route answered, not a guess');
});

test('picking a session names it in the CURRENT url, keeping every other param', () => {
  const html = shellTree();
  const p = page({ tree: html, modules: ['desktop-session-strip'] });
  const pick = html.querySelector('#milpa-embed-session');

  pick.value = 'bbb22222';
  pick.fire('change');

  // Host-agnostic: the panel stays on its section path, the page stays on /desktop. The old handler
  // hardcoded `?session=<id>&embed=1` — a mode that was retired and a path that is one of two hosts.
  assert.deepEqual(p.assigned, ['http://localhost/desktop?session=bbb22222']);
});

test('the passkey probe degrades the link on a 404 and reports a real failure', async () => {
  const html = shellTree();
  const link = new El('a', { id: 'milpa-enroll-link', text: 'Register a passkey' });
  html.appendChild(link);
  const p = page({ tree: html, modules: ['desktop-sidebar'] });
  const sidebar = p.mount('desktopSidebar', { active: 'sessions' });
  stubFetch(p, [response(404, {}), response(200, {}), response(500, {})]);

  await sidebar.enroll({ currentTarget: link });
  assert.equal(link.textContent, 'No passkey door in this app');
  assert.equal(link.getAttribute('aria-disabled'), 'true');
  assert.equal(link.classList.contains('milpa-enroll--absent'), true);

  await sidebar.enroll({ currentTarget: link });
  assert.equal(p.sandbox.location.href, '/webauthn/enroll', 'a door that answers is a door to walk through');

  const told = [];
  p.desktop().onNotice((n) => told.push(n.text));
  await sidebar.enroll({ currentTarget: link });
  assert.deepEqual(told, ['The request failed (HTTP 500)'], 'a 500 is a broken door, not a missing one');
});

test('the entry overlay creates the session through the guard, and NAVIGATES to it only on a 2xx', async () => {
  const html = shellTree();
  const p = page({ tree: html, modules: ['desktop-sidebar', 'desktop-auth'] });
  const auth = p.mount('desktopAuth');
  const calls = stubFetch(p, [response(201, { ok: true, id: 'desk-0123456789abcdef' }), response(403, { error: 'loopback_only' })]);

  html.querySelector('#milpa-auth-open').fire('click');
  assert.equal(auth.open, true);

  await auth.enter();
  assert.equal(calls[0].url, '/desktop/sessions');
  assert.deepEqual(JSON.parse(calls[0].init.body), { goal: 'New session · getmilpa/framework' });
  // The session lives in the URL (greenhouse evidence/0561): the page goes to the session it created, it
  // does not reload into whatever the cookie held.
  assert.deepEqual(p.assigned, ['?session=desk-0123456789abcdef']);
  assert.equal(p.reloads(), 0);

  const told = [];
  p.desktop().onNotice((n) => told.push(n.text));
  await auth.enter();

  assert.equal(p.assigned.length, 1, 'a refused creation navigates nowhere');
  assert.equal(auth.open, false, 'the overlay closes so the notice is in view');
  assert.deepEqual(told, ['Not allowed here (loopback_only)']);
});

test('in embed mode the entry overlay keeps the embed flag on the session it navigates to', async () => {
  const html = shellTree();
  const p = page({ tree: html, modules: ['desktop-sidebar', 'desktop-auth'] });
  p.sandbox.location.search = '?embed=1';
  const auth = p.mount('desktopAuth');
  stubFetch(p, [response(201, { ok: true, id: 'desk-0123456789abcdef' })]);

  await auth.enter();
  assert.deepEqual(p.assigned, ['?session=desk-0123456789abcdef&embed=1']);
});

// ── desktop-activity (B6) ───────────────────────────────────────────────────────────────────────────
test('every live fact of the bus is prepended to the Activity stream, newest first', () => {
  const html = new El('html');
  const stream = new El('ol', { id: 'milpa-activity' });
  // The empty row as `Live\Activity` prints it: MARKED, so the module finds it by its mark and not by
  // reading the English words it happens to carry today.
  const empty = new El('li', { class: 'mui-replay__event', 'data-activity-empty': '' });
  empty.appendChild(new El('span', { class: 'mui-replay__actor', text: 'no facts recorded yet' }));
  stream.appendChild(empty);
  html.appendChild(stream);

  const p = page({ tree: html, modules: ['desktop-activity'] });
  const bus = shellBus(p);
  p.mount('desktopActivity');

  bus.emit('gate.opened', { operation: 'capabilities:enable' });

  assert.equal(stream.children.length, 1, 'the empty state goes when the first real fact lands');
  assert.equal(stream.children[0].querySelector('.mui-replay__type').textContent, 'gate.opened');
  assert.equal(
    stream.children[0].querySelector('.mui-replay__actor').textContent,
    '{"operation":"capabilities:enable"} · live',
    'the payload is set as TEXT — a fact carries data the Desktop did not write',
  );

  bus.emit('session.state', { state: 'working' });
  assert.equal(stream.children.length, 2);
  assert.equal(stream.children[0].querySelector('.mui-replay__type').textContent, 'session.state', 'newest first');
});
