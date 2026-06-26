<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Hook;

use Drupal\ai_content_audit\Service\AiroNodeAnalysisFormAlterer;
use Drupal\ai_content_audit\Service\NodeLayoutBuilderDetector;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Page-level hooks for AIRO routes and attachments.
 */
final class AiContentAuditPageHooks {

  public function __construct(
    protected RouteMatchInterface $routeMatch,
    #[Autowire(service: 'ai_content_audit.node_layout_builder_detector')]
    protected NodeLayoutBuilderDetector $layoutBuilderDetector,
    protected ConfigFactoryInterface $configFactory,
    protected readonly ModuleHandlerInterface $moduleHandler,
    #[Autowire(service: 'ai_content_audit.airo_node_analysis_form_alterer')]
    protected AiroNodeAnalysisFormAlterer $formAlterer,
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
    if (!$this->moduleHandler->moduleExists('gin_lb')
      || $this->routeMatch->getRouteName() !== AiroNodeAnalysisFormAlterer::ROUTE_NAME) {
      return;
    }

    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface && $this->layoutBuilderDetector->isLayoutBuilderEnabled($node)) {
      $is_layout_builder_route = TRUE;
    }
  }

}
