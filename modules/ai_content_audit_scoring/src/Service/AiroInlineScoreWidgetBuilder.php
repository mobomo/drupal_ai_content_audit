<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Service;

use Drupal\ai_content_audit\Repository\AiContentAssessmentRepository;
use Drupal\node\NodeInterface;

/**
 * Builds the inline AIRO score widget render array.
 */
final class AiroInlineScoreWidgetBuilder {

  public function __construct(
    protected AiContentAssessmentRepository $assessmentRepository,
  ) {}

  /**
   * Builds the widget render array for a node.
   */
  public function build(NodeInterface $node): array {
    $assessment = $this->assessmentRepository->getLatestForNode((int) $node->id());
    $actionItems = $assessment?->getActionItems() ?? [];
    $highPriorityCount = count(array_filter(
      $actionItems,
      static fn(array $item): bool => ($item['priority'] ?? 'low') === 'high'
    ));

    return [
      '#theme' => 'ai_inline_score_widget',
      '#node_id' => $node->id(),
      '#revision_id' => (int) $node->getRevisionId(),
      '#score' => $assessment?->getScore(),
      '#is_analyzing' => FALSE,
      '#has_assessment' => $assessment !== NULL,
      '#high_priority_count' => $highPriorityCount,
      '#attached' => [
        'library' => [
          'ai_content_audit/inline-widget',
        ],
      ],
    ];
  }

}
