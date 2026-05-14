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
 * This file handles two behaviors:
 *  1. airoPanel       — tab switching (applies in both off-canvas and accordion).
 *  2. airoPanelAccordionReanalyze — Re-analyze button in the accordion footer.
 *     After the assessment POST completes the page is reloaded so the accordion
 *     item re-renders with fresh data.
 */
(function (Drupal, once) {
  'use strict';

  // ── 1. Tab switching ──────────────────────────────────────────────────────
  Drupal.behaviors.airoPanel = {
    attach: function (context) {
      once('airo-tab-switch', '.tabs__link[data-airo-tab]', context).forEach(function (tab) {
        tab.addEventListener('click', function (e) {
          e.preventDefault();

          var tabId = this.getAttribute('data-airo-tab');
          var panel = this.closest('.airo-panel');
          if (!panel || !tabId) {
            return;
          }

          // ---- Update tab-button state (button + parent <li>) ----
          panel.querySelectorAll('.tabs__link[data-airo-tab]').forEach(function (t) {
            var active = t.getAttribute('data-airo-tab') === tabId;
            t.classList.toggle('is-active', active);
            t.setAttribute('aria-selected', active ? 'true' : 'false');
            // Toggle is-active on the parent <li class="tabs__tab"> too.
            if (t.parentElement) {
              t.parentElement.classList.toggle('is-active', active);
            }
          });

          // ---- Show the matching pane, hide all others ----
          panel.querySelectorAll('[data-tab-pane]').forEach(function (pane) {
            var show = pane.getAttribute('data-tab-pane') === tabId;
            pane.hidden = !show;
            pane.setAttribute('aria-hidden', show ? 'false' : 'true');
          });
        });
      });
    },

    detach: function (context, settings, trigger) {
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
    attach: function (context) {
      once(
        'airo-accordion-reanalyze',
        '[data-airo-action="accordion-reanalyze"]',
        context
      ).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();

          var assessUrl = this.getAttribute('data-assess-url');
          var panel     = this.closest('.airo-panel--accordion');
          if (!assessUrl || !panel) {
            return;
          }

          // Show an analyzing state that fills the panel body.
          panel.innerHTML =
            '<div class="airo-panel__analyzing" role="status" aria-live="polite">' +
              '<div class="airo-panel__analyzing-spinner" aria-hidden="true"></div>' +
              '<div class="airo-panel__analyzing-label">' +
                Drupal.t('Analyzing content\u2026') +
              '</div>' +
              '<div class="airo-panel__analyzing-sublabel">' +
                Drupal.t('This may take a moment') +
              '</div>' +
            '</div>';

          fetch(assessUrl, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
          })
          .then(function (response) { return response.json(); })
          .then(function () {
            // Reload the page — the accordion item re-renders from the
            // freshly saved assessment entity in the database.
            window.location.reload();
          })
          .catch(function () {
            panel.innerHTML =
              '<div class="messages messages--error" role="alert">' +
                '<div class="messages__content">' +
                  Drupal.t('Analysis failed. Please try again.') +
                '</div>' +
              '</div>';
          });
        });
      });
    },

    detach: function (context, settings, trigger) {
      if (trigger === 'unload') {
        once.remove(
          'airo-accordion-reanalyze',
          '[data-airo-action="accordion-reanalyze"]',
          context
        );
      }
    },
  };

})(Drupal, once);
