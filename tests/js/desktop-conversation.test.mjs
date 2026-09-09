/**
 * The conversation and its message components, measured by EXECUTION (greenhouse decisions/0211, phase C1).
 *
 * The thread and every message KIND are declared views now, so what these run is the shipped file: the
 * prototypes the server renders are handed to the stub page as real `<template>` tags, the modules clone
 * and fill them, and what is asserted is the DOM a browser would end up with.
 *
 * Run: `node --test 'tests/js/**\/*.test.mjs'` — or `npm test` (Node ≥ 22; node:test is built in).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { El, page, settle } from './support/page.mjs';
import { prototypeTags } from './support/shell.mjs';

/** A page with the thread, its prototypes and every message module the shell declares. */
function thread(extra = {}) {
  const html = new El('html');
  const chat = html.appendChild(new El('section', { id: 'milpa-chat', class: 'tabpane milpa-chat' }));
  const p = page({
    tree: html,
    elements: prototypeTags(),
    bus: true,
    modules: ['desktop-conversation', 'desktop-thinking', 'desktop-agent-message', 'desktop-tool-call', 'desktop-result-claim', 'desktop-ask-grant'],
    ...extra,
  });

  return { p, html, chat, conversation: () => p.desktop().conversation };
}


// ── the replay (greenhouse evidence/0561) ─────────────────────────────────────────────────────────
function transcriptTag(rows) {
  const tag = new El('script', { id: 'milpa-desktop-transcript' });
  tag.textContent = JSON.stringify(rows);
  return tag;
}

test('the thread the server printed is replayed on load, each row with the prototype a live turn uses', () => {
  const html = new El('html');
  const chat = html.appendChild(new El('section', { id: 'milpa-chat' }));
  const rows = [
    { kind: 'user', text: 'run the rollout sequence' },
    { kind: 'tool', name: 'house_context', result: '{"ok":true}' },
    { kind: 'agent', text: 'On it.' },
    { kind: 'question', text: 'El agente quiere correr «sequence:run». ¿Lo autorizas?', reason: 'permission', options: ['sí', 'no'] },
    { kind: 'answered', answer: 'sí', by: 'actor:passkey:abc' },
    { kind: 'sequence_paused', sequence: 'rollout' },
    { kind: 'nonsense' },
  ];
  const p = page({ tree: html, elements: { ...prototypeTags(), 'milpa-desktop-transcript': transcriptTag(rows) }, bus: true, modules: ['desktop-conversation', 'desktop-thinking', 'desktop-agent-message', 'desktop-tool-call', 'desktop-result-claim', 'desktop-ask-grant'] });
  assert.equal(chat.children.length, 0, 'nothing is painted at module load: the fills of the other message modules are not registered yet');
  p.mount('desktopConversation', undefined, chat);

  const kinds = chat.children.map((m) => ['user', 'tool', 'agent', 'grant', 'system'].find((k) => m.classList.contains('msg--' + k)));
  assert.deepEqual(kinds, ['user', 'tool', 'agent', 'grant', 'system', 'system'], 'six rows painted, in order; the unknown one skipped');
  assert.equal(chat.children[0].querySelector('[data-user-body]').textContent, 'run the rollout sequence');
  // No turn is open in a replay, so a tool lands in the THREAD rather than nested (greenhouse decisions/0254).
  assert.equal(chat.children[1].querySelector('[data-tool-name]').textContent, 'house_context');
  const systemText = (m) => (m.getAttribute('data-system-body') !== null ? m : m.querySelector('[data-system-body]')).textContent;
  // The parked question is REPLAYED as the request it is, with the agent's own options as buttons — so a
  // question raised before a reload is still answerable from the conversation it was raised in.
  const grant = chat.children[3];
  assert.equal(grant.querySelector('[data-grant-question]').textContent, 'El agente quiere correr «sequence:run». ¿Lo autorizas?');
  assert.deepEqual(grant.querySelectorAll('[data-grant-option]').filter((b) => b.hidden !== true).map((b) => b.textContent), ['sí', 'no']);
  assert.equal(grant.querySelector('[data-grant-kind]').textContent, 'Permission needed', 'the STABLE reason code names the halt');
  assert.equal(systemText(chat.children[4]), 'Answered «sí» by actor:passkey:abc');
  assert.equal(systemText(chat.children[5]), 'Sequence «rollout» paused — answer it in Decisions');
  assert.equal(p.desktop().conversation.replay(), 0, 'a second replay paints nothing — once, like the subscriptions');
});

