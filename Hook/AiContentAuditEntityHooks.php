<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Hook;

use Drupal\ai_content_audit\Service\AiContentAuditLifecycle;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\node\NodeInterface;

/**
 * Entity and node lifecycle hooks.
 */
final class AiContentAuditEntityHooks {

  public function __construct(
    protected AiContentAuditLifecycle $lifecycle,
  ) {}

  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    $this->lifecycle->invalidateListCacheForAssessment($entity);
  }

  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    $this->lifecycle->invalidateListCacheForAssessment($entity);
  }

  #[Hook('node_insert')]
  public function nodeInsert(NodeInterface $node): void {
    $this->lifecycle->maybeEnqueueNode($node);
  }

  #[Hook('node_update')]
  public function nodeUpdate(NodeInterface $node): void {
    $this->lifecycle->maybeEnqueueNode($node);
  }

  #[Hook('node_delete')]
  public function nodeDelete(NodeInterface $node): void {
    $this->lifecycle->deleteAssessmentsForDeletedNode($node);
  }

}
