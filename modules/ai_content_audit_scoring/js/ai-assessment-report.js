/**
 * @file
 * JavaScript for the AI Assessment Report page.
 *
 * Handles copy-to-clipboard functionality for all .ai-report__copy-btn elements.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.aiAssessmentReport = {
    attach(context) {
      once('ai-report-copy', '.ai-report__copy-btn', context).forEach((btn) => {
        btn.addEventListener('click', () => {
          const targetId = btn.dataset.copyTarget;
          if (!targetId) return;

          const target = context.querySelector
            ? context.querySelector('#' + CSS.escape(targetId))
            : document.getElementById(targetId);

          if (!target) return;

          const text = target.innerText || target.textContent || '';

          navigator.clipboard.writeText(text).then(() => {
            const original = btn.textContent;
            btn.textContent = 'Copied!';
            btn.classList.add('copied');
            setTimeout(() => {
              btn.textContent = original;
              btn.classList.remove('copied');
            }, 2000);
          }).catch(() => {
            // Fallback for older browsers / HTTP contexts.
            const range = document.createRange();
            range.selectNodeContents(target);
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
            try {
              document.execCommand('copy');
              const original = btn.textContent;
              btn.textContent = 'Copied!';
              btn.classList.add('copied');
              setTimeout(() => {
                btn.textContent = original;
                btn.classList.remove('copied');
              }, 2000);
            } catch (e) {
              // Silent fail — clipboard not available.
            }
            sel.removeAllRanges();
          });
        });
      });
    },
  };

}(Drupal, once));
