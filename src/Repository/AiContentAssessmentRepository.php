<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Repository;

use Drupal\ai_content_audit\Entity\AiContentAssessment;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;

/**
 * Repository for AiContentAssessment entity queries.
 *
 * Replaces the static query methods on the AiContentAssessment entity class,
 * enabling dependency injection and unit testing of callers.
 */
class AiContentAssessmentRepository {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns the most recent assessment for a given node ID.
   *
   * Mirrors AiContentAssessment::getLatestForNode() exactly, including
   * accessCheck(TRUE) so access control is respected for UI callers.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return \Drupal\ai_content_audit\Entity\AiContentAssessment|null
   *   The latest assessment entity, or NULL if none exists.
   */
  public function getLatestForNode(int $nid): ?AiContentAssessment {
    $storage = $this->entityTypeManager->getStorage('ai_content_assessment');
    $query = $storage->getQuery()
      ->condition('target_node', $nid)
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->accessCheck(TRUE);

    return $this->loadFirstResult($query, $storage);
  }

  /**
   * Returns all assessment entities for a given node ID, newest first.
   *
   * Mirrors AiContentAssessment::getAllForNode() exactly, including
   * accessCheck(TRUE) and the same default $limit of 10.
   *
   * @param int $nid
   *   The node ID.
   * @param int $limit
   *   Maximum number of assessments to return. Defaults to 10.
   *
   * @return \Drupal\ai_content_audit\Entity\AiContentAssessment[]
   *   Array of assessment entities, newest first.
   */
  public function getAllForNode(int $nid, int $limit = 10): array {
    $storage = $this->entityTypeManager->getStorage('ai_content_assessment');
    $query = $storage->getQuery()
      ->condition('target_node', $nid)
      ->sort('created', 'DESC')
      ->range(0, $limit)
      ->accessCheck(TRUE);

    $ids = $query->execute();
    if (!$ids) {
      return [];
    }

    $assessments = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      if ($entity instanceof AiContentAssessment) {
        $assessments[] = $entity;
      }
    }

    return $assessments;
  }

  /**
   * Executes the query and loads the first result.
   */
  private function loadFirstResult(QueryInterface $query, EntityStorageInterface $storage): ?AiContentAssessment {
    $ids = $query->execute();
    if (!$ids) {
      return NULL;
    }

    $entity = $storage->load(reset($ids));
    return $entity instanceof AiContentAssessment ? $entity : NULL;
  }

}