test('a page with no transcript tag, or an empty one, replays nothing and the thread stays empty', () => {
  const html = new El('html');
  const chat = html.appendChild(new El('section', { id: 'milpa-chat' }));
  const p = page({ tree: html, elements: { ...prototypeTags(), 'milpa-desktop-transcript': transcriptTag([]) }, bus: true, modules: ['desktop-conversation'] });
  p.mount('desktopConversation', undefined, chat);
  assert.equal(chat.children.length, 0);
});

test('every message kind lands as a clone of ITS OWN component prototype, filled by that component', () => {
  const { p, chat, conversation } = thread();
  const conv = conversation();

  conv.append('user', { text: 'ship the slice' });
  conv.append('agent', { text: 'Done. **Shipped**.' });
  conv.append('tool', { name: 'ledger:read', result: '{"ok":true,"rows":3}' });
  conv.append('task', { title: 'Measure in a browser', status: 'doing' });
  conv.append('system', { text: 'the door answered 403' });
  conv.append('result', { verified: false, reasons: 'a step carries no evidence' });

  assert.equal(chat.children.length, 6, 'six messages, each its own component');
  assert.equal(chat.children[0].classList.contains('msg--user'), true);
  assert.equal(chat.children[0].querySelector('[data-user-body]').textContent, 'ship the slice');

  // The agent's answer is MARKDOWN the component rendered from escaped text.
  assert.equal(
    chat.children[1].querySelector('[data-agent-body]').innerHTML,
    '<p>Done. <strong>Shipped</strong>.</p>',
  );

  // A tool result reads as machinery made legible: a summary, and the raw pretty-printed under the fold.
  assert.equal(chat.children[2].querySelector('[data-tool-name]').textContent, 'ledger:read');
  assert.equal(chat.children[2].querySelector('[data-tool-summary]').textContent, '→ ok · 2 fields');
  assert.equal(chat.children[2].querySelector('[data-tool-body]').textContent, '{\n  "ok": true,\n  "rows": 3\n}');

  assert.equal(chat.children[3].querySelector('[data-task-title]').textContent, 'Measure in a browser');
  assert.equal(chat.children[3].querySelector('[data-task-status]').textContent, 'doing');

  // The system notice's region is the ROOT — the fill that painted empty bubbles before `region()`.
  assert.equal(chat.children[4].textContent, 'the door answered 403');

  // The claim is a judgement with a state, and the same sentence reaches a screen reader.
  const claim = chat.children[5];
  assert.equal(claim.getAttribute('data-verified'), '0');
  assert.equal(claim.querySelector('[data-result-mark]').textContent, '⚠');
  assert.equal(claim.querySelector('[data-result-text]').textContent, 'disputed');
  assert.equal(claim.querySelector('[data-result-tip]').textContent, 'The ledger disputes this turn — a step carries no evidence.');
  assert.equal(claim.getAttribute('aria-label'), 'Disputed. The ledger disputes this turn — a step carries no evidence.');

  // An unknown kind is not silently dropped: it lands as a system notice.
  conv.append('nonsense', { text: 'from a plugin that guessed' });
  assert.equal(chat.children[6].classList.contains('msg--system'), true);
  assert.equal(p.warnings.length, 0);
});

test('a fenced code block survives markdown as ESCAPED code, not as markup', () => {
  const { p } = thread();
  const markdown = p.desktop().messages.agent.markdown;

  assert.equal(
    markdown('before\n```js\nvar x = "<b>";\n```\nafter'),
    '<p>before</p><pre class="md-pre"><code>var x = "&lt;b&gt;";</code></pre><p>after</p>',
  );
  assert.equal(markdown('<script>alert(1)</script>'), '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', 'the model\'s output is never raw HTML');
});

test('the guard says what a door answered and the THREAD renders it — nothing else couples them', () => {
  const { p, chat } = thread();

  p.desktop().notice('error', 'Not allowed here (loopback_only)');

  assert.equal(chat.children.length, 1);
  assert.equal(chat.children[0].classList.contains('msg--system'), true);
  assert.equal(chat.children[0].textContent, 'Not allowed here (loopback_only)');
  assert.equal(p.signal('desktop.notice').text, 'Not allowed here (loopback_only)', 'and it is a signal any surface may read');
});

