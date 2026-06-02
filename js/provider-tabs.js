/**
 * @file
 * Provider tabs — collapsible compare section with ARIA-accessible tab panels.
 */
(function (Drupal, once) {
  'use strict';

  /**
   * @param {Element} tab
   * @param {NodeList|Array} tabs
   * @param {Element} compare
   */
  function activateTab(tab, tabs, compare) {
    tabs.forEach(function (t) {
      t.setAttribute('aria-selected', 'false');
      t.classList.remove('is-active');
      t.setAttribute('tabindex', '-1');
      if (t.parentElement) {
        t.parentElement.classList.remove('is-active');
      }
    });

    var panels = compare.querySelectorAll('[role="tabpanel"]');
    panels.forEach(function (p) {
      p.setAttribute('hidden', '');
    });

    tab.setAttribute('aria-selected', 'true');
    tab.classList.add('is-active');
    tab.setAttribute('tabindex', '0');
    if (tab.parentElement) {
      tab.parentElement.classList.add('is-active');
    }

    var targetKey = tab.getAttribute('data-ai-tab-target');
    var panel = compare.querySelector('[data-ai-tab="' + targetKey + '"]');
    if (panel) {
      panel.removeAttribute('hidden');
    }
  }

  /**
   * @param {Element} tabsRoot Element containing [role="tab"] buttons.
   * @param {Element} compare  Element containing [role="tabpanel"] panels.
   */
  function wireProviderTabs(tabsRoot, compare) {
    var tabs = tabsRoot.querySelectorAll('[role="tab"]');
    if (!tabs.length || !compare) {
      return;
    }

    tabs.forEach(function (tab) {
      tab.addEventListener('click', function (e) {
        e.preventDefault();
        activateTab(tab, tabs, compare);
      });

      tab.addEventListener('keydown', function (e) {
        var idx = Array.prototype.indexOf.call(tabs, tab);
        var target = null;

        if (e.key === 'ArrowRight') {
          e.preventDefault();
          target = tabs[(idx + 1) % tabs.length];
        }
        else if (e.key === 'ArrowLeft') {
          e.preventDefault();
          target = tabs[(idx - 1 + tabs.length) % tabs.length];
        }
        else if (e.key === 'Home') {
          e.preventDefault();
          target = tabs[0];
        }
        else if (e.key === 'End') {
          e.preventDefault();
          target = tabs[tabs.length - 1];
        }

        if (target) {
          target.focus();
          activateTab(target, tabs, compare);
        }
      });
    });
  }

  Drupal.behaviors.aiContentAuditProviderTabs = {
    attach: function (context) {
      once('ai-content-audit-provider-tabs', '.ai-content-audit-compare--results', context)
        .forEach(function (compare) {
          wireProviderTabs(compare, compare);
        });

      once('ai-content-audit-provider-tabs-nav', '.airo-preview__provider-tabs-nav', context)
        .forEach(function (nav) {
          var host = nav.closest('.airo-preview__provider-tabs-host');
          if (!host) {
            return;
          }
          var compareId = host.getAttribute('data-compare-id');
          var compare = compareId ? document.getElementById(compareId) : null;
          wireProviderTabs(nav, compare);
        });
    },
  };

})(Drupal, once);
