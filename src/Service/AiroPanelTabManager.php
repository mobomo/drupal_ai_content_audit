<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\ai_content_audit\AiroPanel\AiroPanelTabInterface;
use Drupal\node\NodeInterface;

/**
 * Collects and builds AIRO panel tabs.
 */
final class AiroPanelTabManager {

  /**
   * Registered tab providers keyed by tab ID.
   *
   * @var \Drupal\ai_content_audit\AiroPanel\AiroPanelTabInterface[]
   */
  private array $tabs = [];

  /**
   * Adds a tab provider from the service container collector.
   */
  public function addTab(
    AiroPanelTabInterface $tab,
    mixed $priority = 0,
    ?string $service_id = NULL,
  ): void {
    $this->tabs[$tab->id()] = $tab;
  }

  /**
   * Returns applicable tab providers in display order.
   *
   * @return \Drupal\ai_content_audit\AiroPanel\AiroPanelTabInterface[]
   *   Applicable tab providers keyed by tab ID.
   */
  public function getTabs(NodeInterface $node, bool $pageSkin = FALSE): array {
    $tabs = array_filter(
      $this->tabs,
      static fn(AiroPanelTabInterface $tab): bool => $tab->applies($node, $pageSkin)
    );

    uasort($tabs, static function (AiroPanelTabInterface $a, AiroPanelTabInterface $b): int {
      return $a->weight() <=> $b->weight()
        ?: $a->id() <=> $b->id();
    });

    return $tabs;
  }

  /**
   * Builds tab definitions for template navigation.
   *
   * @return array<int, array{id: string, label: \Drupal\Core\StringTranslation\TranslatableMarkup}>
   *   Template-ready tab definitions.
   */
  public function buildTabDefinitions(NodeInterface $node, bool $pageSkin = FALSE): array {
    $definitions = [];
    foreach ($this->getTabs($node, $pageSkin) as $tab) {
      $definitions[] = [
        'id' => $tab->id(),
        'label' => $tab->label(),
      ];
    }
    return $definitions;
  }

  /**
   * Builds tab pane render arrays keyed by tab ID.
   *
   * @return array<string, array>
   *   Tab pane render arrays keyed by tab ID.
   */
  public function buildTabPanes(NodeInterface $node, bool $pageSkin = FALSE): array {
    $panes = [];
    foreach ($this->getTabs($node, $pageSkin) as $tab) {
      $panes[$tab->id()] = $tab->build($node, $pageSkin);
    }
    return $panes;
  }

}
