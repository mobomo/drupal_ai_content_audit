/**
 * @file
 * Auto-open Gin LB sidebar on `/node/{node}/airo-analysis` when Layout Builder is on.
 *
 * Edit without LB uses the two-column page template; no Gin meta-sidebar toggle.
 */
(function (Drupal, once) {
  'use strict';

  var STORAGE_DESKTOP = 'Drupal.gin.sidebarExpanded.desktop';
  var STORAGE_MOBILE = 'Drupal.gin.sidebarExpanded.mobile';
  var SESSION_KEY_LB = 'airo-analysis-sidebar-initialized-lb';

  function isAiroRoute() {
    return document.body && document.body.classList.contains('airo-analysis-route');
  }

  function isLayoutBuilderSidebar() {
    return !!document.querySelector(
      '.airo-analysis-route #gin_sidebar form.layout-builder-form'
    );
  }

  /**
   * Opens Gin LB #gin_sidebar once per tab (Layout Builder nodes only).
   */
  function openLayoutBuilderSidebar() {
    if (sessionStorage.getItem(SESSION_KEY_LB) === '1') {
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
      sessionStorage.setItem(SESSION_KEY_LB, '1');
    }
  }

  Drupal.behaviors.airoAnalysisSidebarOpener = {
    attach: function (context) {
      if (!isAiroRoute() || !isLayoutBuilderSidebar()) {
        return;
      }
      once('airo-analysis-sidebar-opener-lb', 'body', context).forEach(function () {
        openLayoutBuilderSidebar();
        if (!document.querySelector('#edit-airo-panel-slot')) {
          window.setTimeout(openLayoutBuilderSidebar, 150);
        }
      });
    },
  };

})(Drupal, once);
