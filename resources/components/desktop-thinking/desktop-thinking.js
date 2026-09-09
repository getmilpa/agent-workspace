/*!
 * desktop-thinking — the live thinking block, as a client module (greenhouse decisions/0211, phase C1).
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 * @license Apache-2.0
 *
 * Declared by the thinking prototype's renderer through DeclaresClientAssets and emitted, once, by
 * `LiveBoot::html()`. It registers NO Alpine factory: the block is CLONED per turn from a server-rendered
 * `<template>`, and a per-instance `x-data` double-initialises in this environment (greenhouse
 * decisions/0191) — so the component's behaviour is registered against its KIND in
 * `MilpaLive.desktop.messages.thinking`, and the conversation's one delegated handler routes to it.
 *
 * This message type has behaviour of its own, and it is the only one whose life spans a whole turn:
 *
 *   - `delta(thread, text)` opens the block on the first reasoning token and appends every one after it;
 *   - `end()` stamps the elapsed into the label — the animated spark and dots are the component's own and
 *     must survive, so only the WORDS are replaced — stops the pulse and collapses it;
 *   - `click(event)` is the collapse toggle: CSS state on `data-open`, flipped through the conversation's
 *     delegated handler, never a listener per clone.
 */
(function () {
  'use strict';

  var live = window.MilpaLive;
  if (!live) {
    if (window.console && console.warn) { console.warn('[desktop-thinking] the milpa/live runtime must load first'); }

    return;
  }

  /** The prototype the conversation clones per turn. */
  var PROTO_ID = 'milpa-thinking-proto';

  function desk() { return live.desktop || null; }
  function tr(key) { var d = desk(); return d ? d.tr.apply(null, arguments) : key; }

  /** The registry of message kinds — created by whichever module gets there first. */
  function messages() {
    var d = desk();
    if (!d) { return null; }
    d.messages = d.messages || {};

    return d.messages;
  }

  var registry = messages();
  if (!registry) {
    if (window.console && console.warn) { console.warn('[desktop-thinking] the shared desktop runtime must load first'); }

    return;
  }
  if (registry.thinking) {
    if (window.console && console.warn) { console.warn('[desktop-thinking] loaded twice; ignoring the second copy'); }

    return;
  }

  /** The block being written into, and when it opened — a turn has at most one. */
  var block = null;
  var openedAt = 0;

  registry.thinking = {
    proto: PROTO_ID,
    /** Open the block on the first token, then append every delta into its body. */
    delta: function (thread, text) {
      if (!thread) { return null; }
      if (!block) {
        var proto = document.getElementById(PROTO_ID);
        if (!proto || !('content' in proto)) { return null; }
        var frag = proto.content.cloneNode(true);
        block = frag.querySelector('.milpa-think');
        openedAt = Date.now();
        thread.appendChild(frag);
      }
      if (block) {
        var body = block.querySelector('[data-thinking-body]');
        if (body) {
          body.textContent += (text || '');
          // Keep the TAIL on screen by scrolling the BODY to its own bottom (greenhouse decisions/0254).
          // This used to be `block.scrollIntoView()` on every single token, which dragged the whole page
          // down mid-read: a reader who scrolled up to check something was yanked back by the next token.
          // The body scrolls itself; the page is the reader's.
          body.scrollTop = body.scrollHeight;
        }
      }

      return block;
    },
    /** The reasoning is done: stamp the elapsed, stop the pulse, collapse. */
    end: function () {
      if (!block) { return null; }
      var seconds = Math.max(1, Math.round((Date.now() - openedAt) / 1000));
      var elapsed = tr('thinking.elapsed', seconds);
      var label = block.querySelector('[data-thinking-label]');
      if (label) {
        label.textContent = elapsed;
      } else {
        var head = block.querySelector('[data-thinking-head]');
        if (head) { head.textContent = '◈ ' + elapsed; }
      }
      block.setAttribute('data-thinking-active', '0');
      block.setAttribute('data-open', '0');
      // The view returns to `tail` so re-opening a finished block starts where every other one does, and
      // `full` never leaks from one turn into how the next reads.
      block.setAttribute('data-thinking-view', 'tail');
      var closed = block;
      block = null;

      return closed;
    },
    /** A DISCRETE thinking message (the whole reasoning at once) is the same block, opened and closed. */
    append: function (thread, opts) {
      this.delta(thread, opts.text || '');

      return this.end();
    },
    /**
     * The toggle, flipped through the conversation's delegated handler — the component's own CSS state.
     *
     * It means two different things at two different moments, and that is the point: WHILE the model
     * reasons the body is showing its tail, so the toggle opens the rest of the reasoning (`view`); once
     * the turn is done the reasoning is folded away, so the toggle unfolds it (`open`). One control, the
     * affordance the caret advertises at each moment.
     */
    click: function (event) {
      var toggle = event.target.closest('[data-thinking-toggle]');
      if (!toggle) { return false; }
      var open = toggle.closest('.milpa-think');
      if (!open) { return true; }
      if (open.getAttribute('data-thinking-active') === '1') {
        var tail = open.getAttribute('data-thinking-view') !== 'full';
        open.setAttribute('data-thinking-view', tail ? 'full' : 'tail');
        if (tail === false) {
          var body = open.querySelector('[data-thinking-body]');
          if (body) { body.scrollTop = body.scrollHeight; }
        }

        return true;
      }
      open.setAttribute('data-open', open.getAttribute('data-open') === '1' ? '0' : '1');

      return true;
    },
    /**
     * Hang one STEP of this turn — a tool call, a parked question — under the reasoning.
     *
     * Returns false when no block is open, and the caller lands the node in the thread instead: a step
     * with no turn to belong to is still a fact, and a fact nobody can nest is shown, never dropped.
     */
    step: function (node) {
      if (!block || !node) { return false; }
      var steps = block.querySelector('[data-thinking-steps]');
      if (!steps) { return false; }
      steps.appendChild(node);

      return true;
    },
    /** The block this turn is writing into, if any — how the conversation asks whether a turn is open. */
    open: function () { return block; },
  };
})();
