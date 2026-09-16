/*!
 * Stage explicit criteria or a candidate for the existing governed turn; only the server records them.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency · Apache-2.0
 */
(function () {
  'use strict';
  var live = window.MilpaLive;
  if (!live || !live.desktop || live.desktop.delivery) { return; }
  var d = live.desktop;
  var selector = '.desktop-delivery-evidence';
  var blocked = false;
  function root() { return document.querySelector(selector); }
  function error(key) { return new Error(d.tr('evidence.' + key)); }

  /** Read only the two delivery inputs; the turn owns session, prompt and permission mode. */
  function prepare() {
    if (blocked) { throw error('refresh_failed'); }
    var region = root();
    var fields = {};
    if (!region) { return fields; }
    var enabled = region.querySelector('[data-expectation-enable]');
    if (enabled && enabled.checked) {
      function value(name) {
        var input = region.querySelector('[data-expectation-field="' + name + '"]');
        return input ? String(input.value || '').trim() : '';
      }
      var target = { test: { path: value('test_path'), filter: value('test_filter') }, screen: { name: value('screen_name'), type: value('screen_type') } };
      if (!target.test.path || !target.screen.name || !target.screen.type) { throw error('invalid_criteria'); }
      var definition = value('screen_definition');
      if (definition !== '') {
        try { definition = JSON.parse(definition); } catch (_) { throw error('invalid_definition'); }
        if (!definition || typeof definition !== 'object' || Array.isArray(definition)) { throw error('invalid_definition'); }
        target.screen.definition = definition;
      }
      fields.expectation = JSON.stringify(target);
    }
    var selected = region.querySelector('[data-delivery-candidate]');
    if (selected && selected.checked) { fields.deliveryCandidate = String(selected.value); }
    return fields;
  }

  /** Replace the static evidence region with a fresh authenticated server render after any response. */
  function refresh() {
    var region = root();
    if (!region) { return Promise.resolve(); }
    blocked = true;
    region.textContent = d.tr('evidence.refreshing');
    region.setAttribute('aria-busy', 'true');
    return fetch(window.location.href, { headers: { Accept: 'text/html' }, cache: 'no-store' }).then(d.guarded).then(function (response) {
      return response.text();
    }).then(function (html) {
      var page = new DOMParser().parseFromString(html, 'text/html');
      var replacement = page.querySelector(selector);
      if (!replacement || replacement.getAttribute('data-native-evidence') === 'read_failed') { throw error('refresh_failed'); }
      region.replaceWith(replacement);
      blocked = false;
    }).catch(function () {
      region.textContent = d.tr('evidence.refresh_failed');
      region.setAttribute('data-native-evidence', 'read_failed');
      region.removeAttribute('aria-busy');
      region.setAttribute('role', 'alert');
    });
  }

  document.addEventListener('change', function (event) {
    var target = event.target;
    if (!target || !target.hasAttribute('data-expectation-enable')) { return; }
    var region = target.closest(selector);
    var fields = region ? region.querySelector('[data-expectation-fields]') : null;
    if (fields) { fields.disabled = !target.checked; }
  });
  d.delivery = { prepare: prepare, refresh: refresh };
})();
