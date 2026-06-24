<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Controller;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\ai_content_audit\Service\AiroAnalysisPanelBuilder;
use Drupal\ai_content_audit\Service\NodeLayoutBuilderDetector;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
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
    protected RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_content_audit.airo_analysis_panel_builder'),
      $container->get('ai_content_audit.node_layout_builder_detector'),
      $container->get('access_manager'),
      $container->get('renderer'),
    );
  }

  /**
   * Access callback: view node + update when edit/LB UI is shown.
   */
  public function access(NodeInterface $node, AccountInterface $account): AccessResultInterface {
    return $this->runAccess($node, $account);
  }

  /**
   * Access callback for assessment routes that only need to read node data.
   */
  public function viewAccess(NodeInterface $node, AccountInterface $account): AccessResultInterface {
    $access = $node->access('view', $account, TRUE);
    if ($access instanceof AccessResult) {
      return $access->addCacheableDependency($node)->cachePerPermissions();
    }

    return $access;
  }

  /**
   * Access callback for routes that assess or mutate node assessment state.
   */
  public function runAccess(NodeInterface $node, AccountInterface $account): AccessResultInterface {
    $view_access = $this->viewAccess($node, $account);
    if (!$view_access->isAllowed()) {
      return $view_access;
    }

    if ($this->layoutBuilderDetector->isLayoutBuilderEnabled($node)) {
      $allowed = $this->accessManager->checkNamedRoute(
        'layout_builder.overrides.node.view',
        ['node' => $node->id()],
        $account,
      );
      return $view_access->andIf(AccessResult::allowedIf($allowed)
        ->addCacheableDependency($node)
        ->cachePerPermissions());
    }

    /** @var \Drupal\Core\Access\AccessResult $update_access */
    $update_access = $node->access('update', $account, TRUE);

    return $view_access->andIf($update_access
      ->addCacheableDependency($node)
      ->cachePerPermissions());
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
      $this->renderer->renderRoot($build),
    ));
    return $response;
  }

}
