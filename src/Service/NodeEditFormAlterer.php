<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\ai_content_audit\Controller\AiroPanelController;
use Drupal\ai_content_audit\Repository\AiContentAssessmentRepository;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Alters the node edit form to add AIRO sidebar widgets (delegates tab build to controller).
 */
final class NodeEditFormAlterer {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AiContentAssessmentRepository $assessmentRepository,
    protected AiroPanelController $airoPanelController,
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

    $node_id = (int) $node->id();
    $assessment = $this->assessmentRepository->getLatestForNode($node_id);
    $score = $assessment?->getScore();
    $has_assessment = $assessment !== NULL;

    $high_priority_count = 0;
    if ($assessment && !$assessment->get('action_items')->isEmpty()) {
      $raw = $assessment->get('action_items')->value;
      if ($raw) {
        $items = json_decode($raw, TRUE) ?? [];
        foreach ($items as $item) {
          if (($item['priority'] ?? '') === 'high') {
            $high_priority_count++;
          }
        }
      }
    }

    $form['ai_inline_score_widget'] = [
      '#type' => 'container',
      '#weight' => 10,
      '#attributes' => ['id' => 'ai-inline-score-widget-wrapper'],
      'widget' => [
        '#theme' => 'ai_inline_score_widget',
        '#score' => $score,
        '#is_analyzing' => FALSE,
        '#has_assessment' => $has_assessment,
        '#high_priority_count' => $high_priority_count,
        '#node_id' => $node_id,
        '#revision_id' => (int) $node->getRevisionId(),
      ],
      '#attached' => [
        'library' => [
          'ai_content_audit/inline-widget',
        ],
      ],
    ];

    $assessment_storage = $this->entityTypeManager->getStorage('ai_content_assessment');
    $assessment_ids = $assessment_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('target_node', $node_id)
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();
    $assessment_entity = !empty($assessment_ids)
      ? $assessment_storage->load(reset($assessment_ids))
      : NULL;

    $assess_url = Url::fromRoute(
      'ai_content_audit.panel.assess',
      ['node' => $node_id]
    )->toString();

    $full_report_url = $assessment_entity
      ? Url::fromRoute(
        'ai_content_audit.assessment.report',
        ['ai_content_assessment' => $assessment_entity->id()]
      )->toString()
      : NULL;

    $form['airo_analysis'] = [
      '#type' => 'details',
      '#title' => $this->t('AIRO Analysis'),
      '#group' => 'advanced',
      '#accordion_item' => TRUE,
      '#attributes' => ['class' => ['accordion__item']],
      '#weight' => 11,
      'panel' => [
        '#theme' => 'ai_airo_accordion_item',
        '#node_id' => $node_id,
        '#revision_id' => (int) $node->getRevisionId(),
        '#score' => $score,
        '#node_title' => $node->getTitle(),
        '#is_analyzing' => FALSE,
        '#active_tab' => 'preview-tab',
        '#tab_panes' => [
          'preview-tab' => $this->airoPanelController->buildPreviewTab($node),
          'score-tab' => $this->airoPanelController->buildScoreTab($node, $assessment_entity),
          'action-items-tab' => $this->airoPanelController->buildActionItemsTab($node),
          'technical-audit-tab' => $this->airoPanelController->buildTechnicalAuditTab($node),
        ],
        '#assess_url' => $assess_url,
        '#full_report_url' => $full_report_url,
        '#attached' => ['library' => ['ai_content_audit/airo-panel']],
      ],
      '#attached' => ['library' => ['ai_content_audit/airo-panel']],
    ];

    $form_state->set('ai_assessment_nid', $node_id);
  }

}
