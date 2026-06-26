/**
 * @file
 * JavaScript behaviors for the AI Site Audit Dashboard.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Dashboard AJAX behaviors.
   */
  Drupal.behaviors.aiSiteDashboard = {
    attach: function (context) {
      var settings = drupalSettings.aiSiteAudit || {};

      // Refresh stats button.
      once('ai-site-refresh', '[data-drupal-selector="ai-site-refresh-stats"]', context)
        .forEach(function (btn) {
          btn.addEventListener('click', function () {
            btn.classList.add('ai-site-dashboard__refresh-btn--loading');
            btn.textContent = 'Refreshing…';

            fetch(settings.refreshStatsUrl, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
              },
              credentials: 'same-origin',
            })
              .then(function (response) { return response.json(); })
              .then(function (data) {
                if (data.success && data.data) {
                  updateStatsCards(data.data.stats);
                  updateScoreDistribution(data.data.score_distribution);
                }
                btn.classList.remove('ai-site-dashboard__refresh-btn--loading');
                btn.textContent = '↻ Refresh Stats';
              })
              .catch(function () {
                btn.classList.remove('ai-site-dashboard__refresh-btn--loading');
                btn.textContent = '↻ Refresh Stats';
              });
          });
        });

      // Trigger analysis buttons.
      once('ai-site-trigger', '[data-drupal-selector="ai-site-trigger-analysis"]', context)
        .forEach(function (btn) {
          btn.addEventListener('click', function () {
            btn.disabled = true;
            btn.textContent = 'Starting…';

            fetch(settings.triggerAnalysisUrl, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
              },
              credentials: 'same-origin',
            })
              .then(function (response) { return response.json(); })
              .then(function (data) {
                if (data.success) {
                  btn.textContent = 'Analysis Enqueued ✓';
                  pollAnalysisStatus();
                } else {
                  btn.textContent = data.message || 'Failed';
                  btn.disabled = false;
                }
              })
              .catch(function () {
                btn.textContent = 'Generate AI Analysis';
                btn.disabled = false;
              });
          });
        });

      // Export link wiring.
      once('ai-site-export', '.ai-site-dashboard__header-actions a', context)
        .forEach(function (link) {
          if (link.getAttribute('href') === '#') {
            if (link.textContent.indexOf('CSV') !== -1 && settings.exportCsvUrl) {
              link.setAttribute('href', settings.exportCsvUrl);
            }
            if (link.textContent.indexOf('JSON') !== -1 && settings.exportJsonUrl) {
              link.setAttribute('href', settings.exportJsonUrl);
            }
          }
        });
    }
  };

  /**
   * Update stats card values from AJAX response.
   */
  function updateStatsCards(stats) {
    if (!stats) return;

    var mappings = {
      'stat-avg-score': stats.avg_score,
      'stat-total-assessed': stats.total_assessed,
      'stat-ai-ready': stats.ai_ready,
      'stat-improving': stats.improving,
      'stat-needs-work': stats.needs_work,
    };

    Object.keys(mappings).forEach(function (selector) {
      var el = document.querySelector('[data-drupal-selector="' + selector + '"]');
      if (el && mappings[selector] !== undefined) {
        el.textContent = mappings[selector];
      }
    });
  }

  /**
   * Update score distribution bars from AJAX response.
   */
  function updateScoreDistribution(distribution) {
    if (!distribution) return;
    // Update drupalSettings for any components that need it.
    drupalSettings.aiSiteAudit.scoreDistribution = distribution;
  }

  /**
   * Poll analysis status while running.
   */
  function pollAnalysisStatus() {
    var settings = drupalSettings.aiSiteAudit || {};
    if (!settings.analysisStatusUrl) return;

    var statusEl = document.querySelector('[data-drupal-selector="ai-site-analysis-status"]');
    var interval = setInterval(function () {
      fetch(settings.analysisStatusUrl, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (data.success && data.data) {
            var state = data.data.state;

            if (statusEl) {
              if (state === 'complete') {
                statusEl.innerHTML = '<div class="messages messages--status">Analysis complete! Refreshing page…</div>';
                clearInterval(interval);
                setTimeout(function () { window.location.reload(); }, 1500);
              } else if (state === 'failed') {
                statusEl.innerHTML = '<div class="messages messages--error">Analysis failed. Check logs for details.</div>';
                clearInterval(interval);
              } else if (state !== 'idle') {
                statusEl.innerHTML = '<div class="ai-site-dashboard__status ai-site-dashboard__status--running">Analysis in progress: ' + state + '…</div>';
              }
            }
          }
        })
        .catch(function () {
          clearInterval(interval);
        });
    }, 3000);
  }

})(Drupal, drupalSettings, once);
