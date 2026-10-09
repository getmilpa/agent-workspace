/*!
 * What a session rehearsed and did not apply is said beside «verified», by EXECUTION (greenhouse decisions/0605, R2
 * — decided by Rod on 2026-10-09).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 *
 * Since app-runtime 0.218.0 the house's verdict carries `rehearsed: {calls, of_verbs_that_change_state, applied:
 * false}` when the session that wrote an operation tried it before anyone admitted it. The panel dropped it on every
 * road a verdict reaches a thread — the transcript a load prints, the stream, the answer of the turn — so «verified»
 * read the same for a builder that tried its own operations as for one that tried nothing (evidence/1179).
 *
 * The thread says it where it says the verdict, from THE DATUM and nothing else. These tests load the shipped modules.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { El, page, response, settle } from './support/page.mjs';
import { prototypeTags } from './support/shell.mjs';

const REHEARSED = { calls: 2, of_verbs_that_change_state: 1, applied: false };
const WHY = 'Calls of operations this session built that the house answered in a rehearsal: 2 (1 of an operation that changes state). Nothing of them was applied to the house.';
const BACKED = "The ledger backs this turn: every completed step carries evidence, nothing was left open, and no artifact's latest check is red.";

function transcriptTag(rows) {
  const tag = new El('script', { id: 'milpa-desktop-transcript' });
  tag.textContent = JSON.stringify(rows);

  return tag;
}

/** A thread with the transport, the turn and every message module, over the transcript a load printed. */
function thread({ rows = null } = {}) {
  const html = new El('html');
  const chat = html.appendChild(new El('section', { id: 'milpa-chat' }));
  const elements = { ...prototypeTags(), 'milpa-desktop-session': new El('script', { id: 'milpa-desktop-session', text: JSON.stringify({ agent: 'run-1' }) }) };
  if (rows !== null) { elements['milpa-desktop-transcript'] = transcriptTag(rows); }
  const p = page({
    tree: html,
    elements,
    modules: ['desktop-shell-bus', 'desktop-hub', 'desktop-turn', 'desktop-conversation', 'desktop-thinking', 'desktop-agent-message', 'desktop-tool-call', 'desktop-result-claim', 'desktop-ask-grant'],
  });
  p.mount('desktopConversation', undefined, chat);

  return { p, chat, hub: p.desktop().hub };
}

const slotOf = (chat) => chat.children[chat.children.length - 1].querySelector('[data-agent-verdict]');
const plain = (value) => JSON.parse(JSON.stringify(value));

test('a reloaded thread says «rehearsed, not applied» beside «verified», with the two numbers of the datum', () => {
  const { chat } = thread({ rows: [
    { kind: 'user', text: 'Build a plugin named Prestamos and lend the drill' },
    { kind: 'agent', text: 'Prestamos is built.' },
    { kind: 'closure', verified: true, reasons: [], scope: 'house_observation', rehearsed: REHEARSED },
  ] });

  const slot = slotOf(chat);
  assert.equal(slot.hidden, false);
  assert.equal(slot.getAttribute('data-verified'), '1');
  assert.equal(slot.querySelector('[data-verdict-label]').textContent, 'verified · rehearsed, not applied', 'beside the word, where it is read without hovering');
  assert.equal(slot.querySelector('[data-verdict-tip]').textContent, BACKED + ' ' + WHY);
  assert.equal(slot.getAttribute('data-rehearsed'), '2');
  assert.equal(slot.getAttribute('aria-label'), 'Verified. ' + BACKED + ' ' + WHY, 'and a reader is told the same');
});

test('a pushed verdict says it too: the stream carries the datum and the thread reads it the same way', () => {
  const { chat, hub, p } = thread();
  const said = [];
  p.bus().on('house.closure', (fact) => said.push(plain(fact)));
  hub.translate({ kind: 'activity', at: 9, activity: { state: 'ready', role: 'assistant', text: 'Prestamos is built.' } });

  hub.translate({ session: 'run-1', kind: 'closure', at: 10, closure: { verified: true, reasons: [], scope: 'house_observation', rehearsed: REHEARSED } });

  assert.deepEqual(said, [{ verified: true, reasons: [], scope: 'house_observation', rehearsed: REHEARSED }]);
  assert.equal(slotOf(chat).querySelector('[data-verdict-label]').textContent, 'verified · rehearsed, not applied');
  assert.equal(slotOf(chat).getAttribute('data-rehearsed'), '2');
});

