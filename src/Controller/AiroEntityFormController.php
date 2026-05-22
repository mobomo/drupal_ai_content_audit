<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Controller;

use Drupal\ai_content_audit\Service\AiroNodeAnalysisFormAlterer;
use Drupal\ai_content_audit\Service\NodeLayoutBuilderDetector;
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
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getContentResult(Request $request, RouteMatchInterface $route_match): array {
    return parent::getContentResult($request, $route_match);
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
