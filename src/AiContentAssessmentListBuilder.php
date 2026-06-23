<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a listing of AI Content Assessment entities.
 */
class AiContentAssessmentListBuilder extends EntityListBuilder {

  /**
   * Prefetched user objects keyed by user ID.
   *
   * @var array<int, \Drupal\user\UserInterface>
   */
  private array $runByUsers = [];

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(
    ContainerInterface $container,
    EntityTypeInterface $entity_type,
  ): static {
    $entityTypeManager = $container->get('entity_type.manager');
    return new static(
      $entity_type,
      $entityTypeManager->getStorage($entity_type->id()),
      $container->get('date.formatter'),
      $entityTypeManager,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function load() {
    $entities = parent::load();
    $this->prefetchRunByUsers($entities);
    return $entities;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'node'       => $this->t('Assessed node'),
      'score'      => $this->t('Score'),
      'provider'   => $this->t('Provider'),
      'model'      => $this->t('Model'),
      'run_by'     => $this->t('Run by'),
      'created'    => $this->t('Date'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\ai_content_audit\Entity\AiContentAssessment $entity */
    $node = $entity->getTargetNode();

    $run_by_uid = $entity->get('run_by')->target_id;

    // Node title link or fallback.
    if ($node) {
      $node_cell = [
        'data' => [
          '#type'  => 'link',
          '#title' => $node->label(),
          '#url'   => $node->toUrl('canonical'),
        ],
      ];
    }
    else {
      $node_cell = $this->t('(deleted)');
    }

    // User name or fallback.
    if ($run_by_uid) {
      $user = $this->runByUsers[$run_by_uid] ?? NULL;
      $run_by_cell = $user ? $user->getDisplayName() : $this->t('Unknown (@uid)', ['@uid' => $run_by_uid]);
    }
    else {
      $run_by_cell = $this->t('Cron/queue');
    }

    $score_value = $entity->getScore();

    return [
      'node'    => $node_cell,
      'score'   => $score_value !== NULL ? $score_value . '/100' : $this->t('n/a'),
      'provider' => $entity->get('provider_id')->value ?? '—',
      'model'   => $entity->get('model_id')->value ?? '—',
      'run_by'  => $run_by_cell,
      'created' => $this->dateFormatter->format(
        (int) $entity->get('created')->value,
        'short',
      ),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);
    if ($entity->access('delete') && $entity->hasLinkTemplate('delete-form')) {
      $operations['delete'] = [
        'title'  => $this->t('Delete'),
        'weight' => 100,
        'url'    => $entity->toUrl('delete-form'),
      ];
    }
    return $operations;
  }

  /**
   * Prefetches user entities for run_by references to avoid N+1 queries.
   *
   * @param \Drupal\ai_content_audit\Entity\AiContentAssessment[] $entities
   *   The loaded assessment entities.
   */
  private function prefetchRunByUsers(array $entities): void {
    $uids = [];
    foreach ($entities as $entity) {
      $uid = $entity->get('run_by')->target_id;
      if ($uid) {
        $uids[$uid] = TRUE;
      }
    }

    if (!$uids) {
      $this->runByUsers = [];
      return;
    }

    $this->runByUsers = $this->entityTypeManager->getStorage('user')->loadMultiple(array_keys($uids));
  }

}
