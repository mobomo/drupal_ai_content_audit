<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\ai_content_audit\Entity\AiContentAssessment;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Entity/node/cron side effects for assessments (cache, queue, purge).
 */
final class AiContentAuditLifecycle {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected QueueFactory $queueFactory,
    protected Connection $database,
    protected LoggerInterface $logger,
  ) {}

  public function invalidateListCacheForAssessment(EntityInterface $entity): void {
    if ($entity instanceof AiContentAssessment) {
      $nid = (int) $entity->get('target_node')->target_id;
      if ($nid) {
        Cache::invalidateTags(['ai_content_assessment_list:node:' . $nid]);
      }
    }
  }

  public function maybeEnqueueNode(NodeInterface $node): void {
    $config = $this->configFactory->get('ai_content_audit.settings');
    if (!$config->get('enable_on_save')) {
      return;
    }

    $allowed_types = $config->get('node_types') ?? [];
    if (!empty($allowed_types) && !in_array($node->bundle(), $allowed_types, TRUE)) {
      return;
    }

    $this->queueFactory->get('ai_content_audit_assessment')->createItem([
      'nid' => (int) $node->id(),
    ]);
  }

  public function deleteAssessmentsForDeletedNode(NodeInterface $node): void {
    $storage = $this->entityTypeManager->getStorage('ai_content_assessment');
    $ids = $storage->getQuery()
      ->condition('target_node', $node->id())
      ->accessCheck(FALSE)
      ->execute();

    if ($ids) {
      $assessments = $storage->loadMultiple($ids);
      $storage->delete($assessments);
      $this->logger->info(
        'Deleted @count AI assessment(s) for deleted node @nid.',
        ['@count' => count($ids), '@nid' => $node->id()]
      );
    }
  }

  public function enqueueExcessAssessmentsForPurge(): void {
    $max = (int) $this->configFactory->get('ai_content_audit.settings')
      ->get('max_assessments_per_node');

    if ($max <= 0) {
      return;
    }

    $node_ids = $this->database->select('ai_content_assessment', 'a')
      ->fields('a', ['target_node_target_id'])
      ->groupBy('a.target_node_target_id')
      ->having('COUNT(a.id) > :max', [':max' => $max])
      ->execute()
      ->fetchCol();

    if (empty($node_ids)) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('ai_content_assessment');
    $purge_ids = [];
    foreach ($node_ids as $nid) {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('target_node', $nid)
        ->sort('created', 'DESC')
        ->execute();

      $excess = array_slice(array_values($ids), $max);
      foreach ($excess as $id) {
        $purge_ids[] = $id;
      }
    }

    if (empty($purge_ids)) {
      return;
    }

    $queue = $this->queueFactory->get('ai_content_audit_purge');
    foreach (array_chunk($purge_ids, 50) as $chunk) {
      $queue->createItem(['ids' => $chunk]);
    }

    $this->logger->info(
      'Enqueued @total excess assessment ID(s) across @nodes node(s) for purging (retention limit: @max, chunks: @chunks).',
      [
        '@total' => count($purge_ids),
        '@nodes' => count($node_ids),
        '@max' => $max,
        '@chunks' => count(array_chunk($purge_ids, 50)),
      ],
    );
  }

}
