/**
 * @file
 * AIRO AI Preview tab — Phase 2: N-parallel provider comparison.
 *
 * Architecture
 * ────────────
 * When the editor clicks "Compare" / "Ask", the behavior fires N independent
 * fetch() requests simultaneously — one per selected provider+model.
 *
 * Each request posts { question, provider_models: ['one_key'] } to the Phase 1
 * controller endpoint, which already handles N=1 correctly and returns:
 *   { results: [{ key, provider_id, model_id, label, html, duration_ms, error }] }
 *
 * Placeholder cards are rendered immediately in the results grid so the user
 * sees N labelled spinners straight away.  Cards fill in as each response
 * arrives — fast providers appear first.  One failing provider never blocks the
 * others.
 *
 * No backend changes are required for Phase 2 beyond optional `revision_id`
 * in the POST body (same nid) so preview uses the draft revision from the form.
 */
(function (Drupal, once) {
  'use strict';

  /** @type {string} BEM block prefix */
  var P = 'airo-preview';

  // ─── DOM helpers ──────────────────────────────────────────────────────────

  /** @param {Element} el @returns {Element|null} */
  function getWrapper(el) {
    return el.closest('.' + P) || document.querySelector('.' + P);
  }

  /** @param {Element} wrapper @returns {boolean} */
  function usesPageSkin(wrapper) {
    return wrapper && wrapper.getAttribute('data-page-skin') === '1';
  }

  /**
   * Returns all checked provider__model keys inside the given wrapper.
   * @param {Element} wrapper
   * @returns {string[]}
   */
  function getSelectedKeys(wrapper) {
    var select = wrapper.querySelector('.' + P + '__model-select');
    if (select && select.value) {
      return [select.value];
    }
    var boxes = wrapper.querySelectorAll('.' + P + '__model-checkbox:checked');
    return Array.prototype.map.call(boxes, function (b) { return b.value; });
  }

  /**
   * Resolves the display label for a given provider__model key by reading the
   * checkbox label text from the DOM (avoids a separate data attribute or map).
   * @param {string} key
   * @param {Element} wrapper
   * @returns {string}
   */
  function getLabelForKey(key, wrapper) {
    var select = wrapper.querySelector('.' + P + '__model-select');
    if (select) {
      var i;
      for (i = 0; i < select.options.length; i++) {
        if (select.options[i].value === key) {
          return select.options[i].textContent.trim();
        }
      }
    }
    var boxes = wrapper.querySelectorAll('.' + P + '__model-checkbox');
    for (var j = 0; j < boxes.length; j++) {
      if (boxes[j].value === key) {
        var lbl = boxes[j].parentElement
          ? boxes[j].parentElement.querySelector('.' + P + '__model-option-label')
          : null;
        return lbl ? lbl.textContent.trim() : key;
      }
    }
    return key;
  }

  /** @returns {string} */
  function formatQueryTimestamp() {
    var d = new Date();
    var y = d.getFullYear();
    var mo = String(d.getMonth() + 1).padStart(2, '0');
    var day = String(d.getDate()).padStart(2, '0');
    var h = d.getHours();
    var m = String(d.getMinutes()).padStart(2, '0');
    var ampm = h >= 12 ? 'pm' : 'am';
    h = h % 12;
    if (h === 0) {
      h = 12;
    }
    return y + '-' + mo + '-' + day + ' - ' + h + ':' + m + ' ' + ampm;
  }

  /** @returns {string} */
  function formatGeneratedTimestamp() {
    var d = new Date();
    var y = d.getFullYear();
    var mo = String(d.getMonth() + 1).padStart(2, '0');
    var day = String(d.getDate()).padStart(2, '0');
    var h = String(d.getHours()).padStart(2, '0');
    var mi = String(d.getMinutes()).padStart(2, '0');
    var s = String(d.getSeconds()).padStart(2, '0');
    return y + '-' + mo + '-' + day + ' ' + h + ':' + mi + ':' + s;
  }

  /**
   * Keeps the submit button enabled/disabled and updates its label.
   * - Disabled when there is no question text.
   * - Label: "Compare" when ≥2 providers are ticked; "Ask" for exactly 1.
   * @param {Element} wrapper
   */
  function syncButton(wrapper) {
    var input = wrapper.querySelector('.' + P + '__input');
    var btn   = wrapper.querySelector('.' + P + '__submit');
    if (!input || !btn) return;

    var hasQ = input.value.trim() !== '';
    btn.disabled = !hasQ;

    if (usesPageSkin(wrapper)) {
      btn.textContent = Drupal.t('Ask');
      return;
    }

    var checkedCount = wrapper.querySelectorAll('.' + P + '__model-checkbox:checked').length;
    btn.textContent = (checkedCount >= 2)
      ? Drupal.t('Compare')
      : Drupal.t('Ask');
  }

  // ─── Rendering helpers ────────────────────────────────────────────────────

  /**
   * Returns the inner HTML of a result card (header + body, no outer div).
   * Used for both the final card and post-response replacement.
   * @param {{label:string, provider_id:string, html:string|null, duration_ms:number, error:string|null}} result
   * @returns {string}
   */
  function renderCardInner(result) {
    var durationBadge = result.duration_ms
      ? '<span class="' + P + '__response-duration">' +
          Drupal.checkPlain(String(result.duration_ms)) + '\u202fms' +
        '</span>'
      : '';

    var body = result.error
      ? '<div class="' + P + '__error">' + Drupal.checkPlain(result.error) + '</div>'
      : '<div class="' + P + '__response-body">' + (result.html || '') + '</div>';

    return '<div class="' + P + '__response-header">' +
        '<span class="' + P + '__response-provider">' +
          Drupal.checkPlain(result.label || result.provider_id || 'AI') +
        '</span>' +
        '<span class="' + P + '__response-meta">' + durationBadge + '</span>' +
      '</div>' +
      body;
  }

  /**
   * Returns a full, standalone result card div.
   * @param {object} result
   * @returns {string}
   */
  function renderCard(result) {
    return '<div class="' + P + '__response-card">' + renderCardInner(result) + '</div>';
  }

  // ─── Phase 2 submit: N parallel requests ─────────────────────────────────

  /**
   * Fires one fetch() request for a single provider key and returns the Promise.
   * Resolves with a normalised result object whether the call succeeds or fails.
   *
   * @param {string} queryUrl
   * @param {string} question
   * @param {string} key      provider__model key, or '' for the site default.
   * @param {Element} wrapper  .airo-preview root (revision_id + query URL).
   * @returns {Promise<object>}
   */
  function fetchOneProvider(queryUrl, question, key, wrapper) {
    var postBody = {
      question:        question,
      provider_models: key ? [key] : [],
    };
    var rid = wrapper && wrapper.getAttribute('data-revision-id');
    if (rid) {
      var parsed = parseInt(rid, 10);
      if (!isNaN(parsed) && parsed > 0) {
        postBody.revision_id = parsed;
      }
    }

    return fetch(queryUrl, {
      method: 'POST',
      headers: {
        'Content-Type':     'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      credentials: 'same-origin',
      body: JSON.stringify(postBody),
    })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      // If the server returned a top-level error, synthesise a result object.
      if (data.error) {
        return { key: key, label: key, provider_id: '', model_id: '', html: null, duration_ms: 0, error: data.error };
      }
      // Normal path: return the first (only) element of results[].
      return (data.results && data.results[0]) || { key: key, label: key, html: null, duration_ms: 0, error: 'No result returned.' };
    })
    .catch(function (err) {
      return { key: key, label: key, provider_id: '', model_id: '', html: null, duration_ms: 0, error: Drupal.t('Request failed. Please try again.') };
    });
  }

  /**
   * Main entry point — submits the comparison query.
   *
   * 1. Renders N placeholder loading cards immediately in the grid so the user
   *    sees labelled spinners for every provider right away.
   * 2. Fires N fetch() calls simultaneously (one per selected key).
   * 3. As each promise resolves, finds its placeholder card in the grid by
   *    stable ID and replaces its content with the actual result.
   *
   * One provider failing never affects the others.
   *
   * @param {string}  question
   * @param {Element} wrapper
   */
  /**
   * Renders or updates an AI answer card (page skin).
   */
  function renderPageSkinAnswerCard(card, result, providerLabel, queryUrl, question, key, wrapper) {
    var label = Drupal.checkPlain(result.label || providerLabel || 'AI');
    var body = result.error
      ? '<div class="' + P + '__error">' + Drupal.checkPlain(result.error) + '</div>'
      : '<div class="' + P + '__answer-card-body">' + (result.html || '') + '</div>';

    card.classList.remove(P + '__answer-card--loading');
    card.innerHTML =
      '<div class="' + P + '__answer-card-header">' +
        '<span class="' + P + '__answer-card-provider">' + label + '</span>' +
        '<button type="button" class="' + P + '__answer-card-rerun" aria-label="' + Drupal.t('Regenerate response') + '" title="' + Drupal.t('Regenerate') + '">' +
          '<span aria-hidden="true">&#8635;</span>' +
        '</button>' +
      '</div>' +
      body +
      '<footer class="' + P + '__answer-card-footer">' +
        '<span>' + Drupal.t('Simulated response') + '</span>' +
        '<span>' + Drupal.t('Generated:') + ' ' + Drupal.checkPlain(formatGeneratedTimestamp()) + '</span>' +
      '</footer>';

    var rerunBtn = card.querySelector('.' + P + '__answer-card-rerun');
    if (rerunBtn) {
      rerunBtn.addEventListener('click', function (e) {
        e.preventDefault();
        card.classList.add(P + '__answer-card--loading');
        card.innerHTML =
          '<div class="' + P + '__answer-card-header">' +
            '<span class="' + P + '__answer-card-provider">' + Drupal.checkPlain(providerLabel) + '</span>' +
          '</div>' +
          '<div class="' + P + '__loading">' +
            '<div class="' + P + '__loading-spinner"></div>' +
            '<div class="' + P + '__loading-text">' + Drupal.t('Querying\u2026') + '</div>' +
          '</div>';
        fetchOneProvider(queryUrl, question, key, wrapper).then(function (rerunResult) {
          if (!card.isConnected) return;
          renderPageSkinAnswerCard(card, rerunResult, providerLabel, queryUrl, question, key, wrapper);
        });
      });
    }
  }

  /**
   * Figma-style stacked query + answer cards (page skin).
   *
   * @param {string} question
   * @param {Element} wrapper
   * @param {boolean} append
   */
  function submitQueryPageSkin(question, wrapper, append) {
    if (!question || !wrapper) return;

    var nodeId = wrapper.getAttribute('data-node-id');
    var queryUrl = wrapper.getAttribute('data-query-url');
    var resultsEl = document.getElementById(P + '-results-' + nodeId);
    if (!queryUrl || !nodeId || !resultsEl) return;

    var keys = getSelectedKeys(wrapper);
    var key = keys.length > 0 ? keys[0] : '';
    var providerLabel = key ? getLabelForKey(key, wrapper) : Drupal.t('AI');
    var threadId = 'thread-' + nodeId + '-' + Date.now();

    if (!append) {
      resultsEl.innerHTML = '';
    }

    var queryCard =
      '<article class="' + P + '__query-card" data-thread-id="' + threadId + '">' +
        '<div class="' + P + '__query-card-header">' +
          '<span>' + Drupal.t('Your query:') + '</span>' +
          '<time datetime="">' + Drupal.checkPlain(formatQueryTimestamp()) + '</time>' +
        '</div>' +
        '<p class="' + P + '__query-card-text">' + Drupal.checkPlain(question) + '</p>' +
      '</article>';

    var answerId = threadId + '-answer';
    var loadingCard =
      '<article class="' + P + '__answer-card ' + P + '__answer-card--loading" id="' + answerId + '" data-thread-id="' + threadId + '">' +
        '<div class="' + P + '__answer-card-header">' +
          '<span class="' + P + '__answer-card-provider">' + Drupal.checkPlain(providerLabel) + '</span>' +
        '</div>' +
        '<div class="' + P + '__loading">' +
          '<div class="' + P + '__loading-spinner"></div>' +
          '<div class="' + P + '__loading-text">' + Drupal.t('Querying\u2026') + '</div>' +
        '</div>' +
      '</article>';

    resultsEl.insertAdjacentHTML('beforeend', queryCard + loadingCard);
    resultsEl.scrollTop = resultsEl.scrollHeight;

    fetchOneProvider(queryUrl, question, key, wrapper).then(function (result) {
      var card = document.getElementById(answerId);
      if (!card) return;
      renderPageSkinAnswerCard(card, result, providerLabel, queryUrl, question, key, wrapper);
    });
  }

  function submitQueryParallel(question, wrapper) {
    if (!question || !wrapper) return;

    if (usesPageSkin(wrapper)) {
      submitQueryPageSkin(question, wrapper, false);
      return;
    }

    var nodeId    = wrapper.getAttribute('data-node-id');
    var queryUrl  = wrapper.getAttribute('data-query-url');
    var resultsEl = document.getElementById(P + '-results-' + nodeId);
    if (!queryUrl || !nodeId || !resultsEl) return;

    // Gather selected keys; fall back to a single empty key so the server
    // resolves to the site default (covers users without the multi-select permission).
    var selectedKeys = getSelectedKeys(wrapper);
    var keys = selectedKeys.length > 0 ? selectedKeys : [''];

    // ── Step 1: Build compare <div> with tablist and placeholder panels ──────

    var tabButtons = keys.map(function (key, i) {
      var label   = key ? getLabelForKey(key, wrapper) : Drupal.t('AI');
      var panelId = P + '-panel-' + nodeId + '-' + i;
      var tabKey  = P + '-tab-' + nodeId + '-' + i;
      return '<li class="tabs__tab' + (i === 0 ? ' is-active' : '') + '">' +
        '<button type="button" role="tab"' +
        ' class="tabs__link' + (i === 0 ? ' is-active' : '') + '"' +
        ' aria-controls="' + panelId + '"' +
        ' aria-selected="' + (i === 0 ? 'true' : 'false') + '"' +
        ' data-ai-tab-target="' + tabKey + '"' +
        ' tabindex="' + (i === 0 ? '0' : '-1') + '">' +
        Drupal.checkPlain(label) +
        '</button></li>';
    });

    var tabPanels = keys.map(function (key, i) {
      var panelId = P + '-panel-' + nodeId + '-' + i;
      var tabKey  = P + '-tab-' + nodeId + '-' + i;
      return '<div class="ai-content-audit-tab-panel"' +
        ' role="tabpanel"' +
        ' id="' + panelId + '"' +
        ' data-ai-tab="' + tabKey + '"' +
        (i !== 0 ? ' hidden' : '') + '>' +
        '<div class="' + P + '__loading">' +
          '<div class="' + P + '__loading-spinner"></div>' +
          '<div class="' + P + '__loading-text">' + Drupal.t('Querying\u2026') + '</div>' +
        '</div>' +
        '</div>';
    });

    resultsEl.innerHTML =
      '<div class="ai-content-audit-compare ai-content-audit-compare--results">' +
        '<nav class="tabs-wrapper is-horizontal" aria-label="' + Drupal.t('AI preview providers') + '">' +
          '<ul class="tabs tabs--primary is-horizontal clearfix" role="tablist">' +
            tabButtons.join('') +
          '</ul>' +
        '</nav>' +
        tabPanels.join('') +
      '</div>';

    // Attach behaviors so provider-tabs.js fires on the freshly injected DOM.
    Drupal.attachBehaviors(resultsEl);

    // ── Step 2: Fire N requests in parallel ───────────────────────────────

    keys.forEach(function (key, i) {
      var panelId = P + '-panel-' + nodeId + '-' + i;

      fetchOneProvider(queryUrl, question, key, wrapper).then(function (result) {
        var panel = document.getElementById(panelId);
        if (!panel) return;

        var durationBadge = result.duration_ms
          ? '<span class="' + P + '__response-duration">' +
              Drupal.checkPlain(String(result.duration_ms)) + '\u202fms' +
            '</span>'
          : '';

        var body = result.error
          ? '<div class="' + P + '__error">' + Drupal.checkPlain(result.error) + '</div>'
          : '<div class="' + P + '__response-body">' + (result.html || '') + '</div>';

        panel.innerHTML =
          '<div class="' + P + '__response-header">' +
            '<span class="' + P + '__response-provider">' +
              Drupal.checkPlain(result.label || result.provider_id || 'AI') +
            '</span>' +
            '<span class="' + P + '__response-meta">' + durationBadge + '</span>' +
          '</div>' +
          body;
      });
    });
  }

  // ─── Drupal behavior ──────────────────────────────────────────────────────

  Drupal.behaviors.airoPreviewTab = {
    attach: function (context) {

      // Submit button click.
      once('airo-preview-submit', '.' + P + '__submit', context).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var wrapper = getWrapper(btn);
          if (!wrapper) return;
          var input = wrapper.querySelector('.' + P + '__input');
          var q = input ? input.value.trim() : '';
          if (q) submitQueryParallel(q, wrapper);
        });
      });

      // Enter key on input.
      once('airo-preview-input', '.' + P + '__input', context).forEach(function (input) {
        input.addEventListener('input', function () {
          syncButton(getWrapper(input));
        });
        input.addEventListener('keypress', function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            var q = this.value.trim();
            if (q) submitQueryParallel(q, getWrapper(this));
          }
        });
      });

      // Suggested prompt chips — fill input and submit immediately.
      once('airo-preview-suggestion', '.' + P + '__suggestion-btn', context).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var prompt = this.getAttribute('data-prompt');
          if (!prompt) return;
          var wrapper = getWrapper(btn);
          if (!wrapper) return;
          var input = wrapper.querySelector('.' + P + '__input');
          if (input) {
            input.value = prompt;
            syncButton(wrapper);
          }
          submitQueryParallel(prompt, wrapper);
        });
      });

      // Checkbox changes — sync button label ("Compare" vs "Ask").
      once('airo-preview-checkbox', '.' + P + '__model-checkbox', context).forEach(function (cb) {
        cb.addEventListener('change', function () {
          syncButton(getWrapper(cb));
        });
      });

      once('airo-preview-reset', '.' + P + '__reset', context).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var wrapper = getWrapper(btn);
          if (!wrapper) return;
          var input = wrapper.querySelector('.' + P + '__input');
          if (input) {
            input.value = '';
          }
          var resultsEl = document.getElementById(P + '-results-' + wrapper.getAttribute('data-node-id'));
          if (resultsEl) {
            resultsEl.innerHTML = '';
          }
          syncButton(wrapper);
        });
      });

      // Initial sync on first attach (handles pre-ticked checkboxes from tempstore).
      once('airo-preview-init', '.' + P, context).forEach(function (wrapper) {
        syncButton(wrapper);
      });

      // Panel host is a <form> for gin_lb field styles; block native submit.
      once('airo-analysis-panel-host', 'form.airo-analysis-panel-host', context).forEach(function (form) {
        form.addEventListener('submit', function (e) {
          e.preventDefault();
        });
      });
    },
  };

})(Drupal, once);
