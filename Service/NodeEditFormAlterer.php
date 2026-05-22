<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\ai_content_audit\Repository\AiContentAssessmentRepository;
use Drupal\Core\Form\FormStateInterface;
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
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Implements hook_form_node_form_alter() logic.
   */
  public function alterForm(array &$form, FormStateInterface $form_state, string $form_id): void {
    $node = $form_state->getFormObject()->getEntity();

    if (!$node instanceof NodeInterface || $node->isNew()) {
      return;
    }

    $nodeId = (int) $node->id();
    $urls = $this->panelBuilder->buildActionUrls($node);
    $assessment = $this->assessmentRepository->getLatestForNode($nodeId);

    $form['airo_analysis'] = [
      '#type' => 'details',
      '#title' => $this->t('AIRO Analysis'),
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
        '#active_tab' => 'preview-tab',
        '#tab_panes' => $this->panelBuilder->buildTabPanes($node),
        '#assess_url' => $urls['assess_url'],
        '#full_report_url' => $urls['full_report_url'],
        '#attached' => ['library' => ['ai_content_audit/airo-panel']],
      ],
      '#attached' => ['library' => ['ai_content_audit/airo-panel']],
    ];

    $form_state->set('ai_assessment_nid', $nodeId);
  }

}
