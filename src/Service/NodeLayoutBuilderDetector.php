<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\node\NodeInterface;

/**
 * Detects whether Layout Builder is enabled for a node bundle view display.
 */
final class NodeLayoutBuilderDetector {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Whether the bundle's view display uses Layout Builder for the given mode.
   */
  public function isLayoutBuilderEnabled(NodeInterface $node, string $viewMode = 'full'): bool {
    if ($node->hasField('layout_builder__layout') && !$node->get('layout_builder__layout')->isEmpty()) {
      return TRUE;
    }

    $storage = $this->entityTypeManager->getStorage('entity_view_display');
    $display = $storage->load('node.' . $node->bundle() . '.' . $viewMode);

    if (!$display instanceof EntityViewDisplayInterface) {
      return FALSE;
    }

    if (method_exists($display, 'isLayoutBuilderEnabled')) {
      return $display->isLayoutBuilderEnabled();
    }

    return (bool) $display->getThirdPartySetting('layout_builder', 'enabled', FALSE);
  }

}
