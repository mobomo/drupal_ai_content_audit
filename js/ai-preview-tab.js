/**
 * @file
 * @param {Drupal} Drupal
 *   The Drupal global used to define behaviors.
 * @param {Function} once
 *   The once library used to ensure behaviors are attached only once.
 *
 * AIRO AI Preview tab — parallel provider comparison + page skin two-screen flow.
 */
(function (Drupal, once) {
  /** @type {string} BEM block prefix */
  const P = 'airo-preview';

  // ─── DOM helpers ──────────────────────────────────────────────────────────

  /**
   * @param {Element} el
   *   The Element to get the wrapper from.
   *
   * @return {Element|null}
   *   The closest preview wrapper element, or null if not found.
   */
  function getWrapper(el) {
    return el.closest(`.${P}`) || document.querySelector(`.${P}`);
  }

  /**
   * @param {Element} wrapper
   *   The preview wrapper element.
   *
   * @return {Element|null}
   *   The page skin panel element or null.
   */
  function getPageSkinPanel(wrapper) {
    return wrapper ? wrapper.closest('.airo-panel--page-skin') : null;
  }

  /**
   * @param {Element} wrapper
   *   The preview wrapper element.
   *
   * @return {boolean}
   *   TRUE if the wrapper uses the page skin layout.
   */
  function usesPageSkin(wrapper) {
    return wrapper && wrapper.getAttribute('data-page-skin') === '1';
  }

  /**
   * Keeps landing/conversation checkbox lists in sync (two copies in the DOM).
   *
   * @param {Element} wrapper
   *   The preview wrapper element.
   * @param {Element} fromScreen
   *   The screen element to sync from.
   * @param {Element} toScreen
   *   The screen element to sync to.
   */
  function syncModelCheckboxesBetweenScreens(wrapper, fromScreen, toScreen) {
    if (!fromScreen || !toScreen) return;
    const toBoxes = toScreen.querySelectorAll(`.${P}__model-checkbox`);
    Array.prototype.forEach.call(toBoxes, function (toBox) {
      const fromBox = fromScreen.querySelector(
        `.${P}__model-checkbox[value="${CSS.escape(toBox.value)}"]`,
      );
      if (fromBox) {
        toBox.checked = fromBox.checked;
      }
    });
  }

  /**
   * @param {Element} wrapper
   *   The preview wrapper element.
   * @param {'landing'|'conversation'} state
   *   The UI state to set.
   */
  function setUiState(wrapper, state) {
    const panel = getPageSkinPanel(wrapper);
    if (!panel) return;
    const landingScreen = wrapper.querySelector('.airo-panel__screen--landing');
    const convScreen = wrapper.querySelector(
      '.airo-panel__screen--conversation',
    );
    if (landingScreen && convScreen) {
      if (state === 'conversation') {
        syncModelCheckboxesBetweenScreens(wrapper, landingScreen, convScreen);
      } else {
        syncModelCheckboxesBetweenScreens(wrapper, convScreen, landingScreen);
      }
    }
    panel.setAttribute('data-airo-ui-state', state);
    if (landingScreen) {
      landingScreen.hidden = state !== 'landing';
    }
    if (convScreen) {
      convScreen.hidden = state !== 'conversation';
    }
  }

  /**
   * @param {Element} wrapper
   *   The preview wrapper element.
   *
   * @return {HTMLTextAreaElement|HTMLInputElement|null}
   *   The landing input element or null.
   */
  function getLandingInput(wrapper) {
    return wrapper.querySelector(`.${P}__input--landing`);
  }

  /**
   * @param {Element} wrapper
   *   The preview wrapper element.
   *
   * @return {HTMLTextAreaElement|HTMLInputElement|null}
   *   The conversation input element or null.
   */
  function getConversationInput(wrapper) {
    return wrapper.querySelector(`.${P}__input--conversation`);
  }

  /**
   * @param {Element} wrapper
   *   The preview wrapper element.
   * @param {string} composer
   *   Either 'landing' or 'conversation'.
   *
   * @return {Element|null}
   *   The submit arrow button element or null.
   */
  function getSubmitArrow(wrapper, composer) {
    return wrapper.querySelector(
      `.${P}__submit-arrow[data-composer="${composer}"]`,
    );
  }

  /**
   * Active screen root for model checkboxes (avoids duplicate landing + conversation sets).
   *
   * @param {Element} wrapper
   *   The preview wrapper element.
   *
   * @return {Element}
   *   The active model selector root element.
   */
  function getModelSelectorRoot(wrapper) {
    if (!usesPageSkin(wrapper)) {
      return wrapper;
    }
    const panel = getPageSkinPanel(wrapper);
    if (panel && panel.getAttribute('data-airo-ui-state') === 'conversation') {
      const conv = wrapper.querySelector('.airo-panel__screen--conversation');
      if (conv) {
        return conv;
      }
    }
    const landing = wrapper.querySelector('.airo-panel__screen--landing');
    return landing || wrapper;
  }

  /**
   * Returns all checked provider/model keys inside the given wrapper.
   *
   * @param {Element} wrapper
   *   The preview wrapper element.
   *
   * @return {string[]}
   *   Array of unique selected provider model keys.
   */
  function getSelectedKeys(wrapper) {
    const root = getModelSelectorRoot(wrapper);
    const boxes = root.querySelectorAll(`.${P}__model-checkbox:checked`);
    const keys = Array.prototype.map.call(boxes, function (b) {
      return b.value;
    });
    return keys.filter(function (key, i) {
      return keys.indexOf(key) === i;
    });
  }

  /**
   * @param {string} key
   *   The provider model key.
   * @param {Element} wrapper
   *   The preview wrapper element.
   *
   * @return {string}
   *   The human-readable label for the given key.
   */
  function getLabelForKey(key, wrapper) {
    const root = getModelSelectorRoot(wrapper);
    const boxes = root.querySelectorAll(`.${P}__model-checkbox`);
    for (let j = 0; j < boxes.length; j++) {
      if (boxes[j].value === key) {
        const lbl = boxes[j].parentElement
          ? boxes[j].parentElement.querySelector(`.${P}__model-option-label`)
          : null;
        return lbl ? lbl.textContent.trim() : key;
      }
    }
    const single = wrapper.querySelector(`.${P}__model-single-label`);
    if (single) {
      return single.textContent.trim();
    }
    return key;
  }

  /**
   * @param {Element} wrapper
   *   The preview wrapper element.
   *
   * @return {string|null}
   *   The active provider model key or null.
   */
  function getActiveProviderModelKey(wrapper) {
    const nodeId = wrapper.getAttribute('data-node-id');
    const tabsHost = document.getElementById(
      `airo-preview-provider-tabs-${nodeId}`,
    );
    if (!tabsHost) {
      return null;
    }

    const activeTab =
      tabsHost.querySelector('[role="tab"][aria-selected="true"]') ||
      tabsHost.querySelector('[role="tab"].is-active');
    if (!activeTab) {
      return null;
    }

    return activeTab.getAttribute('data-provider-model-key');
  }

  /**
   * When only one model remains selected, show its compare panel.
   *
   * @param {Element} wrapper
   *   The preview wrapper element.
   * @param {string[]} keys
   *   Array of currently selected provider model keys.
   */
  function syncActiveComparePanel(wrapper, keys) {
    if (!usesPageSkin(wrapper) || keys.length !== 1) {
      return;
    }

    const nodeId = wrapper.getAttribute('data-node-id');
    const tabsHost = document.getElementById(
      `airo-preview-provider-tabs-${nodeId}`,
    );
    if (!tabsHost) {
      return;
    }

    const compareId = tabsHost.getAttribute('data-compare-id');
    if (!compareId) {
      return;
    }

    const compare = document.getElementById(compareId);
    if (!compare) {
      return;
    }

    const selectedKey = keys[0];
    const panels = compare.querySelectorAll('[role="tabpanel"]');
    let matched = false;

    panels.forEach(function (panel) {
      const panelKey = panel.getAttribute('data-provider-model-key');
      if (panelKey === selectedKey) {
        panel.removeAttribute('hidden');
        matched = true;
      } else {
        panel.setAttribute('hidden', '');
      }
    });

    if (matched) {
      return;
    }

    // Fallback for compare blocks created before data-provider-model-key existed.
    const tabs = tabsHost.querySelectorAll('[role="tab"]');
    let targetIndex = -1;
    Array.prototype.forEach.call(tabs, function (tab, i) {
      if (tab.getAttribute('data-provider-model-key') === selectedKey) {
        targetIndex = i;
      }
    });
    if (targetIndex < 0) {
      return;
    }
    Array.prototype.forEach.call(panels, function (panel, i) {
      if (i === targetIndex) {
        panel.removeAttribute('hidden');
      } else {
        panel.setAttribute('hidden', '');
      }
    });
  }

  /**
   * Updates "Multiple models" legend text and provider tabs visibility.
   *
   * @param {Element} wrapper
   *   The preview wrapper element.
   */
  function updateModelSelectorUi(wrapper) {
    if (!wrapper) return;

    const keys = getSelectedKeys(wrapper);
    const labelText =
      keys.length === 1
        ? getLabelForKey(keys[0], wrapper)
        : Drupal.t('Multiple models');

    wrapper
      .querySelectorAll(`.${P}__model-legend-label`)
      .forEach(function (el) {
        el.textContent = labelText;
      });

    if (!usesPageSkin(wrapper)) return;

    const nodeId = wrapper.getAttribute('data-node-id');
    const tabsHost = document.getElementById(
      `airo-preview-provider-tabs-${nodeId}`,
    );
    if (!tabsHost) return;

    if (keys.length <= 1) {
      syncActiveComparePanel(wrapper, keys);
      tabsHost.hidden = true;
      return;
    }

    if (tabsHost.querySelector('[role="tab"]')) {
      tabsHost.hidden = false;
    }
  }

  /**
   * Formats a timestamp for display on query cards.
   *
   * @return {string}
   *   Formatted timestamp string.
   */
  function formatGeneratedTimestamp() {
    const d = new Date();
    const y = d.getFullYear();
    const mo = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    const h = String(d.getHours()).padStart(2, '0');
    const mi = String(d.getMinutes()).padStart(2, '0');
    const s = String(d.getSeconds()).padStart(2, '0');
    return `${y}-${mo}-${day} ${h}:${mi}:${s}`;
  }

  /**
   * Syncs submit button state with current input value and model selection.
   *
   * @param {Element} wrapper
   *   The preview wrapper element.
   */
  function syncButton(wrapper) {
    if (!wrapper) return;

    if (usesPageSkin(wrapper)) {
      ['landing', 'conversation'].forEach(function (composer) {
        const input =
          composer === 'landing'
            ? getLandingInput(wrapper)
            : getConversationInput(wrapper);
        const btn = getSubmitArrow(wrapper, composer);
        if (!input || !btn) return;
        const hasQ = input.value.trim() !== '';
        btn.disabled = !hasQ;
      });
      return;
    }

    const input = wrapper.querySelector(`.${P}__input`);
    const btn = wrapper.querySelector(`.${P}__submit`);
    if (!input || !btn) return;

    const hasQ = input.value.trim() !== '';
    btn.disabled = !hasQ;

    const checkedCount = wrapper.querySelectorAll(
      `.${P}__model-checkbox:checked`,
    ).length;
    btn.textContent = checkedCount >= 2 ? Drupal.t('Compare') : Drupal.t('Ask');
  }

  /**
   * Focuses the conversation results region and input.
   *
   * @param {Element} wrapper
   *   The preview wrapper element.
   */
  function focusConversationRegion(wrapper) {
    const resultsEl = document.getElementById(
      `${P}-results-${wrapper.getAttribute('data-node-id')}`,
    );
    if (resultsEl) {
      resultsEl.focus({ preventScroll: false });
    }
    const convInput = getConversationInput(wrapper);
    if (convInput) {
      convInput.focus();
    }
  }

  // ─── Rendering helpers ────────────────────────────────────────────────────

  /**
   * Builds the HTML for a page skin loading card.
   *
   * @param {string} answerId
   *   The ID to assign to the loading card element.
   * @param {string} threadId
   *   The thread ID for grouping query/answer pairs.
   *
   * @return {string}
   *   HTML string for the loading card.
   */
  function buildPageSkinLoadingCard(answerId, threadId) {
    return (
      `<article class="${P}__loading-card" id="${answerId}" data-thread-id="${
        threadId
      }">` +
      `<p class="${P}__loading-card-text">${Drupal.t(
        'Generating response',
      )}<span class="${P}__loading-ellipsis" aria-hidden="true"></span>` +
      `</p>` +
      `</article>`
    );
  }

  /**
   * @param {string} scopeId
   *   Block or node id prefix for panel/tab ids.
   * @param {number} index
   *   The zero-based tab index.
   *
   * @return {{panelId: string, tabKey: string, answerId: string}}
   *   Object containing generated IDs for the tab panel.
   */
  function providerTabIds(scopeId, index) {
    return {
      panelId: `${P}-panel-${scopeId}-${index}`,
      tabKey: `${P}-tab-${scopeId}-${index}`,
      answerId: `${scopeId}-answer-${index}`,
    };
  }

  /**
   * Builds the HTML for the accordion loading state.
   *
   * @return {string}
   *   HTML string for the loading indicator.
   */
  function buildAccordionLoadingHtml() {
    return (
      `<div class="${P}__loading">` +
      `<div class="${P}__loading-spinner"></div>` +
      `<div class="${P}__loading-text">${Drupal.t('Querying\u2026')}</div>` +
      `</div>`
    );
  }

  /**
   * Builds the HTML for a provider tab button.
   *
   * @param {string} key
   *   The provider model key.
   * @param {number} index
   *   The zero-based tab index.
   * @param {Element} wrapper
   *   The preview wrapper element.
   * @param {{scopeId: string, activeIndex: number, trackProviderKey: boolean}} opts
   *   Options for building the tab button.
   *
   * @return {string}
   *   HTML string for the tab button list item.
   */
  function buildProviderTabButtonHtml(key, index, wrapper, opts) {
    const ids = providerTabIds(opts.scopeId, index);
    const label = key ? getLabelForKey(key, wrapper) : Drupal.t('AI');
    const isActive = index === opts.activeIndex;
    const providerAttr = opts.trackProviderKey
      ? ` data-provider-model-key="${Drupal.checkPlain(key)}"`
      : '';

    return (
      `<li class="tabs__tab${isActive ? ' is-active' : ''}">` +
      `<button type="button" role="tab"` +
      ` class="tabs__link${isActive ? ' is-active' : ''}"` +
      ` aria-controls="${ids.panelId}"` +
      ` aria-selected="${isActive ? 'true' : 'false'}"` +
      ` data-ai-tab-target="${ids.tabKey}"${providerAttr} tabindex="${
        isActive ? '0' : '-1'
      }">${Drupal.checkPlain(label)}</button></li>`
    );
  }

  /**
   * Builds the HTML for a provider tab panel.
   *
   * @param {string} key
   *   The provider model key.
   * @param {number} index
   *   The zero-based tab index.
   * @param {{scopeId: string, activeIndex: number, trackProviderKey: boolean, loadingHtml: string}} opts
   *   Options for building the tab panel.
   *
   * @return {string}
   *   HTML string for the tab panel div.
   */
  function buildProviderTabPanelHtml(key, index, opts) {
    const ids = providerTabIds(opts.scopeId, index);
    const isActive = index === opts.activeIndex;
    const providerAttr = opts.trackProviderKey
      ? ` data-provider-model-key="${Drupal.checkPlain(key)}"`
      : '';

    return (
      `<div class="ai-content-audit-tab-panel"` +
      ` role="tabpanel"` +
      ` id="${ids.panelId}"` +
      ` data-ai-tab="${ids.tabKey}"${
        providerAttr
      }${isActive ? '' : ' hidden'}>${opts.loadingHtml}</div>`
    );
  }

  /**
   * Builds the HTML for the provider tabs navigation wrapper.
   *
   * @param {string[]} tabButtons
   *   Array of tab button HTML strings.
   * @param {{pageSkinNav: boolean}} opts
   *   Options for building the nav element.
   *
   * @return {string}
   *   HTML string for the tab navigation.
   */
  function buildProviderTabNavHtml(tabButtons, opts) {
    let navClass = 'tabs-wrapper is-horizontal';
    if (opts.pageSkinNav) {
      navClass += ' airo-preview__provider-tabs-nav';
    }

    return (
      `<nav class="${navClass}" aria-label="${Drupal.t(
        'AI preview providers',
      )}">` +
      `<ul class="tabs tabs--primary is-horizontal clearfix" role="tablist">${tabButtons.join(
        '',
      )}</ul>` +
      `</nav>`
    );
  }

  /**
   * Builds both tab buttons and tab panels for a set of provider keys.
   *
   * @param {string[]} keys
   *   Array of provider model keys.
   * @param {Element} wrapper
   *   The preview wrapper element.
   * @param {{scopeId: string, activeIndex: number, trackProviderKey: boolean, loadingHtmlForIndex: function(number, string): string}} opts
   *   Options for building the markup.
   *
   * @return {{tabButtons: string[], tabPanels: string[]}}
   *   Object containing arrays of tab button and panel HTML strings.
   */
  function buildProviderTabMarkup(keys, wrapper, opts) {
    const tabOpts = {
      scopeId: opts.scopeId,
      activeIndex: opts.activeIndex,
      trackProviderKey: opts.trackProviderKey,
    };

    const tabButtons = keys.map(function (key, i) {
      return buildProviderTabButtonHtml(key, i, wrapper, tabOpts);
    });

    const tabPanels = keys.map(function (key, i) {
      return buildProviderTabPanelHtml(key, i, {
        scopeId: opts.scopeId,
        activeIndex: opts.activeIndex,
        trackProviderKey: opts.trackProviderKey,
        loadingHtml: opts.loadingHtmlForIndex(i, key),
      });
    });

    return { tabButtons, tabPanels };
  }

  /**
   * Returns the providers configuration URL from the wrapper element.
   *
   * @param {Element|null} wrapper
   *   The preview wrapper element.
   *
   * @return {string}
   *   The providers configuration URL or empty string.
   */
  function getProvidersConfigUrl(wrapper) {
    return wrapper ? wrapper.getAttribute('data-providers-url') || '' : '';
  }

  /**
   * Determines whether to show an API key hint for the given result.
   *
   * @param {object} result
   *   The provider result object containing error information.
   *
   * @return {boolean}
   *   TRUE if an API key hint should be shown.
   */
  function shouldShowApiKeyHint(result) {
    if (!result || !result.error) {
      return false;
    }
    if (result.error_hint === 'api_key') {
      return true;
    }
    const lower = String(result.error).toLowerCase();
    return /api.?key|authentication|unauthorized|invalid.?key|credential|bearer|not configured|no ai chat provider/.test(
      lower,
    );
  }

  /**
   * Builds the HTML for the API key configuration hint.
   *
   * @param {string} providersUrl
   *   The URL to the AI providers configuration page.
   *
   * @return {string}
   *   HTML string for the API key hint block.
   */
  function buildApiKeyHintHtml(providersUrl) {
    const url = providersUrl || '/admin/config/ai/providers';
    const link = `<a href="${Drupal.checkPlain(url)}" target="_blank" rel="noopener noreferrer">${Drupal.t(
      'AI Providers configuration',
    )}</a>`;

    return (
      `<div class="${P}__error-hint">` +
      `<p class="${P}__prompt-hint ${P}__prompt-hint--intro">` +
      `<strong>${Drupal.t('How to add your API key')}</strong>` +
      `</p>` +
      `<ol class="${P}__error-hint-steps">` +
      `<li>${Drupal.t(
        'In the Drupal admin menu, go to Configuration → AI.',
      )}</li>` +
      `<li>${Drupal.t('Open')} ${link}.</li>` +
      `<li>${Drupal.t(
        'Select the provider for the model you chose (for example OpenAI or Anthropic).',
      )}</li>` +
      `<li>${Drupal.t('Paste your API key into the API key field.')}</li>` +
      `<li>${Drupal.t(
        'Save configuration, then ask your question again.',
      )}</li>` +
      `</ol>` +
      `</div>`
    );
  }

  /**
   * Builds the HTML for a preview error message.
   *
   * @param {object} result
   *   The provider result object containing error information.
   * @param {Element|null} wrapper
   *   The preview wrapper element.
   *
   * @return {string}
   *   HTML string for the error display.
   */
  function buildPreviewErrorHtml(result, wrapper) {
    let html = `<div class="${P}__error">${Drupal.checkPlain(
      result.error,
    )}</div>`;
    if (shouldShowApiKeyHint(result)) {
      html += buildApiKeyHintHtml(getProvidersConfigUrl(wrapper));
    }
    return html;
  }

  /**
   * Renders a result into an accordion panel element.
   *
   * @param {Element} panel
   *   The tab panel DOM element to render into.
   * @param {object} result
   *   The provider result object.
   * @param {Element|null} wrapper
   *   The preview wrapper element.
   */
  function renderAccordionPanelResult(panel, result, wrapper) {
    const durationBadge = result.duration_ms
      ? `<span class="${P}__response-duration">${Drupal.checkPlain(
          String(result.duration_ms),
        )}\u202fms</span>`
      : '';

    const body = result.error
      ? buildPreviewErrorHtml(result, wrapper)
      : `<div class="${P}__response-body">${result.html || ''}</div>`;

    panel.innerHTML =
      `<div class="${P}__response-header">` +
      `<span class="${P}__response-provider">${Drupal.checkPlain(
        result.label || result.provider_id || 'AI',
      )}</span>` +
      `<span class="${P}__response-meta">${durationBadge}</span>` +
      `</div>${body}`;
  }

  /**
   * Renders a result into a page skin answer card element.
   *
   * @param {Element} card
   *   The answer card DOM element to render into.
   * @param {{label:string, provider_id:string, html:string|null, duration_ms:number, error:string|null}} result
   *   The provider result object.
   * @param {Element|null} wrapper
   *   The preview wrapper element.
   */
  function renderPageSkinAnswerCard(card, result, wrapper) {
    const body = result.error
      ? buildPreviewErrorHtml(result, wrapper)
      : `<div class="${P}__answer-card-body">${result.html || ''}</div>`;

    const footer = result.error
      ? ''
      : `<footer class="${P}__answer-card-footer">` +
        `<span>${Drupal.t('Simulated response')}</span>` +
        `<span>${Drupal.t('Generated:')} ${Drupal.checkPlain(formatGeneratedTimestamp())}</span>` +
        `</footer>`;

    card.classList.remove(`${P}__loading-card`, `${P}__answer-card--loading`);
    card.classList.add(`${P}__answer-card`);
    card.innerHTML = body + footer;
  }

  /**
   * Fetches a response from a single provider.
   *
   * @param {string} queryUrl
   *   The endpoint URL to POST the query to.
   * @param {string} question
   *   The user's question text.
   * @param {string} key
   *   The provider model key.
   * @param {Element} wrapper
   *   The preview wrapper element.
   *
   * @return {Promise<object>}
   *   Promise resolving to a normalized result object.
   */
  function fetchOneProvider(queryUrl, question, key, wrapper) {
    const postBody = {
      question,
      provider_models: key ? [key] : [],
    };
    const rid = wrapper && wrapper.getAttribute('data-revision-id');
    if (rid) {
      const parsed = parseInt(rid, 10);
      if (!Number.isNaN(parsed) && parsed > 0) {
        postBody.revision_id = parsed;
      }
    }

    return Drupal.airoContentAudit
      .postJson(queryUrl, JSON.stringify(postBody))
      .then(function (response) {
        return response.json();
      })
      .then(function (data) {
        if (data.error) {
          return {
            key,
            label: key,
            provider_id: '',
            model_id: '',
            html: null,
            duration_ms: 0,
            error: data.error,
            error_hint: data.error_hint || null,
          };
        }
        const one = (data.results && data.results[0]) || {
          key,
          label: key,
          html: null,
          duration_ms: 0,
          error: 'No result returned.',
        };
        return {
          key: one.key || key,
          label: one.label || key,
          provider_id: one.provider_id || '',
          model_id: one.model_id || '',
          html: one.html || null,
          duration_ms: one.duration_ms || 0,
          error: one.error || null,
          error_hint: one.error_hint || null,
        };
      })
      .catch(function () {
        return {
          key,
          label: key,
          provider_id: '',
          model_id: '',
          html: null,
          duration_ms: 0,
          error: Drupal.t('Request failed. Try again.'),
          error_hint: null,
        };
      });
  }

  /**
   * Single-model page skin: stacked query + answer cards.
   *
   * @param {string} question
   *   The user's question text.
   * @param {Element} wrapper
   *   The preview wrapper element.
   * @param {boolean} append
   *   Whether to append to existing results or replace them.
   */
  function submitQueryPageSkinCards(question, wrapper, append) {
    const nodeId = wrapper.getAttribute('data-node-id');
    const queryUrl = wrapper.getAttribute('data-query-url');
    const resultsEl = document.getElementById(`${P}-results-${nodeId}`);
    if (!queryUrl || !nodeId || !resultsEl) return;

    const keys = getSelectedKeys(wrapper);
    const key = keys.length > 0 ? keys[0] : '';
    const threadId = `thread-${nodeId}-${Date.now()}`;

    if (!append) {
      resultsEl.innerHTML = '';
    }

    const tabsHost = document.getElementById(
      `airo-preview-provider-tabs-${nodeId}`,
    );
    if (tabsHost) {
      tabsHost.innerHTML = '';
      tabsHost.hidden = true;
      tabsHost.removeAttribute('data-compare-id');
    }

    const queryCard =
      `<article class="${P}__query-card" data-thread-id="${threadId}">` +
      `<p class="${P}__query-card-text">${Drupal.checkPlain(question)}</p>` +
      `</article>`;

    const answerId = `${threadId}-answer`;
    const loadingCard = buildPageSkinLoadingCard(answerId, threadId);

    resultsEl.insertAdjacentHTML('beforeend', queryCard + loadingCard);
    resultsEl.scrollTop = resultsEl.scrollHeight;

    fetchOneProvider(queryUrl, question, key, wrapper).then(function (result) {
      const card = document.getElementById(answerId);
      if (!card) return;
      renderPageSkinAnswerCard(card, result, wrapper);
    });
  }

  /**
   * Multi-model: provider tabs + parallel fetch (accordion pattern).
   *
   * @param {string} question
   *   The user's question text.
   * @param {Element} wrapper
   *   The preview wrapper element.
   * @param {boolean} append
   *   Whether to append to existing results or replace them.
   */
  function submitQueryPageSkinTabs(question, wrapper, append) {
    const nodeId = wrapper.getAttribute('data-node-id');
    const queryUrl = wrapper.getAttribute('data-query-url');
    const resultsEl = document.getElementById(`${P}-results-${nodeId}`);
    if (!queryUrl || !nodeId || !resultsEl) return;

    const selectedKeys = getSelectedKeys(wrapper);
    const keys = selectedKeys.length > 0 ? selectedKeys : [''];
    const blockId = `compare-${nodeId}-${Date.now()}`;
    const previousActiveKey = append
      ? getActiveProviderModelKey(wrapper)
      : null;
    let activeIndex = 0;

    if (previousActiveKey) {
      const previousIndex = keys.indexOf(previousActiveKey);
      if (previousIndex >= 0) {
        activeIndex = previousIndex;
      }
    }

    const queryCard =
      `<article class="${P}__query-card" data-thread-id="${blockId}">` +
      `<p class="${P}__query-card-text">${Drupal.checkPlain(question)}</p>` +
      `</article>`;

    const tabMarkup = buildProviderTabMarkup(keys, wrapper, {
      scopeId: blockId,
      activeIndex,
      trackProviderKey: true,
      loadingHtmlForIndex(i) {
        return buildPageSkinLoadingCard(
          providerTabIds(blockId, i).answerId,
          blockId,
        );
      },
    });

    const tabNav = buildProviderTabNavHtml(tabMarkup.tabButtons, {
      pageSkinNav: true,
    });

    const compareBlock = `<div class="ai-content-audit-compare ai-content-audit-compare--results" id="${blockId}">${tabMarkup.tabPanels.join(
      '',
    )}</div>`;

    const tabsHost = document.getElementById(
      `airo-preview-provider-tabs-${nodeId}`,
    );

    if (!append) {
      resultsEl.innerHTML = queryCard + compareBlock;
    } else {
      resultsEl.insertAdjacentHTML('beforeend', queryCard + compareBlock);
    }

    if (tabsHost) {
      tabsHost.innerHTML = tabNav;
      tabsHost.hidden = false;
      tabsHost.setAttribute('data-compare-id', blockId);
      Drupal.attachBehaviors(tabsHost);
    }

    updateModelSelectorUi(wrapper);

    resultsEl.scrollTop = resultsEl.scrollHeight;

    keys.forEach(function (key, i) {
      const answerId = providerTabIds(blockId, i).answerId;

      fetchOneProvider(queryUrl, question, key, wrapper).then(
        function (result) {
          const card = document.getElementById(answerId);
          if (!card) return;
          renderPageSkinAnswerCard(card, result, wrapper);
        },
      );
    });
  }

  /**
   * Accordion (non-page-skin) parallel submit.
   *
   * @param {string} question
   *   The user's question text.
   * @param {Element} wrapper
   *   The preview wrapper element.
   */
  function submitQueryAccordionParallel(question, wrapper) {
    const nodeId = wrapper.getAttribute('data-node-id');
    const queryUrl = wrapper.getAttribute('data-query-url');
    const resultsEl = document.getElementById(`${P}-results-${nodeId}`);
    if (!queryUrl || !nodeId || !resultsEl) return;

    const selectedKeys = getSelectedKeys(wrapper);
    const keys = selectedKeys.length > 0 ? selectedKeys : [''];

    const tabMarkup = buildProviderTabMarkup(keys, wrapper, {
      scopeId: nodeId,
      activeIndex: 0,
      trackProviderKey: false,
      loadingHtmlForIndex() {
        return buildAccordionLoadingHtml();
      },
    });

    const tabNav = buildProviderTabNavHtml(tabMarkup.tabButtons, {
      pageSkinNav: false,
    });

    resultsEl.innerHTML = `<div class="ai-content-audit-compare ai-content-audit-compare--results">${
      tabNav
    }${tabMarkup.tabPanels.join('')}</div>`;

    Drupal.attachBehaviors(resultsEl);

    keys.forEach(function (key, i) {
      const panelId = providerTabIds(nodeId, i).panelId;

      fetchOneProvider(queryUrl, question, key, wrapper).then(
        function (result) {
          const panel = document.getElementById(panelId);
          if (!panel) return;
          renderAccordionPanelResult(panel, result, wrapper);
        },
      );
    });
  }

  /**
   * @param {string} question
   *   The user's question text.
   * @param {Element} wrapper
   *   The preview wrapper element.
   * @param {{append?: boolean, fromLanding?: boolean}} options
   *   Options controlling submission behavior.
   */
  function submitQueryParallel(question, wrapper, options) {
    if (!question || !wrapper) return;
    options = options || {};

    if (usesPageSkin(wrapper)) {
      const panel = getPageSkinPanel(wrapper);
      const wasLanding =
        panel && panel.getAttribute('data-airo-ui-state') === 'landing';
      const append = options.append || false;

      if (!append && (wasLanding || options.fromLanding)) {
        setUiState(wrapper, 'conversation');
        updateModelSelectorUi(wrapper);
        const convInput = getConversationInput(wrapper);
        if (convInput && options.fromLanding) {
          convInput.value = '';
        }
        focusConversationRegion(wrapper);
      }

      const keys = getSelectedKeys(wrapper);
      const effectiveKeys = keys.length > 0 ? keys : [''];

      if (effectiveKeys.length > 1) {
        submitQueryPageSkinTabs(question, wrapper, append);
      } else {
        submitQueryPageSkinCards(question, wrapper, append);
      }

      if (!append) {
        const landing = getLandingInput(wrapper);
        if (landing && options.fromLanding) {
          landing.value = '';
        }
        const conv = getConversationInput(wrapper);
        if (conv) {
          conv.value = '';
        }
      }
      syncButton(wrapper);
      updateModelSelectorUi(wrapper);
      return;
    }

    submitQueryAccordionParallel(question, wrapper);
  }

  /**
   * @param {Element} wrapper
   *   The preview wrapper element.
   * @param {string} composer
   *   The composer context, either 'landing' or 'conversation'.
   */
  function handlePageSkinSubmit(wrapper, composer) {
    const input =
      composer === 'landing'
        ? getLandingInput(wrapper)
        : getConversationInput(wrapper);
    if (!input) return;
    const q = input.value.trim();
    if (!q) return;

    const fromLanding = composer === 'landing';
    const panel = getPageSkinPanel(wrapper);
    const append =
      !fromLanding &&
      panel &&
      panel.getAttribute('data-airo-ui-state') === 'conversation';

    submitQueryParallel(q, wrapper, {
      append,
      fromLanding,
    });

    if (!append) {
      input.value = '';
    } else {
      input.value = '';
    }
    syncButton(wrapper);
  }

  /** @type {boolean} */
  let modelDropdownOutsideClickBound = false;

  /**
   * Closes open model selector dropdowns when the user clicks elsewhere.
   *
   * @param {MouseEvent} event
   *   The click event.
   */
  function closeOpenModelDropdownsOnOutsideClick(event) {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }

    document
      .querySelectorAll(`.${P}__model-fieldset[open]`)
      .forEach(function (details) {
        if (!details.contains(target)) {
          details.open = false;
        }
      });
  }

  /**
   * Registers a single document listener for outside-click closing.
   */
  function ensureModelDropdownOutsideClickListener() {
    if (modelDropdownOutsideClickBound) {
      return;
    }
    modelDropdownOutsideClickBound = true;
    document.addEventListener('click', closeOpenModelDropdownsOnOutsideClick);
  }

  // ─── Drupal behavior ──────────────────────────────────────────────────────

  Drupal.behaviors.airoPreviewTab = {
    attach(context) {
      once('airo-preview-submit', `.${P}__submit`, context).forEach(
        function (btn) {
          btn.addEventListener('click', function (e) {
            e.preventDefault();
            const wrapper = getWrapper(btn);
            if (!wrapper) return;
            const input = wrapper.querySelector(`.${P}__input`);
            const q = input ? input.value.trim() : '';
            if (q) submitQueryParallel(q, wrapper);
          });
        },
      );

      once('airo-preview-submit-arrow', `.${P}__submit-arrow`, context).forEach(
        function (btn) {
          btn.addEventListener('click', function (e) {
            e.preventDefault();
            const wrapper = getWrapper(btn);
            if (!wrapper) return;
            const composer = btn.getAttribute('data-composer') || 'landing';
            handlePageSkinSubmit(wrapper, composer);
          });
        },
      );

      once('airo-preview-input', `.${P}__input`, context).forEach(
        function (input) {
          input.addEventListener('input', function () {
            syncButton(getWrapper(input));
          });
          input.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' || e.shiftKey) {
              return;
            }
            if (input.tagName === 'TEXTAREA') {
              e.preventDefault();
            }
            const wrapper = getWrapper(input);
            if (!wrapper) return;
            const q = input.value.trim();
            if (!q) return;

            if (usesPageSkin(wrapper)) {
              const composer = input.getAttribute('data-composer') || 'landing';
              handlePageSkinSubmit(wrapper, composer);
              return;
            }

            e.preventDefault();
            submitQueryParallel(q, wrapper);
          });
        },
      );

      once('airo-preview-checkbox', `.${P}__model-checkbox`, context).forEach(
        function (cb) {
          cb.addEventListener('change', function () {
            const wrapper = getWrapper(cb);
            if (usesPageSkin(wrapper)) {
              const fromScreen = cb.closest(
                '.airo-panel__screen--landing, .airo-panel__screen--conversation',
              );
              const toScreen =
                fromScreen &&
                fromScreen.classList.contains('airo-panel__screen--landing')
                  ? wrapper.querySelector('.airo-panel__screen--conversation')
                  : wrapper.querySelector('.airo-panel__screen--landing');
              syncModelCheckboxesBetweenScreens(wrapper, fromScreen, toScreen);
            }
            syncButton(wrapper);
            updateModelSelectorUi(wrapper);
          });
        },
      );

      once('airo-preview-init', `.${P}`, context).forEach(function (wrapper) {
        ensureModelDropdownOutsideClickListener();
        syncButton(wrapper);
        if (usesPageSkin(wrapper)) {
          setUiState(wrapper, 'landing');
          updateModelSelectorUi(wrapper);
        }
      });

      once(
        'airo-analysis-panel-host',
        'form.airo-analysis-panel-host',
        context,
      ).forEach(function (form) {
        form.addEventListener('submit', function (e) {
          e.preventDefault();
        });
      });
    },
  };
})(Drupal, once);