test('the answer of the turn that ran on this page says it: the verdict it brings is read whole', async () => {
  const { chat, p } = thread();
  p.sandbox.fetch = () => Promise.resolve(response(200, { ok: true, answer: 'Prestamos is built.', closure: { verified: true, reasons: [], scope: 'house_observation', rehearsed: REHEARSED } }));

  await p.desktop().turn.run('Build a plugin named Prestamos and lend the drill');
  await settle();

  assert.equal(slotOf(chat).querySelector('[data-verdict-label]').textContent, 'verified · rehearsed, not applied');
  assert.match(slotOf(chat).querySelector('[data-verdict-tip]').textContent, /answered in a rehearsal: 2 \(1 of an operation that changes state\)/);
});

test('a verdict that did not verify says it after its reasons; with no answer to ride, the line of its own says it', () => {
  const { chat, hub } = thread();

  hub.translate({ kind: 'closure', closure: { verified: false, reasons: ['artifact Prestamos has no current verification'], scope: 'recorded_work', rehearsed: REHEARSED } });

  assert.equal(chat.children.length, 1);
  const claim = chat.children[0];
  assert.equal(claim.getAttribute('data-verified'), '0');
  assert.equal(claim.querySelector('[data-result-text]').textContent, 'disputed · rehearsed, not applied');
  assert.equal(claim.querySelector('[data-result-tip]').textContent, 'The ledger disputes this turn — artifact Prestamos has no current verification. ' + WHY);
  assert.equal(claim.getAttribute('data-rehearsed'), '2');
});

test('a verdict that says nothing of a rehearsal is painted byte for byte as it was', () => {
  const { chat, hub, p } = thread();
  const said = [];
  p.bus().on('house.closure', (fact) => said.push(plain(fact)));
  hub.translate({ kind: 'activity', at: 9, activity: { state: 'ready', role: 'assistant', text: 'The blog is built.' } });

  hub.translate({ kind: 'closure', closure: { verified: true, reasons: [], scope: 'recorded_work' } });

  assert.deepEqual(said, [{ verified: true, reasons: [], scope: 'recorded_work' }], 'the fact has no key for what the house did not say');
  assert.equal(slotOf(chat).querySelector('[data-verdict-label]').textContent, 'verified');
  assert.equal(slotOf(chat).querySelector('[data-verdict-tip]').textContent, BACKED);
  assert.equal(slotOf(chat).getAttribute('data-rehearsed'), null);
});

test('BY THE DATUM, never by a sentence: reasons that speak of a rehearsal, or a datum that is not this one, say nothing', () => {
  for (const closure of [
    { verified: false, reasons: ['«herramientas.prestar» ran in a trial, not in the house (seq 9): a rehearsal is not work'], scope: 'recorded_work' },
    { verified: true, reasons: [], scope: 'house_observation', rehearsed: { calls: 2, of_verbs_that_change_state: 1, applied: true } },
    { verified: true, reasons: [], scope: 'house_observation', rehearsed: { calls: 0, of_verbs_that_change_state: 0, applied: false } },
    { verified: true, reasons: [], scope: 'house_observation', rehearsed: { calls: '2', of_verbs_that_change_state: 1, applied: false } },
    { verified: true, reasons: [], scope: 'house_observation', rehearsed: { calls: 1, of_verbs_that_change_state: 2, applied: false } },
    { verified: true, reasons: [], scope: 'house_observation', rehearsed: 'two calls were rehearsed' },
  ]) {
    const { chat, hub } = thread();
    hub.translate({ kind: 'activity', at: 9, activity: { state: 'ready', role: 'assistant', text: 'Done.' } });

    hub.translate({ kind: 'closure', closure });

    assert.doesNotMatch(slotOf(chat).querySelector('[data-verdict-label]').textContent, /rehearsed/, JSON.stringify(closure));
    assert.doesNotMatch(slotOf(chat).querySelector('[data-verdict-tip]').textContent, /answered in a rehearsal/, JSON.stringify(closure));
    assert.equal(slotOf(chat).getAttribute('data-rehearsed'), null);
  }
});

test('a later verdict that rehearsed nothing takes the line away from the answer it now judges', () => {
  const { chat, hub } = thread();
  hub.translate({ kind: 'activity', at: 9, activity: { state: 'ready', role: 'assistant', text: 'Prestamos is built.' } });
  hub.translate({ kind: 'closure', closure: { verified: true, reasons: [], scope: 'house_observation', rehearsed: REHEARSED } });
  assert.equal(slotOf(chat).getAttribute('data-rehearsed'), '2');

  hub.translate({ kind: 'closure', closure: { verified: true, reasons: [], scope: 'house_observation' } });

  assert.equal(slotOf(chat).querySelector('[data-verdict-label]').textContent, 'verified');
  assert.equal(slotOf(chat).getAttribute('data-rehearsed'), null, 'a stamp that stayed from the verdict before would be saying something the house no longer says');
});
