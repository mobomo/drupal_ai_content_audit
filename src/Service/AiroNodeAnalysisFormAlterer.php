<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;

/**
 * Alters native Layout/Edit forms on the AIRO Analysis tab.
 */
final class AiroNodeAnalysisFormAlterer {

  use StringTranslationTrait;

  public const ROUTE_NAME = 'ai_content_audit.node.airo_analysis';

  public function __construct(
    protected AiroAnalysisPanelBuilder $panelBuilder,
    protected RouteMatchInterface $routeMatch,
    protected NodeLayoutBuilderDetector $layoutBuilderDetector,
    protected AiroGinLayoutBuilderAdapter $ginLayoutBuilderAdapter,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Attaches AIRO panel libraries and form metadata for sidebar replacement.
   */
  public function alterForm(array &$form, FormStateInterface $form_state, string $form_id): void {
    if ($this->routeMatch->getRouteName() !== self::ROUTE_NAME) {
      return;
    }

    $form_object = $form_state->getFormObject();
    if (!method_exists($form_object, 'getEntity')) {
      return;
    }

    $entity = $form_object->getEntity();
    if (!$entity instanceof NodeInterface) {
      return;
    }

    $panel = $this->panelBuilder->build($entity, ['variant' => 'page']);
    $form['#airo_analysis_panel'] = $panel;

    $form['#attached']['library'][] = 'ai_content_audit/airo-panel';
    $form['#attached']['library'][] = 'ai_content_audit/airo-panel-page-skin';
    $form['#attached']['library'][] = 'ai_content_audit/airo-analysis-page';

    if ($this->layoutBuilderDetector->isLayoutBuilderEnabled($entity)
      && str_contains($form_id, 'layout_builder_form')) {
      $form['#attributes']['class'][] = 'airo-analysis-layout-builder-form';
      $this->ginLayoutBuilderAdapter->attachForm($form);
      return;
    }

    // Non-Layout Builder forms render the panel in the page aside.
    $form['#attributes']['class'][] = 'airo-analysis-page__edit-form';
    NodeEditFormAlterer::stripAiroAnalysisTabSidebar($form);
    $form['#after_build'][] = [NodeEditFormAlterer::class, 'afterBuildStripSidebarPanel'];
  }

}
