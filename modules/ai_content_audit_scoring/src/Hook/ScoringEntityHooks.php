<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Hook;

use Drupal\ai_content_audit_scoring\Service\AiContentAuditLifecycle;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Entity and node lifecycle hooks.
 */
final class ScoringEntityHooks {

  public function __construct(
    #[Autowire(service: 'ai_content_audit_scoring.lifecycle')]
    protected AiContentAuditLifecycle $lifecycle,
  ) {}

  /**
   * Implements hook_entity_insert().
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    $this->lifecycle->invalidateListCacheForAssessment($entity);
  }

  /**
   * Implements hook_entity_update().
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    $this->lifecycle->invalidateListCacheForAssessment($entity);
  }

  /**
   * Implements hook_node_insert().
   */
  #[Hook('node_insert')]
  public function nodeInsert(NodeInterface $node): void {
    $this->lifecycle->maybeEnqueueNode($node);
  }

  /**
   * Implements hook_node_update().
   */
  #[Hook('node_update')]
  public function nodeUpdate(NodeInterface $node): void {
    $this->lifecycle->maybeEnqueueNode($node);
  }

  /**
   * Implements hook_node_delete().
   */
  #[Hook('node_delete')]
  public function nodeDelete(NodeInterface $node): void {
    $this->lifecycle->deleteAssessmentsForDeletedNode($node);
  }

}
