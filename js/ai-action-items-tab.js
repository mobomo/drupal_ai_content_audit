/**
 * @file
 * Drupal behaviors for the AIRO Action Items tab.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.airoActionItems = {
    attach: function (context) {
      // Checkbox toggle
      once('airo-action-toggle', '.airo-actions__checkbox', context).forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
          var item = this.closest('.airo-actions__item');
          var url = this.getAttribute('data-toggle-url');
          if (!item || !url) return;

          var isCompleted = this.checked;

          // Update visual state immediately
          if (isCompleted) {
            item.classList.add('airo-actions__item--completed');
            var title = item.querySelector('.airo-actions__item-title');
            if (title) title.classList.add('airo-actions__item-title--completed');
          } else {
            item.classList.remove('airo-actions__item--completed');
            var title = item.querySelector('.airo-actions__item-title');
            if (title) title.classList.remove('airo-actions__item-title--completed');
          }

          // Persist via AJAX
          fetch(url, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ completed: isCompleted }),
          })
          .then(function (response) { return response.json(); })
          .then(function (data) {
            // Update summary counts
            var summary = document.querySelector('.airo-actions__count-completed');
            if (summary && data.completed_count !== undefined) {
              summary.textContent = data.completed_count + ' ' + Drupal.t('completed');
            }
          })
          .catch(function () {
            // Revert on failure
            checkbox.checked = !isCompleted;
            if (!isCompleted) {
              item.classList.add('airo-actions__item--completed');
            } else {
              item.classList.remove('airo-actions__item--completed');
            }
          });
        });
      });

      // Copy suggested content to clipboard
      once('airo-copy-btn', '.airo-actions__copy-btn', context).forEach(function (btn) {
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
            // Fallback for older browsers
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
      once('airo-actions-recheck', '.airo-actions__recheck', context).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var url = this.getAttribute('data-assess-url');
          if (!url) return;

          btn.disabled = true;
          btn.textContent = Drupal.t('Checking...');

          fetch(url, {
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
              // Reload the action items tab
              var tabBtn = document.querySelector('.tabs__link[data-airo-tab="action-items-tab"]');
              if (tabBtn) {
                tabBtn.click();
              } else {
                window.location.reload();
              }
            }
          })
          .catch(function () {
            btn.disabled = false;
            btn.textContent = Drupal.t('Re-check');
          });
        });
      });
    },
  };

})(Drupal, once);
