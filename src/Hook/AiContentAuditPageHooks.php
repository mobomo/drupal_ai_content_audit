<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Hook;

use Drupal\ai_content_audit\Service\AiroNodeAnalysisFormAlterer;
use Drupal\ai_content_audit\Service\NodeLayoutBuilderDetector;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;

/**
 * Page attachments (Gin shim when AIRO libraries are present).
 */
final class AiContentAuditPageHooks {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected RouteMatchInterface $routeMatch,
    protected NodeLayoutBuilderDetector $layoutBuilderDetector,
  ) {}

  #[Hook('preprocess_html')]
  public function preprocessHtml(array &$variables): void {
    if ($this->routeMatch->getRouteName() !== 'ai_content_audit.node.airo_analysis') {
      return;
    }

    $variables['attributes']['class'][] = 'airo-analysis-route';
  }

  #[Hook('page_attachments_alter')]
  public function pageAttachmentsAlter(array &$attachments): void {
    $system_theme_config = $this->configFactory->get('system.theme');
    $attachments['#cache']['tags'] = Cache::mergeTags(
      $attachments['#cache']['tags'] ?? [],
      $system_theme_config->getCacheTags()
    );

    if ($system_theme_config->get('admin') !== 'gin') {
      return;
    }

    $attached_libraries = $attachments['#attached']['library'] ?? [];
    $airo_present = array_filter($attached_libraries, static fn(string $lib): bool =>
      str_starts_with($lib, 'ai_content_audit/airo-panel') ||
      str_starts_with($lib, 'ai_content_audit/airo-analysis-page') ||
      str_starts_with($lib, 'ai_content_audit/assessment-report') ||
      str_starts_with($lib, 'ai_content_audit/inline-widget')
    );

    if (empty($airo_present)) {
      return;
    }

    $attachments['#attached']['library'][] = 'ai_content_audit/airo-panel-gin-shim';
  }

  /**
   * Treat AIRO Analysis as a Layout Builder route when the node uses LB.
   */
  #[Hook('gin_lb_is_layout_builder_route_alter')]
  public function ginLbIsLayoutBuilderRouteAlter(bool &$is_layout_builder_route): void {
    if ($this->routeMatch->getRouteName() !== AiroNodeAnalysisFormAlterer::ROUTE_NAME) {
      return;
    }

    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface && $this->layoutBuilderDetector->isLayoutBuilderEnabled($node)) {
      $is_layout_builder_route = TRUE;
    }
  }

  /**
   * Attaches Gin LB assets on AIRO Analysis when Layout Builder is active.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    if ($this->routeMatch->getRouteName() !== AiroNodeAnalysisFormAlterer::ROUTE_NAME) {
      return;
    }

    $node = $this->routeMatch->getParameter('node');
    if (!$node instanceof NodeInterface || !$this->layoutBuilderDetector->isLayoutBuilderEnabled($node)) {
      return;
    }

    foreach ([
      'gin_lb/gin_lb_init',
      'gin_lb/offcanvas',
      'gin_lb/preview',
      'gin_lb/toolbar',
      'gin/gin_ckeditor',
      'claro/claro.jquery.ui',
      'gin_lb/gin_lb',
      'claro/global-styling',
      'gin_lb/gin_lb_10',
    ] as $library) {
      $attachments['#attached']['library'][] = $library;
    }
  }

}
