<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Controller;

use Drupal\ai_content_audit\Service\AiroAnalysisPanelBuilder;
use Drupal\ai_content_audit\Service\NodeLayoutBuilderDetector;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Renders the AIRO Analysis node tab (main content + side panel).
 */
final class AiroNodeAnalysisController extends ControllerBase {

  public function __construct(
    protected NodeLayoutBuilderDetector $layoutBuilderDetector,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFormBuilderInterface $entityFormBuilder,
    protected AiroAnalysisPanelBuilder $panelBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_content_audit.node_layout_builder_detector'),
      $container->get('entity_type.manager'),
      $container->get('entity.form_builder'),
      $container->get('ai_content_audit.airo_analysis_panel_builder'),
    );
  }

  /**
   * Access callback: view node + update when edit/LB UI is shown.
   */
  public function access(NodeInterface $node, AccountInterface $account): AccessResult {
    if (!$node->access('view', $account)) {
      return AccessResult::forbidden();
    }

    $needs_update = TRUE;
    if ($needs_update && !$node->access('update', $account)) {
      return AccessResult::forbidden()
        ->addCacheableDependency($node)
        ->cachePerPermissions();
    }

    return AccessResult::allowed()
      ->addCacheableDependency($node)
      ->cachePerPermissions();
  }

  /**
   * Builds the AIRO Analysis page.
   */
  public function page(NodeInterface $node, Request $request): array {
    if (!$this->access($node, $this->currentUser())->isAllowed()) {
      throw new AccessDeniedHttpException();
    }

    $hasLayoutBuilder = $this->layoutBuilderDetector->isLayoutBuilderEnabled($node);
    $nodeRender = $hasLayoutBuilder
      ? $this->buildLayoutBuilderMain($node)
      : $this->buildNodeEditMain($node);

    $analysisPanel = $this->panelBuilder->build($node, ['variant' => 'page']);

    $build = [
      '#theme' => 'airo_analysis_node_page',
      '#node' => $node,
      '#node_render' => $nodeRender,
      '#analysis_panel' => $analysisPanel,
      '#has_layout_builder' => $hasLayoutBuilder,
      '#attached' => [
        'library' => [
          'ai_content_audit/airo-analysis-page',
        ],
      ],
    ];

    $cacheability = CacheableMetadata::createFromRenderArray($nodeRender)
      ->addCacheableDependency(CacheableMetadata::createFromRenderArray($analysisPanel))
      ->addCacheableDependency($node)
      ->addCacheContexts(['user.permissions', 'languages:language_interface']);
    $cacheability->applyTo($build);

    return $build;
  }

  /**
   * Layout Builder override UI (same form operation as /node/{node}/layout).
   */
  protected function buildLayoutBuilderMain(NodeInterface $node): array {
    try {
      $form = $this->entityFormBuilder->getForm($node, 'layout_builder');
    }
    catch (\InvalidArgumentException) {
      return $this->buildNodeEditMain($node);
    }

    $form['#attributes']['class'][] = 'airo-analysis-page__layout-form';

    return $form;
  }

  /**
   * Standard node edit form embedded in the tab main column.
   */
  protected function buildNodeEditMain(NodeInterface $node): array {
    $formObject = $this->entityTypeManager
      ->getFormObject('node', 'default')
      ->setEntity($node);

    $form = $this->entityFormBuilder->getForm($formObject);
    $form['#attributes']['class'][] = 'airo-analysis-page__edit-form';

    return $form;
  }

}
