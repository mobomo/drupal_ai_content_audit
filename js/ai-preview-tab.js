/**
 * @file
 * AIRO AI Preview tab — parallel provider comparison + page skin two-screen flow.
 */
(function (Drupal, once, Cookies) {
  'use strict';

  /** @type {string} BEM block prefix */
  var P = 'airo-preview';

  /** @type {string} Cookie keys for saved preview prompts (per browser). */
  var PROMPT_COOKIE_MODE = 'ai_ca_pp_mode';
  var PROMPT_COOKIE_SYSTEM = 'ai_ca_pp_sys';
  var PROMPT_COOKIE_USER = 'ai_ca_pp_usr';
  var PROMPT_COOKIE_EXPIRES_DAYS = 365;
  var PROMPT_COOKIE_MAX_CHARS = 3500;

  // ─── DOM helpers ──────────────────────────────────────────────────────────

  /** @param {Element} el @returns {Element|null} */
  function getWrapper(el) {
    return el.closest('.' + P) || document.querySelector('.' + P);
  }

  /** @param {Element} wrapper @returns {Element|null} */
  function getPageSkinPanel(wrapper) {
    return wrapper ? wrapper.closest('.airo-panel--page-skin') : null;
  }

  /** @param {Element} wrapper @returns {boolean} */
  function usesPageSkin(wrapper) {
    return wrapper && wrapper.getAttribute('data-page-skin') === '1';
  }

  /**
   * @param {Element} wrapper
   * @param {'landing'|'conversation'} state
   */
  /**
   * Keeps landing/conversation checkbox lists in sync (two copies in the DOM).
   */
  function syncModelCheckboxesBetweenScreens(wrapper, fromScreen, toScreen) {
    if (!fromScreen || !toScreen) return;
    var toBoxes = toScreen.querySelectorAll('.' + P + '__model-checkbox');
    Array.prototype.forEach.call(toBoxes, function (toBox) {
      var fromBox = fromScreen.querySelector(
        '.' + P + '__model-checkbox[value="' + CSS.escape(toBox.value) + '"]'
      );
      if (fromBox) {
        toBox.checked = fromBox.checked;
      }
    });
  }

  /**
   * @param {Element} screenRoot
   */
  function updatePromptCustomVisibility(screenRoot) {
    if (!screenRoot) return;
    var customPanel = screenRoot.querySelector('.' + P + '__prompt-custom');
    var customMode = screenRoot.querySelector('.' + P + '__prompt-mode[value="custom"]:checked');
    if (customPanel) {
      customPanel.hidden = !customMode;
    }
    var badge = screenRoot.querySelector('[data-prompt-mode-badge]');
    if (badge) {
      badge.textContent = customMode ? Drupal.t('Custom') : Drupal.t('Default');
    }
  }

  /**
   * @param {Element} wrapper
   */
  function updateAllPromptBadges(wrapper) {
    if (!wrapper) return;
    wrapper.querySelectorAll('.' + P + '__prompt-fieldset').forEach(function (fieldset) {
      updatePromptCustomVisibility(fieldset);
    });
  }

  /**
   * @param {Element} fromScreen
   * @param {Element} toScreen
   */
  function syncPromptEditorBetweenScreens(fromScreen, toScreen) {
    if (!fromScreen || !toScreen) return;

    var fromMode = fromScreen.querySelector('.' + P + '__prompt-mode:checked');
    if (fromMode) {
      toScreen.querySelectorAll('.' + P + '__prompt-mode').forEach(function (radio) {
        radio.checked = radio.value === fromMode.value;
      });
    }

    var fromSystem = fromScreen.querySelector('.' + P + '__prompt-system');
    var toSystem = toScreen.querySelector('.' + P + '__prompt-system');
    if (fromSystem && toSystem) {
      toSystem.value = fromSystem.value;
    }

    var fromUser = fromScreen.querySelector('.' + P + '__prompt-user-template');
    var toUser = toScreen.querySelector('.' + P + '__prompt-user-template');
    if (fromUser && toUser) {
      toUser.value = fromUser.value;
    }

    updatePromptCustomVisibility(toScreen);
  }

  /**
   * @param {Element} wrapper
   * @returns {Element}
   */
  function getActivePromptScreenRoot(wrapper) {
    if (!usesPageSkin(wrapper)) {
      return wrapper;
    }
    var panel = getPageSkinPanel(wrapper);
    if (panel && panel.getAttribute('data-airo-ui-state') === 'conversation') {
      var conv = wrapper.querySelector('.airo-panel__screen--conversation');
      if (conv) {
        return conv;
      }
    }
    var landing = wrapper.querySelector('.airo-panel__screen--landing');
    return landing || wrapper;
  }

  /**
   * @param {Element} wrapper
   * @returns {object}
   */
  function getPromptPayload(wrapper) {
    var root = getActivePromptScreenRoot(wrapper);
    var customMode = root.querySelector('.' + P + '__prompt-mode[value="custom"]:checked');
    if (!customMode) {
      return { prompt_mode: 'default' };
    }

    var system = root.querySelector('.' + P + '__prompt-system');
    var userTemplate = root.querySelector('.' + P + '__prompt-user-template');

    return {
      prompt_mode: 'custom',
      system_prompt: system ? system.value : '',
      user_prompt_template: userTemplate ? userTemplate.value : '',
    };
  }

  /**
   * @param {Element} wrapper
   * @returns {{system: string, userTemplate: string}}
   */
  function getPromptDefaults(wrapper) {
    var el = wrapper ? wrapper.querySelector('.' + P + '__prompt-defaults') : null;
    if (!el) {
      return { system: '', userTemplate: '' };
    }
    try {
      var parsed = JSON.parse(el.textContent || '{}');
      return {
        system: parsed.system || '',
        userTemplate: parsed.userTemplate || '',
      };
    }
    catch (e) {
      return { system: '', userTemplate: '' };
    }
  }

  /**
   * @param {Function} fn
   * @param {number} wait
   * @returns {function(...[*])}
   */
  function debounce(fn, wait) {
    var timeoutId;
    return function () {
      var args = arguments;
      var self = this;
      clearTimeout(timeoutId);
      timeoutId = setTimeout(function () {
        fn.apply(self, args);
      }, wait);
    };
  }

  /**
   * @param {string} name
   * @param {string} value
   */
  function writePromptCookie(name, value) {
    if (!Cookies || value.length > PROMPT_COOKIE_MAX_CHARS) {
      return;
    }
    Cookies.set(name, value, {
      expires: PROMPT_COOKIE_EXPIRES_DAYS,
      path: '/',
      sameSite: 'Lax',
    });
  }

  /**
   * @param {string} name
   * @returns {string}
   */
  function readPromptCookie(name) {
    return Cookies ? (Cookies.get(name) || '') : '';
  }

  /**
   * @param {Element} wrapper
   * @returns {{mode: string, system: string, userTemplate: string}}
   */
  function collectPromptState(wrapper) {
    if (!wrapper) {
      return { mode: 'default', system: '', userTemplate: '' };
    }

    var customMode = wrapper.querySelector('.' + P + '__prompt-mode[value="custom"]:checked');
    var systemEl = wrapper.querySelector('.' + P + '__prompt-system');
    var userEl = wrapper.querySelector('.' + P + '__prompt-user-template');

    return {
      mode: customMode ? 'custom' : 'default',
      system: systemEl ? systemEl.value : '',
      userTemplate: userEl ? userEl.value : '',
    };
  }

  /**
   * Persists prompt mode and textarea content in browser cookies.
   *
   * @param {Element} wrapper
   */
  function savePromptToCookies(wrapper) {
    if (!wrapper || !Cookies) {
      return;
    }

    var state = collectPromptState(wrapper);
    writePromptCookie(PROMPT_COOKIE_MODE, state.mode);
    writePromptCookie(PROMPT_COOKIE_SYSTEM, state.system);
    writePromptCookie(PROMPT_COOKIE_USER, state.userTemplate);
  }

  var savePromptToCookiesDebounced = debounce(savePromptToCookies, 400);

  /**
   * @param {Element} wrapper
   * @param {{mode?: string, system?: string, userTemplate?: string}} state
   */
  function applyPromptState(wrapper, state) {
    if (!wrapper || !state) {
      return;
    }

    var mode = state.mode === 'custom' ? 'custom' : 'default';
    wrapper.querySelectorAll('.' + P + '__prompt-mode').forEach(function (radio) {
      radio.checked = radio.value === mode;
    });

    if (typeof state.system === 'string' && state.system !== '') {
      wrapper.querySelectorAll('.' + P + '__prompt-system').forEach(function (el) {
        el.value = state.system;
      });
    }

    if (typeof state.userTemplate === 'string' && state.userTemplate !== '') {
      wrapper.querySelectorAll('.' + P + '__prompt-user-template').forEach(function (el) {
        el.value = state.userTemplate;
      });
    }

    updateAllPromptBadges(wrapper);
  }

  /**
   * Restores the last saved prompt from cookies, if present.
   *
   * @param {Element} wrapper
   */
  function loadPromptFromCookies(wrapper) {
    if (!wrapper || !Cookies) {
      return;
    }

    var mode = readPromptCookie(PROMPT_COOKIE_MODE);
    if (mode !== 'default' && mode !== 'custom') {
      return;
    }

    applyPromptState(wrapper, {
      mode: mode,
      system: readPromptCookie(PROMPT_COOKIE_SYSTEM),
      userTemplate: readPromptCookie(PROMPT_COOKIE_USER),
    });
  }

  /**
   * @param {Element} wrapper
   * @param {HTMLTextAreaElement} source
   */
  function syncPromptTextareasInWrapper(wrapper, source) {
    if (!wrapper || !source) {
      return;
    }

    var selector = source.classList.contains(P + '__prompt-system')
      ? '.' + P + '__prompt-system'
      : '.' + P + '__prompt-user-template';

    wrapper.querySelectorAll(selector).forEach(function (el) {
      if (el !== source) {
        el.value = source.value;
      }
    });
  }

  /**
   * Refills custom prompt fields with the built-in template text.
   *
   * Does not change the selected mode — use "Use default prompt" for that.
   *
   * @param {Element} wrapper
   */
  function restorePromptTemplate(wrapper) {
    if (!wrapper) return;
    var defaults = getPromptDefaults(wrapper);

    wrapper.querySelectorAll('.' + P + '__prompt-system').forEach(function (el) {
      el.value = defaults.system;
    });
    wrapper.querySelectorAll('.' + P + '__prompt-user-template').forEach(function (el) {
      el.value = defaults.userTemplate;
    });
    savePromptToCookies(wrapper);
  }

  function setUiState(wrapper, state) {
    var panel = getPageSkinPanel(wrapper);
    if (!panel) return;
    var landingScreen = wrapper.querySelector('.airo-panel__screen--landing');
    var convScreen = wrapper.querySelector('.airo-panel__screen--conversation');
    if (landingScreen && convScreen) {
      if (state === 'conversation') {
        syncModelCheckboxesBetweenScreens(wrapper, landingScreen, convScreen);
        syncPromptEditorBetweenScreens(landingScreen, convScreen);
      }
      else {
        syncModelCheckboxesBetweenScreens(wrapper, convScreen, landingScreen);
        syncPromptEditorBetweenScreens(convScreen, landingScreen);
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
   * @returns {HTMLTextAreaElement|HTMLInputElement|null}
   */
  function getLandingInput(wrapper) {
    return wrapper.querySelector('.' + P + '__input--landing');
  }

  /**
   * @param {Element} wrapper
   * @returns {HTMLTextAreaElement|HTMLInputElement|null}
   */
  function getConversationInput(wrapper) {
    return wrapper.querySelector('.' + P + '__input--conversation');
  }

  /**
   * @param {Element} wrapper
   * @param {string} composer landing|conversation
   * @returns {Element|null}
   */
  function getSubmitArrow(wrapper, composer) {
    return wrapper.querySelector('.' + P + '__submit-arrow[data-composer="' + composer + '"]');
  }

  /**
   * Active screen root for model checkboxes (avoids duplicate landing + conversation sets).
   * @param {Element} wrapper
   * @returns {Element}
   */
  function getModelSelectorRoot(wrapper) {
    if (!usesPageSkin(wrapper)) {
      return wrapper;
    }
    var panel = getPageSkinPanel(wrapper);
    if (panel && panel.getAttribute('data-airo-ui-state') === 'conversation') {
      var conv = wrapper.querySelector('.airo-panel__screen--conversation');
      if (conv) {
        return conv;
      }
    }
    var landing = wrapper.querySelector('.airo-panel__screen--landing');
    return landing || wrapper;
  }

  /**
   * Returns all checked provider__model keys inside the given wrapper.
   * @param {Element} wrapper
   * @returns {string[]}
   */
  function getSelectedKeys(wrapper) {
    var root = getModelSelectorRoot(wrapper);
    var boxes = root.querySelectorAll('.' + P + '__model-checkbox:checked');
    var keys = Array.prototype.map.call(boxes, function (b) { return b.value; });
    return keys.filter(function (key, i) { return keys.indexOf(key) === i; });
  }

  /**
   * @param {string} key
   * @param {Element} wrapper
   * @returns {string}
   */
  function getLabelForKey(key, wrapper) {
    var root = getModelSelectorRoot(wrapper);
    var boxes = root.querySelectorAll('.' + P + '__model-checkbox');
    for (var j = 0; j < boxes.length; j++) {
      if (boxes[j].value === key) {
        var lbl = boxes[j].parentElement
          ? boxes[j].parentElement.querySelector('.' + P + '__model-option-label')
          : null;
        return lbl ? lbl.textContent.trim() : key;
      }
    }
    var single = wrapper.querySelector('.' + P + '__model-single-label');
    if (single) {
      return single.textContent.trim();
    }
    return key;
  }

  /**
   * @param {Element} wrapper
   * @returns {string|null}
   */
  function getActiveProviderModelKey(wrapper) {
    var nodeId = wrapper.getAttribute('data-node-id');
    var tabsHost = document.getElementById('airo-preview-provider-tabs-' + nodeId);
    if (!tabsHost) {
      return null;
    }

    var activeTab = tabsHost.querySelector('[role="tab"][aria-selected="true"]')
      || tabsHost.querySelector('[role="tab"].is-active');
    if (!activeTab) {
      return null;
    }

    return activeTab.getAttribute('data-provider-model-key');
  }

  /**
   * When only one model remains selected, show its compare panel (not always index 0).
   *
   * @param {Element} wrapper
   * @param {string[]} keys
   */
  function syncActiveComparePanel(wrapper, keys) {
    if (!usesPageSkin(wrapper) || keys.length !== 1) {
      return;
    }

    var nodeId = wrapper.getAttribute('data-node-id');
    var tabsHost = document.getElementById('airo-preview-provider-tabs-' + nodeId);
    if (!tabsHost) {
      return;
    }

    var compareId = tabsHost.getAttribute('data-compare-id');
    if (!compareId) {
      return;
    }

    var compare = document.getElementById(compareId);
    if (!compare) {
      return;
    }

    var selectedKey = keys[0];
    var panels = compare.querySelectorAll('[role="tabpanel"]');
    var matched = false;

    panels.forEach(function (panel) {
      var panelKey = panel.getAttribute('data-provider-model-key');
      if (panelKey === selectedKey) {
        panel.removeAttribute('hidden');
        matched = true;
      }
      else {
        panel.setAttribute('hidden', '');
      }
    });

    if (matched) {
      return;
    }

    // Fallback for compare blocks created before data-provider-model-key existed.
    var tabs = tabsHost.querySelectorAll('[role="tab"]');
    var targetIndex = -1;
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
      }
      else {
        panel.setAttribute('hidden', '');
      }
    });
  }

  /**
   * Updates "Multiple models" legend text and provider tabs visibility.
   *
   * @param {Element} wrapper
   */
  function updateModelSelectorUi(wrapper) {
    if (!wrapper) return;

    var keys = getSelectedKeys(wrapper);
    var labelText = keys.length === 1
      ? getLabelForKey(keys[0], wrapper)
      : Drupal.t('Multiple models');

    wrapper.querySelectorAll('.' + P + '__model-legend-label').forEach(function (el) {
      el.textContent = labelText;
    });

    if (!usesPageSkin(wrapper)) return;

    var nodeId = wrapper.getAttribute('data-node-id');
    var tabsHost = document.getElementById('airo-preview-provider-tabs-' + nodeId);
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
   * @param {Element} wrapper
   */
  function syncButton(wrapper) {
    if (!wrapper) return;

    if (usesPageSkin(wrapper)) {
      ['landing', 'conversation'].forEach(function (composer) {
        var input = composer === 'landing' ? getLandingInput(wrapper) : getConversationInput(wrapper);
        var btn = getSubmitArrow(wrapper, composer);
        if (!input || !btn) return;
        var hasQ = input.value.trim() !== '';
        btn.disabled = !hasQ;
      });
      return;
    }

    var input = wrapper.querySelector('.' + P + '__input');
    var btn = wrapper.querySelector('.' + P + '__submit');
    if (!input || !btn) return;

    var hasQ = input.value.trim() !== '';
    btn.disabled = !hasQ;

    var checkedCount = wrapper.querySelectorAll('.' + P + '__model-checkbox:checked').length;
    btn.textContent = (checkedCount >= 2)
      ? Drupal.t('Compare')
      : Drupal.t('Ask');
  }

  /**
   * @param {Element} wrapper
   */
  function focusConversationRegion(wrapper) {
    var resultsEl = document.getElementById(P + '-results-' + wrapper.getAttribute('data-node-id'));
    if (resultsEl) {
      resultsEl.focus({ preventScroll: false });
    }
    var convInput = getConversationInput(wrapper);
    if (convInput) {
      convInput.focus();
    }
  }

  // ─── Rendering helpers ────────────────────────────────────────────────────

  /**
   * @param {string} answerId
   * @param {string} threadId
   * @returns {string}
   */
  function buildPageSkinLoadingCard(answerId, threadId) {
    return '<article class="' + P + '__loading-card" id="' + answerId + '" data-thread-id="' + threadId + '">' +
      '<p class="' + P + '__loading-card-text">' +
        Drupal.t('Generating response') +
        '<span class="' + P + '__loading-ellipsis" aria-hidden="true"></span>' +
      '</p>' +
    '</article>';
  }

  /**
   * @param {string} scopeId Block or node id prefix for panel/tab ids.
   * @param {number} index
   * @returns {{panelId: string, tabKey: string, answerId: string}}
   */
  function providerTabIds(scopeId, index) {
    return {
      panelId: P + '-panel-' + scopeId + '-' + index,
      tabKey: P + '-tab-' + scopeId + '-' + index,
      answerId: scopeId + '-answer-' + index,
    };
  }

  /**
   * @returns {string}
   */
  function buildAccordionLoadingHtml() {
    return '<div class="' + P + '__loading">' +
      '<div class="' + P + '__loading-spinner"></div>' +
      '<div class="' + P + '__loading-text">' + Drupal.t('Querying\u2026') + '</div>' +
    '</div>';
  }

  /**
   * @param {string} key
   * @param {number} index
   * @param {Element} wrapper
   * @param {{scopeId: string, activeIndex: number, trackProviderKey: boolean}} opts
   * @returns {string}
   */
  function buildProviderTabButtonHtml(key, index, wrapper, opts) {
    var ids = providerTabIds(opts.scopeId, index);
    var label = key ? getLabelForKey(key, wrapper) : Drupal.t('AI');
    var isActive = index === opts.activeIndex;
    var providerAttr = opts.trackProviderKey
      ? ' data-provider-model-key="' + Drupal.checkPlain(key) + '"'
      : '';

    return '<li class="tabs__tab' + (isActive ? ' is-active' : '') + '">' +
      '<button type="button" role="tab"' +
      ' class="tabs__link' + (isActive ? ' is-active' : '') + '"' +
      ' aria-controls="' + ids.panelId + '"' +
      ' aria-selected="' + (isActive ? 'true' : 'false') + '"' +
      ' data-ai-tab-target="' + ids.tabKey + '"' +
      providerAttr +
      ' tabindex="' + (isActive ? '0' : '-1') + '">' +
      Drupal.checkPlain(label) +
      '</button></li>';
  }

  /**
   * @param {string} key
   * @param {number} index
   * @param {{scopeId: string, activeIndex: number, trackProviderKey: boolean, loadingHtml: string}} opts
   * @returns {string}
   */
  function buildProviderTabPanelHtml(key, index, opts) {
    var ids = providerTabIds(opts.scopeId, index);
    var isActive = index === opts.activeIndex;
    var providerAttr = opts.trackProviderKey
      ? ' data-provider-model-key="' + Drupal.checkPlain(key) + '"'
      : '';

    return '<div class="ai-content-audit-tab-panel"' +
      ' role="tabpanel"' +
      ' id="' + ids.panelId + '"' +
      ' data-ai-tab="' + ids.tabKey + '"' +
      providerAttr +
      (isActive ? '' : ' hidden') + '>' +
      opts.loadingHtml +
      '</div>';
  }

  /**
   * @param {string[]} tabButtons
   * @param {{pageSkinNav: boolean}} opts
   * @returns {string}
   */
  function buildProviderTabNavHtml(tabButtons, opts) {
    var navClass = 'tabs-wrapper is-horizontal';
    if (opts.pageSkinNav) {
      navClass += ' airo-preview__provider-tabs-nav';
    }

    return '<nav class="' + navClass + '" aria-label="' + Drupal.t('AI preview providers') + '">' +
      '<ul class="tabs tabs--primary is-horizontal clearfix" role="tablist">' +
        tabButtons.join('') +
      '</ul>' +
    '</nav>';
  }

  /**
   * @param {string[]} keys
   * @param {Element} wrapper
   * @param {{scopeId: string, activeIndex: number, trackProviderKey: boolean, loadingHtmlForIndex: function(number, string): string}} opts
   * @returns {{tabButtons: string[], tabPanels: string[]}}
   */
  function buildProviderTabMarkup(keys, wrapper, opts) {
    var tabOpts = {
      scopeId: opts.scopeId,
      activeIndex: opts.activeIndex,
      trackProviderKey: opts.trackProviderKey,
    };

    var tabButtons = keys.map(function (key, i) {
      return buildProviderTabButtonHtml(key, i, wrapper, tabOpts);
    });

    var tabPanels = keys.map(function (key, i) {
      return buildProviderTabPanelHtml(key, i, {
        scopeId: opts.scopeId,
        activeIndex: opts.activeIndex,
        trackProviderKey: opts.trackProviderKey,
        loadingHtml: opts.loadingHtmlForIndex(i, key),
      });
    });

    return { tabButtons: tabButtons, tabPanels: tabPanels };
  }

  /**
   * @param {Element|null} wrapper
   * @returns {string}
   */
  function getProvidersConfigUrl(wrapper) {
    return wrapper ? (wrapper.getAttribute('data-providers-url') || '') : '';
  }

  /**
   * @param {object} result
   * @returns {boolean}
   */
  function shouldShowApiKeyHint(result) {
    if (!result || !result.error) {
      return false;
    }
    if (result.error_hint === 'api_key') {
      return true;
    }
    var lower = String(result.error).toLowerCase();
    return /api.?key|authentication|unauthorized|invalid.?key|credential|bearer|not configured|no ai chat provider/.test(lower);
  }

  /**
   * @param {string} providersUrl
   * @returns {string}
   */
  function buildApiKeyHintHtml(providersUrl) {
    var url = providersUrl || '/admin/config/ai/providers';
    var link =
      '<a href="' + Drupal.checkPlain(url) + '" target="_blank" rel="noopener noreferrer">' +
        Drupal.t('AI Providers configuration') +
      '</a>';

    return '<div class="' + P + '__error-hint">' +
      '<p class="' + P + '__prompt-hint ' + P + '__prompt-hint--intro">' +
        '<strong>' + Drupal.t('How to add your API key') + '</strong>' +
      '</p>' +
      '<ol class="' + P + '__error-hint-steps">' +
        '<li>' + Drupal.t('In the Drupal admin menu, go to Configuration → AI.') + '</li>' +
        '<li>' + Drupal.t('Open') + ' ' + link + '.</li>' +
        '<li>' + Drupal.t('Select the provider for the model you chose (for example OpenAI or Anthropic).') + '</li>' +
        '<li>' + Drupal.t('Paste your API key into the API key field.') + '</li>' +
        '<li>' + Drupal.t('Save configuration, then ask your question again.') + '</li>' +
      '</ol>' +
    '</div>';
  }

  /**
   * @param {object} result
   * @param {Element|null} wrapper
   * @returns {string}
   */
  function buildPreviewErrorHtml(result, wrapper) {
    var html = '<div class="' + P + '__error">' + Drupal.checkPlain(result.error) + '</div>';
    if (shouldShowApiKeyHint(result)) {
      html += buildApiKeyHintHtml(getProvidersConfigUrl(wrapper));
    }
    return html;
  }

  /**
   * @param {Element} panel
   * @param {object} result
   * @param {Element|null} wrapper
   */
  function renderAccordionPanelResult(panel, result, wrapper) {
    var durationBadge = result.duration_ms
      ? '<span class="' + P + '__response-duration">' +
          Drupal.checkPlain(String(result.duration_ms)) + '\u202fms' +
        '</span>'
      : '';

    var body = result.error
      ? buildPreviewErrorHtml(result, wrapper)
      : '<div class="' + P + '__response-body">' + (result.html || '') + '</div>';

    panel.innerHTML =
      '<div class="' + P + '__response-header">' +
        '<span class="' + P + '__response-provider">' +
          Drupal.checkPlain(result.label || result.provider_id || 'AI') +
        '</span>' +
        '<span class="' + P + '__response-meta">' + durationBadge + '</span>' +
      '</div>' +
      body;
  }

  /**
   * @param {{label:string, provider_id:string, html:string|null, duration_ms:number, error:string|null}} result
   * @param {Element|null} wrapper
   */
  function renderPageSkinAnswerCard(card, result, wrapper) {
    var body = result.error
      ? buildPreviewErrorHtml(result, wrapper)
      : '<div class="' + P + '__answer-card-body">' + (result.html || '') + '</div>';

    var footer = result.error
      ? ''
      : '<footer class="' + P + '__answer-card-footer">' +
          '<span>' + Drupal.t('Simulated response') + '</span>' +
          '<span>' + Drupal.t('Generated:') + ' ' + Drupal.checkPlain(formatGeneratedTimestamp()) + '</span>' +
        '</footer>';

    card.classList.remove(P + '__loading-card', P + '__answer-card--loading');
    card.classList.add(P + '__answer-card');
    card.innerHTML = body + footer;
  }

  /**
   * @param {string} queryUrl
   * @param {string} question
   * @param {string} key
   * @param {Element} wrapper
   * @returns {Promise<object>}
   */
  function fetchOneProvider(queryUrl, question, key, wrapper) {
    var postBody = {
      question: question,
      provider_models: key ? [key] : [],
    };
    if (wrapper) {
      var promptPayload = getPromptPayload(wrapper);
      Object.keys(promptPayload).forEach(function (k) {
        postBody[k] = promptPayload[k];
      });
    }
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
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      credentials: 'same-origin',
      body: JSON.stringify(postBody),
    })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      if (data.error) {
        return {
          key: key,
          label: key,
          provider_id: '',
          model_id: '',
          html: null,
          duration_ms: 0,
          error: data.error,
          error_hint: data.error_hint || null,
        };
      }
      var one = (data.results && data.results[0]) || {
        key: key,
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
        key: key,
        label: key,
        provider_id: '',
        model_id: '',
        html: null,
        duration_ms: 0,
        error: Drupal.t('Request failed. Please try again.'),
        error_hint: null,
      };
    });
  }

  /**
   * Single-model page skin: stacked query + answer cards.
   *
   * @param {string} question
   * @param {Element} wrapper
   * @param {boolean} append
   */
  function submitQueryPageSkinCards(question, wrapper, append) {
    var nodeId = wrapper.getAttribute('data-node-id');
    var queryUrl = wrapper.getAttribute('data-query-url');
    var resultsEl = document.getElementById(P + '-results-' + nodeId);
    if (!queryUrl || !nodeId || !resultsEl) return;

    var keys = getSelectedKeys(wrapper);
    var key = keys.length > 0 ? keys[0] : '';
    var threadId = 'thread-' + nodeId + '-' + Date.now();

    if (!append) {
      resultsEl.innerHTML = '';
    }

    var tabsHost = document.getElementById('airo-preview-provider-tabs-' + nodeId);
    if (tabsHost) {
      tabsHost.innerHTML = '';
      tabsHost.hidden = true;
      tabsHost.removeAttribute('data-compare-id');
    }

    var queryCard =
      '<article class="' + P + '__query-card" data-thread-id="' + threadId + '">' +
        '<p class="' + P + '__query-card-text">' + Drupal.checkPlain(question) + '</p>' +
      '</article>';

    var answerId = threadId + '-answer';
    var loadingCard = buildPageSkinLoadingCard(answerId, threadId);

    resultsEl.insertAdjacentHTML('beforeend', queryCard + loadingCard);
    resultsEl.scrollTop = resultsEl.scrollHeight;

    fetchOneProvider(queryUrl, question, key, wrapper).then(function (result) {
      var card = document.getElementById(answerId);
      if (!card) return;
      renderPageSkinAnswerCard(card, result, wrapper);
    });
  }

  /**
   * Multi-model: provider tabs + parallel fetch (accordion pattern).
   *
   * @param {string} question
   * @param {Element} wrapper
   * @param {boolean} append
   */
  function submitQueryPageSkinTabs(question, wrapper, append) {
    var nodeId = wrapper.getAttribute('data-node-id');
    var queryUrl = wrapper.getAttribute('data-query-url');
    var resultsEl = document.getElementById(P + '-results-' + nodeId);
    if (!queryUrl || !nodeId || !resultsEl) return;

    var selectedKeys = getSelectedKeys(wrapper);
    var keys = selectedKeys.length > 0 ? selectedKeys : [''];
    var blockId = 'compare-' + nodeId + '-' + Date.now();
    var previousActiveKey = append ? getActiveProviderModelKey(wrapper) : null;
    var activeIndex = 0;

    if (previousActiveKey) {
      var previousIndex = keys.indexOf(previousActiveKey);
      if (previousIndex >= 0) {
        activeIndex = previousIndex;
      }
    }

    var queryCard =
      '<article class="' + P + '__query-card" data-thread-id="' + blockId + '">' +
        '<p class="' + P + '__query-card-text">' + Drupal.checkPlain(question) + '</p>' +
      '</article>';

    var tabMarkup = buildProviderTabMarkup(keys, wrapper, {
      scopeId: blockId,
      activeIndex: activeIndex,
      trackProviderKey: true,
      loadingHtmlForIndex: function (i) {
        return buildPageSkinLoadingCard(providerTabIds(blockId, i).answerId, blockId);
      },
    });

    var tabNav = buildProviderTabNavHtml(tabMarkup.tabButtons, { pageSkinNav: true });

    var compareBlock =
      '<div class="ai-content-audit-compare ai-content-audit-compare--results" id="' + blockId + '">' +
        tabMarkup.tabPanels.join('') +
      '</div>';

    var tabsHost = document.getElementById('airo-preview-provider-tabs-' + nodeId);

    if (!append) {
      resultsEl.innerHTML = queryCard + compareBlock;
    }
    else {
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
      var answerId = providerTabIds(blockId, i).answerId;

      fetchOneProvider(queryUrl, question, key, wrapper).then(function (result) {
        var card = document.getElementById(answerId);
        if (!card) return;
        renderPageSkinAnswerCard(card, result, wrapper);
      });
    });
  }

  /**
   * Accordion (non-page-skin) parallel submit.
   *
   * @param {string} question
   * @param {Element} wrapper
   */
  function submitQueryAccordionParallel(question, wrapper) {
    var nodeId = wrapper.getAttribute('data-node-id');
    var queryUrl = wrapper.getAttribute('data-query-url');
    var resultsEl = document.getElementById(P + '-results-' + nodeId);
    if (!queryUrl || !nodeId || !resultsEl) return;

    var selectedKeys = getSelectedKeys(wrapper);
    var keys = selectedKeys.length > 0 ? selectedKeys : [''];

    var tabMarkup = buildProviderTabMarkup(keys, wrapper, {
      scopeId: nodeId,
      activeIndex: 0,
      trackProviderKey: false,
      loadingHtmlForIndex: function () {
        return buildAccordionLoadingHtml();
      },
    });

    var tabNav = buildProviderTabNavHtml(tabMarkup.tabButtons, { pageSkinNav: false });

    resultsEl.innerHTML =
      '<div class="ai-content-audit-compare ai-content-audit-compare--results">' +
        tabNav +
        tabMarkup.tabPanels.join('') +
      '</div>';

    Drupal.attachBehaviors(resultsEl);

    keys.forEach(function (key, i) {
      var panelId = providerTabIds(nodeId, i).panelId;

      fetchOneProvider(queryUrl, question, key, wrapper).then(function (result) {
        var panel = document.getElementById(panelId);
        if (!panel) return;
        renderAccordionPanelResult(panel, result, wrapper);
      });
    });
  }

  /**
   * @param {string} question
   * @param {Element} wrapper
   * @param {{append?: boolean, fromLanding?: boolean}} options
   */
  function submitQueryParallel(question, wrapper, options) {
    if (!question || !wrapper) return;
    options = options || {};

    if (usesPageSkin(wrapper)) {
      var panel = getPageSkinPanel(wrapper);
      var wasLanding = panel && panel.getAttribute('data-airo-ui-state') === 'landing';
      var append = options.append || false;

      if (!append && (wasLanding || options.fromLanding)) {
        setUiState(wrapper, 'conversation');
        updateModelSelectorUi(wrapper);
        var convInput = getConversationInput(wrapper);
        if (convInput && options.fromLanding) {
          convInput.value = '';
        }
        focusConversationRegion(wrapper);
      }

      var keys = getSelectedKeys(wrapper);
      var effectiveKeys = keys.length > 0 ? keys : [''];

      if (effectiveKeys.length > 1) {
        submitQueryPageSkinTabs(question, wrapper, append);
      }
      else {
        submitQueryPageSkinCards(question, wrapper, append);
      }

      if (!append) {
        var landing = getLandingInput(wrapper);
        if (landing && options.fromLanding) {
          landing.value = '';
        }
        var conv = getConversationInput(wrapper);
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
   * @param {string} composer landing|conversation
   */
  function handlePageSkinSubmit(wrapper, composer) {
    var input = composer === 'landing' ? getLandingInput(wrapper) : getConversationInput(wrapper);
    if (!input) return;
    var q = input.value.trim();
    if (!q) return;

    var fromLanding = composer === 'landing';
    var panel = getPageSkinPanel(wrapper);
    var append = !fromLanding && panel && panel.getAttribute('data-airo-ui-state') === 'conversation';

    submitQueryParallel(q, wrapper, {
      append: append,
      fromLanding: fromLanding,
    });

    if (!append) {
      input.value = '';
    }
    else {
      input.value = '';
    }
    syncButton(wrapper);
  }

  /** @type {boolean} */
  var modelDropdownOutsideClickBound = false;

  /**
   * Closes open model selector dropdowns when the user clicks elsewhere.
   *
   * @param {MouseEvent} event
   */
  function closeOpenModelDropdownsOnOutsideClick(event) {
    var target = event.target;
    if (!(target instanceof Element)) {
      return;
    }

    document.querySelectorAll('.' + P + '__model-fieldset[open]').forEach(function (details) {
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
    attach: function (context) {

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

      once('airo-preview-submit-arrow', '.' + P + '__submit-arrow', context).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var wrapper = getWrapper(btn);
          if (!wrapper) return;
          var composer = btn.getAttribute('data-composer') || 'landing';
          handlePageSkinSubmit(wrapper, composer);
        });
      });

      once('airo-preview-input', '.' + P + '__input', context).forEach(function (input) {
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
          var wrapper = getWrapper(input);
          if (!wrapper) return;
          var q = input.value.trim();
          if (!q) return;

          if (usesPageSkin(wrapper)) {
            var composer = input.getAttribute('data-composer') || 'landing';
            handlePageSkinSubmit(wrapper, composer);
            return;
          }

          e.preventDefault();
          submitQueryParallel(q, wrapper);
        });
      });

      once('airo-preview-checkbox', '.' + P + '__model-checkbox', context).forEach(function (cb) {
        cb.addEventListener('change', function () {
          var wrapper = getWrapper(cb);
          if (usesPageSkin(wrapper)) {
            var fromScreen = cb.closest('.airo-panel__screen--landing, .airo-panel__screen--conversation');
            var toScreen = fromScreen && fromScreen.classList.contains('airo-panel__screen--landing')
              ? wrapper.querySelector('.airo-panel__screen--conversation')
              : wrapper.querySelector('.airo-panel__screen--landing');
            syncModelCheckboxesBetweenScreens(wrapper, fromScreen, toScreen);
          }
          syncButton(wrapper);
          updateModelSelectorUi(wrapper);
        });
      });

      once('airo-preview-prompt-mode', '.' + P + '__prompt-mode', context).forEach(function (radio) {
        radio.addEventListener('change', function () {
          var wrapper = getWrapper(radio);
          if (!wrapper) return;

          var fromScreen = radio.closest('.airo-panel__screen--landing, .airo-panel__screen--conversation, .' + P);
          if (usesPageSkin(wrapper) && fromScreen) {
            var toScreen = fromScreen.classList.contains('airo-panel__screen--landing')
              ? wrapper.querySelector('.airo-panel__screen--conversation')
              : wrapper.querySelector('.airo-panel__screen--landing');
            syncPromptEditorBetweenScreens(fromScreen, toScreen || fromScreen);
          }

          updateAllPromptBadges(wrapper);
          savePromptToCookies(wrapper);
        });
      });

      once('airo-preview-prompt-input', '.' + P + '__prompt-system, .' + P + '__prompt-user-template', context).forEach(function (textarea) {
        textarea.addEventListener('input', function () {
          var wrapper = getWrapper(textarea);
          if (!wrapper) return;

          syncPromptTextareasInWrapper(wrapper, textarea);

          if (usesPageSkin(wrapper)) {
            var fromScreen = textarea.closest('.airo-panel__screen--landing, .airo-panel__screen--conversation');
            if (fromScreen) {
              var toScreen = fromScreen.classList.contains('airo-panel__screen--landing')
                ? wrapper.querySelector('.airo-panel__screen--conversation')
                : wrapper.querySelector('.airo-panel__screen--landing');
              if (toScreen) {
                syncPromptEditorBetweenScreens(fromScreen, toScreen);
              }
            }
          }

          savePromptToCookiesDebounced(wrapper);
        });
      });

      once('airo-preview-prompt-restore', '.' + P + '__prompt-restore', context).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          restorePromptTemplate(getWrapper(btn));
        });
      });

      once('airo-preview-init', '.' + P, context).forEach(function (wrapper) {
        ensureModelDropdownOutsideClickListener();
        loadPromptFromCookies(wrapper);
        syncButton(wrapper);
        updateAllPromptBadges(wrapper);
        if (usesPageSkin(wrapper)) {
          setUiState(wrapper, 'landing');
          updateModelSelectorUi(wrapper);
        }
      });

      once('airo-analysis-panel-host', 'form.airo-analysis-panel-host', context).forEach(function (form) {
        form.addEventListener('submit', function (e) {
          e.preventDefault();
        });
      });
    },
  };

})(Drupal, once, window.Cookies);
