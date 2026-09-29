/**
 * The inbox answers here, by EXECUTION (greenhouse decisions/0223, F4).
 *
 * A stub page carries a declared sequence's card and a parked question's card as the server prints them;
 * the shipped module is loaded on top and driven by clicks. Each claim is asserted by what it POSTed and
 * what it painted: a run walks the confirm gate (428, then the same call with `Confirm-Token`), a pause
 * shows the answers, an approval posts `agent:answer` and then RESUMES through the gate again, a refusal
 * leaves the run parked, and a door's refusal is painted, not swallowed.
 *
 * Run: `node --test 'tests/js/**\/*.test.mjs'` — or `npm test` (Node ≥ 22; node:test is built in).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { El, page, response, settle, stubFetch, CATALOG } from './support/page.mjs';

const COPY = {
  ...CATALOG,
  'decisions.running': 'running…',
  'decisions.paused_on': 'paused on %s — answer below',
  'decisions.applied': 'applied · %s of %s steps ran',
  'decisions.denied': 'denied · %s',
  'decisions.failed': 'did not finish · %s',
  'decisions.answering': 'answering…',
  'decisions.resuming': 'answered · resuming…',
  'decisions.stays_paused': 'refused · the run stays paused',
  'decisions.answered': 'answered · the run continued',
  'decisions.answered_parked': 'answered · the session reads it on its next turn — nothing resumed yet',
  'decisions.refused': 'that answer was refused: %s',
  'cap.no_token': 'the house issued no confirm token',
};

/** A sequence card as `DecisionsInboxView::sequencesHtml()` prints it, and a parked question's card. */
function tree() {
  const root = new El('html');
  const list = root.appendChild(new El('ol', { id: 'milpa-sequences-list' }));
  const card = list.appendChild(new El('li', { class: 'decision-card sequence-card', 'data-sequence': 'deploy', 'data-sequence-session': 'sequence:deploy' }));
  card.appendChild(new El('p', { class: 'decision-card__facts', 'data-sequence-status': '' }));
  const options = card.appendChild(new El('p', { class: 'decision-card__options' }));
  options.appendChild(new El('button', { 'data-sequence-run': '', text: 'Run' }));
  options.appendChild(new El('button', { 'data-agent-answer': 'sí', hidden: '', text: 'Approve' }));
  options.appendChild(new El('button', { 'data-agent-answer': 'no', hidden: '', text: 'Deny' }));

  const inbox = root.appendChild(new El('ol', { id: 'milpa-decisions-list' }));
  const parked = inbox.appendChild(new El('li', { class: 'decision-card', 'data-decision-session': 'sequence:rollout', 'data-decision-sequence': 'rollout' }));
  parked.appendChild(new El('p', { class: 'decision-card__facts', 'data-decision-status': '' }));
  const answers = parked.appendChild(new El('p', { class: 'decision-card__options' }));
  answers.appendChild(new El('button', { 'data-agent-answer': 'sí', text: 'Approve' }));
  answers.appendChild(new El('button', { 'data-agent-answer': 'no', text: 'Deny' }));

  return root;
}

function click(p, el) {
  (p.listeners.click || []).forEach((fn) => fn({ target: el, preventDefault() {} }));
}

const PAUSED = { ok: true, applied: false, paused: true, resumable: true, pending_operation: 'config:set', pending_reason: 'El agente quiere correr «config:set».', executed_count: 1, steps_total: 2 };
const APPLIED = { ok: true, applied: true, paused: false, executed_count: 2, steps_total: 2 };

test('a run walks the confirm gate and a pause shows the answers', async () => {
  const root = tree();
  const p = page({ tree: root, catalog: COPY, modules: ['desktop-decisions'] });
  const calls = stubFetch(p, [response(428, { requires_confirmation: true, confirm_token: 'tok-1' }), response(201, PAUSED)]);
  const card = root.querySelector('[data-sequence="deploy"]');

  click(p, card.querySelector('[data-sequence-run]'));
  await settle();

  assert.equal(calls.length, 2, 'the first call met the gate, the second carried the token');
  assert.equal(calls[0].url, '/workspace/sequence');
  assert.deepEqual(JSON.parse(calls[0].init.body), { sequence: 'deploy', session: 'sequence:deploy' });
  assert.equal(calls[0].init.headers['Confirm-Token'], undefined);
  assert.equal(calls[1].init.headers['Confirm-Token'], 'tok-1');
  assert.equal(card.querySelector('[data-sequence-status]').textContent, 'paused on config:set — answer below — El agente quiere correr «config:set».');
  assert.equal(card.getAttribute('data-sequence-paused'), '', 'the card says it is parked');
  assert.equal(card.querySelector('[data-agent-answer="sí"]').getAttribute('hidden'), null, 'the answers appeared');
});

