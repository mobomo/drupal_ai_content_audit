/**
 * @file
 * Local interactions for the AIRO Readiness Checkpoints widget.
 */
(function (Drupal, once) {
  'use strict';

  function getPostBody(widget) {
    var revisionId = widget.getAttribute('data-revision-id') || '';
    if (!revisionId) {
      return '{}';
    }
    return JSON.stringify({ revision_id: parseInt(revisionId, 10) });
  }

  function getCard(widget) {
    return widget.querySelector('.airo-readiness__card');
  }

  function removeStatus(widget) {
    widget
      .querySelectorAll('.airo-readiness__status')
      .forEach(function (status) {
        status.remove();
      });
  }

  function setButtonsDisabled(widget, disabled) {
    widget
      .querySelectorAll('.airo-readiness__btn')
      .forEach(function (button) {
        button.disabled = disabled || button.hasAttribute('data-originally-disabled');
      });
  }

  function rememberInitialButtonState(widget) {
    widget
      .querySelectorAll('.airo-readiness__btn')
      .forEach(function (button) {
        if (button.disabled) {
          button.setAttribute('data-originally-disabled', '1');
        }
      });
  }

  function setStatus(widget, message, type) {
    var card = getCard(widget);
    if (!card) {
      return;
    }

    removeStatus(widget);

    var status = document.createElement('div');
    status.className = 'airo-readiness__status airo-readiness__status--' + type;
    if (type === 'error') {
      status.setAttribute('role', 'alert');
    }
    else {
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
    }
    status.textContent = message;
    card.insertBefore(status, card.firstChild);
  }

  function setLoading(widget, loading) {
    widget.classList.toggle('is-analyzing', loading);
    widget.setAttribute('aria-busy', loading ? 'true' : 'false');
    setButtonsDisabled(widget, loading);
    if (loading) {
      setStatus(widget, Drupal.t('Analyzing content...'), 'loading');
    }
  }

  function showError(widget, message) {
    widget.classList.remove('is-analyzing');
    widget.setAttribute('aria-busy', 'false');
    setButtonsDisabled(widget, false);
    setStatus(widget, message || Drupal.t('Analysis failed. Please try again.'), 'error');
  }

  function refreshWidget(widget, refreshUrl) {
    var ajax = Drupal.ajax({ url: refreshUrl });
    return ajax.execute();
  }

  Drupal.behaviors.airoReadinessCheckpoints = {
    attach: function (context) {
      once(
        'airo-readiness-analyze',
        '.airo-readiness [data-airo-action="readiness-analyze"]',
        context
      ).forEach(function (button) {
        button.addEventListener('click', function (event) {
          event.preventDefault();

          var widget = button.closest('.airo-readiness');
          var assessUrl = button.getAttribute('data-assess-url');
          var refreshUrl = widget ? widget.getAttribute('data-readiness-refresh-url') : '';

          if (!widget || !assessUrl || !refreshUrl || !Drupal.airoContentAudit) {
            return;
          }

          rememberInitialButtonState(widget);
          setLoading(widget, true);

          Drupal.airoContentAudit.postJson(assessUrl, getPostBody(widget))
            .then(function (response) {
              if (!response.ok) {
                throw new Error(Drupal.t('Analysis failed. Please try again.'));
              }
              return response.json();
            })
            .then(function () {
              return refreshWidget(widget, refreshUrl);
            })
            .catch(function (error) {
              showError(widget, error && error.message);
            });
        });
      });
    },

    detach: function (context, settings, trigger) {
      if (trigger === 'unload') {
        once.remove(
          'airo-readiness-analyze',
          '.airo-readiness [data-airo-action="readiness-analyze"]',
          context
        );
      }
    },
  };

})(Drupal, once);
