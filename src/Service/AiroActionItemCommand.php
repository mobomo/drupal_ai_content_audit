<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\ai_content_audit\Repository\AiContentAssessmentRepository;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Mutates AIRO action item completion state.
 */
final class AiroActionItemCommand {

  public function __construct(
    protected AiContentAssessmentRepository $assessmentRepository,
    protected AccountInterface $currentUser,
    protected TimeInterface $time,
  ) {}

  /**
   * Toggles the completion state of an action item.
   *
   * @return array{status_code: int, payload: array<string, mixed>}
   *   HTTP status code and JSON payload for the controller response.
   */
  public function toggle(NodeInterface $node, string $itemId, bool $completed): array {
    $assessment = $this->assessmentRepository->getLatestForNode((int) $node->id());
    if ($assessment === NULL) {
      return [
        'status_code' => 404,
        'payload' => ['error' => 'No assessment found'],
      ];
    }

    $status = $assessment->getActionItemsStatus() ?? [];
    if ($completed) {
      $status[$itemId] = [
        'completed' => TRUE,
        'completed_by' => (int) $this->currentUser->id(),
        'completed_at' => date('c', $this->time->getRequestTime()),
      ];
    }
    else {
      unset($status[$itemId]);
    }

    $assessment->setActionItemsStatus($status);
    $assessment->save();

    $completedCount = 0;
    foreach ($status as $itemStatus) {
      if (!empty($itemStatus['completed'])) {
        $completedCount++;
      }
    }

    return [
      'status_code' => 200,
      'payload' => [
        'status' => 'ok',
        'item_id' => $itemId,
        'completed' => $completed,
        'completed_count' => $completedCount,
      ],
    ];
  }

}
