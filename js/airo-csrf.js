/**
 * @file
 * Shared helpers for AIRO authenticated JSON requests.
 */
(function (Drupal) {
  'use strict';

  var csrfTokenPromise;

  function getCsrfToken() {
    if (!csrfTokenPromise) {
      csrfTokenPromise = fetch(Drupal.url('session/token'), {
        credentials: 'same-origin',
      }).then(function (response) {
        if (!response.ok) {
          throw new Error('Unable to fetch CSRF token.');
        }
        return response.text();
      });
    }
    return csrfTokenPromise;
  }

  Drupal.airoContentAudit = Drupal.airoContentAudit || {};

  Drupal.airoContentAudit.postJson = function (url, body) {
    return getCsrfToken().then(function (token) {
      return fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': token,
        },
        credentials: 'same-origin',
        body: body,
      });
    });
  };

})(Drupal);