test('the stream renders in the voice of each kind, and the thinking block opens, streams and closes', () => {
  const { p, chat } = thread();
  const bus = p.bus();

  bus.emit('agent.reasoning', { text: 'weighing ' });
  bus.emit('agent.reasoning', { text: 'the options' });

  assert.equal(chat.children.length, 1, 'one block for the whole reasoning');
  const block = chat.children[0];
  assert.equal(block.classList.contains('milpa-think'), true);
  assert.equal(block.querySelector('[data-thinking-body]').textContent, 'weighing the options');
  assert.equal(block.getAttribute('data-thinking-active'), '1', 'the pulse is on while it reasons');

  bus.emit('agent.message', { text: 'here it is' });

  assert.equal(block.getAttribute('data-thinking-active'), '0', 'the answer closes the block');
  assert.equal(block.getAttribute('data-open'), '0');
  assert.match(block.querySelector('[data-thinking-label]').textContent, /^thought for \d+s$/);
  assert.equal(chat.children[1].classList.contains('msg--agent'), true);

  bus.emit('tool.call', { name: 'fs:read', result: '[1,2,3]' });
  bus.emit('task.added', { title: 'Write it up' });
  bus.emit('system.notice', { text: 'a fact' });

  assert.equal(chat.children[2].querySelector('[data-tool-summary]').textContent, '→ 3 items');
  assert.equal(chat.children[3].querySelector('[data-task-status]').textContent, 'todo');
  assert.equal(chat.children[4].textContent, 'a fact');
});

test('ONE delegated click serves every message component: the collapses, Copy and Regenerate', async () => {
  const { p, chat } = thread();
  const conv = conversation(p);
  const asked = [];
  p.desktop().turn = { regenerate: () => asked.push('regenerate') };

  p.bus().emit('agent.reasoning', { text: 'mm' });
  const block = chat.children[0];
  const view = p.mount('desktopConversation', undefined, chat);

  // WHILE the model reasons the body is showing its TAIL, so the toggle opens the rest of the reasoning;
  // `data-open` is untouched, because there is nothing folded away yet (greenhouse decisions/0254).
  assert.equal(block.getAttribute('data-thinking-view'), 'tail', 'a live block starts on its tail');
  view.onClick({ target: block.querySelector('[data-thinking-toggle]') });
  assert.equal(block.getAttribute('data-thinking-view'), 'full', 'the toggle opens the whole reasoning');
  assert.equal(block.getAttribute('data-open'), '1', 'and folds nothing: the block is still alive');
  view.onClick({ target: block.querySelector('[data-thinking-toggle]') });
  assert.equal(block.getAttribute('data-thinking-view'), 'tail');
  // Once the turn ends the SAME control folds the reasoning away, which is what it meant before.
  conv.endReasoning();
  view.onClick({ target: block.querySelector('[data-thinking-toggle]') });
  assert.equal(block.getAttribute('data-open'), '1', 'a finished block unfolds');
  view.onClick({ target: block.querySelector('[data-thinking-toggle]') });
  assert.equal(block.getAttribute('data-open'), '0', 'and folds again');

  conv.append('tool', { name: 't', result: 'x' });
  const tool = chat.children[1];
  view.onClick({ target: tool.querySelector('[data-tool-toggle]') });
  assert.equal(tool.getAttribute('data-open'), '1', 'the raw result opens');

  conv.append('agent', { text: 'an answer' });
  const answer = chat.children[2];
  const copy = answer.querySelector('[data-agent-copy]');
  view.onClick({ target: copy });
  assert.equal(copy.classList.contains('is-done'), true, 'Copy says it copied');

  view.onClick({ target: answer.querySelector('[data-agent-regenerate]') });
  assert.deepEqual(asked, ['regenerate'], 'Regenerate asks the TURN, it does not re-derive one');

  // A click on nothing in particular is nobody's.
  assert.equal(view.onClick({ target: chat }), undefined);
  await settle();
});

/** The conversation API of a page built by `thread()`. */
function conversation(p) { return p.desktop().conversation; }

