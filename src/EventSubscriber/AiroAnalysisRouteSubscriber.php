<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\EventSubscriber;

use Drupal\ai_content_audit\Service\AiroNodeAnalysisFormAlterer;
use Drupal\ai_content_audit\Service\NodeLayoutBuilderDetector;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Route;

/**
 * Aligns AIRO Analysis route options with native Layout vs Edit behavior.
 */
final class AiroAnalysisRouteSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected NodeLayoutBuilderDetector $layoutBuilderDetector,
    protected ConfigFactoryInterface $configFactory,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Must run after Symfony RouterListener (32) so _route and {node} exist.
    return [
      KernelEvents::REQUEST => ['onRequest', 28],
    ];
  }

  /**
   * Sets _admin_route and layout_builder route metadata per node.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== AiroNodeAnalysisFormAlterer::ROUTE_NAME) {
      return;
    }

    $route = $request->attributes->get('_route_object');
    if (!$route instanceof Route) {
      return;
    }

    $node = $this->resolveNode($request->attributes->get('node'));
    if (!$node instanceof NodeInterface) {
      return;
    }

    if ($this->layoutBuilderDetector->isLayoutBuilderEnabled($node)) {
      $route->setOption('_admin_route', FALSE);
      $this->applyLayoutBuilderRouteDefaults($route);
      $route->setDefault('_entity_form', 'node.layout_builder');
    }
    else {
      $route->setOption('_admin_route', TRUE);
      $route->setDefault('_entity_form', 'node.edit');
    }
  }

  /**
   * Mirrors layout_builder.overrides.{entity}.view route defaults for LB forms.
   */
  protected function applyLayoutBuilderRouteDefaults(Route $route): void {
    $route->setDefault('section_storage_type', 'overrides');
    $route->setDefault('section_storage', '');
    $route->setDefault('entity_type_id', 'node');

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
