/**
 * @file
 * Auto-open Gin's meta sidebar and the AIRO Analysis accordion on
 * `/node/{node}/airo-analysis`.
 */
(function (Drupal, once) {
  'use strict';

  var STORAGE_DESKTOP = 'Drupal.gin.sidebarExpanded.desktop';
  var STORAGE_MOBILE = 'Drupal.gin.sidebarExpanded.mobile';

  function isAiroRoute() {
    return document.body && document.body.classList.contains('airo-analysis-route');
  }

  function isLayoutBuilderSidebar() {
    return !!document.querySelector(
      '.airo-analysis-route #gin_sidebar form.layout-builder-form'
    );
  }

  /**
   * Opens Gin / Gin LB sidebar once on load (does not fight the toggle afterward).
   */
  function openGinSidebar() {
    if (sessionStorage.getItem('airo-analysis-sidebar-initialized') === '1') {
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

    sessionStorage.setItem('airo-analysis-sidebar-initialized', '1');
  }

  /**
   * Expands the AIRO Analysis <details> (native edit form path).
   */
  function openAiroDetails() {
    var selectors = [
      '#edit-airo-analysis',
      'details[data-drupal-selector="edit-airo-analysis"]',
      '.layout-region__content details.accordion__item[id*="airo"]',
    ];
    var details = null;
    for (var i = 0; i < selectors.length; i++) {
      details = document.querySelector(selectors[i]);
      if (details) {
        break;
      }
    }
    if (!details) {
      return;
    }
    details.open = true;
    details.setAttribute('open', 'open');
    var summary = details.querySelector('summary');
    if (summary) {
      summary.setAttribute('aria-expanded', 'true');
    }
  }

  function openSidebarAndAccordion() {
    openGinSidebar();
    if (!isLayoutBuilderSidebar()) {
      openAiroDetails();
    }
  }

  Drupal.behaviors.airoAnalysisSidebarOpener = {
    attach: function (context) {
      if (!isAiroRoute()) {
        return;
      }
      once('airo-analysis-sidebar-opener', 'body', context).forEach(function () {
        openSidebarAndAccordion();
        if (!document.querySelector('#edit-airo-panel-slot, #edit-airo-analysis')) {
          window.setTimeout(openSidebarAndAccordion, 150);
        }
      });
    },
  };

})(Drupal, once);
