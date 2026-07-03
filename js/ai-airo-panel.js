/**
 * @file
 * Drupal behaviors for the AIRO panel (off-canvas and accordion variants).
 *
 * Tab-switching strategy
 * ----------------------
 * All four tab panes are rendered server-side and embedded in the initial HTML.
 * Switching tabs shows/hides the pane via the HTML `hidden` attribute — no
 * AJAX requests are fired, so there is no Drupal.attachBehaviors cascade and
 * no active-tab reset race condition.
 *
 * This file handles three behaviors:
 *  1. airoPanel — tab switching (applies in both off-canvas and accordion).
 *  2. airoPanelAccordionReanalyze — Re-analyze in the accordion footer (POST + reload).
 *  3. airoPanelOffCanvasReanalyze — Re-analyze in the off-canvas footer (POST + reload).
 */
(function (Drupal, once) {
  function airoAssessPostBody(el) {
    const panel = el.closest('.airo-panel');
    const rid =
      el.getAttribute('data-revision-id') ||
      (panel && panel.getAttribute('data-revision-id')) ||
      '';
    if (!rid) {
      return '{}';
    }
    return JSON.stringify({ revision_id: parseInt(rid, 10) });
  }

  /**
   * On the AIRO Analysis tab, refresh only the side panel (no full page reload).
   *
   * @param {Element|null} panel
   *   .airo-panel--accordion element (for node id).
   */
  function airoRefreshAfterAssess(panel) {
    const analysisPage = document.querySelector('.airo-analysis-route');
    if (!analysisPage || !panel) {
      window.location.reload();
      return;
    }
    const nodeId = panel.getAttribute('data-node-id');
    if (!nodeId) {
      window.location.reload();
      return;
    }
    Drupal.ajax({
      url: Drupal.url(`node/${nodeId}/airo-analysis/panel-refresh`),
    }).execute();
  }

  // ── 1. Tab switching ──────────────────────────────────────────────────────
  Drupal.behaviors.airoPanel = {
    attach(context) {
      once('airo-tab-switch', '.tabs__link[data-airo-tab]', context).forEach(
        function (tab) {
          tab.addEventListener('click', function (e) {
            e.preventDefault();

            const tabId = this.getAttribute('data-airo-tab');
            const panel = this.closest('.airo-panel');
            if (!panel || !tabId) {
              return;
            }

            // ---- Update tab-button state (button + parent <li>) ----
            panel
              .querySelectorAll('.tabs__link[data-airo-tab]')
              .forEach(function (t) {
                const active = t.getAttribute('data-airo-tab') === tabId;
                t.classList.toggle('is-active', active);
                t.setAttribute('aria-selected', active ? 'true' : 'false');
                // Toggle is-active on the parent <li class="tabs__tab"> too.
                if (t.parentElement) {
                  t.parentElement.classList.toggle('is-active', active);
                }
              });

            // ---- Show the matching pane, hide all others ----
            panel.querySelectorAll('[data-tab-pane]').forEach(function (pane) {
              const show = pane.getAttribute('data-tab-pane') === tabId;
              pane.hidden = !show;
              pane.setAttribute('aria-hidden', show ? 'false' : 'true');
            });
          });
        },
      );
    },

    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        once.remove('airo-tab-switch', '.tabs__link[data-airo-tab]', context);
      }
    },
  };

  // ── 2. Accordion Re-analyze button ───────────────────────────────────────
  // Handles [data-airo-action="accordion-reanalyze"] buttons rendered inside
  // .airo-panel--accordion (the node-edit sidebar accordion item).
  // Workflow:
  //   a) Show an analyzing overlay inside the .airo-panel--accordion wrapper.
  //   b) POST to data-assess-url.
  //   c) On success or error, reload the page so the accordion item
  //      re-renders with fresh assessment data from the database.
  Drupal.behaviors.airoPanelAccordionReanalyze = {
    attach(context) {
      once(
        'airo-accordion-reanalyze',
        '[data-airo-action="accordion-reanalyze"]',
        context,
      ).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();

          const assessUrl = this.getAttribute('data-assess-url');
          const panel = this.closest('.airo-panel--accordion');
          if (!assessUrl || !panel) {
            return;
          }

          // Show an analyzing state that fills the panel body.
          panel.innerHTML =
            `<div class="airo-panel__analyzing" role="status" aria-live="polite">` +
            `<div class="airo-panel__analyzing-spinner" aria-hidden="true"></div>` +
            `<div class="airo-panel__analyzing-label">${Drupal.t(
              'Analyzing content\u2026',
            )}</div>` +
            `<div class="airo-panel__analyzing-sublabel">${Drupal.t(
              'This may take a moment',
            )}</div>` +
            `</div>`;

          Drupal.airoContentAudit
            .postJson(assessUrl, airoAssessPostBody(this))
            .then(function (response) {
              return response.json();
            })
            .then(function () {
              airoRefreshAfterAssess(panel);
            })
            .catch(function () {
              panel.innerHTML =
                `<div class="messages messages--error" role="alert">` +
                `<div class="messages__content">${Drupal.t(
                  'Analysis failed. Try again.',
                )}</div>` +
                `</div>`;
            });
        });
      });
    },

    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        once.remove(
          'airo-accordion-reanalyze',
          '[data-airo-action="accordion-reanalyze"]',
          context,
        );
      }
    },
  };

  // ── 3. Off-canvas panel footer Re-analyze ─────────────────────────────────
  Drupal.behaviors.airoPanelOffCanvasReanalyze = {
    attach(context) {
      once(
        'airo-panel-offcanvas-reanalyze',
        '[data-airo-action="panel-reanalyze"]',
        context,
      ).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          const assessUrl = this.getAttribute('data-assess-url');
          if (!assessUrl) {
            return;
          }
          btn.disabled = true;
          Drupal.airoContentAudit
            .postJson(assessUrl, airoAssessPostBody(btn))
            .then(function (response) {
              return response.json();
            })
            .then(function () {
              window.location.reload();
            })
            .catch(function () {
              btn.disabled = false;
            });
        });
      });
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        once.remove(
          'airo-panel-offcanvas-reanalyze',
          '[data-airo-action="panel-reanalyze"]',
          context,
        );
      }
    },
  };
})(Drupal, once);
