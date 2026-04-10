/**
 * @file
 * Drupal behaviors for the AIRO AI Preview tab.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.airoPreviewTab = {
    attach: function (context) {

      // Submit query button
      once('airo-preview-submit', '.airo-preview__submit', context).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var input = document.querySelector('.airo-preview__input');
          if (!input || !input.value.trim()) return;
          Drupal.behaviors.airoPreviewTab.submitQuery(input.value.trim());
        });
      });

      // Enter key on input
      once('airo-preview-input', '.airo-preview__input', context).forEach(function (input) {
        input.addEventListener('keypress', function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            var value = this.value.trim();
            if (value) {
              Drupal.behaviors.airoPreviewTab.submitQuery(value);
            }
          }
        });
      });

      // Suggested prompt buttons
      once('airo-preview-suggestion', '.airo-preview__suggestion-btn', context).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var prompt = this.getAttribute('data-prompt');
          if (!prompt) return;

          // Fill the input and submit
          var input = document.querySelector('.airo-preview__input');
          if (input) {
            input.value = prompt;
          }
          Drupal.behaviors.airoPreviewTab.submitQuery(prompt);
        });
      });

      // Provider tab switching
      once('airo-preview-provider-tab', '.airo-preview__provider-tab', context).forEach(function (tab) {
        tab.addEventListener('click', function (e) {
          e.preventDefault();
          var providerId = this.getAttribute('data-provider-id');
          if (!providerId) return;

          // Update active tab
          document.querySelectorAll('.airo-preview__provider-tab').forEach(function (t) {
            t.classList.remove('airo-preview__provider-tab--active');
            t.setAttribute('aria-selected', 'false');
          });
          this.classList.add('airo-preview__provider-tab--active');
          this.setAttribute('aria-selected', 'true');

          // If there's a current query, re-submit with new provider
          var input = document.querySelector('.airo-preview__input');
          if (input && input.value.trim()) {
            Drupal.behaviors.airoPreviewTab.submitQuery(input.value.trim(), providerId);
          }
        });
      });
    },

    /**
     * Submits a preview query to the server.
     */
    submitQuery: function (question, providerId) {
      var previewEl = document.querySelector('.airo-preview');
      if (!previewEl) return;
      var nodeId = previewEl.getAttribute('data-node-id');
      var responseArea = document.getElementById('airo-preview-response-' + nodeId);
      if (!responseArea) return;

      var input = document.querySelector('.airo-preview__input');
      var queryUrl = previewEl.getAttribute('data-query-url');
      if (!queryUrl || !nodeId) return;

      // Get active provider if not specified
      if (!providerId) {
        var activeTab = document.querySelector('.airo-preview__provider-tab--active');
        providerId = activeTab ? activeTab.getAttribute('data-provider-id') : null;
      }

      // Show loading state
      responseArea.innerHTML =
        '<div class="airo-preview__loading">' +
          '<div class="airo-preview__loading-spinner"></div>' +
          '<div class="airo-preview__loading-text">' + Drupal.t('Generating response...') + '</div>' +
        '</div>';

      // Submit query
      fetch(queryUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          question: question,
          provider_id: providerId,
        }),
      })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (data.error) {
          responseArea.innerHTML =
            '<div class="airo-preview__error">' + Drupal.checkPlain(data.error) + '</div>';
          return;
        }

        var providerLabel = data.provider_label || 'AI';
        var timestamp = data.timestamp || '';

        responseArea.innerHTML =
          '<div class="airo-preview__response-card">' +
            '<div class="airo-preview__response-header">' +
              '<span class="airo-preview__response-provider">' + Drupal.checkPlain(providerLabel) + '</span>' +
              '<span class="airo-preview__response-meta">' +
                (timestamp ? '<span>' + Drupal.checkPlain(timestamp) + '</span>' : '') +
              '</span>' +
            '</div>' +
            '<div class="airo-preview__response-body">' + data.response_html + '</div>' +
          '</div>';

        // Re-attach behaviors in case response contains Drupal elements
        Drupal.attachBehaviors(responseArea);
      })
      .catch(function (err) {
        responseArea.innerHTML =
          '<div class="airo-preview__error">' +
            Drupal.t('Failed to get a response. Please try again.') +
          '</div>';
      });
    },
  };

})(Drupal, once);
