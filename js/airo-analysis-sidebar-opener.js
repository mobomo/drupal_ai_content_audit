/**
 * @file
 * Auto-open the AIRO side panel on `/node/{node}/airo-analysis`.
 */
(function (Drupal, once) {
  'use strict';

  var SESSION_KEY_LB = 'airo-analysis-sidebar-initialized-lb';
  var SESSION_KEY_EDIT = 'airo-analysis-sidebar-initialized-edit';

  function isAiroRoute() {
    return document.body && document.body.classList.contains('airo-analysis-route');
  }

  function isLayoutBuilderSidebar() {
    return !!document.querySelector(
      '.airo-analysis-route .airo-analysis-page--layout-builder .airo-analysis-page__panel'
    );
  }

  function isEditFormPanel() {
    return !!document.querySelector('.airo-analysis-route .airo-analysis-page--edit-form');
  }

  /**
   * Sets the AIRO side panel state.
   *
   * @param {boolean} open
   *   TRUE to open the AIRO side panel.
   */
  function setSidePanelOpen(open) {
    document.body.setAttribute('data-meta-sidebar', open ? 'open' : 'closed');
  }

  /**
   * Binds the Gin sidebar trigger to the AIRO side panel when Gin is present.
   */
  function bindGinSidebarTrigger(context) {
    once('airo-analysis-gin-sidebar-trigger', '.meta-sidebar__trigger', context).forEach(function (trigger) {
      trigger.addEventListener('click', function () {
        var nextOpen = document.body.getAttribute('data-meta-sidebar') !== 'open';

        window.setTimeout(function () {
          setSidePanelOpen(nextOpen);
        }, 0);
        window.setTimeout(function () {
          setSidePanelOpen(nextOpen);
        }, 100);
      }, true);
    });
  }

  /**
   * Opens the side panel once per tab (per layout mode).
   *
   * @param {string} sessionKey
   */
  function openSidePanel(sessionKey) {
    setSidePanelOpen(true);

    if (
      document.body.getAttribute('data-meta-sidebar') === 'open' &&
      sessionStorage.getItem(sessionKey) !== '1'
    ) {
      sessionStorage.setItem(sessionKey, '1');
    }
  }

  Drupal.behaviors.airoAnalysisSidebarOpener = {
    attach: function (context) {
      if (!isAiroRoute()) {
        return;
      }

      once('airo-analysis-sidebar-opener', 'body', context).forEach(function () {
        bindGinSidebarTrigger(context);

        if (isLayoutBuilderSidebar()) {
          openSidePanel(SESSION_KEY_LB);
        }
        else if (isEditFormPanel()) {
          openSidePanel(SESSION_KEY_EDIT);
        }
      });
    },
  };

})(Drupal, once);
