/**
 * @file
 * Drupal behaviors for the AIRO Technical Audit tab.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.airoTechnicalAudit = {
    attach: function (context) {
      // Copy to clipboard buttons
      once('airo-audit-copy', '.airo-audit__copy-btn', context).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var text = this.getAttribute('data-copy-text');
          if (!text) return;

          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
              btn.textContent = Drupal.t('Copied!');
              setTimeout(function () {
                btn.textContent = Drupal.t('Copy');
              }, 2000);
            });
          } else {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            btn.textContent = Drupal.t('Copied!');
            setTimeout(function () {
              btn.textContent = Drupal.t('Copy');
            }, 2000);
          }
        });
      });

      // Re-check button
      once('airo-audit-refresh', '.airo-audit__refresh', context).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          btn.disabled = true;
          btn.textContent = Drupal.t('Checking...');

          // Reload the technical audit tab via the tab click handler
          var tabBtn = document.querySelector('.tabs__link[data-airo-tab="technical-audit-tab"]');
          if (tabBtn) {
            // Add force_refresh parameter by modifying the URL temporarily
            var panel = document.querySelector('.airo-panel');
            if (panel) {
              panel.setAttribute('data-force-refresh', '1');
            }
            tabBtn.click();

            // Clean up
            setTimeout(function () {
              if (panel) {
                panel.removeAttribute('data-force-refresh');
              }
            }, 500);
          }
        });
      });
    },
  };

})(Drupal, once);