test('an approval posts agent:answer and then RESUMES the run through the gate', async () => {
  const root = tree();
  const p = page({ tree: root, catalog: COPY, modules: ['desktop-decisions'] });
  const calls = stubFetch(p, [
    response(201, { ok: true, session: 'sequence:deploy', answered: 'perm:config:set', granted: 'config:set' }),
    response(428, { requires_confirmation: true, confirm_token: 'tok-2' }),
    response(201, APPLIED),
  ]);
  const card = root.querySelector('[data-sequence="deploy"]');
  card.setAttribute('data-sequence-paused', '');

  click(p, card.querySelector('[data-agent-answer="sí"]'));
  await settle();

  assert.equal(calls.length, 3);
  assert.equal(calls[0].url, '/workspace/answer');
  assert.deepEqual(JSON.parse(calls[0].init.body), { session: 'sequence:deploy', answer: 'sí' });
  assert.equal(calls[1].url, '/workspace/sequence');
  assert.deepEqual(JSON.parse(calls[1].init.body), { sequence: 'deploy', session: 'sequence:deploy' });
  assert.equal(calls[2].init.headers['Confirm-Token'], 'tok-2');
  assert.equal(card.querySelector('[data-sequence-status]').textContent, 'applied · 2 of 2 steps ran');
  assert.equal(card.getAttribute('data-sequence-paused'), null, 'no longer parked');
  assert.equal(card.querySelector('[data-agent-answer="sí"]').getAttribute('hidden'), '', 'the answers went back to waiting');
});

test('a refusal answers no and leaves the run parked — nothing is resumed', async () => {
  const root = tree();
  const p = page({ tree: root, catalog: COPY, modules: ['desktop-decisions'] });
  const calls = stubFetch(p, [response(201, { ok: true, session: 'sequence:deploy', answered: 'perm:config:set', granted: null })]);
  const card = root.querySelector('[data-sequence="deploy"]');

  click(p, card.querySelector('[data-agent-answer="no"]'));
  await settle();

  assert.equal(calls.length, 1, 'no second call: a refusal resumes nothing');
  assert.deepEqual(JSON.parse(calls[0].init.body), { session: 'sequence:deploy', answer: 'no' });
  assert.equal(card.querySelector('[data-sequence-status]').textContent, 'refused · the run stays paused');
});

test('the inbox card of a session parked on a sequence approves and resumes the same way', async () => {
  const root = tree();
  const p = page({ tree: root, catalog: COPY, modules: ['desktop-decisions'] });
  const calls = stubFetch(p, [response(201, { ok: true }), response(428, { confirm_token: 'tok-3' }), response(201, APPLIED)]);
  const card = root.querySelector('[data-decision-session="sequence:rollout"]');

  click(p, card.querySelector('[data-agent-answer="sí"]'));
  await settle();

  assert.equal(calls.length, 3);
  assert.deepEqual(JSON.parse(calls[0].init.body), { session: 'sequence:rollout', answer: 'sí' });
  assert.deepEqual(JSON.parse(calls[1].init.body), { sequence: 'rollout', session: 'sequence:rollout' });
  assert.equal(card.querySelector('[data-decision-status]').textContent, 'applied · 2 of 2 steps ran');
});

test('a seat\'s parked question is answered through the panel\'s own door, walking the confirm gate (0495)', async () => {
  // Measured before this: the inbox posted to /agent/answer, which a fresh app never mounts — Deny answered 404
  // (greenhouse evidence/1026, B1b). The panel's door is /workspace/answer, and answering is irreversible, so
  // the house may ask first: the same 428 → Confirm-Token flow the other buttons walk.
  const root = tree();
  const p = page({ tree: root, catalog: COPY, modules: ['desktop-decisions'] });
  const calls = stubFetch(p, [response(428, { requires_confirmation: true, confirm_token: 'tok-9' }), response(201, { ok: true, answered: 'intent:target_not_named', granted: null })]);
  const card = root.querySelector('[data-decision-session="sequence:rollout"]');
  card.removeAttribute('data-decision-sequence');

  click(p, card.querySelector('[data-agent-answer="no"]'));
  await settle();

  assert.equal(calls.length, 2, 'the first call met the gate, the second carried the token');
  assert.equal(calls[0].url, '/workspace/answer');
  assert.equal(calls[1].url, '/workspace/answer');
  assert.equal(calls[1].init.headers['Confirm-Token'], 'tok-9');
  assert.equal(card.querySelector('[data-decision-status]').textContent, 'refused · the run stays paused');
});