test('the verdict RIDES the last answer, and falls back to a standalone claim when there is none', () => {
  const { p, chat } = thread();
  const conv = conversation(p);

  assert.equal(conv.verdict(true, ''), false, 'with no answer to ride, the caller is told so');

  conv.append('agent', { text: 'first' });
  conv.append('agent', { text: 'second' });
  assert.equal(conv.verdict(true, ''), true);

  const slot = chat.children[1].querySelector('[data-agent-verdict]');
  assert.equal(slot.hidden, false, 'the LAST answer carries it');
  assert.equal(slot.getAttribute('data-verified'), '1');
  assert.equal(slot.querySelector('[data-verdict-label]').textContent, 'verified');
  assert.equal(
    slot.querySelector('[data-verdict-tip]').textContent,
    'The ledger backs this turn: every completed step carries evidence, nothing was left open, and no artifact\'s latest check is red.',
  );
  assert.equal(chat.children[0].querySelector('[data-agent-verdict]').hidden, true, 'the earlier answer is untouched');
});

// ── the turn as the unit of the thread (greenhouse decisions/0254) ──────────────────────────────────

test('F1 · while it reasons the body keeps ALL the words and scrolls ITSELF — the page is never dragged', () => {
  const { p, chat } = thread();
  const conv = conversation(p);

  conv.reasoning('one\n');
  const block = chat.children[0];
  const body = block.querySelector('[data-thinking-body]');
  body.scrollHeight = 400;
  conv.reasoning('two\n');
  conv.reasoning('three\n');
  conv.reasoning('four');

  assert.equal(block.getAttribute('data-thinking-view'), 'tail', 'the body shows its tail while the model reasons');
  assert.equal(body.textContent, 'one\ntwo\nthree\nfour', 'nothing is dropped — the tail CLIPS, it does not truncate');
  assert.equal(body.scrollTop, 400, 'the body scrolled itself to its own bottom');
  // The one that matters: this used to be `block.scrollIntoView()` on EVERY token, which yanked the page
  // out from under anyone who had scrolled up to read something.
  assert.equal(block.scrolledIntoView, 0, 'the page is never dragged by a reasoning token');
});

test('F2 · a tool run DURING a turn hangs inside that turn; one with no turn open lands in the thread', () => {
  const { p, chat } = thread();
  const conv = conversation(p);

  // No turn open yet: the step has nothing to belong to, and is shown rather than dropped.
  conv.append('tool', { name: 'orphan', result: 'x' });
  assert.equal(chat.children.length, 1, 'a tool with no turn open lands in the thread');
  assert.equal(chat.children[0].classList.contains('msg--tool'), true);

  conv.reasoning('deciding…');
  const block = chat.children[1];
  conv.append('tool', { name: 'house_context', result: '{"ok":true}' });
  conv.append('tool', { name: 'read_file', result: 'contents' });

  assert.equal(chat.children.length, 2, 'the two tools of the turn did NOT land as siblings');
  const steps = block.querySelector('[data-thinking-steps]');
  assert.deepEqual(steps.children.map((s) => s.querySelector('[data-tool-name]').textContent), ['house_context', 'read_file'], 'they hang under the reasoning that ran them, in order');
});

test('F3 · when the reasoning folds away, what the turn DID stays visible', () => {
  const { p, chat } = thread();
  const conv = conversation(p);

  conv.reasoning('thinking about it');
  const block = chat.children[0];
  conv.append('tool', { name: 'write_file', result: 'ok' });
  conv.endReasoning();

  assert.equal(block.getAttribute('data-open'), '0', 'the private reasoning folds — it is not the answer');
  const steps = block.querySelector('[data-thinking-steps]');
  assert.equal(steps.children.length, 1, 'the record of what happened is NOT folded with it');
  assert.equal(steps.children[0].querySelector('[data-tool-name]').textContent, 'write_file');
});

test('F2b · a question parked mid-turn hangs under the reasoning that led to it', () => {
  const { p, chat } = thread();
  const conv = conversation(p);

  conv.reasoning('this one needs a human');
  const block = chat.children[0];
  p.bus().emit('agent.parked', { id: 'q-3', text: 'May I write outside the project?', reason: 'permission', why: 'the path is above the root', options: ['yes', 'no'] });

  assert.equal(chat.children.length, 1, 'the request is not a sibling of the reasoning');
  const grant = block.querySelector('[data-thinking-steps]').children[0];
  assert.equal(grant.classList.contains('msg--grant'), true);
  assert.equal(grant.querySelector('[data-grant-question]').textContent, 'May I write outside the project?');
  assert.equal(grant.querySelector('[data-grant-why]').textContent, 'the path is above the root');
  assert.deepEqual(grant.querySelectorAll('[data-grant-option]').filter((b) => b.hidden !== true).map((b) => b.textContent), ['yes', 'no']);
});

