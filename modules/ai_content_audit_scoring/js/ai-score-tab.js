/**
 * @file
 * Drupal behaviors for the AIRO AI Score tab.
 */
(function (Drupal, once) {
  function airoAssessPostBody(el) {
    const rid =
      el.getAttribute('data-revision-id') ||
      (el.closest('.airo-score') &&
        el.closest('.airo-score').getAttribute('data-revision-id')) ||
      '';
    if (!rid) {
      return '{}';
    }
    return JSON.stringify({ revision_id: parseInt(rid, 10) });
  }

  Drupal.behaviors.airoScoreTab = {
    attach(context) {
      // Re-analyze button in Score tab
      once('airo-score-reanalyze', '.airo-score__reanalyze', context).forEach(
        function (btn) {
          btn.addEventListener('click', function (e) {
            e.preventDefault();
            const url = this.getAttribute('data-assess-url');
            if (!url) return;

            btn.disabled = true;
            btn.textContent = Drupal.t('Analyzing...');

            Drupal.airoContentAudit
              .postJson(url, airoAssessPostBody(btn))
              .then(function (response) {
                return response.json();
              })
              .then(function (data) {
                if (data.status === 'complete') {
                  const panel = btn.closest('.airo-panel--accordion');
                  const analysisPage = document.querySelector(
                    '.airo-analysis-page',
                  );
                  if (analysisPage && panel) {
                    const nodeId = panel.getAttribute('data-node-id');
                    if (nodeId) {
                      Drupal.ajax({
                        url: Drupal.url(
                          `node/${nodeId}/airo-analysis/panel-refresh`,
                        ),
                      }).execute();
                      btn.disabled = false;
                      btn.textContent = Drupal.t('Re-analyze');
                      return;
                    }
                  }
                  const scoreTabBtn = document.querySelector(
                    '.tabs__link[data-airo-tab="score-tab"]',
                  );
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
        },
      );

      // Animate donut on load
      once('airo-donut-animate', '.airo-score__donut-fill', context).forEach(
        function (circle) {
          const finalOffset = circle.getAttribute('stroke-dashoffset');
          const dashArray = circle.getAttribute('stroke-dasharray');
          // Start from full offset (empty), animate to target
          circle.style.strokeDashoffset = dashArray;
          // Trigger reflow
          circle.getBoundingClientRect();
          // Animate to final position
          circle.style.strokeDashoffset = finalOffset;
        },
      );
    },
  };
})(Drupal, once);