test('a yes to a parked question says it was RECORDED, not that the run continued (0513 §6)', async () => {
  // Measured in greenhouse evidence/1036 (and 1028): the card read «the run continued» while nothing ran until the
  // resident's next leg. agent:answer records the answer and resumes nothing; its hint says how to take the next turn.
  const root = tree();
  const p = page({ tree: root, catalog: COPY, modules: ['desktop-decisions'] });
  const hint = 'pick it up with `php bin/coa agent "continue" --session=camino-1036-blog`';
  stubFetch(p, [response(201, { ok: true, session: 'camino-1036-blog', answered: 'intent-d49f98f0185d', granted: null, hint: hint })]);
  const card = root.querySelector('[data-decision-session="sequence:rollout"]');
  card.removeAttribute('data-decision-sequence');

  click(p, card.querySelector('[data-agent-answer="sí"]'));
  await settle();

  const said = card.querySelector('[data-decision-status]').textContent;
  assert.equal(said, 'answered · the session reads it on its next turn — nothing resumed yet — ' + hint);
  assert.doesNotMatch(said, /continued/, 'nothing continued, so the card does not say so');
});

test('a yes without a hint still says only what happened', async () => {
  const root = tree();
  const p = page({ tree: root, catalog: COPY, modules: ['desktop-decisions'] });
  stubFetch(p, [response(201, { ok: true, answered: 'intent:x', granted: null })]);
  const card = root.querySelector('[data-decision-session="sequence:rollout"]');
  card.removeAttribute('data-decision-sequence');

  click(p, card.querySelector('[data-agent-answer="sí"]'));
  await settle();

  assert.equal(card.querySelector('[data-decision-status]').textContent, 'answered · the session reads it on its next turn — nothing resumed yet');
});

test('a door the app does not offer is painted, not swallowed (501)', async () => {
  const root = tree();
  const p = page({ tree: root, catalog: COPY, modules: ['desktop-decisions'] });
  const card = root.querySelector('[data-decision-session="sequence:rollout"]');

  stubFetch(p, [response(501, { ok: false, error: 'This app offers no agent:answer for the panel to run — it comes with milpa/app-runtime.' })]);
  click(p, card.querySelector('[data-agent-answer="no"]'));
  await settle();

  assert.equal(card.querySelector('[data-decision-status]').textContent, 'that answer was refused: This app offers no agent:answer for the panel to run — it comes with milpa/app-runtime.');
});

test('a door that refuses is painted on the card, and a gate without a token is a failure said aloud', async () => {
  const root = tree();
  const p = page({ tree: root, catalog: COPY, modules: ['desktop-decisions'] });
  const card = root.querySelector('[data-sequence="deploy"]');

  stubFetch(p, [response(403, { error: 'MILPA_SCOPE_DENIED' })]);
  click(p, card.querySelector('[data-sequence-run]'));
  await settle();
  assert.match(card.querySelector('[data-sequence-status]').textContent, /^did not finish · /);

  stubFetch(p, [response(428, { requires_confirmation: true })]);
  click(p, card.querySelector('[data-sequence-run]'));
  await settle();
  assert.equal(card.querySelector('[data-sequence-status]').textContent, 'did not finish · the house issued no confirm token');
});

test('a run denied at a step, or that failed, says so and shows no answers', async () => {
  const root = tree();
  const p = page({ tree: root, catalog: COPY, modules: ['desktop-decisions'] });
  const card = root.querySelector('[data-sequence="deploy"]');

  stubFetch(p, [response(201, { ok: false, applied: false, paused: false, denied: true, denied_operation: 'nobody:has-this', reason: 'UNJUDGEABLE: …' })]);
  click(p, card.querySelector('[data-sequence-run]'));
  await settle();
  assert.equal(card.querySelector('[data-sequence-status]').textContent, 'denied · UNJUDGEABLE: …');
  assert.equal(card.querySelector('[data-agent-answer="sí"]').getAttribute('hidden'), '');

  stubFetch(p, [response(201, { ok: false, applied: false, paused: false, reason: 'step 2 threw' })]);
  click(p, card.querySelector('[data-sequence-run]'));
  await settle();
  assert.equal(card.querySelector('[data-sequence-status]').textContent, 'did not finish · step 2 threw');
});
