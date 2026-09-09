/**
 * The parked question, answered where it was raised (greenhouse decisions/0254), measured by EXECUTION.
 *
 * What is under test is the shipped module: the server's prototype is handed to the stub page as a real
 * `<template>`, the module clones and fills it, and what is asserted is the DOM a browser would have —
 * plus the request that actually left for `agent:answer`'s own door.
 *
 * Run: `node --test 'tests/js/**\/*.test.mjs'` — or `npm test` (Node ≥ 22; node:test is built in).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { El, page, response, settle, stubFetch } from './support/page.mjs';
import { prototypeTags } from './support/shell.mjs';

/** A thread with the message modules, and a turn module that only knows which session it drives. */
function thread({ session = 'desk-1' } = {}) {
  const html = new El('html');
  const chat = html.appendChild(new El('section', { id: 'milpa-chat' }));
  const p = page({
    tree: html,
    elements: prototypeTags(),
    bus: true,
    modules: ['desktop-conversation', 'desktop-thinking', 'desktop-tool-call', 'desktop-ask-grant'],
  });
  p.desktop().turn = { session: () => session };
  const view = p.mount('desktopConversation', undefined, chat);

  return { p, chat, view, conv: p.desktop().conversation };
}

const QUESTION = { id: 'q-1', text: 'May I write outside the project?', reason: 'permission', why: 'the path is above the root', options: ['yes', 'no'] };

/** Click one of a bubble's option buttons the way the thread's ONE delegated handler does. */
function choose(view, root, label) {
  const button = root.querySelectorAll('[data-grant-option]').find((b) => b.textContent === label);
  assert.ok(button, 'the bubble offers «' + label + '»');
  view.onClick({ target: button });

  return button;
}

test('F5 · an option posts the answer to agent:answer\'s own door, and the bubble records what was chosen', async () => {
  const { p, chat, view, conv } = thread();
  const calls = stubFetch(p, [response(200, { ok: true })]);
  conv.append('ask-grant', QUESTION);
  const grant = chat.children[0];

  choose(view, grant, 'yes');
  await settle();

  assert.equal(calls.length, 1, 'one call, to the operation\'s door');
  assert.equal(calls[0].url, '/agent/answer');
  assert.deepEqual(JSON.parse(calls[0].init.body), { session: 'desk-1', answer: 'yes' }, 'the session the turn owns, and the option the agent proposed');
  assert.equal(grant.getAttribute('data-grant-state'), 'answered');
  assert.equal(grant.querySelector('[data-grant-status]').textContent, 'You answered «yes».');
  const chosen = grant.querySelectorAll('[data-grant-option]').find((b) => b.textContent === 'yes');
  assert.equal(chosen.classList.contains('msg__grant-option--chosen'), true, 'the bubble keeps the record of WHICH answer it got');
  assert.equal(chosen.disabled, true, 'an answered question offers no second answer');
});

test('F5b · a second click on an answered question sends nothing', async () => {
  const { p, chat, view, conv } = thread();
  const calls = stubFetch(p, [response(200, { ok: true }), response(200, { ok: true })]);
  conv.append('ask-grant', QUESTION);
  const grant = chat.children[0];

  choose(view, grant, 'no');
  await settle();
  view.onClick({ target: grant.querySelectorAll('[data-grant-option]').find((b) => b.textContent === 'no') });
  await settle();

  assert.equal(calls.length, 1, 'the door is asked once — a decision is not re-sent by a stray click');
});

test('F5c · the confirm gate is a FLOW: the 428 hands a token and the second call carries it', async () => {
  const { p, chat, view, conv } = thread();
  const calls = stubFetch(p, [response(428, { confirm_token: 'tok-77' }), response(200, { ok: true })]);
  conv.append('ask-grant', QUESTION);

  choose(view, chat.children[0], 'yes');
  await settle();

  assert.equal(calls.length, 2, 'a gate is walked, not reported as a failure');
  assert.equal(calls[0].init.headers['Confirm-Token'], undefined, 'the first call carries none');
  assert.equal(calls[1].init.headers['Confirm-Token'], 'tok-77', 'the second carries the one-use token back');
  assert.equal(chat.children[0].getAttribute('data-grant-state'), 'answered');
});

