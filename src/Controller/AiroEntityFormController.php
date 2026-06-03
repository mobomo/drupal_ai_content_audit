<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Controller;

use Drupal\ai_content_audit\Service\AiroAnalysisPanelBuilder;
use Drupal\ai_content_audit\Service\AiroNodeAnalysisFormAlterer;
use Drupal\ai_content_audit\Service\NodeLayoutBuilderDetector;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\HtmlEntityFormController;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ArgumentResolverInterface;

/**
 * Renders native Layout Builder or node edit forms on the AIRO Analysis tab.
 */
final class AiroEntityFormController extends HtmlEntityFormController implements ContainerInjectionInterface {

  public function __construct(
    ArgumentResolverInterface $argument_resolver,
    FormBuilderInterface $form_builder,
    EntityTypeManagerInterface $entity_type_manager,
    protected NodeLayoutBuilderDetector $layoutBuilderDetector,
    protected AiroAnalysisPanelBuilder $panelBuilder,
  ) {
    parent::__construct($argument_resolver, $form_builder, $entity_type_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('http_kernel.controller.argument_resolver'),
      $container->get('form_builder'),
      $container->get('entity_type.manager'),
      $container->get('ai_content_audit.node_layout_builder_detector'),
      $container->get('ai_content_audit.airo_analysis_panel_builder'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getContentResult(Request $request, RouteMatchInterface $route_match): array {
    $form_build = parent::getContentResult($request, $route_match);

    if ($route_match->getRouteName() !== AiroNodeAnalysisFormAlterer::ROUTE_NAME) {
      return $form_build;
    }

    $node = $route_match->getParameter('node');
    if (!$node instanceof NodeInterface || $this->layoutBuilderDetector->isLayoutBuilderEnabled($node)) {
      return $form_build;
    }

    // Sin LB: pestaña sin layout Gin de dos columnas; panel en aside.
    $panel = $this->panelBuilder->build($node, ['variant' => 'page']);

    $build = [
      '#theme' => 'airo_analysis_node_page',
      '#node' => $node,
      '#node_render' => $form_build,
      '#analysis_panel' => $panel,
      '#has_layout_builder' => FALSE,
      '#attached' => [
        'library' => [
          'ai_content_audit/airo-panel',
          'ai_content_audit/airo-panel-page-skin',
          'ai_content_audit/airo-analysis-page',
        ],
      ],
    ];

    $cacheability = CacheableMetadata::createFromRenderArray($form_build)
      ->addCacheableDependency(CacheableMetadata::createFromRenderArray($panel))
      ->addCacheableDependency($node);
    $cacheability->applyTo($build);

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function getFormArgument(RouteMatchInterface $route_match): string {
    if ($route_match->getRouteName() !== AiroNodeAnalysisFormAlterer::ROUTE_NAME) {
      return parent::getFormArgument($route_match);
    }

    $node = $route_match->getParameter('node');
    if ($node instanceof NodeInterface && $this->layoutBuilderDetector->isLayoutBuilderEnabled($node)) {
      return 'node.layout_builder';
    }

    return 'node.edit';
  }

}
