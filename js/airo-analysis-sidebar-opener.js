/**
 * @file
 * Auto-open Gin meta-sidebar on `/node/{node}/airo-analysis` (LB and Edit sin LB).
 */
(function (Drupal, once) {
  'use strict';

  var STORAGE_DESKTOP = 'Drupal.gin.sidebarExpanded.desktop';
  var STORAGE_MOBILE = 'Drupal.gin.sidebarExpanded.mobile';
  var SESSION_KEY_LB = 'airo-analysis-sidebar-initialized-lb';
  var SESSION_KEY_EDIT = 'airo-analysis-sidebar-initialized-edit';

  function isAiroRoute() {
    return document.body && document.body.classList.contains('airo-analysis-route');
  }

  function isLayoutBuilderSidebar() {
    return !!document.querySelector(
      '.airo-analysis-route #gin_sidebar form.layout-builder-form'
    );
  }

  function isEditFormPanel() {
    return !!document.querySelector('.airo-analysis-route .airo-analysis-page--edit-form');
  }

  /**
   * Opens Gin meta-sidebar once per tab (per layout mode).
   *
   * @param {string} sessionKey
   */
  function openMetaSidebar(sessionKey) {
    if (sessionStorage.getItem(sessionKey) === '1') {
      return;
    }

    try {
      localStorage.setItem(STORAGE_DESKTOP, 'true');
      localStorage.setItem(STORAGE_MOBILE, 'true');
    }
    catch (e) {
      // localStorage may be unavailable.
    }

    document.body.setAttribute('data-meta-sidebar', 'open');

    if (Drupal.ginSidebar && typeof Drupal.ginSidebar.showSidebar === 'function') {
      Drupal.ginSidebar.showSidebar();
    }
    else {
      var trigger = document.querySelector('.meta-sidebar__trigger');
      if (trigger && !trigger.classList.contains('is-active')) {
        trigger.click();
      }
    }

    if (document.body.getAttribute('data-meta-sidebar') === 'open') {
      sessionStorage.setItem(sessionKey, '1');
    }
  }

  Drupal.behaviors.airoAnalysisSidebarOpener = {
    attach: function (context) {
      if (!isAiroRoute()) {
        return;
      }

      once('airo-analysis-sidebar-opener', 'body', context).forEach(function () {
        if (isLayoutBuilderSidebar()) {
          openMetaSidebar(SESSION_KEY_LB);
          if (!document.querySelector('#edit-airo-panel-slot')) {
            window.setTimeout(function () {
              openMetaSidebar(SESSION_KEY_LB);
            }, 150);
          }
        }
        else if (isEditFormPanel()) {
          openMetaSidebar(SESSION_KEY_EDIT);
        }
      });
    },
  };

})(Drupal, once);
