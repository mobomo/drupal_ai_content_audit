<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Hook;

use Drupal\ai_content_audit\Service\AiroNodeAnalysisFormAlterer;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Form and theme hooks for the AIRO Analysis native Layout/Edit pages.
 */
final class AiContentAuditFormHooks {

  public function __construct(
    protected AiroNodeAnalysisFormAlterer $formAlterer,
    protected RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Implements hook_form_alter().
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $this->formAlterer->alterForm($form, $form_state, $form_id);
  }

  /**
   * Adds a Gin LB sidebar theme suggestion on the AIRO Analysis route.
   */
  #[Hook('theme_suggestions_form_alter')]
  public function themeSuggestionsFormAlter(array &$suggestions, array $variables): void {
    if ($this->routeMatch->getRouteName() !== AiroNodeAnalysisFormAlterer::ROUTE_NAME) {
      return;
    }

    if (!str_contains($variables['element']['#form_id'] ?? '', 'layout_builder_form')) {
      return;
    }

    $suggestions = array_values(array_filter(
      $suggestions,
      static fn(string $suggestion): bool => $suggestion !== 'form__layout_builder_form__gin_lb',
    ));
    $suggestions[] = 'form__layout_builder_form__gin_lb__airo_analysis';
  }

  /**
   * Exposes the AIRO panel render array to the sidebar template.
   */
  #[Hook('preprocess_form')]
  public function preprocessForm(array &$variables): void {
    if ($this->routeMatch->getRouteName() !== AiroNodeAnalysisFormAlterer::ROUTE_NAME) {
      return;
    }

    $element = $variables['element'] ?? [];
    if (isset($element['#airo_analysis_panel'])) {
      $variables['airo_panel'] = $element['#airo_analysis_panel'];
    }
  }

  /**
   * Ensures the AIRO panel variable is set for the LB sidebar form template.
   */
  #[Hook('preprocess_form__layout_builder_form__gin_lb__airo_analysis')]
  public function preprocessAiroAnalysisLayoutForm(array &$variables): void {
    $element = $variables['element'] ?? [];
    if (isset($element['#airo_analysis_panel'])) {
      $variables['airo_panel'] = $element['#airo_analysis_panel'];
    }
  }

}