test('F6 · a compaction draws its boundary across the thread — and a session without one draws none', () => {
  const { p, chat } = thread();

  p.bus().emit('agent.message', { text: 'first answer' });
  assert.equal(chat.children.filter((m) => m.classList.contains('msg--compacted')).length, 0, 'the positive control: no compaction, no separator');

  p.bus().emit('session.compacted', { through: 12, summary: 'the first twelve turns' });
  p.bus().emit('agent.message', { text: 'second answer' });

  const kinds = chat.children.map((m) => (m.classList.contains('msg--compacted') ? 'rule' : 'msg'));
  assert.deepEqual(kinds, ['msg', 'rule', 'msg'], 'the boundary sits BETWEEN the turns, where it happened');
  assert.equal(chat.children[1].querySelector('[data-compacted-text]').textContent, 'context compacted through turn 12');
});

test('F6b · a compaction that says nothing about how far it reached still says it happened', () => {
  const { p, chat } = thread();

  p.bus().emit('session.compacted', {});

  assert.equal(chat.children[0].querySelector('[data-compacted-text]').textContent, 'context compacted');
});

test('F9 · arriving later, a question the ledger says was ANSWERED does not offer its buttons', () => {
  // Rod's scenario: start on Desktop, arrive on mobile. The thread replays the question AND its answer,
  // and painting live buttons for something decided days ago is the room lying about what is open.
  const html = new El('html');
  const chat = html.appendChild(new El('section', { id: 'milpa-chat' }));
  const rows = [
    { kind: 'question', text: '¿Autorizas «capabilities:enable»?', id: 'perm:capabilities:enable', reason: 'permission', options: ['sí', 'no'] },
    { kind: 'answered', id: 'perm:capabilities:enable', answer: 'no', by: 'actor:rod' },
  ];
  const p = page({ tree: html, elements: { ...prototypeTags(), 'milpa-desktop-transcript': transcriptTag(rows) }, bus: true,
    modules: ['desktop-conversation', 'desktop-thinking', 'desktop-agent-message', 'desktop-tool-call', 'desktop-result-claim', 'desktop-ask-grant'] });
  p.mount('desktopConversation', undefined, chat);

  const grant = chat.children.find((m) => m.classList.contains('msg--grant'));
  assert.ok(grant, 'la pregunta se revive');
  assert.equal(grant.getAttribute('data-grant-state'), 'answered', 'y llega ya cerrada');
  assert.equal(grant.querySelectorAll('[data-grant-option]').filter((b) => b.hidden !== true && b.disabled !== true).length, 0, 'sin botones que ofrezcan decidir lo decidido');
  assert.equal(grant.querySelector('[data-grant-question]').textContent, '¿Autorizas «capabilities:enable»?', 'y la pregunta se conserva: es el registro');
});

test('F9b · the control: a question the ledger shows UNANSWERED still offers its buttons', () => {
  const html = new El('html');
  const chat = html.appendChild(new El('section', { id: 'milpa-chat' }));
  const rows = [{ kind: 'question', text: '¿Autorizas?', id: 'perm:x', reason: 'permission', options: ['sí', 'no'] }];
  const p = page({ tree: html, elements: { ...prototypeTags(), 'milpa-desktop-transcript': transcriptTag(rows) }, bus: true,
    modules: ['desktop-conversation', 'desktop-thinking', 'desktop-ask-grant'] });
  p.desktop().turn = { session: () => 'desk-1' };
  p.mount('desktopConversation', undefined, chat);

  const grant = chat.children.find((m) => m.classList.contains('msg--grant'));
  assert.equal(grant.getAttribute('data-grant-state'), 'open', 'sigue esperando, porque nadie la contestó');
  assert.equal(grant.querySelectorAll('[data-grant-option]').filter((b) => b.hidden !== true).length, 2);
});

