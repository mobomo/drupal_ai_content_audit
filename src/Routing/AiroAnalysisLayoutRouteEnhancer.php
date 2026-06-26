<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Routing;

use Drupal\ai_content_audit\Service\AiroNodeAnalysisFormAlterer;
use Drupal\ai_content_audit\Service\NodeLayoutBuilderDetector;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\EnhancerInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\Routing\LayoutSectionStorageParamConverter;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Applies Layout Builder route metadata before param conversion on AIRO tab.
 */
final class AiroAnalysisLayoutRouteEnhancer implements EnhancerInterface {

  public function __construct(
    protected NodeLayoutBuilderDetector $layoutBuilderDetector,
    protected LayoutSectionStorageParamConverter $sectionStorageParamConverter,
    protected LayoutTempstoreRepositoryInterface $layoutTempstoreRepository,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function enhance(array $defaults, Request $request): array {
    $route_name = $defaults[RouteObjectInterface::ROUTE_NAME] ?? '';
    if ($route_name !== AiroNodeAnalysisFormAlterer::ROUTE_NAME) {
      return $defaults;
    }

    $route = $defaults[RouteObjectInterface::ROUTE_OBJECT] ?? NULL;
    if (!$route instanceof Route) {
      return $defaults;
    }

    $node = $this->resolveNode($defaults['node'] ?? NULL);
    if (!$node instanceof NodeInterface || !$this->layoutBuilderDetector->isLayoutBuilderEnabled($node)) {
      return $defaults;
    }

    $this->applyLayoutBuilderRouteDefaults($route);
    $defaults['section_storage_type'] = 'overrides';
    $defaults['entity_type_id'] = 'node';
    $defaults['_entity_form'] = 'node.layout_builder';

    if (!isset($defaults['section_storage']) || !$defaults['section_storage'] instanceof SectionStorageInterface) {
      $storage = $this->sectionStorageParamConverter->convert(
        $node->getEntityTypeId() . '.' . $node->id(),
        ['layout_builder_tempstore' => TRUE],
        'section_storage',
        $defaults,
      );
      if ($storage instanceof SectionStorageInterface) {
        $defaults['section_storage'] = $this->layoutTempstoreRepository->get($storage);
      }
    }

    return $defaults;
  }

  /**
   * Mirrors layout_builder.overrides.{entity}.view route defaults for LB forms.
   */
  protected function applyLayoutBuilderRouteDefaults(Route $route): void {
    $route->setDefault('section_storage_type', 'overrides');
    $route->setDefault('section_storage', '');
    $route->setDefault('entity_type_id', 'node');
    $route->setDefault('_entity_form', 'node.layout_builder');

    $options = $route->getOptions();
    if ($this->configFactory->get('system.theme')->get('admin') === 'gin') {
      $options['_layout_builder'] = TRUE;
    }
    else {
      unset($options['_layout_builder']);
    }
    $parameters = $options['parameters'] ?? [];
    $parameters['section_storage'] = [
      'layout_builder_tempstore' => TRUE,
    ];
    $options['parameters'] = $parameters;
    $route->setOptions($options);
  }

  /**
   * Resolves the node route parameter to an entity when possible.
   */
  protected function resolveNode(mixed $node): ?NodeInterface {
    if ($node instanceof NodeInterface) {
      return $node;
    }
    if (is_numeric($node)) {
      $loaded = $this->entityTypeManager->getStorage('node')->load((int) $node);
      return $loaded instanceof NodeInterface ? $loaded : NULL;
    }
    return NULL;
  }

}
