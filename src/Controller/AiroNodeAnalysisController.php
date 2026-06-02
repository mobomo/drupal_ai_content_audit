<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Controller;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ai_content_audit\Service\AiroAnalysisPanelBuilder;
use Drupal\ai_content_audit\Service\NodeLayoutBuilderDetector;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;

/**
 * Access and AJAX helpers for the AIRO Analysis node tab.
 */
final class AiroNodeAnalysisController extends ControllerBase {

  public function __construct(
    protected AiroAnalysisPanelBuilder $panelBuilder,
    protected NodeLayoutBuilderDetector $layoutBuilderDetector,
    protected AccessManagerInterface $accessManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_content_audit.airo_analysis_panel_builder'),
      $container->get('ai_content_audit.node_layout_builder_detector'),
      $container->get('access_manager'),
    );
  }

  /**
   * Access callback: view node + update when edit/LB UI is shown.
   */
  public function access(NodeInterface $node, AccountInterface $account): AccessResult {
    if (!$node->access('view', $account)) {
      return AccessResult::forbidden();
    }

    if ($this->layoutBuilderDetector->isLayoutBuilderEnabled($node)) {
      $allowed = $this->accessManager->checkNamedRoute(
        'layout_builder.overrides.node.view',
        ['node' => $node->id()],
        $account,
      );
      return AccessResult::allowedIf($allowed)
        ->addCacheableDependency($node)
        ->cachePerPermissions();
    }

    if (!$node->access('update', $account)) {
      return AccessResult::forbidden()
        ->addCacheableDependency($node)
        ->cachePerPermissions();
    }

    return AccessResult::allowed()
      ->addCacheableDependency($node)
      ->cachePerPermissions();
  }

  /**
   * AJAX: replace the AIRO sidebar after re-analyze.
   */
  public function panelRefresh(NodeInterface $node): AjaxResponse {
    if (!$this->access($node, $this->currentUser())->isAllowed()) {
      throw new AccessDeniedHttpException();
    }

    $build = $this->panelBuilder->build($node, ['variant' => 'page']);
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand(
      '.airo-analysis-panel--page',
      \Drupal::service('renderer')->renderRoot($build),
    ));
    return $response;
  }

}
