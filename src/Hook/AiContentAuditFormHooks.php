<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Hook;

use Drupal\ai_content_audit\Service\AiroNodeAnalysisFormAlterer;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Form and theme hooks for the AIRO Analysis native Layout/Edit pages.
 */
final class AiContentAuditFormHooks {

  public function __construct(
    #[Autowire(service: 'ai_content_audit.airo_node_analysis_form_alterer')]
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

}
