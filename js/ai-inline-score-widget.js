/**
 * @file
 * Drupal behaviors for the AIRO inline score widget.
 *
 * G5: After a successful assessment POST the widget is refreshed via a Drupal
 *   AJAX GET to ai_content_audit.widget.refresh, which returns a ReplaceCommand
 *   targeting .airo-widget[data-node-id="N"].  This replaces window.location.reload().
 *
 * G8: Button selectors use data-airo-action attributes so the behavior survives
 *   template class renames.  once() key updated to 'airo-action-btn'.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.airoInlineWidget = {
    attach: function (context) {
      // G8: Use data-attribute selectors — stable across template class renames.
      once(
        'airo-action-btn',
        '[data-airo-action="run-analysis"], [data-airo-action="reanalyze"]',
        context
      ).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var assessUrl = this.getAttribute('data-assess-url');
          var widget    = this.closest('.airo-widget');
          if (!assessUrl || !widget) return;

          var nodeId = widget.getAttribute('data-node-id');

          // G8: Analyzing state HTML uses GIN-aligned class structure.
          widget.innerHTML =
            '<div class="card__content-wrapper airo-widget__body">' +
              '<div class="airo-widget__state airo-widget__state--analyzing" role="status" aria-live="polite">' +
                '<div class="airo-widget__spinner" aria-hidden="true"></div>' +
                '<div class="airo-widget__state-text">' +
                  '<span class="airo-widget__state-title">' + Drupal.t('Analyzing content\u2026') + '</span>' +
                  '<span class="airo-widget__state-sub">' + Drupal.t('AI readiness check in progress') + '</span>' +
                '</div>' +
              '</div>' +
            '</div>';

          // POST to assess endpoint.
          fetch(assessUrl, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
          })
          .then(function (response) { return response.json(); })
          .then(function (data) {
            if (data.status === 'complete') {
              // G5: Swap only the widget card — no full page reload.
              Drupal.behaviors.airoInlineWidget.refreshWidget(nodeId);
            }
            else {
              // Start polling (assessment may be async).
              Drupal.behaviors.airoInlineWidget.pollStatus(widget, nodeId);
            }
          })
          .catch(function () {
            // Show an inline error using GIN's .messages--error pattern.
            widget.innerHTML =
              '<div class="card__content-wrapper airo-widget__body">' +
                '<div class="messages messages--error" role="alert">' +
                  '<div class="messages__content">' +
                    Drupal.t('Analysis failed. Please try again.') +
                  '</div>' +
                '</div>' +
              '</div>';
          });
        });
      });
    },

    /**
     * G5: Replace the widget in-place via a Drupal AJAX ReplaceCommand.
     *
     * @param {string} nodeId   The node ID (from data-node-id).
     */
    refreshWidget: function (nodeId) {
      if (!nodeId) return;
      var refreshUrl = Drupal.url('admin/ai-content-audit/widget/' + nodeId + '/refresh');
      // Drupal.ajax fires the request and processes all returned AjaxCommands,
      // including the ReplaceCommand that swaps .airo-widget[data-node-id="N"].
      // Drupal automatically calls attachBehaviors() after the replace, so
      // once() ensures this behavior does not double-attach.
      Drupal.ajax({ url: refreshUrl }).execute();
    },

    /**
     * Polls the assessment status endpoint and refreshes the widget when done.
     *
     * @param {HTMLElement} widget   The .airo-widget element.
     * @param {string}      nodeId   The node ID.
     */
    pollStatus: function (widget, nodeId) {
      if (!nodeId) return;
      var statusUrl = Drupal.url('admin/ai-content-audit/status/' + nodeId);

      var interval = setInterval(function () {
        fetch(statusUrl, { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.status === 'complete') {
              clearInterval(interval);
              // G5: AJAX refresh instead of full-page reload.
              Drupal.behaviors.airoInlineWidget.refreshWidget(nodeId);
            }
          })
          .catch(function () {
            clearInterval(interval);
          });
      }, 2000);
    },
  };

})(Drupal, once);
