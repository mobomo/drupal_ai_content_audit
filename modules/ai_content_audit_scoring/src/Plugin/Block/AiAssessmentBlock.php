<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit_scoring\Plugin\Block;

use Drupal\ai_content_audit_scoring\Repository\AiContentAssessmentRepository;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an AI Assessment Summary block for node pages.
 */
#[Block(
  id: 'ai_assessment_block',
  admin_label: new TranslatableMarkup('AI Assessment Summary'),
  category: new TranslatableMarkup('AI Content Audit'),
)]
class AiAssessmentBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly RouteMatchInterface $routeMatch,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly AiContentAssessmentRepository $assessmentRepository,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('entity_type.manager'),
      $container->get('ai_content_audit_scoring.assessment_repository'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $node = $this->routeMatch->getParameter('node');
    // Route parameter may be a string ID before upcasting in some contexts.
    if (is_numeric($node)) {
      $node = $this->entityTypeManager->getStorage('node')->load($node);
    }

    $meta  = new CacheableMetadata();
    $build = [];

    $meta->addCacheContexts(['route']);

    if (!$node instanceof NodeInterface) {
      $meta->applyTo($build);
      return $build;
    }

    $nid = (int) $node->id();
    $meta->addCacheTags(['ai_content_assessment_list:node:' . $nid]);
    $meta->addCacheTags($node->getCacheTags());

    $assessment = $this->assessmentRepository->getLatestForNode($nid);

    if ($assessment === NULL) {
      $build['empty'] = ['#markup' => '<p>' . $this->t('No AI assessment available for this node.') . '</p>'];
      $meta->applyTo($build);
      return $build;
    }

    $meta->addCacheTags($assessment->getCacheTags());

    $result = $assessment->getParsedResult();

    $build = [
      '#theme'       => 'ai_assessment_panel',
      '#score'       => $assessment->getScore(),
      '#suggestions' => $result['suggestions'] ?? [],
      '#provider_id' => $assessment->get('provider_id')->value,
      '#model_id'    => $assessment->get('model_id')->value,
      '#created'     => $assessment->get('created')->value,
      '#attached'    => ['library' => ['ai_content_audit_scoring/assessment-panel']],
    ];

    $meta->applyTo($build);
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'view ai content assessment')
      ->cachePerPermissions();
  }

}