test('F10 · a replayed thread does not paint the question twice', () => {
  // The ledger holds the assistant's turn AND the question, because the house answers a parked turn
  // with the question as its answer. Rod saw both painted in a screenshot: the request with its
  // buttons, and right under it an agent bubble repeating it word for word.
  const html = new El('html');
  const chat = html.appendChild(new El('section', { id: 'milpa-chat' }));
  const asked = 'El agente quiere correr «capabilities:enable». ¿Lo autorizas?';
  const rows = [
    { kind: 'question', text: asked, id: 'perm:capabilities:enable', options: ['sí', 'no'] },
    { kind: 'agent', text: asked + '\n  con: {"capability":"milpa/mcp-server"}' },
  ];
  const p = page({ tree: html, elements: { ...prototypeTags(), 'milpa-desktop-transcript': transcriptTag(rows) }, bus: true,
    modules: ['desktop-conversation', 'desktop-thinking', 'desktop-agent-message', 'desktop-ask-grant'] });
  p.desktop().turn = { session: () => 'desk-1' };
  p.mount('desktopConversation', undefined, chat);

  assert.equal(chat.children.filter((m) => m.classList.contains('msg--grant')).length, 1, 'la petición');
  assert.equal(chat.children.filter((m) => m.classList.contains('msg--agent')).length, 0, 'y NO su eco');
});

test('F10b · the control: what the agent said that is NOT the question is still replayed', () => {
  const html = new El('html');
  const chat = html.appendChild(new El('section', { id: 'milpa-chat' }));
  const rows = [
    { kind: 'question', text: '¿Autorizas?', id: 'perm:x', options: ['sí', 'no'] },
    { kind: 'agent', text: 'Revisé el catálogo y encontré 42 operaciones.' },
  ];
  const p = page({ tree: html, elements: { ...prototypeTags(), 'milpa-desktop-transcript': transcriptTag(rows) }, bus: true,
    modules: ['desktop-conversation', 'desktop-thinking', 'desktop-agent-message', 'desktop-ask-grant'] });
  p.desktop().turn = { session: () => 'desk-1' };
  p.mount('desktopConversation', undefined, chat);

  assert.equal(chat.children.filter((m) => m.classList.contains('msg--agent')).length, 1, 'eso sí lo dijo');
});

test('F11 · the decision is said ONCE — in the request it settled, not again underneath it', () => {
  // Rod, on a screenshot showing the closed bubble, an echo of the question, and an «ANSWERED …» line:
  // «toda esa info ya está en la primera burbuja».
  const html = new El('html');
  const chat = html.appendChild(new El('section', { id: 'milpa-chat' }));
  const rows = [
    { kind: 'question', text: '¿Autorizas?', id: 'perm:x', options: ['sí', 'no'] },
    { kind: 'answered', id: 'perm:x', answer: 'no', by: 'actor:rod' },
  ];
  const p = page({ tree: html, elements: { ...prototypeTags(), 'milpa-desktop-transcript': transcriptTag(rows) }, bus: true,
    modules: ['desktop-conversation', 'desktop-thinking', 'desktop-ask-grant'] });
  p.desktop().turn = { session: () => 'desk-1' };
  p.mount('desktopConversation', undefined, chat);

  assert.equal(chat.children.filter((m) => m.classList.contains('msg--system')).length, 0, 'ninguna línea repite lo que la burbuja ya dice');
  const grant = chat.children.find((m) => m.classList.contains('msg--grant'));
  assert.equal(grant.getAttribute('data-grant-state'), 'answered');
  assert.equal(grant.querySelector('[data-grant-status]').textContent, 'Answered «no» by actor:rod.', 'dicho una vez, donde corresponde');
});

test('F11b · THE CONTROL: a decision whose request is not on this page is still said', () => {
  // An older thread, a pruned question — then the standalone line is the only record there is, and
  // swallowing it would lose the decision entirely.
  const html = new El('html');
  const chat = html.appendChild(new El('section', { id: 'milpa-chat' }));
  const rows = [{ kind: 'answered', id: 'perm:nowhere', answer: 'sí', by: 'actor:rod' }];
  const p = page({ tree: html, elements: { ...prototypeTags(), 'milpa-desktop-transcript': transcriptTag(rows) }, bus: true,
    modules: ['desktop-conversation', 'desktop-thinking', 'desktop-ask-grant'] });
  p.desktop().turn = { session: () => 'desk-1' };
  p.mount('desktopConversation', undefined, chat);

  assert.equal(chat.children.filter((m) => m.classList.contains('msg--system')).length, 1, 'sin burbuja que lo diga, la línea es el registro');
  assert.equal(chat.children[0].textContent, 'Answered «sí» by actor:rod');
});
