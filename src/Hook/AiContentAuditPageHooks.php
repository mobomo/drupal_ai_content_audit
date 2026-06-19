<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Hook;

use Drupal\ai_content_audit\Service\AiroNodeAnalysisFormAlterer;
use Drupal\ai_content_audit\Service\NodeLayoutBuilderDetector;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;

/**
 * Page-level hooks for AIRO routes and attachments.
 */
final class AiContentAuditPageHooks {

  public function __construct(
    protected RouteMatchInterface $routeMatch,
    protected NodeLayoutBuilderDetector $layoutBuilderDetector,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_preprocess_html().
   */
  #[Hook('preprocess_html')]
  public function preprocessHtml(array &$variables): void {
    if ($this->routeMatch->getRouteName() !== 'ai_content_audit.node.airo_analysis') {
      return;
    }

    $variables['attributes']['class'][] = 'airo-analysis-route';
  }

  /**
   * Treats AIRO Analysis as a Gin Layout Builder route when Gin LB is present.
   */
  #[Hook('gin_lb_is_layout_builder_route_alter')]
  public function ginLbIsLayoutBuilderRouteAlter(bool &$is_layout_builder_route): void {
    if ($this->configFactory->get('system.theme')->get('admin') !== 'gin'
      || $this->routeMatch->getRouteName() !== AiroNodeAnalysisFormAlterer::ROUTE_NAME) {
      return;
    }

    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface && $this->layoutBuilderDetector->isLayoutBuilderEnabled($node)) {
      $is_layout_builder_route = TRUE;
    }
  }

  /**
   * Disables Gin LB's sidebar template on AIRO routes outside the Gin theme.
   */
  #[Hook('gin_lb_show_toolbar_alter')]
  public function ginLbShowToolbarAlter(bool &$show_toolbar): void {
    if ($this->routeMatch->getRouteName() !== AiroNodeAnalysisFormAlterer::ROUTE_NAME) {
      return;
    }

    if ($this->configFactory->get('system.theme')->get('admin') !== 'gin') {
      $show_toolbar = FALSE;
    }
  }

}
