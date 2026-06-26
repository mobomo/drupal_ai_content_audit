<?php

declare(strict_types=1);

namespace Drupal\ai_content_audit\AiroPanel;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;

/**
 * Provides one tab in the AIRO analysis panel.
 */
interface AiroPanelTabInterface {

  /**
   * Returns the stable tab ID used by markup and JavaScript.
   */
  public function id(): string;

  /**
   * Returns the human-readable tab label.
   */
  public function label(): TranslatableMarkup;

  /**
   * Returns the tab sort weight.
   */
  public function weight(): int;

  /**
   * Whether this tab should appear for the current node/surface.
   */
  public function applies(NodeInterface $node, bool $pageSkin = FALSE): bool;

  /**
   * Builds the tab pane render array.
   */
  public function build(NodeInterface $node, bool $pageSkin = FALSE): array;

}
