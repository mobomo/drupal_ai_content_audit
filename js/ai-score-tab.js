/**
 * @file
 * Drupal behaviors for the AIRO AI Score tab.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.airoScoreTab = {
    attach: function (context) {
      // Re-analyze button in Score tab
      once('airo-score-reanalyze', '.airo-score__reanalyze', context).forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var url = this.getAttribute('data-assess-url');
          if (!url) return;

          btn.disabled = true;
          btn.textContent = Drupal.t('Analyzing...');

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
              // Reload the score tab via the tab click handler
              var scoreTabBtn = document.querySelector('.tabs__link[data-airo-tab="score-tab"]');
              if (scoreTabBtn) {
                scoreTabBtn.click();
              } else {
                window.location.reload();
              }
            }
          })
          .catch(function () {
            btn.disabled = false;
            btn.textContent = Drupal.t('Re-analyze');
          });
        });
      });

      // Animate donut on load
      once('airo-donut-animate', '.airo-score__donut-fill', context).forEach(function (circle) {
        var finalOffset = circle.getAttribute('stroke-dashoffset');
        var dashArray = circle.getAttribute('stroke-dasharray');
        // Start from full offset (empty), animate to target
        circle.style.strokeDashoffset = dashArray;
        // Trigger reflow
        circle.getBoundingClientRect();
        // Animate to final position
        circle.style.strokeDashoffset = finalOffset;
      });
    },
  };

})(Drupal, once);