test('F5d · a refused answer leaves the question ANSWERABLE — the turn is not stranded', async () => {
  const { p, chat, view, conv } = thread();
  stubFetch(p, [response(200, { ok: false, error: 'that answer is not one of the options' })]);
  conv.append('ask-grant', QUESTION);
  const grant = chat.children[0];

  choose(view, grant, 'yes');
  await settle();

  assert.equal(grant.getAttribute('data-grant-state'), 'open', 'still waiting');
  assert.equal(grant.querySelector('[data-grant-status]').textContent, 'That answer did not go through: that answer is not one of the options');
  assert.equal(grant.querySelectorAll('[data-grant-option]').find((b) => b.textContent === 'yes').disabled, false, 'and still answerable');
});

test('a page driving no session says so instead of posting an answer nobody can place', async () => {
  const { p, chat, view, conv } = thread({ session: '' });
  const calls = stubFetch(p, [response(200, { ok: true })]);
  conv.append('ask-grant', QUESTION);

  choose(view, chat.children[0], 'yes');
  await settle();

  assert.equal(calls.length, 0);
  assert.equal(chat.children[0].querySelector('[data-grant-status]').textContent, 'This page is not driving an agent session, so there is nothing to answer.');
});

test('a question with no options still renders, and says where it CAN be answered', () => {
  const { chat, conv } = thread();
  conv.append('ask-grant', { text: 'What should I do?', options: [] });
  const grant = chat.children[0];

  assert.equal(grant.querySelector('[data-grant-question]').textContent, 'What should I do?');
  assert.equal(grant.querySelectorAll('[data-grant-option]').filter((b) => b.hidden !== true).length, 0);
  assert.equal(grant.querySelector('[data-grant-status]').textContent, 'The agent proposed no options — answer with «coa agent:answer».');
  assert.equal(grant.querySelector('[data-grant-kind]').textContent, 'Waiting on you', 'an unnamed reason falls back to the generic label, never a raw code');
  assert.equal(grant.querySelector('[data-grant-why]').hidden, true, 'an absent why costs no space');
});

test('an unknown reason code is still a question worth answering, named generically', () => {
  const { chat, conv } = thread();
  conv.append('ask-grant', { text: 'Proceed?', reason: 'some_future_code', options: ['ok'] });

  assert.equal(chat.children[0].querySelector('[data-grant-kind]').textContent, 'Waiting on you');
});

test('F7 · the CONTROL: with no steps region in the prototype, a step falls back to the thread', () => {
  // The instrument before the finding. F2 and F3 claim a step nests inside the turn's block; if they
  // stayed green with the region gone, they would be measuring something else. Here the shipped module
  // meets a prototype WITHOUT `[data-thinking-steps]` — and the step lands in the thread rather than
  // vanishing, which is also the degradation a plugin that re-rendered the block would get.
  const tags = prototypeTags();
  const html = new El('html');
  const chat = html.appendChild(new El('section', { id: 'milpa-chat' }));
  const stripped = tags['milpa-thinking-proto'];
  const block = stripped.content.children[0];
  block.children = block.children.filter((c) => c.attrs['data-thinking-steps'] === undefined);
  const p = page({ tree: html, elements: tags, bus: true, modules: ['desktop-conversation', 'desktop-thinking', 'desktop-tool-call'] });
  const conv = p.desktop().conversation;

  conv.reasoning('deciding…');
  conv.append('tool', { name: 'read_file', result: 'x' });

  assert.equal(chat.children.length, 2, 'with nowhere to nest it, the step is SHOWN — never dropped');
  assert.equal(chat.children[1].classList.contains('msg--tool'), true);
});
