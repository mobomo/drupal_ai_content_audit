<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Purges excess AI content assessment entities.
 *
 * Receives a chunk of entity IDs to delete (enqueued by hook_cron) and
 * deletes them in one bounded batch.  Using a queue worker instead of
 * deleting inline in hook_cron prevents PHP memory / timeout issues on
 * sites with many nodes and many assessments.
 */
#[QueueWorker(
  id: 'ai_content_audit_purge',
  title: new TranslatableMarkup('AI Content Audit: Purge excess assessments'),
  cron: ['time' => 30],
)]
class AiAssessmentPurgeWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs an AiAssessmentPurgeWorker.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @param array $data
   *   Associative array with key 'ids' — an array of ai_content_assessment
   *   entity IDs to delete.
   */
  public function processItem($data): void {
    $ids = $data['ids'] ?? [];
    if (empty($ids)) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('ai_content_assessment');
    $entities = $storage->loadMultiple($ids);
    if (empty($entities)) {
      return;
    }

    $storage->delete($entities);
    \Drupal::logger('ai_content_audit')->info(
      'Purge queue worker deleted @count excess assessment(s).',
      ['@count' => count($entities)],
    );
  }

}
