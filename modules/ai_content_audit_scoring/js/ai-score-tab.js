/**
 * @file
 * Drupal behaviors for the AIRO AI Score tab.
 */
(function (Drupal, once) {
  'use strict';

  function airoAssessPostBody(el) {
    var rid =
      el.getAttribute('data-revision-id') ||
      (el.closest('.airo-score') && el.closest('.airo-score').getAttribute('data-revision-id')) ||
      '';
    if (!rid) {
      return '{}';
    }
    return JSON.stringify({ revision_id: parseInt(rid, 10) });
  }

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

          Drupal.airoContentAudit.postJson(url, airoAssessPostBody(btn))
          .then(function (response) { return response.json(); })
          .then(function (data) {
            if (data.status === 'complete') {
              var panel = btn.closest('.airo-panel--accordion');
              var analysisPage = document.querySelector('.airo-analysis-page');
              if (analysisPage && panel) {
                var nodeId = panel.getAttribute('data-node-id');
                if (nodeId) {
                  Drupal.ajax({
                    url: Drupal.url('node/' + nodeId + '/airo-analysis/panel-refresh'),
                  }).execute();
                  btn.disabled = false;
                  btn.textContent = Drupal.t('Re-analyze');
                  return;
                }
              }
              var scoreTabBtn = document.querySelector('.tabs__link[data-airo-tab="score-tab"]');
              if (scoreTabBtn) {
                scoreTabBtn.click();
              }
              else {
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
