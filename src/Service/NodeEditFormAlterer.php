<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\ai_content_audit\Repository\AiContentAssessmentRepository;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;

/**
 * Alters the node edit form to add AIRO sidebar widgets.
 */
final class NodeEditFormAlterer {

  use StringTranslationTrait;

  public function __construct(
    protected AiroAnalysisPanelBuilder $panelBuilder,
    protected AiContentAssessmentRepository $assessmentRepository,
    protected RouteMatchInterface $routeMatch,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Implements hook_form_node_form_alter() logic.
   */
  public function alterForm(array &$form, FormStateInterface $form_state, string $form_id): void {
    // AIRO Preview tab: panel in airo-analysis-node-page aside, not advanced.
    if ($this->routeMatch->getRouteName() === AiroNodeAnalysisFormAlterer::ROUTE_NAME) {
      self::stripAiroAnalysisTabSidebar($form);
      return;
    }

    $node = $form_state->getFormObject()->getEntity();

    if (!$node instanceof NodeInterface || $node->isNew()) {
      return;
    }

    $nodeId = (int) $node->id();
    $urls = $this->panelBuilder->buildActionUrls($node);
    $assessment = $this->assessmentRepository->getLatestForNode($nodeId);
    $tabPanes = $this->panelBuilder->buildTabPanes($node);
    $activeTab = array_key_first($tabPanes) ?: 'preview-tab';
    $showAssessmentActions = $this->panelBuilder->hasAssessmentTabs($tabPanes);

    $form['airo_analysis'] = [
      '#type' => 'details',
      '#title' => $this->t('AIRO Preview'),
      '#group' => 'advanced',
      '#accordion_item' => TRUE,
      '#attributes' => ['class' => ['accordion__item']],
      '#weight' => 11,
      'panel' => [
        '#theme' => 'ai_airo_accordion_item',
        '#node_id' => $nodeId,
        '#revision_id' => (int) $node->getRevisionId(),
        '#score' => $assessment?->getScore(),
        '#node_title' => $node->getTitle(),
        '#is_analyzing' => FALSE,
        '#active_tab' => $activeTab,
        '#tab_definitions' => $this->panelBuilder->buildTabDefinitions($node),
        '#tab_panes' => $tabPanes,
        '#show_assessment_actions' => $showAssessmentActions,
        '#assess_url' => $urls['assess_url'],
        '#full_report_url' => $urls['full_report_url'],
        '#attached' => ['library' => ['ai_content_audit/airo-panel']],
      ],
      '#attached' => ['library' => ['ai_content_audit/airo-panel']],
    ];

    $form_state->set('ai_assessment_nid', $nodeId);
  }

  /**
   * Removes Gin entity-meta / advanced sidebar panel from the node edit form.
   *
   * On the AIRO Preview tab without LB, the panel renders in the fixed aside.
   */
  public static function stripAiroAnalysisTabSidebar(array &$form): void {
    unset($form['airo_analysis']);
    if (isset($form['advanced'])) {
      $form['advanced']['#access'] = FALSE;
    }
    self::removeThemedPanelElements($form);
  }

  /**
   * Strips AIRO panel render arrays nested anywhere in the form tree.
   */
  private static function removeThemedPanelElements(array &$element): void {
    foreach (Element::children($element) as $key) {
      if (!is_array($element[$key])) {
        continue;
      }
      $theme = $element[$key]['#theme'] ?? NULL;
      $panel_theme = $element[$key]['panel']['#theme'] ?? NULL;
      if ($theme === 'ai_airo_accordion_item' || $panel_theme === 'ai_airo_accordion_item') {
        unset($element[$key]);
        continue;
      }
      self::removeThemedPanelElements($element[$key]);
    }
  }

  /**
   * After-build: Gin may reattach grouped fields after form_alter.
   */
  public static function afterBuildStripSidebarPanel(array $form, FormStateInterface $form_state): array {
    $classes = $form['#attributes']['class'] ?? [];
    if (!is_array($classes) || !in_array('airo-analysis-page__edit-form', $classes, TRUE)) {
      return $form;
    }
    self::stripAiroAnalysisTabSidebar($form);
    return $form;
  }

}
